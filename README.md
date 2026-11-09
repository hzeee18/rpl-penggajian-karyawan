# Aplikasi Penggajian Karyawan Berbasis Web

Proyek RPL: Rancang Bangun Aplikasi Penggajian Karyawan berbasis Web.
Dibuat dengan **Laravel 13 (PHP 8.5)**, Blade + Bootstrap 5, database **MySQL** (Homebrew) atau **SQLite**.
Struktur tabel mengikuti ERD di `uml/05-erd-database.mmd`.

## Cara menjalankan

```bash
composer install
cp .env.example .env        # kalau belum ada .env
php artisan key:generate    # kalau APP_KEY masih kosong
php artisan migrate:fresh --seed
php artisan serve
```

Buka http://localhost:8000

### Pakai MySQL (Homebrew, tanpa XAMPP)

```bash
brew install mysql
brew services start mysql
mysql -u root -e "CREATE DATABASE penggajian_karyawan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'penggajian'@'localhost' IDENTIFIED BY 'ganti-password';
  GRANT ALL PRIVILEGES ON penggajian_karyawan.* TO 'penggajian'@'localhost';"
```

Lalu ubah `.env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=penggajian_karyawan
DB_USERNAME=penggajian
DB_PASSWORD=ganti-password
```

Jalankan `php artisan config:clear && php artisan migrate:fresh --seed`. Mau balik ke SQLite tinggal set `DB_CONNECTION=sqlite`.

## Akun contoh

Semua password: `password`

| Username      | Role     | Keterangan                                   |
|---------------|----------|----------------------------------------------|
| `admin`       | Admin    | Kelola user, role, menu, hak akses           |
| `hr`          | HR       | Master data, karyawan, kontrak, absensi, payroll |
| `manager.hr`  | Manager  | Manager departemen HRD → approve **payroll** |
| `manager.hr2` | Manager  | Manager HR kedua (HRD punya 2 manager), cukup salah satu yang approve |
| `manager.it`  | Manager  | Approve kontrak tim IT, input lembur tim     |
| `manager.keu` | Manager  | Manager Keuangan                             |
| `rizky`, `nadia`, `fitri`, `joko`, `agus` | Karyawan | Lihat slip, absensi, profil & kontrak |

Data contoh: 4 departemen, 9 karyawan dengan kontrak aktif, absensi 2 periode terakhir, contoh cuti, sakit, dan lembur,
payroll bulan lalu yang sudah final (slip gaji sudah terbit), dan 1 kontrak perpanjangan yang menunggu approval `manager.it`.

## Tampilan

Tampilan mengikuti Figma *Aplikasi Penggajian Karyawan - UI*: font Public Sans, ikon Lucide, sidebar navy dengan logo Unindra,
warna utama `#1b6fa3`. Semua warna ada di `public/css/app.css` (bagian `:root`). Nama & NPM anggota di halaman login diatur di `config/kelompok.php`.

## Alur utama

1. **Kontrak**: HR buat kontrak + pilih komponen gaji → otomatis diajukan ke manager divisi → disetujui → kontrak aktif (kontrak lama nonaktif). Kalau ditolak, HR revisi lalu ajukan ulang.
2. **Absensi, cuti, lembur**: HR input atau import Excel. Manager bisa input lembur anggota timnya (HR dapat notifikasi).
3. **Payroll**: HR pilih periode → cek rekap → Generate (draft) → Ajukan → Manager HR setujui/tolak.
   Setelah disetujui payroll jadi final, slip PDF dibuat, karyawan dapat notifikasi.
4. **Slip gaji & laporan**: karyawan lihat/unduh slip PDF miliknya. HR lihat laporan per periode/departemen, ekspor Excel/PDF.

## Rumus

| Komponen | Rumus |
|---|---|
| Tunjangan makan & transport | 15% × gaji pokok ÷ hari kerja × hari hadir |
| Lembur | 1/173 × gaji pokok × (1,5 jam pertama + 2 × jam berikutnya), atau tarif khusus di kontrak |
| Potongan alfa | gaji pokok ÷ hari kerja × hari alfa |
| Bruto | gaji pokok + semua komponen penambah |
| PPh 21 | bruto (komponen kena pajak) × tarif TER golongan A/B/C (PP 58/2023) |
| Take home pay | bruto − semua komponen pengurang |

Rentang tanggal dan hari kerja diatur di menu **Periode**. Pengali lembur diatur di rumus komponen **Lembur (LB)** di menu Komponen Gaji.
Periode `2026-11` dengan cut-off 25 = 26 Oktober s/d 25 November.

## Aturan penting

- Login salah 5 kali → akun terkunci, Admin dapat notifikasi dan bisa buka kunci.
- Lupa kata sandi → permintaan masuk ke notifikasi Admin, Admin reset password.
- Sesi berakhir setelah 30 menit tidak aktif.
- Hak akses tiap role diatur di tabel `privileges` (menu Role & Hak Akses).
- Data absensi/cuti/lembur di periode payroll yang sudah diajukan atau final tidak bisa diubah.
- Karyawan yang sudah punya riwayat gaji hanya bisa dinonaktifkan, tidak dihapus.
- Kontrak yang disetujui untuk tanggal mendatang aktif otomatis saat tanggalnya tiba
  (`php artisan kontrak:aktifkan`, terjadwal tiap hari; juga dicek saat generate payroll).

## Struktur kode

```
app/Models/               model per tabel ERD
app/Services/PayrollService.php   rekap, hitung gaji, PPh 21, finalisasi + slip PDF
app/Services/ApprovalService.php  pengajuan, setujui, tolak (kontrak & payroll)
app/Http/Middleware/CekAkses.php  cek hak akses role ke menu
app/Http/Controllers/     controller per modul (Admin/, Master/, dll)
database/migrations/2026_11_07_000001_create_penggajian_tables.php   semua tabel
database/seeders/         data awal + tarif TER
resources/views/          halaman Blade
```
