# Aplikasi Penggajian Karyawan (Multi-Perusahaan)

Proyek RPL: aplikasi penggajian karyawan berbasis web.
Branch `feature/multi-tenant-payroll` mengubah aplikasi menjadi **multi-perusahaan** dan memakai **modul payroll dinamis**
(rumus diatur sendiri tiap perusahaan, Kalender Payroll, prorata hari kerja, PPh21 TER, slip PDF dengan password opsional).

Dibuat dengan **Laravel 13**, Blade + Bootstrap 5 + Turbo, database **MySQL**.

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
composer run dev         # server + queue:listen + log + vite dalam satu perintah (disarankan)
```

atau dua terminal:

```bash
php artisan serve        # terminal 1: web di http://localhost:8000
php artisan queue:work   # terminal 2: WAJIB untuk pratinjau, generate payroll, dan slip PDF
```

Tanpa worker antrean, proses pratinjau/generate payroll akan terus berstatus "antre". `queue:work` **tidak** membaca ulang
kode yang berubah: setelah `git pull` atau mengubah kode, hentikan lalu jalankan lagi (atau `php artisan queue:restart`).

## 3. Akun login

Semua password: `password`

| Username | Role | Fungsi |
|---|---|---|
| `superadmin` | Super Admin | Dashboard platform, membuat perusahaan beserta akun Admin-nya |
| `admin-mts` | Admin (MTS) | Semua menu perusahaan: user, role, jabatan, departemen, tipe leave, cut off, tabel referensi, dll. |
| `lulu` | HR (MTS) | Karyawan, kontrak, absensi/leave/lembur, import CSV, master payroll, generate payroll |
| `septiana` | Manager (MTS) | Manager departemen Finance & Accounting (penyetuju payroll diatur di menu Approver Payroll) |
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

## 4b. Data uji alur lengkap (`FlowUjiSeeder`) — disarankan untuk mencoba alur

Membuat akun `superadmin` dan perusahaan **MTS – PT Maju Terus Sejahtera** berisi semua kasus untuk dicoba dari awal sampai slip. Semua skenario sekaligus (MTS 122 karyawan + BNS + SBR): `php artisan db:seed --class=SkenarioMultiTenantSeeder`.
**Peringatan: perintah pertama menghapus semua data di database.**

```bash
php artisan migrate:fresh --seed              # data dasar (role, menu, perusahaan DEFAULT, Super Admin)
php artisan db:seed --class=FlowUjiSeeder     # superadmin + perusahaan MTS + data alur (aman dijalankan ulang, tidak menimpa)
```

| Username | Role | Fungsi |
|---|---|---|
| `admin.mts` | Admin | Setelan: pakai approval, password slip, Cut Off & prorata, user/role |
| `hr.mts` | HR | Kalender Payroll, kontrak, absensi/leave/lembur, master payroll, generate |
| `manager.mts` | Manager (jabatan Manager, departemen HR) | **Penyetuju payroll** (dipilih di Approver Payroll) |
| `staf.mts` | Karyawan (Operasional) | Slip gaji & absensi sendiri |

Isi: departemen HR, Operasional (pola hari kerja Senin–Minggu), Finance (libur khusus 11 Des); kontrak tetap, kontrak hampir habis
(31 Jan 2027, tombol Perbarui muncul), kontrak masih lama; karyawan **masuk 10 Nov** dan **resign 15 Nov** (prorata hari kerja);
Master "Payroll Default" terbit (PPh21 TER otomatis); Kalender Payroll **Okt 2026 final** (slip terbit), **Nov 2026 draft**
(dengan penyesuaian Bonus kena pajak dan Reimburse), **Des 2026 belum diproses**; libur nasional, cuti bersama 24 Des;
absensi/leave/lembur 26 Sep – 25 Des 2026. Langkah uji: `docs/SKENARIO_UJI.md`.

## 5. Alur penggunaan

1. **Super Admin** → menu **Company** → *Tambah Perusahaan* sekaligus akun Admin-nya. Untuk menambah Admin lain, pakai tombol **Admin** di tiap baris.
2. **Admin perusahaan** login → isi Master Data (Jabatan, Departemen + manager, Tipe Leave), Konfigurasi (Cut Off + dasar prorata,

   Tabel Referensi) → menu **Approver**: payroll pakai persetujuan atau langsung final, PDF slip pakai password atau tidak →
    **Manajemen User**: buat akun HR, Manager, dan Karyawan. Penyetuju payroll = semua user Manager yang karyawannya di departemen **HRD**.
3. **HR** → **Kalender Payroll**: buat periode (tanggal otomatis menyambung), hari libur, pola hari kerja per departemen →

   Data HRIS: Karyawan → Kontrak (**Ubah** = koreksi, **Perbarui** = kontrak baru, muncul H-3 bulan; **Perubahan gaji** dari detail) →
    Absensi / Leave / Lembur (manual atau **Import CSV**) → Katalog Komponen → **Master Payroll** (rumus, terbitkan) →
    **Generate Payroll**: pilih periode → cek **Persiapan sebelum generate** → Lihat preview (semua karyawan) → Tambah penyesuaian bila perlu → Generate.
4. **Penyetuju payroll** (diatur di menu Approver Payroll) → Payroll → **Approval** → setujui atau tolak (siapa pun yang pertama). Selama menunggu, HR bisa **Tarik kembali** ke draft.
5. Setelah disetujui, slip PDF terbit. Setiap user hanya melihat **slip miliknya sendiri** di menu Slip Gaji.

   Payroll final tidak bisa diubah; koreksi lewat penyesuaian di payroll periode berikutnya.

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
| Pratinjau/generate payroll tidak selesai | Jalankan `php artisan queue:work` (atau `composer run dev`); proses yang macet > 15 menit bisa dibatalkan |
| Generate gagal "Call to undefined method …" setelah update kode | Worker `queue:work` masih memakai kode lama: hentikan dan jalankan ulang |
| Tombol Generate nonaktif | Lihat kartu **Persiapan sebelum generate**: master payroll terbit, penyetuju (atur di menu Approver Payroll), preview tanpa gaji minus |
| Tidak bisa membuat payroll bulan X | Periode bulan itu belum dibuat: Kalender Payroll → Buat periode berikutnya |
| `DataQaSeeder` menolak berjalan | Database sudah berisi; jalankan `php artisan migrate:fresh` dulu (tanpa `--seed`) |
| Halaman error setelah ganti `.env` | `php artisan config:clear` |

## 9. Dokumen lain

- `docs/PAYROLL_SPEC.md`: aturan teknis modul payroll dan bahasa rumus.
- `docs/AUDIT.md`: catatan keputusan dan perubahan per tahap.
- `docs/SKENARIO_UJI.md`: langkah uji alur lengkap dengan data `FlowUjiSeeder`.
- `docs/uml/`: use case, activity, class, sequence, dan ERD (Mermaid) sesuai sistem sekarang.
- `AGENTS.md`: aturan pengembangan branch ini.
