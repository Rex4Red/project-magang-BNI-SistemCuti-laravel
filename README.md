# BNI Sistem Cuti · Laravel

Versi terpisah dari project Node/Express pada commit 44d1fe7afa0ccfd68736a381813ca45c00b7c694. Backend memakai Laravel 13 dan MySQL/MariaDB. Tampilan React yang sudah ada dipertahankan.

Pembaruan Node disinkronkan sampai commit `9ce4e4e`: laporan PDF A4 portrait, waktu pengajuan lengkap dalam WIB, pengurutan antrean, penanganan gangguan frontend, dan pemulihan akun demo khusus lingkungan nonproduksi.

Node.js hanya diperlukan saat membangun tampilan atau menjalankan pengujian frontend. Hosting menjalankan PHP; tidak perlu proses Node atau queue worker yang hidup terus.

## Kebutuhan

- PHP 8.3–8.5 dan Composer 2.
- Ekstensi Laravel: Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer, XML. Tambahkan PDO MySQL, GD, Zip, XMLReader, XMLWriter, SimpleXML untuk database dan laporan Excel.
- MySQL 8.0+ atau MariaDB 10.3+ dengan InnoDB.
- Node.js 22.12+ atau 24+ untuk build Vite. Pengembangan lokal menggunakan Node 24.
- Cron setiap menit untuk email.

## Menjalankan

~~~bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
~~~

Isi koneksi MySQL pada .env. Buat database kosong sesuai DB_DATABASE, lalu:

~~~bash
php artisan migrate
~~~

Pilih satu cara pengisian awal:

1. **Memindahkan data lama:** gunakan cuti:import seperti petunjuk di bawah. Jangan membuat data demo lebih dulu.
2. **Data uji:** atur APP_ENV=local, DEMO_MODE=true, lalu jalankan php artisan cuti:setup --demo.
3. **Cabang baru:** atur DEMO_MODE=false, BRANCH_NAME, BOOTSTRAP_EMAIL, BOOTSTRAP_PASSWORD, lalu jalankan php artisan cuti:setup.

Setup hanya mengisi database kosong. Database yang sudah memiliki cabang tidak ditimpa.

~~~bash
npm run build
php artisan serve --host=127.0.0.1 --port=3004
~~~

Buka http://127.0.0.1:3004. Saat mengedit tampilan, jalankan npm run dev di terminal kedua; Vite meneruskan /api ke Laravel port 3004.

Pada Windows, ganti cp dengan Copy-Item. Instalasi lokal hasil migrasi menyimpan PHP portable di .tools/php/php.exe dan Composer di .tools/composer.phar. Folder ini tidak masuk Git; komputer lain perlu menyediakan PHP dan Composer sendiri.

## Akun demonstrasi

Hanya tersedia setelah setup demo, atau jika ikut diimpor dari data lama.

| Peran | Email |
|---|---|
| Admin | admin@demo.bni.local |
| SDM | sdm@demo.bni.local |
| Karyawan | karyawan@demo.bni.local |

Kata sandi awal demo: BniCuti!2026. Password yang sudah diubah pada data lama tetap berlaku setelah import.

## Memindahkan database versi Node

Hentikan aplikasi Node sebelum membuka database PGlite-nya. Folder Node beserta node_modules tetap diperlukan untuk exporter. Exporter menggunakan transaksi baca saja, tidak menjalankan seeder atau mengubah kebijakan.

Jalankan dari folder Laravel:

~~~powershell
node scripts/export-legacy.mjs "E:/CODEX/PROJECT BNI SISTEM CUTI" ".runtime/legacy-export.json"
php artisan migrate
php artisan cuti:import .runtime/legacy-export.json
~~~

Untuk PostgreSQL eksternal, set LEGACY_DATABASE_URL sebelum menjalankan exporter. Database tujuan harus kosong dan berbeda dari sumber. Import bersifat atomik: kegagalan membatalkan seluruh data yang sedang dimasukkan.

Yang dipertahankan: ID akun dan pengajuan, hash password, outlet, posisi, kuota, kalender, aturan PGS, status, riwayat, audit, dan antrean email. Sesi login dan cache pengiriman ulang HTTP tidak disalin; pengguna masuk kembali.

File ekspor berisi data karyawan dan hash password. Simpan secara privat. Folder .runtime, .env, database lokal dan kredensial tidak masuk Git.

Salinan lokal hasil migrasi menggunakan database bni_cuti_laravel pada MariaDB 127.0.0.1:3308. Lokasi data MariaDB: .runtime/mysql. Koneksi hosting diatur sendiri melalui .env.

Untuk menjalankan lagi instalasi lokal Windows setelah restart, gunakan:

~~~powershell
.\scripts\start-local.ps1
~~~

Script memakai runtime lokal bila tersedia dan menyalakan MariaDB terpisah pada port 3308. Script tidak membuat ulang atau menghapus data.

## Email

Default CUTI_MAIL_MODE=capture menyimpan hasil uji di mail_deliveries tanpa mengirim email.

Untuk email nyata, isi MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS dan set CUTI_MAIL_MODE=smtp. Port 465 biasanya memakai MAIL_SCHEME=smtps; port 587 memakai smtp dengan STARTTLS bila tersedia.

~~~bash
php artisan cuti:mail --limit=20
php artisan schedule:run
~~~

Cron menjalankan schedule:run setiap menit. Reguler dijadwalkan satu bulan sebelum tanggal mulai, paling cepat saat pengajuan dikirim; darurat dan keputusan langsung masuk antrean. Pengiriman berlangsung pada cron berikutnya. Kegagalan dicoba ulang hingga lima kali dan dapat diulang lewat administrasi.

Pesan bisa terkirim ulang jika server mati setelah penyedia menerima email tetapi sebelum status tersimpan. ID pesan dan catatan per penerima membantu penelusuran; sistem tidak menjanjikan pengiriman tepat satu kali.

## Pengujian

~~~bash
php artisan test
npm run test:frontend
npx playwright install chromium
npm run test:e2e
~~~

PHPUnit memakai SQLite in-memory secara default. Playwright membuat .runtime/e2e.sqlite sendiri, menjalankan Laravel port 3011 dan Vite port 5174, lalu menguji alur nyata lewat browser. Data uji tidak memakai database operasional.

Untuk pemeriksaan MySQL dan dua proses yang memperebutkan slot terakhir, sediakan database kosong yang namanya **berakhiran _test**, atur DB_CONNECTION=mysql beserta koneksinya sebagai environment proses, lalu:

~~~bash
php artisan test tests/Feature/MySqlConcurrencyTest.php tests/Feature/CutiWorkflowTest.php tests/Feature/MailAndImportTest.php
~~~

Pengujian MySQL memakai migrate:fresh pada database uji. TestCase menolak koneksi MySQL dengan nama yang tidak berakhiran _test.

Panduan hosting ada di [DEPLOYMENT.md](DEPLOYMENT.md). Struktur backend dijelaskan di [architecture.md](architecture.md).
