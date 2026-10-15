<?php

namespace Tests\Unit;

use App\Domain\LeaveRules;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class LeaveRulesTest extends TestCase
{
    private function employee(string $id = 'a'): array
    {
        return ['id' => $id, 'unit' => 'KC01', 'position' => 'CS_BINA'];
    }

    private function input(array $override = []): array
    {
        return [...['category' => 'REGULAR', 'start' => '2026-10-05', 'end' => '2026-10-09'], ...$override];
    }

    private function leave(string $id, array $days, string $status = 'PENDING_SDM'): array
    {
        return ['id' => $id, 'employeeId' => $id, 'unit' => 'KC01', 'position' => 'CS_BINA', 'status' => $status, 'days' => $days, 'effectiveStart' => $days[0], 'limit' => 2];
    }

    public function test_cutoff_uses_last_three_working_days_and_truncates_request(): void
    {
        $calendar = LeaveRules::defaults()['calendar'];
        $this->assertSame(['2026-10-28', '2026-10-29', '2026-10-30'], LeaveRules::blockedDays('2026-10', $calendar));
        $result = LeaveRules::evaluate($this->input(['start' => '2026-10-26', 'end' => '2026-11-03']), $this->employee(), [], $calendar, 2, '2026-09-01');
        $this->assertSame(['2026-10-26', '2026-10-27'], $result['days']);
        $this->assertTrue($result['adjusted']);
        $calendar['exceptions']['2026-10-30'] = ['working' => false, 'label' => 'Libur uji'];
        $this->assertSame(['2026-10-27', '2026-10-28', '2026-10-29'], LeaveRules::blockedDays('2026-10', $calendar));
    }

    public function test_regular_notice_clamps_month_and_emergency_only_skips_notice(): void
    {
        $this->assertSame('2028-02-29', LeaveRules::addMonths('2028-01-31', 1));
        $this->assertSame('2027-02-28', LeaveRules::addMonths('2027-01-31', 1));
        $calendar = LeaveRules::defaults()['calendar'];
        $emergency = $this->input(['category' => 'EMERGENCY', 'start' => '2026-10-01', 'end' => '2026-10-01']);
        $this->assertSame([], LeaveRules::evaluate($emergency, $this->employee(), [], $calendar, 2, '2026-10-01')['errors']);
        $blocked = LeaveRules::evaluate([...$emergency, 'start' => '2026-10-29', 'end' => '2026-10-29'], $this->employee(), [], $calendar, 2, '2026-10-01');
        $this->assertContains('MONTH_END_BLOCKED', array_column($blocked['errors'], 'code'));
    }

    public function test_quota_counts_unique_employees_not_days_and_releases_withdrawals(): void
    {
        $calendar = LeaveRules::defaults()['calendar'];
        $result = LeaveRules::evaluate($this->input(), $this->employee(), [], $calendar, 2, '2026-09-01');
        $this->assertSame(5, $result['duration']);
        $this->assertSame([], $result['errors']);
        $all = [$this->leave('b', ['2026-10-01']), $this->leave('b', ['2026-10-12']), $this->leave('c', ['2026-10-15'], 'WITHDRAWN')];
        $this->assertSame(1, LeaveRules::quotaFor('CS_BINA', '2026-10', $all, 2)['used']);
        $all[] = $this->leave('c', ['2026-10-15']);
        $full = LeaveRules::evaluate($this->input(), $this->employee(), $all, $calendar, 2, '2026-09-01');
        $this->assertContains('QUOTA_EXCEEDED', array_column($full['errors'], 'code'));
    }

    public function test_same_position_allows_only_two_overlap_days_and_rejects_identical_ranges(): void
    {
        $calendar = LeaveRules::defaults()['calendar'];
        $all = [$this->leave('b', ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'])];
        $identical = LeaveRules::evaluate($this->input(), $this->employee(), $all, $calendar, 4, '2026-09-01');
        $this->assertContains('IDENTICAL_RANGE', array_column($identical['errors'], 'code'));
        $two = LeaveRules::evaluate($this->input(['start' => '2026-10-08', 'end' => '2026-10-13']), $this->employee(), $all, $calendar, 4, '2026-09-01');
        $this->assertSame([], $two['errors']);
        $three = LeaveRules::evaluate($this->input(['start' => '2026-10-07', 'end' => '2026-10-13']), $this->employee(), $all, $calendar, 4, '2026-09-01');
        $this->assertContains('OVERLAP_LIMIT', array_column($three['errors'], 'code'));
    }

    public function test_reminders_never_schedule_in_the_past(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30T03:00:00Z'));
        try {
            $this->assertSame('2026-10-02T01:00:00.000Z', LeaveRules::reminderAt('2026-11-02', 'REGULAR'));
            $this->assertSame('2026-09-30T03:00:00.000Z', LeaveRules::reminderAt('2026-10-30', 'REGULAR'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
