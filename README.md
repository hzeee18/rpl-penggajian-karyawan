# Aplikasi Penggajian Karyawan (Multi-Perusahaan)

Proyek RPL: aplikasi penggajian karyawan berbasis web.
Branch `feature/multi-tenant-payroll` mengubah aplikasi menjadi **multi-perusahaan** dan memakai **modul payroll dinamis**
(rumus diatur sendiri tiap perusahaan, PPh21 TER, slip PDF berpassword).

Dibuat dengan **Laravel 13**, Blade + Bootstrap 5, database **MySQL**.

---

## 1. Kebutuhan

| Kebutuhan | Keterangan |
|---|---|
| PHP **8.3 atau lebih baru** | Dikembangkan dengan PHP 8.4 (Laragon) |
| Ekstensi PHP | `pdo_mysql`, `bcmath`, `mbstring`, `openssl`, `dom`, `gd`, `fileinfo`. Untuk menjalankan test juga `pdo_sqlite` |
| Composer 2 | |
| MySQL 8 | Laragon / XAMPP / MySQL biasa |

Di Laragon, ekstensi bisa dinyalakan lewat menu **PHP → Extensions**. Cek dengan `php -m`.

## 2. Cara menjalankan (pertama kali)

```bash
git clone <url-repo> rpl-penggajian-karyawan
cd rpl-penggajian-karyawan
git checkout feature/multi-tenant-payroll

composer install
copy .env.example .env          # Linux/Mac: cp .env.example .env
php artisan key:generate
```

Buat database kosong di MySQL (lewat HeidiSQL/phpMyAdmin atau perintah ini):

```sql
CREATE DATABASE penggajian_karyawan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Ubah bagian database di `.env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=penggajian_karyawan
DB_USERNAME=root
DB_PASSWORD=
```

Buat tabel lalu isi **data QA terkini**:

```bash
php artisan config:clear
php artisan migrate:fresh
php artisan db:seed --class=DataQaSeeder
```

> Jangan pakai `migrate:fresh --seed`. Itu mengisi data contoh aplikasi lama, dan `DataQaSeeder` akan menolak
> berjalan di database yang sudah berisi.

Jalankan aplikasi. Perlu **dua terminal**, dan keduanya harus tetap menyala:

```bash
php artisan serve        # terminal 1: web di http://localhost:8000
php artisan queue:work   # terminal 2: WAJIB untuk pratinjau, generate payroll, dan slip PDF
```

Tanpa `queue:work`, proses pratinjau/generate payroll akan terus berstatus "antre".

## 3. Akun login

Semua password: **`password`**

| Username | Role | Fungsi |
|---|---|---|
| `superadmin` | Super Admin | Dashboard platform, membuat perusahaan beserta akun Admin-nya |
| `admin-mts` | Admin (MTS) | Semua menu perusahaan: user, role, jabatan, departemen, tipe leave, cut off, tabel referensi, dll. |
| `lulu` | HR (MTS) | Karyawan, kontrak, absensi/leave/lembur, import CSV, master payroll, generate payroll |
| `septiana` | Manager (MTS) | Manager departemen Finance & Accounting; **approver payroll** buatan Lulu |
| `anisa` | Karyawan (MTS) | Dashboard, absensi sendiri, slip gaji sendiri |

## 4. Isi data QA (`DataQaSeeder`)

Salinan database QA terakhir, diambil dari `database/seeders/data/qa_snapshot.json`:

- 1 perusahaan: **MTS – Mitra Tiga Sepakat** (modul payroll baru aktif).
- 2 departemen (FIN, OPS), 3 jabatan, 4 tipe leave, cut off tanggal 1–30.
- 3 karyawan dengan kontrak aktif. Katalog berisi 8 komponen: gaji pokok, tunjangan, BPJS, uang makan, transport, lembur, PPh21.
- Absensi, leave, dan lembur **November 2026** (sebagian lewat Import CSV).
- Master payroll **"Payroll Normal"**: lembur = tarif per jam × total jam, PPh21 TER otomatis.
- 1 payroll **November 2026** yang sudah disetujui, beserta 3 slip gaji. PDF slip dibuat ulang otomatis saat seeding.
- Tabel nasional TER PPh21 dan kategori PTKP.

Password **PDF slip** = 2 digit terakhir nomor karyawan + 4 digit terakhir nomor rekening:

| Karyawan | No. | Password PDF |
|---|---|---|
| Septiana | 0001 | `010023` |
| Lulu | 0002 | `027284` |
| Anisa | 0003 | `032938` |

### Memperbarui data seed

Kalau data di database kamu sudah berubah dan ingin dibagikan lagi:

```bash
php artisan seed:ekspor-qa      # menulis ulang database/seeders/data/qa_snapshot.json
git add database/seeders/data/qa_snapshot.json
git commit -m "chore: perbarui data QA"
```

Teman yang menarik perubahan itu cukup menjalankan ulang `migrate:fresh` dan `db:seed --class=DataQaSeeder`.
Catatan teknis: NPWP dan nomor rekening disimpan terenkripsi dengan `APP_KEY` masing-masing laptop. Ekspor membukanya,
lalu seeder mengenkripsi ulang dengan `APP_KEY` laptop tujuan. Password akun tidak ikut diekspor dan selalu diset ke `password`.

## 5. Alur penggunaan

1. **Super Admin** → menu **Company** → *Tambah Perusahaan* sekaligus akun Admin-nya. Untuk menambah Admin lain, pakai tombol **Admin** di tiap baris.
2. **Admin perusahaan** login → isi Master Data (Jabatan, Departemen + manager, Tipe Leave), Konfigurasi (Cut Off, Tabel Referensi) →
   **Manajemen User**: buat akun HR, Manager, dan Karyawan. Untuk user HR, pilih **Approver payroll** (user Manager).
3. **HR** → Data HRIS: Karyawan → Kontrak (**Ubah** = koreksi, **Perbarui** = versi baru) → Absensi / Leave / Lembur
   (manual atau **Import CSV**; template tersedia di halaman Import) → Konfigurasi: Katalog Komponen, Kalender Libur →
   Payroll: **Master Payroll** (rumus) → **Generate Payroll** (pratinjau → generate).
4. **Manager (approver)** → Payroll → **Approval** → setujui atau tolak.
5. Setelah disetujui, slip PDF terbit. Setiap user hanya melihat **slip miliknya sendiri** di menu Slip Gaji.

Hubungan manager–karyawan diatur per **departemen** (Master Data → Departemen → Manager).
Manager departemen **HRD** dianggap Manager HR dan ikut mendapat menu HR.

## 6. Data contoh lain (opsional)

- Perusahaan baru berisi data dummy: buat perusahaan lewat Super Admin, lalu `php artisan payroll:isi-data {KODE} --karyawan=12`
  (master payroll/rumus tidak ikut dibuat).
- Data contoh aplikasi lama (perusahaan DEFAULT): `php artisan migrate:fresh --seed`.

## 7. Menjalankan test

```bash
php artisan test                                     # SQLite in-memory (butuh pdo_sqlite)
php artisan test --configuration=phpunit.mysql.xml   # MySQL, database terpisah `penggajian_test`
```

Database `penggajian_test` harus dibuat dulu dan **jangan** sama dengan database utama, karena test mengosongkannya.

## 8. Masalah umum

| Gejala | Solusi |
|---|---|
| `could not find driver` | Ekstensi `pdo_mysql` (atau `pdo_sqlite` untuk test) belum aktif |
| Pratinjau/generate payroll tidak selesai | Jalankan `php artisan queue:work` |
| HR tidak bisa generate payroll | User HR belum punya approver: Admin → Manajemen User → ubah user HR → Approver payroll |
| `DataQaSeeder` menolak berjalan | Database sudah berisi; jalankan `php artisan migrate:fresh` dulu (tanpa `--seed`) |
| Halaman error setelah ganti `.env` | `php artisan config:clear` |

## 9. Dokumen lain

- `docs/PAYROLL_SPEC.md`: aturan teknis modul payroll dan bahasa rumus.
- `docs/AUDIT.md`: catatan keputusan dan perubahan per tahap.
- `AGENTS.md`: aturan pengembangan branch ini.
