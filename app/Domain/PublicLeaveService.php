<?php

namespace App\Domain;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class PublicLeaveService
{
    public function __construct(private CutiStore $store, private LeaveService $leaves) {}

    public function configuration(): array
    {
        $units = DB::table('units')->get()->map(fn ($unit) => [
            'id' => $unit->id, 'name' => $unit->name,
            'positions' => LeaveRules::positions(CutiStore::decode($unit->policy)),
            'calendar' => CutiStore::decode($unit->policy)['calendar'],
            'outlets' => array_map(fn ($outlet) => ['id' => $outlet['id'], 'name' => $outlet['name']], $this->store->outlets($unit->id)),
        ])->all();

        return ['today' => LeaveRules::today(), 'units' => $units];
    }

    public function preview(array $body): array
    {
        $identity = $this->identity($body);
        $actor = $this->applicant($identity);
        $input = LeaveService::input($body, $actor);
        $preview = $this->leaves->preview($actor, $input);
        $preview['fingerprint'] = $this->fingerprint($identity, $preview);

        return $preview;
    }

    public function submit(array $body, string $key): array
    {
        $identity = $this->identity($body);

        return DB::transaction(function () use ($identity, $body, $key) {
            $outlet = DB::table('outlets')->where('id', $identity['outletId'])->first();
            CutiError::check($outlet, 'Outlet tidak tersedia.');
            DB::table('units')->where('id', $outlet->unit_id)->lockForUpdate()->first();
            $actor = $this->applicant($identity);
            $input = LeaveService::input($body, $actor);
            $preview = $this->leaves->preview($actor, $input);
            CutiError::check(is_string($body['fingerprint'] ?? null) && hash_equals($this->fingerprint($identity, $preview), $body['fingerprint']), 'Data atau perhitungan berubah. Periksa formulir lalu kirim kembali.', 409, 'PREVIEW_CHANGED');
            if (! $this->store->employee($actor['id'])) {
                DB::table('employees')->insert([
                    'id' => $actor['id'], 'unit_id' => $actor['unit'], 'email' => $actor['email'],
                    'password_hash' => password_hash(Str::random(64), PASSWORD_BCRYPT), 'data' => CutiStore::json($actor),
                ]);
            }
            $leave = $this->leaves->submit($actor, [...$input, 'fingerprint' => $preview['fingerprint'], 'acceptAdjustment' => ($body['acceptAdjustment'] ?? false) === true], $key);

            return [
                'number' => $leave['number'],
                'status' => $leave['status'],
                'effectiveStart' => $leave['effectiveStart'],
                'effectiveEnd' => $leave['effectiveEnd'],
                'duration' => $leave['duration'],
            ];
        }, 3);
    }

    private function identity(array $body): array
    {
        $body['npp'] = is_string($body['npp'] ?? null) ? strtoupper(trim($body['npp'])) : ($body['npp'] ?? null);
        $identity = Validator::make($body, [
            'name' => 'required|string|min:2|max:100', 'npp' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9-]+$/'],
            'position' => 'required|string|max:40', 'outletId' => 'required|string|max:64',
        ])->validate();
        $identity['name'] = preg_replace('/\s+/u', ' ', trim($identity['name']));

        return $identity;
    }

    private function applicant(array $identity): array
    {
        $row = DB::table('outlets')->where('id', $identity['outletId'])->first();
        $outlet = $row ? CutiStore::decode($row->data) : null;
        CutiError::check($outlet && empty($outlet['deletedAt']), 'Outlet tidak tersedia. Pilih ulang outlet.');
        $policy = $this->store->policy($row->unit_id);
        $position = collect(LeaveRules::positions($policy))->first(fn ($item) => $item[0] === $identity['position']);
        CutiError::check($position, 'Posisi tidak tersedia di cabang ini.');
        $existing = DB::table('employees')->where('unit_id', $row->unit_id)->get()->first(fn ($person) => strtoupper(CutiStore::decode($person->data)['npp'] ?? '') === $identity['npp']);
        if ($existing) {
            $employee = CutiStore::decode($existing->data);
            CutiError::check($employee['active'] && empty($employee['deletedAt']) && in_array('EMPLOYEE', $employee['roles'], true)
                && $employee['unit'] === $row->unit_id && $employee['position'] === $identity['position']
                && CutiStore::outletId($employee) === $identity['outletId']
                && mb_strtolower(preg_replace('/\s+/u', ' ', trim($employee['name']))) === mb_strtolower($identity['name']),
                'Identitas tidak sesuai dengan data yang tersimpan. Periksa nama, NPP, posisi, dan outlet atau hubungi admin.', 422, 'IDENTITY_MISMATCH');

            return $employee;
        }

        $identityHash = substr(hash('sha256', $row->unit_id.'|'.$identity['npp']), 0, 40);

        return [...$identity, 'id' => 'public-'.$identityHash, 'email' => 'npp-'.$identityHash.'@cuti.invalid', 'phone' => '', 'unit' => $row->unit_id,
            'positionName' => $position[1], 'roles' => ['EMPLOYEE'], 'active' => true, 'publicApplicant' => true];
    }

    private function fingerprint(array $identity, array $preview): string
    {
        return CutiStore::digest(['identity' => $identity, 'preview' => $preview['fingerprint']]);
    }
}
