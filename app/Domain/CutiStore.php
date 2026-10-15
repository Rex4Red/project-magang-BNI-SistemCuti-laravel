<?php

namespace App\Domain;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CutiStore
{
    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function decode(string $value): array
    {
        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', self::json($value));
    }

    public function employees(?string $unit = null): array
    {
        $query = DB::table('employees');
        if ($unit) {
            $query->where('unit_id', $unit);
        }

        return $query->get()->map(fn ($row) => self::decode($row->data))->filter(fn ($employee) => empty($employee['deletedAt']))->values()->all();
    }

    public function employee(string $id): ?array
    {
        $row = DB::table('employees')->where('id', $id)->first();

        return $row ? self::decode($row->data) : null;
    }

    public function requests(?string $unit = null): array
    {
        $query = DB::table('requests');
        if ($unit) {
            $query->where('unit_id', $unit);
        }

        return $query->get()->map(fn ($row) => self::decode($row->data))->all();
    }

    public function policy(string $unit): array
    {
        $row = DB::table('units')->where('id', $unit)->first();
        CutiError::check($row, 'Unit tidak tersedia.', 404);

        return self::decode($row->policy);
    }

    public function savePolicy(string $unit, array $policy): array
    {
        DB::table('units')->where('id', $unit)->update(['policy' => self::json($policy)]);

        return $policy;
    }

    public function saveRequest(array $leave): array
    {
        DB::table('requests')->updateOrInsert(['id' => $leave['id']], [
            'employee_id' => $leave['employeeId'], 'unit_id' => $leave['unit'],
            'position' => $leave['position'], 'status' => $leave['status'],
            'month' => substr($leave['effectiveStart'], 0, 7), 'data' => self::json($leave),
        ]);

        return $leave;
    }

    public function audit(array $actor, string $action, string $object): void
    {
        DB::table('audit')->insert(['id' => (string) Str::uuid(), 'unit_id' => $actor['unit'], 'actor_id' => $actor['id'], 'action' => $action, 'object_id' => $object, 'at' => now('UTC')]);
    }

    public static function outletId(array $employee): string
    {
        return $employee['outletId'] ?? $employee['unit'].'-MAIN';
    }

    public function outlets(string $unit): array
    {
        $items = DB::table('outlets')->where('unit_id', $unit)->get()->map(fn ($row) => self::decode($row->data))->filter(fn ($outlet) => empty($outlet['deletedAt']))->values()->all();
        usort($items, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $items;
    }

    public function mutate(array $actor, string $key, array $payload, Closure $action): mixed
    {
        CutiError::check(strlen($key) >= 8 && strlen($key) <= 150, 'Identitas pengiriman tidak valid.');

        return DB::transaction(function () use ($actor, $key, $payload, $action) {
            // One unit lock makes quota, PGS checks and idempotency atomic together.
            DB::table('units')->where('id', $actor['unit'])->lockForUpdate()->first();
            $current = $this->employee($actor['id']);
            CutiError::check($current && $current['active'] && empty($current['deletedAt']) && $current['unit'] === $actor['unit'] && $current['roles'] === $actor['roles'] && $current['position'] === $actor['position'] && self::outletId($current) === self::outletId($actor), 'Profil berubah. Masuk kembali.', 409);
            $existing = DB::table('operations')->where('actor_id', $actor['id'])->where('key', $key)->first();
            if ($existing) {
                CutiError::check(hash_equals($existing->hash, self::digest($payload)), 'Identitas pengiriman digunakan untuk data berbeda.', 409);

                return json_decode($existing->result, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $action();
            DB::table('operations')->insert(['actor_id' => $actor['id'], 'key' => $key, 'hash' => self::digest($payload), 'result' => self::json($result)]);

            return $result;
        }, 3);
    }

    public static function canRead(array $actor, array $leave): bool
    {
        return $actor['unit'] === $leave['unit'] && ($actor['id'] === $leave['employeeId'] || $actor['id'] === ($leave['replacement']['employeeId'] ?? null) || in_array('SDM', $actor['roles'], true));
    }

    public function findLeave(array $actor, string $id): array
    {
        $row = DB::table('requests')->where('id', $id)->where('unit_id', $actor['unit'])->first();
        $leave = $row ? self::decode($row->data) : null;
        CutiError::check($leave && self::canRead($actor, $leave), 'Pengajuan tidak ditemukan.', 404);
        if (isset($leave['replacement']) && empty($leave['replacement']['phone'])) {
            $employee = $this->employee($leave['replacement']['employeeId']);
            $leave['replacement']['phone'] = $employee['phone'] ?? '';
            $leave['replacement']['email'] = $leave['replacement']['email'] ?? $employee['email'] ?? '';
        }

        return $leave;
    }

    public function queueMail(array $leave, string $kind, string $due): void
    {
        $event = $leave['id'].':'.$kind;
        if ($kind === 'DECISION') {
            $event .= ':'.$leave['version'];
        }
        DB::table('mail_jobs')->insertOrIgnore(['id' => (string) Str::uuid(), 'event_key' => $event, 'unit_id' => $leave['unit'], 'request_id' => $leave['id'], 'kind' => $kind, 'due_at' => CarbonImmutable::parse($due)->utc(), 'state' => 'QUEUED', 'attempts' => 0, 'created_at' => now('UTC')]);
    }
}
