<?php

namespace Tests\Feature;

use App\Domain\CutiStore;
use App\Domain\LeaveRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CutiWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cuti.demo' => true]);
        $this->artisan('cuti:setup', ['--demo' => true])->assertSuccessful();
        $this->withHeader('X-Cuti-Client', 'web')->withCredentials();
    }

    private function actor(string $id): void
    {
        $token = bin2hex(random_bytes(32));
        DB::table('cuti_sessions')->insert(['token' => CutiStore::digest($token), 'employee_id' => $id, 'expires_at' => now('UTC')->addHour()]);
        $this->withUnencryptedCookie(config('cuti.cookie'), $token);
    }

    private function input(): array
    {
        $start = LeaveRules::addMonths(LeaveRules::today(), 1);
        $calendar = LeaveRules::defaults()['calendar'];
        while (! LeaveRules::isWorking($start, $calendar) || in_array($start, LeaveRules::blockedDays(substr($start, 0, 7), $calendar), true)) {
            $start = CarbonImmutable::parse($start)->addDay()->format('Y-m-d');
        }

        return ['category' => 'REGULAR', 'subtype' => 'Keluarga', 'reason' => 'Pengujian cuti keluarga.', 'start' => $start, 'end' => $start, 'email' => 'karyawan@demo.bni.local', 'phone' => '081234567899'];
    }

    private function submit(): array
    {
        $this->actor('employee');
        $input = $this->input();
        $preview = $this->postJson('/api/leave-requests/preview', $input)->assertOk()->json();

        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/leave-requests', [...$input, 'fingerprint' => $preview['fingerprint'], 'acceptAdjustment' => true])->assertCreated()->json();
    }

    public function test_submit_is_idempotent_and_status_changes_require_current_version(): void
    {
        $leave = $this->submit();
        $this->postJson('/api/leave-requests', [...$this->input(), 'fingerprint' => $this->postJson('/api/leave-requests/preview', $this->input())->json('fingerprint'), 'acceptAdjustment' => true])->assertCreated();
        $this->assertSame(1, DB::table('requests')->where('employee_id', 'employee')->count());
        $this->actor('sdm');
        $url = '/api/leave-requests/'.$leave['id'].'/decision';
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, ['version' => 1, 'outcome' => 'APPROVED'])->assertUnprocessable();
        $confirmed = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/leave-requests/'.$leave['id'].'/confirmation', ['version' => 1, 'result' => 'CONFIRMED', 'channel' => 'Telepon'])->assertOk()->json();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, ['version' => 1, 'outcome' => 'APPROVED'])->assertConflict()->assertJsonPath('code', 'STALE_VERSION');
        $approved = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, ['version' => $confirmed['version'], 'outcome' => 'APPROVED'])->assertOk()->assertJsonPath('status', 'APPROVED')->json();
        $this->assertDatabaseHas('mail_jobs', ['request_id' => $leave['id'], 'kind' => 'DECISION']);
        $this->actor('employee');
        $cancel = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/leave-requests/'.$leave['id'].'/cancel-request', ['version' => $approved['version'], 'reason' => 'Jadwal keluarga berubah'])->assertOk()->json();
        $this->actor('sdm');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/leave-requests/'.$leave['id'].'/cancel-review', ['version' => $cancel['version'], 'outcome' => 'APPROVED'])->assertOk()->assertJsonPath('status', 'WITHDRAWN');
        $this->assertSame(2, DB::table('mail_jobs')->where('request_id', $leave['id'])->where('kind', 'DECISION')->count());
    }

    public function test_employee_cannot_review_or_read_another_employees_request(): void
    {
        $leave = $this->submit();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/leave-requests/'.$leave['id'].'/decision', ['version' => 1, 'outcome' => 'APPROVED'])->assertForbidden();
        $this->actor('staff0');
        $this->getJson('/api/leave-requests/'.$leave['id'])->assertNotFound();
        $this->getJson('/api/admin')->assertForbidden();
        $this->getJson('/api/reports.xlsx?month=all')->assertForbidden();
    }

    public function test_password_change_revokes_other_sessions_and_keeps_current_session(): void
    {
        $this->actor('employee');
        $this->actor('employee');
        $this->assertSame(2, DB::table('cuti_sessions')->where('employee_id', 'employee')->count());
        $this->postJson('/api/me/password', ['currentPassword' => 'BniCuti!2026', 'newPassword' => 'abc123', 'confirmPassword' => 'abc123'])->assertOk();
        $this->getJson('/api/me')->assertOk();
        $this->assertSame(1, DB::table('cuti_sessions')->where('employee_id', 'employee')->count());
        $this->postJson('/api/login', ['email' => 'karyawan@demo.bni.local', 'password' => 'BniCuti!2026'])->assertUnauthorized();
        $this->postJson('/api/login', ['email' => 'karyawan@demo.bni.local', 'password' => 'abc123'])->assertOk();
        $this->assertStringNotContainsString('abc123', CutiStore::json(DB::table('audit')->get()->all()));
    }

    public function test_active_pgs_rule_without_candidates_blocks_approval_until_disabled(): void
    {
        $leave = $this->submit();
        $store = app(CutiStore::class);
        $policy = $store->policy('KC01');
        $policy['replacementRules']['CS_BINA'] = ['position' => 'CS_BINA', 'enabled' => true, 'sourcePositions' => ['BM'], 'sameOutlet' => true, 'otherOutlets' => 'ALL', 'outletIds' => []];
        $store->savePolicy('KC01', $policy);
        $this->actor('sdm');
        $confirmed = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/leave-requests/'.$leave['id'].'/confirmation', ['version' => 1, 'result' => 'CONFIRMED', 'channel' => 'Telepon'])->assertOk()->json();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/leave-requests/'.$leave['id'].'/decision', ['version' => $confirmed['version'], 'outcome' => 'APPROVED'])->assertConflict()->assertJsonPath('code', 'NO_REPLACEMENT');
        $policy['replacementRules']['CS_BINA']['enabled'] = false;
        $store->savePolicy('KC01', $policy);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/leave-requests/'.$leave['id'].'/decision', ['version' => $confirmed['version'], 'outcome' => 'APPROVED'])->assertOk();
    }

    public function test_candidate_on_leave_or_assigned_elsewhere_is_unavailable_for_entire_request(): void
    {
        $leave = $this->submit();
        $store = app(CutiStore::class);
        $candidate = [...$store->employee('staff0'), 'id' => 'pgs-candidate', 'position' => 'BM', 'email' => 'pgs@example.test'];
        DB::table('employees')->insert(['id' => $candidate['id'], 'unit_id' => 'KC01', 'email' => $candidate['email'], 'password_hash' => password_hash('abc123', PASSWORD_BCRYPT), 'data' => CutiStore::json($candidate)]);
        $policy = $store->policy('KC01');
        $policy['replacementRules']['CS_BINA'] = ['position' => 'CS_BINA', 'enabled' => true, 'sourcePositions' => ['BM'], 'sameOutlet' => true, 'otherOutlets' => 'ALL', 'outletIds' => []];
        $store->savePolicy('KC01', $policy);
        $busy = [...$leave, 'id' => (string) Str::uuid(), 'employeeId' => $candidate['id'], 'position' => 'BM'];
        $store->saveRequest($busy);
        $this->actor('sdm');
        $url = '/api/leave-requests/'.$leave['id'].'/replacements';
        $this->getJson($url)->assertOk()->assertJsonPath('candidates.0.available', false)->assertJsonPath('candidates.0.reason', 'Sedang cuti atau menunggu review');
        $busy['status'] = 'WITHDRAWN';
        $store->saveRequest($busy);
        $assignment = [...$leave, 'id' => (string) Str::uuid(), 'employeeId' => 'staff0', 'position' => 'TELLER', 'status' => 'APPROVED', 'replacement' => ['employeeId' => $candidate['id']]];
        $store->saveRequest($assignment);
        $this->getJson($url)->assertOk()->assertJsonPath('candidates.0.available', false)->assertJsonPath('candidates.0.reason', 'Sudah bertugas sebagai PGS');
        $assignment['status'] = 'WITHDRAWN';
        $store->saveRequest($assignment);
        $this->getJson($url)->assertOk()->assertJsonPath('candidates.0.available', true);
    }

    public function test_quota_and_calendar_are_protected_once_allocated_and_outlet_name_is_current(): void
    {
        $leave = $this->submit();
        $this->actor('admin');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/admin/quota', ['month' => substr($leave['start'], 0, 7), 'position' => 'CS_BINA', 'limit' => 8])->assertConflict();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/admin/calendar', ['date' => $leave['start'], 'working' => false, 'label' => 'Libur uji'])->assertConflict();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/admin/outlets', ['id' => 'KC01-MAIN', 'name' => 'SUDIRMAN'])->assertOk();
        $this->actor('employee');
        $this->getJson('/api/dashboard?month='.substr($leave['start'], 0, 7))->assertOk()->assertJsonPath('outletName', 'SUDIRMAN');
    }

    public function test_mutating_requests_reject_untrusted_origins_and_missing_custom_header(): void
    {
        $this->withHeader('Origin', 'https://untrusted.example')->postJson('/api/login', ['email' => 'admin@demo.bni.local', 'password' => 'BniCuti!2026'])->assertForbidden();
        $this->withHeader('Origin', 'http://127.0.0.1:3004')->withHeader('X-Cuti-Client', '')->postJson('/api/login', ['email' => 'admin@demo.bni.local', 'password' => 'BniCuti!2026'])->assertForbidden();
    }
}
