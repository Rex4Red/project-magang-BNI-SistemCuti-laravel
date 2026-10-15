<?php

namespace App\Domain;

use Illuminate\Support\Facades\DB;

class DemoAccounts
{
    private const PEOPLE = [
        ['sdm', 'Nadia Putri', 'sdm@demo.bni.local', 'SDM', ['SDM', 'EMPLOYEE']],
        ['employee', 'Alya Rahma', 'karyawan@demo.bni.local', 'CS_BINA', ['EMPLOYEE']],
        ['admin', 'Administrator', 'admin@demo.bni.local', 'BBO', ['ADMIN']],
    ];

    public function recoverForLogin(string $email, string $password): void
    {
        if (hash_equals('BniCuti!2026', $password)) {
            $this->recover(strtolower($email));
        }
    }

    public function recover(?string $email = null): int
    {
        if (! config('cuti.demo') || app()->environment('production')) {
            return 0;
        }

        return DB::transaction(function () use ($email): int {
            $unit = DB::table('units')->where('id', 'KC01')->lockForUpdate()->first();
            $outlets = DB::table('outlets')->where('unit_id', 'KC01')->pluck('id')->all();
            if (! $unit || $outlets === []) {
                return 0;
            }
            $restored = 0;
            $hash = null;
            foreach (self::PEOPLE as [$id, $name, $address, $position, $roles]) {
                if ($email !== null && $email !== $address) {
                    continue;
                }
                $row = DB::table('employees')->where('email', $address)->lockForUpdate()->first()
                    ?? DB::table('employees')->where('id', $id)->lockForUpdate()->first();
                if ($row && ($row->email !== $address || $row->unit_id !== 'KC01')) {
                    continue;
                }
                $existing = $row ? CutiStore::decode($row->data) : [];
                if (($existing['active'] ?? false) && empty($existing['deletedAt'])) {
                    continue;
                }
                $id = $row->id ?? $id;
                $outlet = in_array($existing['outletId'] ?? '', $outlets, true) ? $existing['outletId'] : $outlets[0];
                $employee = [...$existing, 'id' => $id, 'name' => $name, 'email' => $address, 'phone' => $existing['phone'] ?? '081234567890', 'position' => $position, 'positionName' => $position === 'CS_BINA' ? 'CS BINA' : $position, 'unit' => 'KC01', 'outletId' => $outlet, 'roles' => $roles, 'active' => true];
                unset($employee['deletedAt']);
                $hash ??= password_hash('BniCuti!2026', PASSWORD_BCRYPT, ['cost' => 12]);
                DB::table('employees')->updateOrInsert(['id' => $id], ['email' => $address, 'unit_id' => 'KC01', 'password_hash' => $hash, 'data' => CutiStore::json($employee)]);
                DB::table('cuti_sessions')->where('employee_id', $id)->delete();
                $restored++;
            }

            return $restored;
        });
    }
}
