<?php

namespace App\Console\Commands;

use App\Domain\CutiStore;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

#[Signature('cuti:import {file : Legacy JSON export path}')]
#[Description('Import legacy data into an empty database; existing data is never overwritten')]
class CutiImport extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_file($path) || filesize($path) > 100 * 1024 * 1024) {
            $this->error('File ekspor tidak ditemukan atau melebihi 100 MB.');

            return self::FAILURE;
        }
        if (DB::table('units')->exists()) {
            $this->error('Import hanya untuk database kosong. Pilih database baru untuk menjaga data yang ada.');

            return self::FAILURE;
        }
        try {
            $export = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            Validator::make($export, ['format' => 'required|in:bni-cuti-legacy-v1', 'tables' => 'required|array', 'tables.units' => 'required|array|min:1', 'tables.employees' => 'required|array|min:1', 'tables.outlets' => 'present|array', 'tables.requests' => 'present|array'])->validate();
            DB::transaction(function () use ($export) {
                $tables = $export['tables'];
                foreach ($tables['units'] as $row) {
                    DB::table('units')->insert(['id' => $row['id'], 'name' => $row['name'], 'policy' => CutiStore::json($row['policy'])]);
                }
                foreach ($tables['employees'] as $row) {
                    $employee = $row['data'];
                    $employee['outletId'] ??= $employee['unit'].'-MAIN';
                    DB::table('employees')->insert(['id' => $row['id'], 'unit_id' => $employee['unit'], 'email' => strtolower($row['email']), 'password_hash' => $row['password_hash'], 'data' => CutiStore::json($employee)]);
                }
                foreach ($tables['outlets'] as $row) {
                    DB::table('outlets')->insert(['id' => $row['id'], 'unit_id' => $row['unit_id'], 'data' => CutiStore::json($row['data'])]);
                }
                foreach ($tables['units'] as $row) {
                    $id = $row['id'].'-MAIN';
                    DB::table('outlets')->insertOrIgnore(['id' => $id, 'unit_id' => $row['id'], 'data' => CutiStore::json(['id' => $id, 'unit' => $row['id'], 'name' => preg_replace('/\s*[•·]\s*Demo$/u', '', $row['name'])])]);
                }
                foreach ($tables['requests'] as $row) {
                    app(CutiStore::class)->saveRequest($row['data']);
                }
                foreach ($tables['audit'] ?? [] as $row) {
                    DB::table('audit')->insert([...array_intersect_key($row, array_flip(['id', 'unit_id', 'actor_id', 'action', 'object_id'])), 'at' => CarbonImmutable::parse($row['at'])->utc()]);
                }
                foreach ($tables['mail_jobs'] ?? [] as $row) {
                    $fields = array_intersect_key($row, array_flip(['id', 'event_key', 'unit_id', 'request_id', 'kind', 'state', 'attempts', 'last_error']));
                    if ($fields['state'] === 'PROCESSING') {
                        $fields['state'] = 'QUEUED';
                    }
                    DB::table('mail_jobs')->insert([...$fields, 'due_at' => CarbonImmutable::parse($row['due_at'])->utc(), 'created_at' => CarbonImmutable::parse($row['created_at'])->utc(), 'lease_until' => null]);
                }
                foreach ($tables['mail_deliveries'] ?? [] as $row) {
                    DB::table('mail_deliveries')->insert(array_intersect_key($row, array_flip(['id', 'job_id', 'recipient', 'state', 'subject', 'body'])));
                }
            });
        } catch (Throwable $error) {
            report($error);
            $this->error('Import dibatalkan; tidak ada data tersimpan. Periksa struktur ekspor dan log aplikasi.');

            return self::FAILURE;
        }
        $this->info('Import selesai: akun, outlet, kebijakan, pengajuan, audit dan email. Sesi login lama tidak disalin.');

        return self::SUCCESS;
    }
}
