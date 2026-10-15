<?php

namespace App\Console\Commands;

use App\Domain\CutiStore;
use App\Domain\DemoAccounts;
use App\Domain\LeaveRules;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('cuti:setup {--demo : Create or recover demonstration accounts outside production}')]
#[Description('Initialize an empty BNI cuti database without replacing existing records')]
class CutiSetup extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CutiStore $store, DemoAccounts $demoAccounts): int
    {
        $demo = (bool) $this->option('demo');
        if ($demo && (! config('cuti.demo') || app()->environment('production'))) {
            $this->error('Demo hanya dapat dibuat ketika DEMO_MODE=true di lingkungan local/testing.');

            return self::FAILURE;
        }
        if (DB::table('units')->exists()) {
            $recovered = $demo ? $demoAccounts->recover() : 0;
            $this->info($recovered > 0 ? $recovered.' akun demo dipulihkan; data lainnya tetap.' : 'Data sudah tersedia; tidak ada data yang ditimpa.');

            return self::SUCCESS;
        }
        $email = $demo ? 'admin@demo.bni.local' : config('cuti.bootstrap_email');
        $password = $demo ? 'BniCuti!2026' : config('cuti.bootstrap_password');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! is_string($password) || ! preg_match(LeaveRules::PASSWORD_PATTERN, $password)) {
            $this->error('Isi BOOTSTRAP_EMAIL dan BOOTSTRAP_PASSWORD (minimal 6 karakter, huruf dan angka).');

            return self::FAILURE;
        }
        DB::transaction(function () use ($demo, $email, $password, $store) {
            $defaults = LeaveRules::defaults();
            $name = $demo ? 'Kantor Cabang • Demo' : config('cuti.branch_name');
            DB::table('units')->insert(['id' => 'KC01', 'name' => $name, 'policy' => CutiStore::json(['calendar' => $defaults['calendar'], 'quotas' => (object) [], 'replacementRules' => (object) []])]);
            $outlet = ['id' => 'KC01-MAIN', 'unit' => 'KC01', 'name' => $demo ? 'Kantor Cabang' : $name];
            DB::table('outlets')->insert(['id' => $outlet['id'], 'unit_id' => 'KC01', 'data' => CutiStore::json($outlet)]);
            $people = $demo ? [
                ['sdm', 'Nadia Putri', 'sdm@demo.bni.local', 'SDM', ['SDM', 'EMPLOYEE']],
                ['employee', 'Alya Rahma', 'karyawan@demo.bni.local', 'CS_BINA', ['EMPLOYEE']],
                ['admin', 'Administrator', $email, 'BBO', ['ADMIN']],
                ['staff0', 'Dimas Pratama', 'pegawai1@demo.bni.local', 'TELLER', ['EMPLOYEE']],
                ['staff1', 'Rani Wulandari', 'pegawai2@demo.bni.local', 'CS_FTE', ['EMPLOYEE']],
                ['staff2', 'Fajar Hidayat', 'pegawai3@demo.bni.local', 'BTRM', ['EMPLOYEE']],
                ['staff3', 'Sinta Maharani', 'pegawai4@demo.bni.local', 'CS_BINA', ['EMPLOYEE']],
                ['staff4', 'Bagas Saputra', 'pegawai5@demo.bni.local', 'ARM', ['EMPLOYEE']],
                ['staff5', 'Citra Lestari', 'pegawai6@demo.bni.local', 'TELLER', ['EMPLOYEE']],
            ] : [[(string) Str::uuid(), 'Administrator', strtolower($email), 'BBO', ['ADMIN']]];
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            foreach ($people as $index => [$id, $personName, $address, $position, $roles]) {
                $label = collect($defaults['positions'])->first(fn ($p) => $p[0] === $position)[1] ?? $position;
                $employee = ['id' => $id, 'name' => $personName, 'email' => $address, 'phone' => '0812345678'.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'position' => $position, 'positionName' => $label, 'unit' => 'KC01', 'outletId' => $outlet['id'], 'roles' => $roles, 'active' => true];
                DB::table('employees')->insert(['id' => $id, 'unit_id' => 'KC01', 'email' => $address, 'password_hash' => $hash, 'data' => CutiStore::json($employee)]);
                if ($demo && $index >= 3 && $index <= 7) {
                    $month = substr(LeaveRules::addMonths(substr(LeaveRules::today(), 0, 7).'-01', 1), 0, 7);
                    $days = array_slice(array_values(array_filter(LeaveRules::monthDays($month), fn ($day) => (int) substr($day, 8) >= 5 + ($index - 3) * 3 && LeaveRules::isWorking($day, $defaults['calendar']) && ! in_array($day, LeaveRules::blockedDays($month, $defaults['calendar']), true))), 0, 2);
                    if (! $days) {
                        continue;
                    }
                    $approved = ($index - 3) % 3 === 0;
                    $now = LeaveRules::timestamp();
                    $store->saveRequest(['id' => (string) Str::uuid(), 'number' => 'CT-DEMO-'.(1001 + $index - 3), 'employeeId' => $id, 'employeeName' => $personName, 'unit' => 'KC01', 'position' => $position, 'positionName' => $label, 'outletId' => $outlet['id'], 'outletName' => $outlet['name'], 'status' => $approved ? 'APPROVED' : 'PENDING_SDM', 'category' => 'REGULAR', 'subtype' => 'Keperluan keluarga', 'reason' => 'Keperluan keluarga pada periode yang diajukan.', 'start' => $days[0], 'end' => end($days), 'email' => $address, 'phone' => $employee['phone'], 'days' => $days, 'effectiveStart' => $days[0], 'effectiveEnd' => end($days), 'duration' => count($days), 'submittedAt' => LeaveRules::addMonths($days[0], -1).'T01:00:00Z', 'createdAt' => $now, 'confirmation' => $approved ? 'CONFIRMED' : 'NOT_CONFIRMED', 'version' => 1, 'calendarVersion' => 1, 'limit' => $defaults['positions'][array_search($position, array_column($defaults['positions'], 0))][2], 'events' => [['at' => $now, 'text' => 'Data contoh untuk demonstrasi', 'actor' => 'Sistem']]]);
                }
            }
        });
        $this->info($demo ? 'Data demonstrasi dibuat. Kata sandi akun contoh: BniCuti!2026' : 'Cabang dan akun administrator dibuat.');

        return self::SUCCESS;
    }
}
