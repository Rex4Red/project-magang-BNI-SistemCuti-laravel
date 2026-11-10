<?php

namespace Tests\Feature;

use App\Domain\CutiStore;
use App\Domain\MailOutbox;
use App\Domain\ReplacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicLeaveFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(9, 0));
        config(['cuti.demo' => true, 'cuti.public_form' => true, 'cuti.mail_mode' => 'capture']);
        $this->artisan('cuti:setup', ['--demo' => true])->assertSuccessful();
        $this->withHeader('X-Cuti-Client', 'web')->withCredentials();
    }

    private function input(array $changes = []): array
    {
        return [...['name' => 'Pemohon Formulir', 'npp' => 'P000001', 'position' => 'BM', 'outletId' => 'KC01-MAIN', 'category' => 'REGULAR', 'subtype' => 'Keperluan keluarga', 'reason' => 'Keperluan keluarga selama cuti.', 'start' => '2026-12-07', 'end' => '2026-12-08'], ...$changes];
    }

    private function submit(array $input, ?string $key = null): array
    {
        $preview = $this->postJson('/api/public/preview', $input)->assertOk()->json();

        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/api/public/requests', [...$input, 'fingerprint' => $preview['fingerprint'], 'acceptAdjustment' => true])->assertCreated()->json();
    }

    private function knownApplicant(): void
    {
        $employee = [...app(CutiStore::class)->employee('employee'), 'name' => 'Pemohon Formulir', 'npp' => 'P000001', 'email' => 'form@example.test', 'position' => 'BM', 'positionName' => 'BM'];
        DB::table('employees')->where('id', $employee['id'])->update(['email' => $employee['email'], 'data' => CutiStore::json($employee)]);
    }

    private function loginAdmin(): void
    {
        $this->postJson('/api/login', ['email' => 'admin@demo.bni.local', 'password' => 'BniCuti!2026'])->assertOk();
        $token = bin2hex(random_bytes(32));
        DB::table('cuti_sessions')->insert(['token' => CutiStore::digest($token), 'employee_id' => 'admin', 'expires_at' => now('UTC')->addHour()]);
        $this->withUnencryptedCookie(config('cuti.cookie'), $token);
    }

    public function test_anonymous_submission_has_no_session_and_discloses_only_a_receipt(): void
    {
        $config = $this->getJson('/api/public/form')->assertOk()->json();
        $this->assertArrayNotHasKey('employees', $config);
        $this->assertArrayNotHasKey('email', $config['units'][0]);
        $receipt = $this->submit($this->input(['employeeId' => 'admin', 'roles' => ['ADMIN'], 'status' => 'APPROVED', 'draftId' => 'anything']));
        $this->assertSame('RECORDED', $receipt['status']);
        $this->assertCount(5, $receipt);
        $this->assertArrayNotHasKey('id', $receipt);
        $this->assertDatabaseCount('cuti_sessions', 0);
        $employee = CutiStore::decode(DB::table('employees')->where('data->npp', 'P000001')->value('data'));
        $this->assertSame(['EMPLOYEE'], $employee['roles']);
        $this->assertTrue($employee['publicApplicant']);
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->getJson('/api/admin')->assertUnauthorized();
        $this->getJson('/api/leave-requests/'.DB::table('requests')->where('employee_id', $employee['id'])->value('id'))->assertUnauthorized();
    }

    public function test_public_form_uses_npp_without_contact_fields_and_preserves_leading_zeros(): void
    {
        $input = $this->input();
        unset($input['npp']);
        $this->postJson('/api/public/preview', $input)->assertUnprocessable();
        $this->postJson('/api/public/preview', [...$input, 'npp' => '=1+1'])->assertUnprocessable();
        $receipt = $this->submit([...$input, 'npp' => '000123', 'email' => 'forged@example.test', 'phone' => '081111111111']);
        $leave = collect(app(CutiStore::class)->requests())->firstWhere('number', $receipt['number']);
        $this->assertSame('000123', $leave['npp']);
        $this->assertSame('', $leave['email']);
        $this->assertSame('', $leave['phone']);
        $this->assertDatabaseMissing('mail_jobs', ['request_id' => $leave['id'], 'kind' => 'RECEIPT']);
        $this->loginAdmin();
        $summary = $this->getJson('/api/leave-calendar?month=2026-12')->assertOk();
        $row = collect($summary->json('rows'))->firstWhere('id', $leave['id']);
        $this->assertSame('000123', $row['npp']);
        $this->assertSame('RECORDED', $row['status']);
        $this->get('/api/leave-calendar.xlsx?month=2026-12')->assertOk()->assertDownload('rekap-kalender-cuti-2026-12.xlsx');
    }

    public function test_same_npp_counts_once_and_retries_do_not_duplicate_or_overwrite_records(): void
    {
        $input = $this->input();
        $key = (string) Str::uuid();
        $receipt = $this->submit($input, $key);
        $operation = DB::table('operations')->where('key', $key)->first();
        $storedResult = CutiStore::decode($operation->result);
        ksort($storedResult);
        DB::table('operations')->where('key', $key)->update(['result' => CutiStore::json($storedResult)]);
        $this->assertSame($receipt, $this->submit($input, $key));
        $this->submit($this->input(['start' => '2026-12-14', 'end' => '2026-12-15']));
        $this->assertSame(1, DB::table('employees')->where('data->npp', 'P000001')->count());
        $this->assertSame(2, DB::table('requests')->where('position', 'BM')->count());
        $preview = $this->postJson('/api/public/preview', $this->input(['start' => '2026-12-21', 'end' => '2026-12-22']))->assertOk();
        $preview->assertJsonPath('quota.used', 1)->assertJsonPath('quota.alreadyCounted', true);
        $store = app(CutiStore::class);
        $leave = collect($store->requests())->firstWhere('number', $receipt['number']);
        $store->saveRequest([...$leave, 'version' => 3]);
        $this->submit($input, $key);
        $this->assertDatabaseHas('requests', ['id' => $leave['id'], 'status' => 'RECORDED']);
        $this->assertSame(3, collect($store->requests('KC01'))->firstWhere('id', $leave['id'])['version']);
    }

    public function test_manual_identity_cannot_change_an_existing_employee_or_outlet(): void
    {
        $this->submit($this->input());
        $this->postJson('/api/public/preview', $this->input(['name' => 'Nama Pengganti']))->assertUnprocessable()->assertJsonPath('code', 'IDENTITY_MISMATCH');
        $this->postJson('/api/public/preview', $this->input(['position' => 'BBO']))->assertUnprocessable();
        $this->postJson('/api/public/preview', $this->input(['outletId' => 'not-an-outlet']))->assertUnprocessable();
        $this->postJson('/api/public/preview', $this->input(['position' => 'not-a-position']))->assertUnprocessable();
        $this->assertDatabaseHas('employees', ['data->npp' => 'P000001']);
    }

    public function test_notice_period_duration_and_month_end_still_apply_to_public_requests(): void
    {
        $this->postJson('/api/public/preview', $this->input(['start' => '2026-11-05', 'end' => '2026-11-05']))->assertOk()->assertJsonFragment(['code' => 'NOTICE_PERIOD']);
        $this->postJson('/api/public/preview', $this->input(['start' => '2026-12-29', 'end' => '2026-12-31']))->assertOk()->assertJsonFragment(['code' => 'MONTH_END_BLOCKED']);
        $this->postJson('/api/public/preview', $this->input(['category' => 'EMERGENCY', 'start' => '2026-12-29', 'end' => '2026-12-31']))->assertOk()->assertJsonFragment(['code' => 'MONTH_END_BLOCKED']);
        $this->postJson('/api/public/preview', $this->input(['end' => '2026-12-14']))->assertOk()->assertJsonFragment(['code' => 'DURATION_LIMIT']);
        $this->postJson('/api/public/preview', $this->input(['start' => '2026-12-28', 'end' => '2026-12-31']))->assertOk()->assertJsonPath('duration', 1)->assertJsonPath('adjusted', true);
        $this->submit($this->input(['category' => 'EMERGENCY', 'start' => '2026-11-05', 'end' => '2026-11-05']));
    }

    public function test_public_requests_reserve_quota_and_enforce_overlaps(): void
    {
        $this->submit($this->input(['end' => '2026-12-10']));
        $second = $this->input(['name' => 'Pemohon Kedua', 'npp' => 'P000002', 'start' => '2026-12-09', 'end' => '2026-12-11']);
        $this->postJson('/api/public/preview', [...$second, 'start' => '2026-12-08'])->assertOk()->assertJsonFragment(['code' => 'OVERLAP_LIMIT']);
        $this->submit($second);
        $this->postJson('/api/public/preview', $this->input(['name' => 'Pemohon Ketiga', 'npp' => 'P000003', 'start' => '2026-12-21', 'end' => '2026-12-22']))->assertOk()->assertJsonFragment(['code' => 'QUOTA_EXCEEDED']);
    }

    public function test_admin_can_monitor_records_but_approval_is_disabled_and_mail_is_informational(): void
    {
        $this->postJson('/api/login', ['email' => 'sdm@demo.bni.local', 'password' => 'BniCuti!2026'])->assertForbidden();
        $this->postJson('/api/login', ['email' => 'karyawan@demo.bni.local', 'password' => 'BniCuti!2026'])->assertForbidden();
        $this->knownApplicant();
        $receipt = $this->submit($this->input(['category' => 'EMERGENCY', 'start' => '2026-11-05', 'end' => '2026-11-05']));
        $this->assertTrue(app(MailOutbox::class)->processOne());
        $this->assertTrue(app(MailOutbox::class)->processOne());
        $this->assertFalse(app(MailOutbox::class)->processOne());
        $this->assertDatabaseHas('mail_deliveries', ['recipient' => 'admin@demo.bni.local', 'state' => 'CAPTURED']);
        $this->assertDatabaseHas('mail_deliveries', ['recipient' => 'form@example.test', 'state' => 'CAPTURED']);
        $this->assertDatabaseMissing('mail_deliveries', ['recipient' => 'sdm@demo.bni.local']);
        foreach (DB::table('mail_deliveries')->get() as $delivery) {
            $this->assertStringContainsString('cuti tercatat', strtolower($delivery->subject));
            $this->assertStringNotContainsString('review', strtolower($delivery->body));
        }
        $this->loginAdmin();
        $response = $this->getJson('/api/dashboard?month=2026-11')->assertOk();
        $leave = collect($response->json('requests'))->firstWhere('number', $receipt['number']);
        $this->assertNotNull($leave);
        $this->assertSame('RECORDED', $leave['status']);
        $this->getJson('/api/leave-requests/'.$leave['id'])->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/leave-requests/'.$leave['id'].'/decision', ['version' => 1, 'outcome' => 'APPROVED'])->assertConflict()->assertJsonPath('code', 'APPROVAL_DISABLED');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/leave-requests/'.$leave['id'].'/confirmation', ['version' => 1, 'result' => 'CONFIRMED', 'channel' => 'Telepon'])->assertConflict()->assertJsonPath('code', 'APPROVAL_DISABLED');
        $this->assertDatabaseHas('requests', ['id' => $leave['id'], 'status' => 'RECORDED']);
        $this->getJson('/api/verify-certificate?num='.$receipt['number'])->assertOk()->assertJsonPath('valid', true)->assertJsonPath('recordOnly', true)->assertJsonPath('status', 'RECORDED');
        $report = $this->get('/api/reports.csv?month=2026-11')->assertOk()->streamedContent();
        $this->assertStringContainsString('Tercatat', $report);
        $this->assertStringNotContainsString('Disetujui', $report);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/admin/employee', ['name' => 'Data Tanpa Akun', 'email' => 'directory@example.test', 'phone' => '081234567899', 'position' => 'BM', 'outletId' => 'KC01-MAIN', 'roles' => ['EMPLOYEE'], 'active' => true])->assertOk();
    }

    public function test_public_requests_ignore_pgs_rules_and_existing_assignments(): void
    {
        $store = app(CutiStore::class);
        $policy = $store->policy('KC01');
        $policy['replacementRules']['BM'] = ['position' => 'BM', 'enabled' => true, 'sourcePositions' => ['BMB'], 'sameOutlet' => true, 'otherOutlets' => 'ALL', 'outletIds' => []];
        $store->savePolicy('KC01', $policy);
        $input = $this->input(['category' => 'EMERGENCY', 'start' => '2026-11-05', 'end' => '2026-11-05']);
        $this->postJson('/api/public/preview', $input)->assertOk()->assertJsonPath('errors', []);
        $receipt = $this->submit($input);
        $this->assertSame('RECORDED', $receipt['status']);
        $leave = collect($store->requests())->firstWhere('number', $receipt['number']);
        $this->assertArrayNotHasKey('replacementCheckedAt', $leave);
        $this->assertFalse(collect(app(ReplacementService::class)->check($leave, $policy)['candidates'])->contains('available', true));

        $store->saveRequest([...$leave, 'id' => (string) Str::uuid(), 'employeeId' => 'staff0', 'position' => 'TELLER',
            'days' => ['2026-11-06'], 'effectiveStart' => '2026-11-06', 'effectiveEnd' => '2026-11-06',
            'replacement' => ['employeeId' => $leave['employeeId']]]);
        $nextInput = [...$input, 'start' => '2026-11-06', 'end' => '2026-11-06'];
        $this->postJson('/api/public/preview', $nextInput)->assertOk()->assertJsonPath('errors', []);
        $this->assertSame('RECORDED', $this->submit($nextInput)['status']);
    }

    public function test_regular_receipt_is_immediate_and_admin_notice_keeps_the_scheduled_date(): void
    {
        $this->knownApplicant();
        $this->submit($this->input(['start' => '2026-12-14', 'end' => '2026-12-14']));
        $this->assertTrue(app(MailOutbox::class)->processOne());
        $this->assertFalse(app(MailOutbox::class)->processOne());
        $this->assertDatabaseHas('mail_deliveries', ['recipient' => 'form@example.test']);
        $this->assertDatabaseMissing('mail_deliveries', ['recipient' => 'admin@demo.bni.local']);
        $this->travelTo(now()->setDate(2026, 11, 14)->setTime(9, 0));
        $this->assertTrue(app(MailOutbox::class)->processOne());
        $this->assertDatabaseHas('mail_deliveries', ['recipient' => 'admin@demo.bni.local']);
        $this->assertDatabaseMissing('mail_jobs', ['kind' => 'REMINDER']);
        $this->assertDatabaseMissing('mail_jobs', ['kind' => 'DECISION']);
    }

    public function test_invalid_preview_adjustment_and_rate_limit_cannot_be_bypassed(): void
    {
        $input = $this->input(['start' => '2026-12-28', 'end' => '2026-12-31']);
        $preview = $this->postJson('/api/public/preview', $input)->assertOk()->json();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/public/requests', [...$input, 'fingerprint' => $preview['fingerprint']])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/public/requests', [...$input, 'fingerprint' => 'wrong', 'acceptAdjustment' => true])->assertConflict();
        $this->assertDatabaseMissing('employees', ['data->npp' => 'P000001']);
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('api/public/requests:127.0.0.1', 900);
        }
        $this->postJson('/api/public/requests', $input)->assertStatus(429);
        $this->assertDatabaseMissing('employees', ['data->npp' => 'P000001']);
    }
}
