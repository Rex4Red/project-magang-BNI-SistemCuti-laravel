# Deploy ke shared hosting PHP / cPanel

Project memakai Laravel 13. Pastikan hosting menyediakan PHP **8.3 atau lebih baru**, ekstensi di README, MySQL/MariaDB InnoDB, HTTPS dan cron PHP setiap menit. Keterangan “PHP 8” saja belum memastikan versi dan ekstensi tersedia.

Gunakan domain/subdomain yang document root-nya dapat diarahkan ke folder public Laravel. Build saat ini menargetkan root domain/subdomain, bukan subfolder seperti /cuti.

## Build di laptop

Jika hosting tidak menyediakan Node/Composer, lakukan dependency produksi dan build di laptop/CI dengan versi PHP yang sesuai:

~~~bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
composer check-platform-reqs --no-dev
~~~

Upload source, vendor dan public/build. Jangan upload .git, .tools, .runtime, .data, node_modules, file ekspor, database pengembangan, cache bootstrap lokal, atau .env laptop. Simpan .env hosting di luar document root.

Untuk kembali mengembangkan setelah membuat paket produksi, jalankan composer install agar dependency pengujian tersedia kembali.

## Penempatan

~~~text
/home/akun/bni-cuti/          ← Laravel, vendor, storage, .env
/home/akun/bni-cuti/public/   ← document root domain
~~~

Jangan mengarahkan document root ke folder source Laravel. Jika paket hanya mengizinkan public_html, simpan source di luar folder publik dan isi public_html hanya dengan isi public. Sesuaikan path vendor/autoload.php dan bootstrap/app.php pada index.php. Untuk susunan ini, atur usePublicPath ke folder publik sebelum aplikasi dijalankan. Subdomain yang menunjuk langsung ke public lebih mudah dipelihara.

storage dan bootstrap/cache harus dapat ditulis PHP sesuai ownership akun hosting. Tidak perlu permission 777.

## Environment produksi

Buat database/user di cPanel dan isi .env hosting:

~~~dotenv
APP_NAME="BNI Sistem Cuti"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://cuti.domain-anda.id
APP_KEY=
APP_TIMEZONE=Asia/Jakarta
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=akun_bnicuti
DB_USERNAME=akun_cuti
DB_PASSWORD="isi-dari-cpanel"
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
DEMO_MODE=false
BRANCH_NAME="Nama cabang"
CUTI_MAIL_MODE=smtp
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=mail.domain-anda.id
MAIL_PORT=587
MAIL_USERNAME=cuti@domain-anda.id
MAIL_PASSWORD="isi-password-email"
MAIL_FROM_ADDRESS=cuti@domain-anda.id
MAIL_FROM_NAME="BNI Sistem Cuti"
~~~

PHP terminal dan PHP website bisa berbeda versi. Gunakan binary PHP 8.3 yang benar untuk CLI/cron.

~~~bash
php artisan key:generate --force
php artisan migrate --force
~~~

Untuk data lama, upload ekspor privat dan jalankan cuti:import dengan path file tersebut. Untuk cabang baru, isi BOOTSTRAP_EMAIL/BOOTSTRAP_PASSWORD lalu jalankan cuti:setup. Jangan gunakan migrate:fresh pada database berisi data. Kosongkan BOOTSTRAP_PASSWORD setelah akun awal tersedia.

~~~bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
~~~

## Cron dan pemeriksaan

Tambahkan cron cPanel setiap menit dengan path sebenarnya:

~~~cron
* * * * * cd /home/akun/bni-cuti && /path/php83 artisan schedule:run >> /home/akun/bni-cuti/storage/logs/scheduler.log 2>&1
~~~

Tidak perlu npm run dev, artisan serve, atau worker permanen di hosting Apache/LiteSpeed.

Periksa setelah upload:

1. /api/health mengembalikan ok dan halaman login memuat aset/logo.
2. Login tiap peran berhasil dan profil menunjukkan outlet yang benar.
3. Pengajuan uji masuk antrean SDM; popup dan status ikut diperbarui.
4. cuti:mail --limit=1 mengirim ke alamat uji. Cron memproses pekerjaan jatuh tempo berikutnya.
5. Persetujuan/pembatalan menghasilkan status dan email yang sesuai.
6. Excel dapat dibuka dan QR surat menunjukkan status terkini.
7. File .env dan source privat tidak dapat diakses dari web.

Backup mencakup database serta .env/APP_KEY secara privat. Uji pemulihannya sebelum pemakaian operasional dan buat backup lagi sebelum memperbarui source atau migration.

## Deploy otomatis dari GitHub

Workflow `Verify and deploy Laravel` menguji setiap push dan pull request. Setelah semua pemeriksaan berhasil untuk branch `main`, job `publish` membuat rilis `deploy-<commit>` berisi source PHP, dependensi produksi, dan hasil build frontend. Pull request dan branch lain tidak menerbitkan paket deploy. Paket tidak menyertakan `.env`, storage, database lokal, atau cache konfigurasi.

Hosting mengambil manifest rilis setiap dua menit melalui cron PHP, tanpa menyimpan password cPanel di GitHub. Paket diperiksa checksum, struktur, dan commit-nya sebelum diekstrak ke direktori rilis baru. `.env` dan storage ditautkan ke lokasi bersama yang tetap. Migration yang belum dijalankan didahului backup SQL privat; tidak ada seeding atau reset database saat deploy.

Susunan instalasi pada hosting uji:

~~~text
/home/yyuyabzu/cuti-deploy/
  config.json                  # Konfigurasi privat hosting
  deploy-release.php           # Salinan scripts/deploy-release.php
  ReleasePackage.php           # Salinan scripts/ReleasePackage.php
  current -> releases/<versi>  # Versi aktif
  releases/                   # Paket lengkap per versi
  backups/                    # Backup SQL sebelum migration
  state.json                  # Commit aktif dan versi sebelumnya
  deploy.log                  # Hasil deploy
~~~

Document root subdomain diarahkan ke `cuti-deploy/current/public`. Folder `.env` dan storage lama tetap berada di `/home/yyuyabzu/sistemcuti.bgskr.my.id/`; source lama dipertahankan sebagai cadangan awal. Cron email harus memakai `cuti-deploy/current/artisan` agar mengikuti kode aktif. Hosting ini membatasi `proc_open`, sehingga cron email memanggil `cuti:mail` secara langsung.

`config.json` memakai field `repository`, `base`, `environment`, `storage`, `initial`, dan `url`. Nilai repository adalah `Rex4Red/project-magang-BNI-SistemCuti-laravel`. Field `initial` menunjuk project lama untuk menginisialisasi symlink sebelum document root dipindahkan. Jalankan `php deploy-release.php config.json --initialize` sekali, kemudian pasang cron pada Linux dengan ekstensi cURL dan Zip serta symlink yang didukung hosting:

~~~cron
*/2 * * * * /opt/cpanel/ea-php83/root/usr/bin/php /home/yyuyabzu/cuti-deploy/deploy-release.php /home/yyuyabzu/cuti-deploy/config.json >> /home/yyuyabzu/cuti-deploy/deploy.log 2>&1
* * * * * /opt/cpanel/ea-php83/root/usr/bin/php /home/yyuyabzu/cuti-deploy/current/artisan cuti:mail --limit=20 >> /home/yyuyabzu/sistemcuti.bgskr.my.id/storage/logs/cron.log 2>&1
~~~

Versi baru diaktifkan dengan mengganti symlink setelah persiapan selesai. Pemeriksaan HTTP harus mendapatkan commit yang benar di `/deployment.json` dan hasil sehat di `/api/health`. Jika gagal, kode otomatis kembali ke versi sebelumnya. Perubahan database tidak dibatalkan otomatis: gunakan migration yang tetap cocok dengan versi aplikasi sebelumnya; backup SQL tersedia untuk pemulihan terencana.

Rilis yang gagal ditandai dalam `failed.json` supaya cron tidak mengulangi migration yang gagal setiap dua menit. Push perbaikan dengan commit baru untuk mencoba rilis berikutnya; untuk mengulang commit yang sama setelah memperbaiki penyebabnya, hapus penanda tersebut melalui File Manager. Skrip deploy berada di luar document root dan hanya dijalankan melalui CLI. Jika skrip deploy sendiri diubah di repository, perbarui salinan privatnya di hosting juga.

Status pengujian dan pembuatan rilis tersedia di tab Actions GitHub. Setelah berhasil, hosting biasanya memperbarui website dalam dua menit berikutnya; waktu pengujian dan build ditambahkan sebelum itu.
