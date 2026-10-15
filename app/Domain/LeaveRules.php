<?php

namespace App\Domain;

use Carbon\CarbonImmutable;

final class LeaveRules
{
    public const ACTIVE = ['PENDING_SDM', 'APPROVED'];

    public const PASSWORD_PATTERN = '/^(?=.*[A-Za-z])(?=.*[0-9]).{6,72}$/s';

    public const PASSWORD_HINT = 'Minimal 6 karakter, kombinasi huruf dan angka. Simbol tidak wajib.';

    public static function defaults(): array
    {
        return json_decode(file_get_contents(base_path('database/data/cuti-defaults.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function positions(array $policy): array
    {
        return $policy['positions'] ?? self::defaults()['positions'];
    }

    public static function today(): string
    {
        return CarbonImmutable::now('Asia/Jakarta')->format('Y-m-d');
    }

    public static function timestamp(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function validDate(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || $value < '2020-01-01' || $value > '2100-12-31') {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }

    public static function addMonths(string $date, int $months): string
    {
        return CarbonImmutable::parse($date, 'UTC')->addMonthsNoOverflow($months)->format('Y-m-d');
    }

    public static function monthDays(string $month): array
    {
        $count = CarbonImmutable::parse($month.'-01', 'UTC')->daysInMonth;

        return array_map(fn ($day) => $month.'-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT), range(1, $count));
    }

    public static function isWorking(string $date, array $calendar): bool
    {
        return $calendar['exceptions'][$date]['working'] ?? in_array(CarbonImmutable::parse($date, 'UTC')->dayOfWeek, $calendar['weekdays'], true);
    }

    public static function blockedDays(string $month, array $calendar): array
    {
        return array_slice(array_values(array_filter(self::monthDays($month), fn ($day) => self::isWorking($day, $calendar))), -3);
    }

    public static function quotaFor(string $position, string $month, array $requests, int $limit, string $employeeId = ''): array
    {
        $relevant = array_filter($requests, fn ($leave) => $leave['position'] === $position && str_starts_with($leave['effectiveStart'], $month) && in_array($leave['status'], self::ACTIVE, true));
        $all = array_unique(array_column($relevant, 'employeeId'));
        $approved = array_unique(array_column(array_filter($relevant, fn ($leave) => $leave['status'] === 'APPROVED'), 'employeeId'));

        return ['position' => $position, 'label' => $position, 'limit' => $limit, 'used' => count($all), 'approved' => count($approved), 'pending' => count($all) - count($approved), 'available' => max(0, $limit - count($all)), 'alreadyCounted' => in_array($employeeId, $all, true)];
    }

    public static function limitFor(string $position, string $month, array $policy, array $requests): int
    {
        foreach ($requests as $leave) {
            if ($leave['position'] === $position && str_starts_with($leave['effectiveStart'], $month) && $leave['status'] !== 'DRAFT') {
                return $leave['limit'];
            }
        }
        $periods = array_filter(array_keys($policy['quotas'] ?? []), fn ($period) => $period <= $month);
        sort($periods);
        $effective = end($periods);
        if ($effective && isset($policy['quotas'][$effective][$position])) {
            return $policy['quotas'][$effective][$position];
        }
        foreach (self::positions($policy) as [$code, $label, $limit]) {
            if ($code === $position) {
                return $limit;
            }
        }

        return 2;
    }

    public static function evaluate(array $input, array $employee, array $requests, array $calendar, int $limit, string $today): array
    {
        $errors = [];
        $fail = function (string $code, string $message) use (&$errors): void {
            if (! isset($errors[$code])) {
                $errors[$code] = ['code' => $code, 'message' => $message];
            }
        };
        $minimum = $input['category'] === 'REGULAR' ? self::addMonths($today, 1) : $today;
        $local = array_values(array_filter($requests, fn ($leave) => $leave['unit'] === $employee['unit']));
        $result = ['days' => [], 'effectiveStart' => '', 'effectiveEnd' => '', 'duration' => 0, 'minimum' => $minimum, 'blocked' => [], 'adjusted' => false, 'errors' => [], 'quota' => self::quotaFor($employee['position'], substr($input['start'], 0, 7), $local, $limit, $employee['id'])];
        if (! self::validDate($input['start']) || ! self::validDate($input['end']) || $input['end'] < $input['start']) {
            $fail('INVALID_RANGE', 'Pilih rentang tanggal yang valid.');
        } elseif ($input['end'] > self::addMonths($input['start'], 3)) {
            $fail('RANGE_TOO_LONG', 'Pilih rentang paling panjang tiga bulan.');
        } else {
            if ($input['start'] < $minimum) {
                $fail('NOTICE_PERIOD', 'Tanggal mulai paling awal '.$minimum.'.');
            }
            $result['blocked'] = self::blockedDays(substr($input['start'], 0, 7), $calendar);
            $cutoff = $result['blocked'][0] ?? null;
            if (! $cutoff) {
                $fail('NO_WORKDAYS', 'Kalender bulan ini tidak memiliki hari kerja.');
            } else {
                if (! self::isWorking($input['start'], $calendar)) {
                    $fail('NON_WORKING_DAY', 'Tanggal mulai harus merupakan hari kerja.');
                }
                if ($input['start'] >= $cutoff) {
                    $fail('MONTH_END_BLOCKED', 'Tiga hari kerja terakhir bulan tidak dapat digunakan untuk cuti.');
                }
                $result['days'] = array_values(array_filter(self::monthDays(substr($input['start'], 0, 7)), fn ($day) => $day >= $input['start'] && $day <= $input['end'] && $day < $cutoff && self::isWorking($day, $calendar)));
                $result['duration'] = count($result['days']);
                $result['effectiveStart'] = $result['days'][0] ?? '';
                $result['effectiveEnd'] = $result['days'][array_key_last($result['days'])] ?? '';
                $result['adjusted'] = $result['effectiveEnd'] !== '' && $result['effectiveEnd'] !== $input['end'];
                if ($result['duration'] === 0) {
                    $fail('NO_DAYS', 'Tidak ada hari kerja yang dapat diajukan.');
                }
                if ($result['duration'] > 5) {
                    $fail('DURATION_LIMIT', 'Maksimal 5 hari kerja per pengajuan.');
                }
                if (! $result['quota']['alreadyCounted'] && $result['quota']['available'] < 1) {
                    $fail('QUOTA_EXCEEDED', 'Kuota orang pada posisi Anda untuk bulan ini sudah penuh.');
                }
                foreach ($requests as $leave) {
                    if (! in_array($leave['status'], self::ACTIVE, true)) {
                        continue;
                    }
                    $overlap = count(array_intersect($result['days'], $leave['days']));
                    if ($leave['employeeId'] === $employee['id'] && $overlap > 0) {
                        $fail('OWN_CONFLICT', 'Tanggal bertabrakan dengan pengajuan aktif Anda.');
                    } elseif ($leave['unit'] === $employee['unit'] && $leave['position'] === $employee['position']) {
                        if (count($result['days']) > 0 && $result['days'] === $leave['days']) {
                            $fail('IDENTICAL_RANGE', 'Rentang efektif yang sama sudah diajukan pada posisi Anda.');
                        } elseif ($overlap > 2) {
                            $fail('OVERLAP_LIMIT', 'Irisan '.$overlap.' hari kerja melebihi batas 2 hari.');
                        }
                    }
                }
                foreach ($result['days'] as $day) {
                    $people = [$employee['id']];
                    foreach ($local as $leave) {
                        if ($leave['position'] === $employee['position'] && in_array($leave['status'], self::ACTIVE, true) && in_array($day, $leave['days'], true)) {
                            $people[] = $leave['employeeId'];
                        }
                    }
                    if (count(array_unique($people)) > $limit) {
                        $fail('CONCURRENT_PEOPLE_LIMIT', 'Jumlah orang yang cuti bersamaan melebihi kuota posisi.');
                        break;
                    }
                }
            }
        }
        $result['errors'] = array_values($errors);

        return $result;
    }

    public static function reminderAt(string $start, string $category): string
    {
        $now = CarbonImmutable::now('UTC');
        $due = $category === 'EMERGENCY' ? $now : CarbonImmutable::parse(self::addMonths($start, -1).' 08:00:00', 'Asia/Jakarta')->utc();

        return ($due->greaterThan($now) ? $due : $now)->format('Y-m-d\TH:i:s.v\Z');
    }
}
