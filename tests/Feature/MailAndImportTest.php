<?php

namespace Tests\Feature;

use App\Domain\CutiStore;
use App\Domain\LeaveRules;
use App\Domain\MailOutbox;
use App\Domain\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class MailAndImportTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): CutiStore
    {
        config(['cuti.demo' => true, 'cuti.mail_mode' => 'capture']);
        $this->artisan('cuti:setup', ['--demo' => true])->assertSuccessful();

        return app(CutiStore::class);
    }

    public function test_due_notifications_are_captured_once_without_private_reason(): void
    {
        $store = $this->seedDemo();
        $leave = $store->requests('KC01')[0];
        $store->queueMail($leave, 'DECISION', LeaveRules::timestamp());
        $store->queueMail($leave, 'DECISION', LeaveRules::timestamp());
        $this->assertSame(1, DB::table('mail_jobs')->count());
        $this->assertTrue(app(MailOutbox::class)->processOne());
        $this->assertFalse(app(MailOutbox::class)->processOne());
        $this->assertDatabaseHas('mail_jobs', ['state' => 'CAPTURED']);
        $delivery = DB::table('mail_deliveries')->first();
        $this->assertSame($leave['email'], $delivery->recipient);
        $this->assertStringNotContainsString($leave['reason'], $delivery->body);
    }

    public function test_future_reminder_waits_and_confirmation_skips_obsolete_reminder(): void
    {
        $store = $this->seedDemo();
        $leave = collect($store->requests('KC01'))->firstWhere('status', 'PENDING_SDM');
        $store->queueMail($leave, 'REMINDER', now('UTC')->addDays(2)->toISOString());
        $this->assertFalse(app(MailOutbox::class)->processOne());
        DB::table('mail_jobs')->update(['due_at' => now('UTC')->subMinute()]);
        $leave['confirmation'] = 'CONFIRMED';
        $store->saveRequest($leave);
        $this->assertTrue(app(MailOutbox::class)->processOne());
        $this->assertDatabaseHas('mail_jobs', ['state' => 'SKIPPED']);
        $this->assertDatabaseCount('mail_deliveries', 0);
    }

    public function test_failed_mail_retries_then_becomes_failed_with_sanitized_error(): void
    {
        $store = $this->seedDemo();
        config(['cuti.mail_mode' => 'smtp', 'mail.mailers.smtp.host' => null]);
        $store->queueMail($store->requests('KC01')[0], 'DECISION', LeaveRules::timestamp());
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            DB::table('mail_jobs')->update(['due_at' => now('UTC')->subMinute()]);
            $this->assertTrue(app(MailOutbox::class)->processOne());
            $this->assertDatabaseHas('mail_jobs', ['attempts' => $attempt, 'state' => $attempt === 5 ? 'FAILED' : 'QUEUED', 'last_error' => 'SMTP_NOT_CONFIGURED']);
        }
    }

    public function test_xlsx_uses_literal_cells_and_omits_private_reason(): void
    {
        $store = $this->seedDemo();
        $leave = $store->requests('KC01')[0];
        $leave['employeeName'] = '=HYPERLINK("https://example.test")';
        $response = app(ReportService::class)->download([$leave], $store->employee('sdm'), 'all', 'xlsx');
        ob_start();
        $response->sendContent();
        $bytes = ob_get_clean();
        $file = tempnam(sys_get_temp_dir(), 'cuti-report-');
        try {
            file_put_contents($file, $bytes);
            $sheet = IOFactory::load($file)->getSheetByName('Monitoring Cuti Pegawai');
            $this->assertSame($leave['employeeName'], $sheet->getCell('C6')->getValue());
            $this->assertSame('s', $sheet->getCell('C6')->getDataType());
            $this->assertStringNotContainsString($leave['reason'], CutiStore::json($sheet->toArray()));
        } finally {
            unlink($file);
        }
    }

    public function test_report_formats_include_the_full_submission_time_in_jakarta(): void
    {
        $store = $this->seedDemo();
        $leave = $store->requests('KC01')[0];
        $leave['submittedAt'] = '2026-10-15T06:07:08Z';
        $expected = '15 Okt 2026 13:07:08 WIB';
        foreach (['xlsx', 'xls', 'csv'] as $format) {
            $response = app(ReportService::class)->download([$leave], $store->employee('sdm'), 'all', $format);
            ob_start();
            $response->sendContent();
            $bytes = ob_get_clean();
            if ($format === 'csv') {
                $this->assertStringContainsString('Waktu Diajukan', $bytes);
                $this->assertStringContainsString($expected, $bytes);

                continue;
            }
            $file = tempnam(sys_get_temp_dir(), 'cuti-time-');
            try {
                file_put_contents($file, $bytes);
                $sheet = IOFactory::load($file)->getSheetByName('Monitoring Cuti Pegawai');
                $this->assertSame('Waktu Diajukan', $sheet->getCell('K5')->getValue());
                $this->assertSame($expected, $sheet->getCell('K6')->getValue());
            } finally {
                unlink($file);
            }
        }
        $leave['submittedAt'] = '';
        $leave['createdAt'] = '2026-10-15T06:07:08Z';
        ob_start();
        app(ReportService::class)->download([$leave], $store->employee('sdm'), 'all', 'csv')->sendContent();
        $this->assertStringContainsString($expected, ob_get_clean());
    }

    private function exportFixture(): array
    {
        $employee = ['id' => 'old-admin', 'unit' => 'OLD', 'name' => 'Admin lama', 'email' => 'old@example.test', 'phone' => '081234567890', 'position' => 'BM', 'roles' => ['ADMIN'], 'active' => true, 'outletId' => 'OLD-SUDIRMAN'];

        return ['format' => 'bni-cuti-legacy-v1', 'tables' => [
            'units' => [['id' => 'OLD', 'name' => 'Cabang lama', 'policy' => ['calendar' => LeaveRules::defaults()['calendar'], 'quotas' => []]]],
            'employees' => [['id' => $employee['id'], 'email' => $employee['email'], 'password_hash' => password_hash('abc123', PASSWORD_BCRYPT), 'data' => $employee]],
            'outlets' => [['id' => 'OLD-SUDIRMAN', 'unit_id' => 'OLD', 'data' => ['id' => 'OLD-SUDIRMAN', 'unit' => 'OLD', 'name' => 'SUDIRMAN']]],
            'requests' => [],
        ]];
    }

    public function test_import_preserves_password_hash_and_outlet_and_refuses_overwrite(): void
    {
        $fixture = $this->exportFixture();
        $file = tempnam(sys_get_temp_dir(), 'cuti-import-');
        try {
            file_put_contents($file, CutiStore::json($fixture));
            $this->artisan('cuti:import', ['file' => $file])->assertSuccessful();
            $this->assertSame($fixture['tables']['employees'][0]['password_hash'], DB::table('employees')->value('password_hash'));
            $this->assertSame('OLD-SUDIRMAN', app(CutiStore::class)->employee('old-admin')['outletId']);
            $this->artisan('cuti:import', ['file' => $file])->assertFailed();
            $this->assertDatabaseCount('employees', 1);
        } finally {
            unlink($file);
        }
    }

    public function test_invalid_import_rolls_back_partial_rows(): void
    {
        $fixture = $this->exportFixture();
        $fixture['tables']['employees'][0]['data']['unit'] = 'MISSING';
        $file = tempnam(sys_get_temp_dir(), 'cuti-import-');
        try {
            file_put_contents($file, CutiStore::json($fixture));
            $this->artisan('cuti:import', ['file' => $file])->assertFailed();
            $this->assertDatabaseCount('units', 0);
            $this->assertDatabaseCount('employees', 0);
        } finally {
            unlink($file);
        }
    }
}
