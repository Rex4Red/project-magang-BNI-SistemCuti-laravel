<?php

namespace App\Domain;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class AdminService
{
    public function __construct(private CutiStore $store) {}

    public function data(array $actor): array
    {
        $policy = $this->store->policy($actor['unit']);
        $staff = $this->store->employees($actor['unit']);
        $logs = DB::table('audit')->where('unit_id', $actor['unit'])->orderByDesc('at')->limit(100)->get()->map(function ($row) use ($staff) {
            $log = (array) $row;
            $log['actor_name'] = collect($staff)->firstWhere('id', $row->actor_id)['name'] ?? $row->actor_id;

            return $log;
        })->all();

        return ['employees' => $staff, 'policy' => $policy, 'positions' => LeaveRules::positions($policy), 'outlets' => $this->store->outlets($actor['unit']), 'jobs' => DB::table('mail_jobs')->where('unit_id', $actor['unit'])->orderByDesc('created_at')->limit(100)->get(), 'audit' => $logs];
    }

    public function execute(array $actor, string $action, array $body, string $key): mixed
    {
        return $this->store->mutate($actor, $key, ['action' => $action, 'body' => $body], fn () => match ($action) {
            'employee' => $this->employee($actor, $body),
            'deleteEmployee' => $this->deleteEmployee($actor, $body['id']),
            'outlets' => $this->outlet($actor, $body),
            'deleteOutlet' => $this->deleteOutlet($actor, $body['id']),
            'positions' => $this->position($actor, $body),
            'deletePosition' => $this->deletePosition($actor, $body['id']),
            'replacement-rules' => $this->replacementRule($actor, $body),
            'calendar' => $this->calendar($actor, $body),
            'sync-2027' => $this->calendar($actor, [], true),
            'quota' => $this->quota($actor, $body),
            'retry' => $this->retry($actor, $body['id']),
            default => throw new CutiError('Aksi tidak ditemukan.', 404),
        });
    }

    private function employee(array $actor, array $body): array
    {
        $body = Validator::make($body, ['id' => 'sometimes|string|max:64', 'name' => 'required|string|min:2|max:100', 'email' => 'required|email:rfc|max:200', 'phone' => ['required', 'string', 'regex:/^\+?[\d ()-]{8,20}$/'], 'position' => 'required|string|max:40', 'outletId' => 'sometimes|string|max:64', 'roles' => 'required|array|min:1|max:3', 'roles.*' => 'required|in:EMPLOYEE,SDM,ADMIN|distinct', 'active' => 'required|boolean', 'password' => ['sometimes', 'string', 'max:72', 'regex:'.LeaveRules::PASSWORD_PATTERN]])->validate();
        $existing = ! empty($body['id']) ? $this->store->employee($body['id']) : null;
        if (! empty($body['id'])) {
            CutiError::check($existing && empty($existing['deletedAt']) && $existing['unit'] === $actor['unit'], 'Pegawai tidak ditemukan.', 404);
        } else {
            CutiError::check(! empty($body['password']), 'Kata sandi awal wajib diisi.');
        }
        if (($body['id'] ?? '') === $actor['id']) {
            CutiError::check($body['active'] && in_array('ADMIN', $body['roles'], true), 'Anda tidak dapat menonaktifkan akses admin sendiri.');
        }
        $isSdm = in_array('SDM', $body['roles'], true);
        if ($isSdm) {
            $body['position'] = 'SDM';
        }
        CutiError::check($isSdm || $body['position'] !== 'SDM', 'Posisi SDM hanya untuk peran SDM.');
        $position = $body['position'] === 'SDM' ? ['SDM', 'SDM', 0] : collect(LeaveRules::positions($this->store->policy($actor['unit'])))->first(fn ($p) => $p[0] === $body['position']);
        CutiError::check($position, 'Posisi tidak tersedia.');
        $outlet = $body['outletId'] ?? CutiStore::outletId($existing ?? $actor);
        CutiError::check(collect($this->store->outlets($actor['unit']))->contains('id', $outlet), 'Outlet tidak tersedia dalam cabang ini.');
        if ($existing && ($existing['position'] !== $body['position'] || CutiStore::outletId($existing) !== $outlet || ! $body['active'] || ! in_array('EMPLOYEE', $body['roles'], true))) {
            $this->checkUnassigned($existing);
        }
        $id = $existing['id'] ?? (string) Str::uuid();
        $password = $body['password'] ?? null;
        unset($body['password'], $body['id']);
        $employee = [...$body, 'id' => $id, 'unit' => $actor['unit'], 'email' => strtolower($body['email']), 'outletId' => $outlet, 'positionName' => $position[1]];
        $passwordHash = $password ? password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]) : DB::table('employees')->where('id', $id)->value('password_hash');
        CutiError::check(! DB::table('employees')->where('email', $employee['email'])->where('id', '!=', $id)->exists(), 'Email sudah terdaftar. Gunakan email lain.', 409);
        DB::table('employees')->updateOrInsert(['id' => $id], ['unit_id' => $actor['unit'], 'email' => $employee['email'], 'password_hash' => $passwordHash, 'data' => CutiStore::json($employee)]);
        if ($existing && ($password || $existing['roles'] !== $employee['roles'] || ! $employee['active'])) {
            DB::table('cuti_sessions')->where('employee_id', $id)->delete();
        }
        $this->store->audit($actor, 'EMPLOYEE_UPDATE', $id);

        return $employee;
    }

    private function checkUnassigned(array $employee): void
    {
        $all = $this->store->requests($employee['unit']);
        CutiError::check(! collect($all)->contains(fn ($r) => $r['employeeId'] === $employee['id'] && ($r['status'] === 'PENDING_SDM' || ($r['status'] === 'APPROVED' && $r['effectiveEnd'] >= LeaveRules::today()))), 'Selesaikan pengajuan yang menunggu review atau cuti aktif sebelum mengubah karyawan.', 409);
        CutiError::check(! collect($all)->contains(fn ($r) => ReplacementService::assignedDuring($r, $employee['id'])), 'Karyawan masih memiliki tugas PGS aktif.', 409);
    }

    private function deleteEmployee(array $actor, string $id): array
    {
        $employee = $this->store->employee($id);
        CutiError::check($employee && empty($employee['deletedAt']) && $employee['unit'] === $actor['unit'], 'Karyawan tidak ditemukan.', 404);
        CutiError::check($id !== $actor['id'], 'Anda tidak dapat menghapus akun sendiri.', 409);
        $this->checkUnassigned($employee);
        DB::table('employees')->where('id', $id)->update(['data' => CutiStore::json([...$employee, 'active' => false, 'deletedAt' => LeaveRules::timestamp()])]);
        DB::table('cuti_sessions')->where('employee_id', $id)->delete();
        $this->store->audit($actor, 'EMPLOYEE_DELETE', $id);

        return ['ok' => true];
    }

    private function outlet(array $actor, array $body): array
    {
        $body = Validator::make($body, ['id' => 'sometimes|string|max:64', 'name' => 'required|string|min:2|max:100'])->validate();
        $all = $this->store->outlets($actor['unit']);
        if (isset($body['id'])) {
            CutiError::check(collect($all)->contains('id', $body['id']), 'Outlet tidak ditemukan.', 404);
        }
        CutiError::check(! collect($all)->contains(fn ($o) => $o['id'] !== ($body['id'] ?? '') && mb_strtolower($o['name']) === mb_strtolower($body['name'])), 'Nama outlet sudah digunakan.', 409);
        $outlet = ['id' => $body['id'] ?? (string) Str::uuid(), 'unit' => $actor['unit'], 'name' => $body['name']];
        DB::table('outlets')->updateOrInsert(['id' => $outlet['id']], ['unit_id' => $actor['unit'], 'data' => CutiStore::json($outlet)]);
        $this->store->audit($actor, 'OUTLET_UPDATE', $outlet['id']);

        return $outlet;
    }

    private function deleteOutlet(array $actor, string $id): array
    {
        $outlet = collect($this->store->outlets($actor['unit']))->firstWhere('id', $id);
        CutiError::check($outlet, 'Outlet tidak ditemukan.', 404);
        CutiError::check(! collect($this->store->employees($actor['unit']))->contains(fn ($e) => CutiStore::outletId($e) === $id), 'Pindahkan semua karyawan dari outlet ini sebelum menghapusnya.', 409);
        $policy = $this->store->policy($actor['unit']);
        CutiError::check(! collect($policy['replacementRules'] ?? [])->contains(fn ($r) => in_array($id, $r['outletIds'], true)), 'Hapus outlet ini dari aturan PGS terlebih dahulu.', 409);
        CutiError::check(! collect($this->store->requests($actor['unit']))->contains(fn ($r) => (($r['outletId'] ?? '') === $id && ($r['status'] === 'PENDING_SDM' || ($r['status'] === 'APPROVED' && $r['effectiveEnd'] >= LeaveRules::today()))) || (($r['replacement']['outletId'] ?? '') === $id && ReplacementService::assignedDuring($r, $r['replacement']['employeeId']))), 'Outlet masih dipakai cuti atau tugas PGS aktif.', 409);
        DB::table('outlets')->where('id', $id)->update(['data' => CutiStore::json([...$outlet, 'deletedAt' => LeaveRules::timestamp()])]);
        $this->store->audit($actor, 'OUTLET_DELETE', $id);

        return ['ok' => true];
    }

    private function position(array $actor, array $body): array
    {
        $body = Validator::make($body, ['code' => ['required', 'regex:/^[A-Z][A-Z0-9_]{1,39}$/'], 'name' => 'required|string|min:2|max:60', 'quota' => 'required|integer|min:0|max:1000'])->validate();
        $policy = $this->store->policy($actor['unit']);
        $positions = LeaveRules::positions($policy);
        CutiError::check(! collect($positions)->contains(fn ($p) => $p[0] === $body['code'] || mb_strtolower($p[1]) === mb_strtolower($body['name'])), 'Kode atau nama posisi sudah digunakan.', 409);
        $policy['positions'] = [...$positions, [$body['code'], $body['name'], (int) $body['quota']]];
        $this->store->audit($actor, 'POSITION_ADD', $body['code']);

        return $this->store->savePolicy($actor['unit'], $policy);
    }

    private function deletePosition(array $actor, string $code): array
    {
        $policy = $this->store->policy($actor['unit']);
        CutiError::check(collect(LeaveRules::positions($policy))->contains(fn ($p) => $p[0] === $code), 'Posisi tidak ditemukan.', 404);
        CutiError::check(! collect($this->store->employees($actor['unit']))->contains('position', $code), 'Pindahkan semua karyawan dari posisi ini sebelum menghapusnya.', 409);
        CutiError::check(! collect($policy['replacementRules'] ?? [])->contains(fn ($rule, $target) => $target !== $code && in_array($code, $rule['sourcePositions'], true)), 'Hapus posisi ini dari calon PGS pada aturan lain terlebih dahulu.', 409);
        CutiError::check(! collect($this->store->requests($actor['unit']))->contains(fn ($r) => $r['position'] === $code && ($r['status'] === 'PENDING_SDM' || ($r['status'] === 'APPROVED' && $r['effectiveEnd'] >= LeaveRules::today()))), 'Posisi masih memiliki cuti aktif.', 409);
        $policy['positions'] = array_values(array_filter(LeaveRules::positions($policy), fn ($p) => $p[0] !== $code));
        unset($policy['replacementRules'][$code]);
        $this->store->savePolicy($actor['unit'], $policy);
        $this->store->audit($actor, 'POSITION_DELETE', $code);

        return ['ok' => true];
    }

    private function replacementRule(array $actor, array $body): array
    {
        $rule = Validator::make($body, ['position' => 'required|string|max:40', 'enabled' => 'required|boolean', 'sourcePositions' => 'present|array|max:100', 'sourcePositions.*' => 'string|max:40', 'sameOutlet' => 'required|boolean', 'otherOutlets' => 'required|in:NONE,ALL,SELECTED', 'outletIds' => 'present|array|max:100', 'outletIds.*' => 'string|max:64'])->validate();
        $policy = $this->store->policy($actor['unit']);
        $codes = array_column(LeaveRules::positions($policy), 0);
        CutiError::check(in_array($rule['position'], $codes, true) && ! array_diff($rule['sourcePositions'], $codes), 'Posisi tidak tersedia.');
        CutiError::check(! array_diff($rule['outletIds'], array_column($this->store->outlets($actor['unit']), 'id')), 'Outlet tidak tersedia dalam cabang ini.');
        if ($rule['enabled']) {
            CutiError::check(count($rule['sourcePositions']) > 0, 'Pilih minimal satu posisi pengganti.');
            CutiError::check($rule['sameOutlet'] || $rule['otherOutlets'] !== 'NONE', 'Pilih sumber outlet pengganti.');
            CutiError::check($rule['otherOutlets'] !== 'SELECTED' || count($rule['outletIds']) > 0, 'Pilih minimal satu outlet lain.');
        }
        $rule['sourcePositions'] = array_values(array_unique($rule['sourcePositions']));
        $rule['outletIds'] = $rule['otherOutlets'] === 'SELECTED' ? array_values(array_unique($rule['outletIds'])) : [];
        $policy['replacementRules'][$rule['position']] = $rule;
        $this->store->audit($actor, 'REPLACEMENT_RULE_UPDATE', $rule['position']);

        return $this->store->savePolicy($actor['unit'], $policy);
    }

    private function calendar(array $actor, array $body, bool $sync = false): array
    {
        $policy = $this->store->policy($actor['unit']);
        if ($sync) {
            foreach (LeaveRules::defaults()['calendar']['exceptions'] as $date => $holiday) {
                $policy['calendar']['exceptions'][$date] = $holiday;
            }
            $period = '2027-';
        } else {
            $body = Validator::make($body, ['date' => ['required', fn ($attribute, $value, $fail) => LeaveRules::validDate($value) ?: $fail('Tanggal tidak valid.')], 'working' => 'required|boolean', 'label' => 'required|string|min:2|max:150', 'remove' => 'sometimes|boolean'])->validate();
            CutiError::check($body['date'] >= LeaveRules::today(), 'Kalender historis tidak dapat diubah.');
            if ($body['remove'] ?? false) {
                unset($policy['calendar']['exceptions'][$body['date']]);
            } else {
                $policy['calendar']['exceptions'][$body['date']] = ['working' => $body['working'], 'label' => $body['label']];
            }
            $period = substr($body['date'], 0, 7);
        }
        $policy['calendar']['version']++;
        foreach ($this->store->requests($actor['unit']) as $leave) {
            if (in_array($leave['status'], LeaveRules::ACTIVE, true) && str_starts_with($leave['effectiveStart'], $period)) {
                $employee = $this->store->employee($leave['employeeId']);
                $result = LeaveRules::evaluate([...$leave, 'category' => 'EMERGENCY'], $employee, [], $policy['calendar'], 999, $leave['effectiveStart']);
                CutiError::check($result['days'] === $leave['days'], 'Perubahan kalender memengaruhi cuti aktif pada periode tersebut.', 409);
            }
        }
        $this->store->audit($actor, 'CALENDAR_UPDATE', $sync ? 'HOLIDAYS_2027_SYNC' : $body['date']);

        return $this->store->savePolicy($actor['unit'], $policy);
    }

    private function quota(array $actor, array $body): array
    {
        $body = Validator::make($body, ['month' => ['required', 'regex:/^20\d{2}-(0[1-9]|1[0-2])$/'], 'position' => 'required|string|max:40', 'limit' => 'required|integer|min:0|max:1000'])->validate();
        CutiError::check($body['month'] > substr(LeaveRules::today(), 0, 7), 'Kuota hanya dapat diubah untuk bulan mendatang.');
        CutiError::check(! collect($this->store->requests($actor['unit']))->contains(fn ($r) => $r['position'] === $body['position'] && str_starts_with($r['effectiveStart'], $body['month']) && $r['status'] !== 'DRAFT'), 'Kuota bulan ini sudah memiliki alokasi dan dibekukan.', 409);
        $policy = $this->store->policy($actor['unit']);
        CutiError::check(collect(LeaveRules::positions($policy))->contains(fn ($p) => $p[0] === $body['position']), 'Posisi tidak tersedia.');
        $defaults = [];
        foreach (LeaveRules::positions($policy) as [$code]) {
            $defaults[$code] = LeaveRules::limitFor($code, $body['month'], $policy, []);
        }
        $policy['quotas'][$body['month']] = [...$defaults, ...($policy['quotas'][$body['month']] ?? []), $body['position'] => (int) $body['limit']];
        $this->store->audit($actor, 'QUOTA_UPDATE', $body['position'].':'.$body['month']);

        return $this->store->savePolicy($actor['unit'], $policy);
    }

    private function retry(array $actor, string $id): array
    {
        $updated = DB::table('mail_jobs')->where('id', $id)->where('unit_id', $actor['unit'])->where('state', 'FAILED')->update(['state' => 'QUEUED', 'attempts' => 0, 'due_at' => now('UTC'), 'lease_until' => null, 'last_error' => null]);
        CutiError::check($updated === 1, 'Notifikasi gagal tidak ditemukan.', 404);
        $this->store->audit($actor, 'MAIL_RETRY', $id);

        return ['ok' => true];
    }
}
