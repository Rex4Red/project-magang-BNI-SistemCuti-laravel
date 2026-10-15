<?php

namespace App\Domain;

final class ReplacementService
{
    public function __construct(private CutiStore $store) {}

    public static function overlaps(array $first, array $second): bool
    {
        return count(array_intersect($first, $second)) > 0;
    }

    public static function assignedDuring(array $leave, string $employee, ?string $today = null): bool
    {
        return $leave['status'] === 'APPROVED' && $leave['effectiveEnd'] >= ($today ?? LeaveRules::today()) && ($leave['replacement']['employeeId'] ?? null) === $employee;
    }

    public function check(array $leave, array $policy): array
    {
        $locations = $this->store->outlets($leave['unit']);
        $staff = $this->store->employees($leave['unit']);
        $all = $this->store->requests($leave['unit']);
        $employee = $this->store->employee($leave['employeeId']) ?? ['unit' => $leave['unit']];
        $outletId = $leave['outletId'] ?? CutiStore::outletId($employee);
        $rule = $policy['replacementRules'][$leave['position']] ?? null;
        $candidates = [];
        if ($rule['enabled'] ?? false) {
            foreach ($staff as $person) {
                if ($person['id'] === $leave['employeeId'] || ! $person['active'] || ! in_array('EMPLOYEE', $person['roles'], true) || ! in_array($person['position'], $rule['sourcePositions'], true)) {
                    continue;
                }
                $location = collect($locations)->firstWhere('id', CutiStore::outletId($person));
                if (! $location) {
                    continue;
                }
                $same = $location['id'] === $outletId;
                if ($same ? ! $rule['sameOutlet'] : ($rule['otherOutlets'] === 'NONE' || ($rule['otherOutlets'] === 'SELECTED' && ! in_array($location['id'], $rule['outletIds'], true)))) {
                    continue;
                }
                $own = collect($all)->contains(fn ($r) => $r['employeeId'] === $person['id'] && in_array($r['status'], LeaveRules::ACTIVE, true) && self::overlaps($r['days'], $leave['days']));
                $assignment = collect($all)->contains(fn ($r) => $r['id'] !== $leave['id'] && $r['status'] === 'APPROVED' && ($r['replacement']['employeeId'] ?? null) === $person['id'] && self::overlaps($r['days'], $leave['days']));
                $candidates[] = ['id' => $person['id'], 'name' => $person['name'], 'phone' => $person['phone'], 'email' => $person['email'], 'position' => $person['position'], 'positionName' => $person['positionName'] ?? $person['position'], 'outletId' => $location['id'], 'outletName' => $location['name'], 'available' => ! $own && ! $assignment, 'reason' => $own ? 'Sedang cuti atau menunggu review' : ($assignment ? 'Sudah bertugas sebagai PGS' : 'Tersedia selama seluruh tanggal cuti')];
            }
        }
        usort($candidates, fn ($a, $b) => ($b['available'] <=> $a['available']) ?: (($b['outletId'] === $outletId) <=> ($a['outletId'] === $outletId)) ?: strcasecmp($a['name'], $b['name']));

        return ['rule' => $rule, 'outletId' => $outletId, 'outletName' => $leave['outletName'] ?? collect($locations)->firstWhere('id', $outletId)['name'] ?? 'Outlet asal tidak tersedia', 'candidates' => $candidates];
    }
}
