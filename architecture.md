# Arsitektur Sistem Cuti BNI · Laravel

Versi 1.0, 15 Oktober 2026. Acuan: [PRD.md](PRD.md) dan [design.md](design.md).

## Komponen

~~~text
Browser React → /api/* → Laravel
  CutiAccess       sesi, asal permintaan, peran admin, rate limit
  CutiController   validasi HTTP dan respons
  LeaveRules       tanggal, cutoff, overlap, kuota
  LeaveService     draft, submit, konfirmasi, keputusan, pembatalan
  ReplacementService   kandidat PGS dan ketersediaan
  AdminService     akun, outlet, posisi, kebijakan
  CutiStore        transaksi, penyimpanan, audit, idempotensi
  ReportService    XLSX, XLS, CSV
  MailOutbox       antrean dan pengiriman email
                  ↓
               MySQL / MariaDB

Cron → artisan schedule:run → cuti:mail (batch terbatas)
~~~

Deployment berupa satu aplikasi PHP dan frontend statis di public/build. Tidak memerlukan Express, PGlite, Redis, WebSocket, atau worker permanen. Popup SDM memakai polling dashboard setiap lima detik ketika tab terlihat serta saat jendela kembali aktif.

## Penyimpanan

Migration berada di database/migrations. MySQL/MariaDB memakai InnoDB. SQLite digunakan untuk pengujian cepat.

| Tabel | Isi |
|---|---|
| units | Cabang dan kebijakan posisi/kuota/kalender/PGS |
| employees | Email unik, hash bcrypt, cabang, snapshot profil |
| outlets | Outlet dalam cabang dan arsip penghapusan |
| cuti_sessions | Digest token sesi, pemilik, masa berlaku |
| requests | Indeks cabang/bulan/posisi/status dan snapshot pengajuan |
| operations | Hasil mutasi per pengguna dan Idempotency-Key |
| audit | Waktu, pelaku, aksi, objek |
| mail_jobs | Jenis pesan, jatuh tempo, status, percobaan, lease |
| mail_deliveries | Catatan per penerima, subjek, isi, status |

Tabel users, cache, jobs beserta pendukungnya berasal dari scaffold Laravel. Alur cuti memakai employees, cuti_sessions dan mail_jobs; tidak memakai akun users atau worker tabel jobs.

Snapshot JSON mempertahankan kontrak API dan riwayat dari versi Node. Nama posisi/outlet pada pengajuan merupakan snapshot saat submit. Profil/sidebar memakai outlet akun saat ini. Penghapusan karyawan/outlet berupa arsip, sehingga riwayat lama tetap tersimpan.

## Tanggal dan kapasitas

Tanggal cuti berbentuk YYYY-MM-DD berdasarkan kalender kerja cabang dan zona Asia/Jakarta. Timestamp teknis disimpan UTC.

- Reguler mulai paling cepat satu bulan kalender setelah pengajuan, dengan penyesuaian akhir bulan.
- Darurat hanya melewati masa tunggu; semua batas lain tetap berlaku.
- Tiga hari kerja terakhir setiap bulan diblokir.
- Durasi efektif maksimal lima hari kerja. Rentang dipotong sebelum cutoff dan tidak diteruskan ke bulan berikutnya.
- Kuota menghitung karyawan unik per posisi per bulan. Pending dan approved menempati kuota; ditolak/ditarik melepaskan kapasitas aktif.
- Rentang efektif identik pada posisi sama ditolak. Irisan lain maksimal dua hari kerja, dan orang bersamaan dibatasi kuota.
- Periode yang sudah memiliki pengajuan selain draft membekukan batas kuota, termasuk ketika pengajuan kemudian ditarik.

shared/domain.ts membantu tampilan menghitung pilihan tanggal. PHP LeaveRules menjadi pemeriksaan final. Preview menerima fingerprint; submit menghitung ulang kondisi terbaru dan meminta tinjau ulang jika fingerprint berubah.

## Transaksi

Semua mutasi bisnis mengambil SELECT FOR UPDATE pada baris cabang sebelum memeriksa data. Kunci yang sama mencakup kuota, penugasan PGS, versi, master, audit dan pencatatan email.

Pasangan pengguna dan Idempotency-Key yang sama mengembalikan hasil sebelumnya. Payload berbeda dengan key yang sudah dipakai ditolak. Hanya hash payload disimpan, bukan password plaintext. Versi pengajuan mencegah keputusan dari layar yang tertinggal.

Kunci per cabang dipilih agar pemeriksaan lintas posisi dan outlet tetap atomik pada skala cabang. Jika volume mutasi meningkat, kunci dapat diperinci dengan pengujian konkurensi ulang.

## PGS

Aturan awal kosong. Admin menentukan posisi target, posisi calon, dan outlet sama/lain/terpilih. Kandidat harus aktif, berperan EMPLOYEE, serta berada dalam cabang dan outlet yang diizinkan.

Calon yang memiliki cuti pending/approved atau tugas PGS pada satu tanggal efektif yang beririsan dianggap tidak tersedia. SDM cukup memeriksa adanya calon. Penetapan orang tertentu tetap opsional. Jika dipilih, penugasan disimpan, dapat dilihat pegawai terkait, dan membatasi pengajuan berikutnya.

Aturan aktif tanpa kandidat tersedia memblokir persetujuan. Pemeriksaan diulang dalam transaksi keputusan.

## Akses

Login memakai token acak 32 byte dalam cookie HttpOnly, SameSite=Lax; hanya digest SHA-256 tersimpan. Secure mengikuti HTTPS pada APP_URL. Sesi berlaku delapan jam. Perubahan password memverifikasi password saat ini, menyimpan bcrypt baru, dan mencabut sesi lain.

SDM membaca pengajuan cabangnya; pegawai membaca pengajuan sendiri dan penugasan terkait. ADMIN tidak otomatis mendapat wewenang SDM. Mutasi memerlukan header khusus dan asal sesuai APP_URL. Akses localhost tambahan hanya untuk local/testing. Login dan perubahan password dibatasi percobaannya.

QR surat tetap tersedia. Valid hanya untuk APPROVED; surat yang dibatalkan tidak lagi berlaku. Respons tidak memuat alasan, email, atau nomor HP.

## Email dan laporan

Antrean email tersimpan dalam transaksi bisnis. Cron mengambil pekerjaan dengan row lock dan lease dua menit. Satu eksekusi maksimal 20 pekerjaan atau sekitar 45 detik di antara pekerjaan. Timeout SMTP 15 detik per koneksi; satu pengiriman yang sedang berjalan dapat melewati batas batch.

Mode capture menyimpan pesan uji. Mode SMTP memakai Laravel Mail. Status per penerima mencegah pengiriman ulang yang sudah tercatat. Retry berjarak 1, 5, 15, 60, 360 menit, berhenti setelah lima kegagalan. Keputusan dan review pembatalan memakai event key berbeda menurut versi pengajuan.

Laporan mendukung satu bulan atau seluruh bulan. XLSX/XLS menulis nilai teks secara eksplisit untuk menghindari formula dari input pengguna. CSV mengamankan awalan formula. Alasan pribadi tidak masuk laporan atau badan email.

## Migrasi dan verifikasi

Exporter membaca sumber PostgreSQL/PGlite secara konsisten. Import hanya menerima database kosong dan berjalan dalam satu transaksi. ID, hash password, pengajuan, kebijakan, audit dan antrean dipertahankan; sesi tidak disalin.

Pengujian mencakup aturan frontend/PHP, API dan otorisasi, password, email capture/retry, laporan, rollback import, dan browser desktop/mobile. Pengujian MySQL menjalankan dua proses PHP dengan koneksi terpisah yang bersaing untuk slot terakhir. SMTP nyata perlu diuji menggunakan akun hosting tujuan.
