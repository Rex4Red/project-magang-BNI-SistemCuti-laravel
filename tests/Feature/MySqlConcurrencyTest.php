<?php

namespace Tests\Feature;

use App\Domain\CutiStore;
use App\Domain\LeaveRules;
use App\Domain\LeaveService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MySqlConcurrencyTest extends TestCase
{
    public function test_two_processes_cannot_reserve_the_last_slot_twice(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Run separately with a disposable MySQL database ending in _test.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config(['cuti.demo' => true]);
        $this->artisan('cuti:setup', ['--demo' => true])->assertSuccessful();
        $store = app(CutiStore::class);
        $employee = [...$store->employee('employee'), 'id' => 'race-b', 'email' => 'race-b@example.test'];
        DB::table('employees')->insert(['id' => $employee['id'], 'unit_id' => $employee['unit'], 'email' => $employee['email'], 'password_hash' => password_hash('abc123', PASSWORD_BCRYPT), 'data' => CutiStore::json($employee)]);
        $month = substr(LeaveRules::addMonths(LeaveRules::today(), 1), 0, 7);
        $calendar = $store->policy('KC01')['calendar'];
        $minimum = LeaveRules::addMonths(LeaveRules::today(), 1);
        $days = array_values(array_filter(LeaveRules::monthDays($month), fn ($day) => $day >= $minimum && LeaveRules::isWorking($day, $calendar) && ! in_array($day, LeaveRules::blockedDays($month, $calendar), true) && ! collect($store->requests('KC01'))->contains(fn ($r) => $r['position'] === 'CS_BINA' && in_array($day, $r['days'], true))));
        $this->assertGreaterThanOrEqual(2, count($days));
        $connection = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array'];
        $directory = base_path('.runtime');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $workers = [];
        $markers = [];
        try {
            foreach (['employee', 'race-b'] as $index => $id) {
                $actor = $store->employee($id);
                $input = ['category' => 'REGULAR', 'subtype' => 'Keluarga', 'reason' => 'Pengajuan bersamaan untuk slot terakhir.', 'start' => $days[$index], 'end' => $days[$index], 'email' => $actor['email'], 'phone' => $actor['phone']];
                $preview = app(LeaveService::class)->preview($actor, $input);
                $this->assertSame([], $preview['errors']);
                $ready = $directory.'/race-'.Str::uuid().'.ready';
                $markers[] = $ready;
                $worker = new Process([PHP_BINARY, base_path('tests/Support/submit-worker.php')], base_path(), $environment, CutiStore::json(['actor' => $id, 'body' => [...$input, 'fingerprint' => $preview['fingerprint']], 'key' => (string) Str::uuid(), 'ready' => $ready]), 30);
                $workers[] = $worker;
            }
            DB::beginTransaction();
            DB::table('units')->where('id', 'KC01')->lockForUpdate()->first();
            foreach ($workers as $worker) {
                $worker->start();
            }
            $deadline = microtime(true) + 15;
            while (count(array_filter($markers, 'is_file')) < 2 && microtime(true) < $deadline) {
                usleep(20000);
            }
            $this->assertSame(2, count(array_filter($markers, 'is_file')), 'Both workers must reach the transaction together.');
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR)['result'];
            }
            sort($results);
            $this->assertSame(['QUOTA_EXCEEDED', 'ok'], $results);
            $this->assertSame(1, DB::table('requests')->whereIn('employee_id', ['employee', 'race-b'])->count());
            $this->assertSame(1, DB::table('operations')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                $worker->stop();
            }
            foreach ($markers as $marker) {
                if (is_file($marker)) {
                    unlink($marker);
                }
            }
        }
    }
}
