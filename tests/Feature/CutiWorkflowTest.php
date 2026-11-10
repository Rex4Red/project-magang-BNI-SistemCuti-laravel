<?php

namespace Tests\Feature;

use App\Domain\CutiStore;
use App\Domain\LeaveRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

        return ['category' => 'REGULAR', 'subtype' => 'Keluarga', 'reason' => 'Pengujian cuti keluarga.', 'npp' => 'P000001', 'start' => $start, 'end' => $start, 'email' => 'karyawan@demo.bni.local', 'phone' => '081234567899'];
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
        $this->assertSame('APPROVED', $leave['status']);

        // Pengajuan tidak dapat ditarik kembali setelah disubmit
        $this->actor('employee');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/leave-requests/'.$leave['id'].'/withdraw', ['version' => $leave['version']])->assertForbidden();

        $store = app(CutiStore::class);
        $leave['status'] = 'PENDING_SDM';
        $leave['confirmation'] = 'NOT_CONFIRMED';
        $store->saveRequest($leave);

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
        $store = app(CutiStore::class);
        $policy = $store->policy('KC01');
        $policy['replacementRules']['CS_BINA'] = ['position' => 'CS_BINA', 'enabled' => true, 'sourcePositions' => ['BM'], 'sameOutlet' => true, 'otherOutlets' => 'ALL', 'outletIds' => []];
        $store->savePolicy('KC01', $policy);
        $leave = $this->submit();
        $this->assertSame('PENDING_SDM', $leave['status']);
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

    public function test_leave_requires_npp_and_uses_profile_contacts_instead_of_form_contacts(): void
    {
        $this->actor('employee');
        $input = $this->input();
        unset($input['email'], $input['phone'], $input['npp']);
        $this->postJson('/api/leave-requests/preview', $input)->assertUnprocessable();
        $this->postJson('/api/leave-requests/preview', [...$input, 'npp' => '=1+1'])->assertUnprocessable();
        $input['npp'] = '000123';
        $preview = $this->postJson('/api/leave-requests/preview', $input)->assertOk()->json();
        $employee = app(CutiStore::class)->employee('employee');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/leave-requests', [...$input, 'email' => 'forged@example.test', 'phone' => '081111111111', 'fingerprint' => $preview['fingerprint'], 'acceptAdjustment' => true])
            ->assertCreated()->assertJsonPath('npp', '000123')->assertJsonPath('email', $employee['email'])->assertJsonPath('phone', $employee['phone']);
    }

    public function test_npp_must_match_the_profile_and_is_preserved_in_a_draft(): void
    {
        $store = app(CutiStore::class);
        $employee = [...$store->employee('employee'), 'npp' => 'P000001'];
        DB::table('employees')->where('id', 'employee')->update(['data' => CutiStore::json($employee)]);
        $this->actor('employee');
        $this->postJson('/api/leave-requests/preview', [...$this->input(), 'npp' => 'P999999'])->assertUnprocessable();
        $draft = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/drafts', ['category' => 'REGULAR', 'npp' => 'p000001'])->assertOk()->assertJsonPath('npp', 'P000001')->json();
        $this->getJson('/api/leave-requests/'.$draft['id'])->assertOk()->assertJsonPath('npp', 'P000001')->assertJsonPath('email', $employee['email']);
    }

    public function test_admin_can_store_npp_and_cannot_assign_a_duplicate(): void
    {
        $this->actor('admin');
        $store = app(CutiStore::class);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/admin/employee', [...$store->employee('employee'), 'npp' => 'p041629'])->assertOk()->assertJsonPath('npp', 'P041629');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/admin/employee', [...$store->employee('staff0'), 'npp' => 'P041629'])->assertConflict();
        $this->assertSame('P041629', $store->employee('employee')['npp']);
    }

    private function calendarFixtures(): void
    {
        $store = app(CutiStore::class);
        $template = $store->requests('KC01')[0];
        DB::table('requests')->delete();
        $policy = $store->policy('KC01');
        $policy['calendar']['exceptions']['2026-10-05'] = ['working' => false, 'label' => 'Libur unit'];
        $store->savePolicy('KC01', $policy);
        $base = [...$template, 'id' => 'calendar-approved', 'employeeId' => 'staff0', 'employeeName' => '=SUM(1,1)', 'npp' => '000123', 'position' => 'TELLER', 'positionName' => 'Teller', 'status' => 'APPROVED', 'days' => ['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06'], 'start' => '2026-10-01', 'end' => '2026-10-06', 'effectiveStart' => '2026-10-01', 'effectiveEnd' => '2026-10-06', 'subtype' => 'Cuti Tahunan', 'reason' => 'ALASAN RAHASIA', 'replacement' => ['employeeName' => '=2+2']];
        $store->saveRequest($base);
        $store->saveRequest([...$base, 'id' => 'calendar-pending', 'employeeName' => 'Z Pending', 'position' => 'BM', 'positionName' => 'BM', 'status' => 'PENDING_SDM']);
        foreach (['DRAFT', 'REJECTED', 'WITHDRAWN'] as $status) {
            $store->saveRequest([...$base, 'id' => 'calendar-'.$status, 'status' => $status]);
        }
        DB::table('units')->insert(['id' => 'KC02', 'name' => 'Cabang lain', 'policy' => CutiStore::json($policy)]);
        $store->saveRequest([...$base, 'id' => 'calendar-foreign', 'unit' => 'KC02']);
    }

    public function test_calendar_summary_filters_active_leaves_and_preserves_branch_privacy(): void
    {
        $this->calendarFixtures();
        $this->actor('admin');
        $response = $this->getJson('/api/leave-calendar?month=2026-10')->assertOk()->assertJsonCount(31, 'days')->assertJsonCount(2, 'rows')->assertJsonPath('rows.0.npp', '000123')->assertJsonPath('rows.0.cells.0.text', '=2+2')->assertJsonPath('days.2.off', true)->assertJsonPath('days.4.off', true)->assertJsonPath('rows.0.cells.4.active', false);
        $this->assertStringNotContainsString('ALASAN RAHASIA', $response->getContent());
        $this->assertArrayNotHasKey('email', $response->json('rows.0'));
        $this->getJson('/api/leave-calendar?month=2026-10&position=BM')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.name', 'Z Pending');
        $this->getJson('/api/leave-calendar?month=2026-10&position=INVALID')->assertUnprocessable();
        $this->getJson('/api/leave-calendar?month=2026-13')->assertUnprocessable();
        $this->getJson('/api/leave-calendar?month=2028-02')->assertOk()->assertJsonCount(29, 'days')->assertJsonCount(0, 'rows');
        $this->getJson('/api/dashboard?month=2026-10')->assertOk()->assertJsonCount(2, 'calendar')->assertJsonFragment(['name' => '=SUM(1,1)', 'id' => '']);
        $this->getJson('/api/leave-requests/calendar-approved')->assertNotFound();
        $this->actor('sdm');
        $this->getJson('/api/leave-calendar?month=2026-10')->assertOk()->assertJsonCount(2, 'rows');
    }

    public function test_calendar_summary_and_export_require_admin_or_sdm_access(): void
    {
        foreach (['leave-calendar', 'leave-calendar.xlsx'] as $endpoint) {
            $this->getJson('/api/'.$endpoint.'?month=2026-10')->assertUnauthorized();
        }
        $this->actor('employee');
        foreach (['leave-calendar', 'leave-calendar.xlsx'] as $endpoint) {
            $this->getJson('/api/'.$endpoint.'?month=2026-10')->assertForbidden();
        }
    }

    public function test_calendar_excel_matches_the_grid_with_literal_npp_labels_and_day_colours(): void
    {
        $this->calendarFixtures();
        $this->actor('admin');
        $response = $this->get('/api/leave-calendar.xlsx?month=2026-10')->assertOk()->assertDownload('rekap-kalender-cuti-2026-10.xlsx');
        $file = tempnam(sys_get_temp_dir(), 'cuti-calendar-');
        try {
            file_put_contents($file, $response->streamedContent());
            $workbook = IOFactory::load($file);
            $sheet = $workbook->getActiveSheet();
            $this->assertSame('NPP', $sheet->getCell('C5')->getValue());
            $this->assertSame('Oktober 2026', $sheet->getCell('F6')->getValue());
            $this->assertSame(31, $sheet->getCell('AJ5')->getValue());
            $this->assertSame('PENGGANTI / PGS', $sheet->getCell('AK5')->getValue());
            $this->assertSame('000123', $sheet->getCell('C7')->getValue());
            $this->assertSame('s', $sheet->getCell('C7')->getDataType());
            $this->assertSame('=SUM(1,1)', $sheet->getCell('B7')->getValue());
            $this->assertSame('s', $sheet->getCell('B7')->getDataType());
            $this->assertSame('=2+2', $sheet->getCell('F7')->getValue());
            $this->assertSame('s', $sheet->getCell('F7')->getDataType());
            $this->assertSame('72B687', $sheet->getStyle('F7')->getFill()->getStartColor()->getRGB());
            $this->assertSame('F3D16B', $sheet->getStyle('F8')->getFill()->getStartColor()->getRGB());
            $this->assertSame('E69A9F', $sheet->getStyle('H7')->getFill()->getStartColor()->getRGB());
            $this->assertSame('E69A9F', $sheet->getStyle('J7')->getFill()->getStartColor()->getRGB());
            $this->assertContains('F7:G7', $sheet->getMergeCells());
            $this->assertSame('F7', $sheet->getFreezePane());
            $this->assertSame('landscape', $sheet->getPageSetup()->getOrientation());
            $this->assertStringNotContainsString('ALASAN RAHASIA', CutiStore::json($sheet->toArray()));
            $workbook->disconnectWorksheets();
        } finally {
            unlink($file);
        }
        $this->get('/api/leave-calendar.xlsx?month=2028-02&position=BM')->assertOk()->assertDownload('rekap-kalender-cuti-2028-02.xlsx');
    }

    public function test_mutating_requests_reject_untrusted_origins_and_missing_custom_header(): void
    {
        $this->withHeader('Origin', 'https://untrusted.example')->postJson('/api/login', ['email' => 'admin@demo.bni.local', 'password' => 'BniCuti!2026'])->assertForbidden();
        $this->withHeader('Origin', 'http://127.0.0.1:3004')->withHeader('X-Cuti-Client', '')->postJson('/api/login', ['email' => 'admin@demo.bni.local', 'password' => 'BniCuti!2026'])->assertForbidden();
    }
}
