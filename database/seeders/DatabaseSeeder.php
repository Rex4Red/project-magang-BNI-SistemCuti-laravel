<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $parameters = config('cuti.demo') ? ['--demo' => true] : [];
        $result = Artisan::call('cuti:setup', $parameters);
        if ($result !== 0) {
            throw new \RuntimeException('Konfigurasi inisialisasi cuti belum lengkap.');
        }
    }
}
