<?php

namespace Tests\Feature;

use App\Domain\CutiStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoAccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cuti.demo' => true]);
        $this->artisan('cuti:setup', ['--demo' => true])->assertSuccessful();
        $this->withHeader('X-Cuti-Client', 'web');
    }

    private function disable(string $id): void
    {
        $person = app(CutiStore::class)->employee($id);
        DB::table('employees')->where('id', $id)->update(['data' => CutiStore::json([...$person, 'active' => false, 'deletedAt' => now('UTC')->toISOString()])]);
    }

    public function test_only_the_correct_demo_password_recovers_a_disabled_demo_account(): void
    {
        $this->disable('sdm');
        $this->postJson('/api/login', ['email' => 'sdm@demo.bni.local', 'password' => 'wrong123'])->assertUnauthorized();
        $this->assertFalse(app(CutiStore::class)->employee('sdm')['active']);
        $this->postJson('/api/login', ['email' => 'sdm@demo.bni.local', 'password' => 'BniCuti!2026'])->assertOk()->assertJsonPath('active', true)->assertJsonPath('position', 'SDM');
        $this->assertArrayNotHasKey('deletedAt', app(CutiStore::class)->employee('sdm'));
        $this->assertDatabaseCount('employees', 9);
    }

    public function test_setup_recovers_missing_demo_accounts_without_replacing_other_data_or_active_passwords(): void
    {
        $requests = DB::table('requests')->pluck('data', 'id')->all();
        $hash = password_hash('changed123', PASSWORD_BCRYPT);
        DB::table('employees')->where('id', 'employee')->update(['password_hash' => $hash]);
        DB::table('employees')->where('id', 'admin')->delete();
        $this->disable('sdm');
        $this->artisan('cuti:setup', ['--demo' => true])->assertSuccessful();
        $this->assertDatabaseCount('employees', 9);
        $this->assertSame($requests, DB::table('requests')->pluck('data', 'id')->all());
        $this->assertSame($hash, DB::table('employees')->where('id', 'employee')->value('password_hash'));
        $this->postJson('/api/login', ['email' => 'admin@demo.bni.local', 'password' => 'BniCuti!2026'])->assertOk();
        $this->postJson('/api/login', ['email' => 'karyawan@demo.bni.local', 'password' => 'BniCuti!2026'])->assertUnauthorized();
        $this->postJson('/api/login', ['email' => 'karyawan@demo.bni.local', 'password' => 'changed123'])->assertOk();
    }

    public function test_recovery_is_disabled_when_demo_mode_is_off(): void
    {
        $this->disable('sdm');
        config(['cuti.demo' => false]);
        $this->postJson('/api/login', ['email' => 'sdm@demo.bni.local', 'password' => 'BniCuti!2026'])->assertUnauthorized();
        $this->assertFalse(app(CutiStore::class)->employee('sdm')['active']);
    }

    public function test_recovery_is_disabled_in_production_even_when_demo_mode_is_enabled(): void
    {
        $this->disable('sdm');
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->postJson('/api/login', ['email' => 'sdm@demo.bni.local', 'password' => 'BniCuti!2026'])->assertUnauthorized();
        $this->artisan('cuti:setup', ['--demo' => true])->assertFailed();
        $this->assertFalse(app(CutiStore::class)->employee('sdm')['active']);
    }

    public function test_regular_employees_and_conflicting_account_ids_are_never_recovered(): void
    {
        $this->disable('staff0');
        $this->postJson('/api/login', ['email' => 'pegawai1@demo.bni.local', 'password' => 'BniCuti!2026'])->assertUnauthorized();
        $person = app(CutiStore::class)->employee('admin');
        $person['email'] = 'custom@example.test';
        $person['active'] = false;
        DB::table('employees')->where('id', 'admin')->update(['email' => $person['email'], 'data' => CutiStore::json($person)]);
        $this->postJson('/api/login', ['email' => 'admin@demo.bni.local', 'password' => 'BniCuti!2026'])->assertUnauthorized();
        $this->assertDatabaseHas('employees', ['id' => 'admin', 'email' => 'custom@example.test']);
        $this->assertFalse(app(CutiStore::class)->employee('staff0')['active']);
    }
}
