# AUDIT.md — Fase 0 (kondisi aplikasi sebelum modul payroll baru)

Tanggal audit: 2026-11-11. Branch: `main` (commit `7b1c62e first commit`).
Audit ini **tidak mengubah kode atau database**. Semua perintah yang dijalankan bersifat baca-saja
(`composer show`, `php artisan about`, `migrate:status`, `SELECT`, `php artisan test`).

---

## 1. Stack dan versi sebenarnya

| Item | Versi terpasang | Catatan |
|---|---|---|
| PHP (CLI lokal, Laragon) | **8.4.27** | README menyebut PHP 8.5, `PAYROLL_SPEC.md` menyebut 8.5.12. `composer.json` minta `^8.3`, jadi 8.4 sah. Tidak di-upgrade. |
| Laravel | **13.40.0** | |
| Database (dev lokal) | **MySQL 8.0.30** | Mendukung `CHECK` dan generated column (syarat spec: 8.0.16+). |
| Database test | SQLite `:memory:` (`phpunit.xml`) | Ekstensi `pdo_sqlite` **tidak aktif** di PHP lokal (lihat §7). |
| Queue | `database` (tabel `jobs`, `job_batches`, `failed_jobs` sudah ada) | Belum ada job yang dipakai aplikasi. |
| Session / Cache | `database` / `database` | `SESSION_LIFETIME=30`. |
| Timezone / locale | `Asia/Jakarta` / `id` | Sudah sesuai spec. |
| PDF | `barryvdh/laravel-dompdf` 3.1.2 (dompdf 3.1.6) | **Tidak ada `mpdf`**. Dompdf tidak punya `SetProtection` bawaan seperti mpdf; perlu keputusan untuk password PDF (§8). |
| Excel/CSV | `phpoffice/phpspreadsheet` 5.10.0 | Dipakai import lama. Tidak ada `league/csv` / `maatwebsite/excel`. |
| Ekspresi rumus | Parser sendiri `App\Support\Rumus` (tanpa `eval`, berbasis `float`) | Tidak ada `symfony/expression-language`. |
| Ekstensi PHP | `bcmath`, `intl`, `gd`, `pdo_mysql` aktif | `bcmath` tersedia untuk uang. |
| Test / lint | PHPUnit 12.5.43, Pint 1.32.1 | **Tidak ada Pest, PHPStan/Larastan**. |
| Frontend | Blade + Bootstrap 5, Vite, ikon Lucide | Warna di `public/css/app.css`. |

## 2. Cara menjalankan

```bash
composer install
cp .env.example .env && php artisan key:generate   # hanya jika .env belum ada
php artisan migrate                                 # JANGAN migrate:fresh (dilarang AGENTS.md)
php artisan serve                                   # http://localhost:8000
php artisan queue:work --tries=1 --timeout=0        # belum dibutuhkan modul lama
php artisan schedule:work                           # menjalankan kontrak:aktifkan tiap 00:05
php artisan test                                    # butuh pdo_sqlite, lihat §7
vendor/bin/pint
```

Catatan: README menyuruh `php artisan migrate:fresh --seed`. Perintah itu **dilarang** di branch ini.
`DatabaseSeeder` bawaan juga tidak boleh dijalankan ulang di DB yang sudah berisi karena memakai `create()` (akan bentrok unique key).

Akun contoh dari README: `admin`, `hr`, `manager.hr`, `manager.hr2`, `manager.it`, `manager.keu`, `rizky`, … (password `password`).

## 3. Tabel yang sudah ada (DB lokal, jumlah baris per hari audit)

Semua migration sudah dijalankan (batch 1):
`0001_01_01_000000..2`, `2026_11_07_000001_create_penggajian_tables`, `2026_11_07_000002_tambah_tabel_figma`,
`2026_11_07_000003_kontrak_nomor_riwayat`, `2026_11_08_000001_komponen_rumus`, `2026_11_08_000002_departemen_multi_manager`.

| Kelompok | Tabel (baris) | Kolom penting |
|---|---|---|
| Akses | `users` (15) | `role_id`, `karyawan_id` (unique, nullable), `username` (unique, dipakai login), `email` (nullable, **tidak** unique), `is_active`, `gagal_login`, `is_locked`, `last_login` |
| | `roles` (4) | `nama` **unique global** (`Admin`, `HR`, `Manager`, `Karyawan`) |
| | `menus` (20) | `parent_id`, `nama`, `url` (kunci akses), `icon`, `urutan` |
| | `privileges` (76) | `role_id`, `menu_id`, `can_view/create/update/delete/approve`, unique (`role_id`,`menu_id`) |
| Organisasi | `karyawan` (120) | `departemen_id`, `pajak_id`, `nik` (unique global, isinya nomor karyawan mis. `K0001`), `nama`, `jabatan` (teks), `tgl_masuk`, `no_rekening` (**teks biasa**), `nama_bank`, `status_kepegawaian`, `sisa_cuti`, `is_active` |
| | `departemen` (6) | `kode` (unique global), `nama`, `manager_id` |
| | `departemen_manager` (7) | pivot multi-manager |
| Kontrak | `kontrak` (190) | `nomor`, `karyawan_id`, `kontrak_sebelumnya_id`, `jenis` (PKWT/PKWTT), `tgl_mulai`, `tgl_akhir`, `gaji_pokok`, `status_approval` (draft/menunggu/disetujui/ditolak), `is_active` |
| | `kontrak_komponen` (437) | `kontrak_id`, `payslip_component_id`, `tipe_nilai`, `nilai`, `is_active` |
| Kehadiran | `attendance` (11.129) | `karyawan_id`, `tanggal`, `jam_masuk`, `jam_keluar`, `status` (hadir/terlambat/alfa), unique (`karyawan_id`,`tanggal`) |
| | `leave` (298) | `karyawan_id`, `jenis` **varchar(10)** (cuti/izin/sakit), `tgl_mulai`, `tgl_selesai` (**rentang**, bukan per hari), `sumber_input` |
| | `overtime` (812) | `karyawan_id`, `tanggal`, `jam_mulai`/`jam_selesai` (NOT NULL), `durasi`, `diinput_oleh` |
| Pajak | `golongan_ter` (3), `pajak` (8 status PTKP), `tarif_ter` (125) | Angka TER di-*hardcode* di `TarifTerSeeder` |
| Payroll lama | `payslip_component` (7), `master_payroll` (6), `payroll` (5), `payroll_detail` (2.811), `payslip` (478) | lihat §5 |
| Approval | `approval` (9), `approval_mapping` (2) | generik (`jenis_ref` = kontrak / payroll) |
| Lain-lain | `notifikasi` (69), `log_aktivitas` (17), `import_excel` (0) | |
| Infrastruktur | `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`, `migrations` | |

Tidak ada tabel: `companies`, `holidays`, `positions`, `leave_types`, `audit_logs`, tabel referensi berversi.

Catatan: migration bawaan `0001_01_01_000000_create_users_table` sudah diubah oleh pembuat lama (tabel `users`
dipindah ke migration `create_penggajian_tables`). Dicatat saja, tidak disentuh.

## 4. Login, role, menu, multi-tenant

- **Login**: `AuthController` manual (bukan Breeze/Fortify), memakai **`username` + password**. Terkunci setelah 5× salah (`is_locked`), notifikasi ke Admin.
  Lupa password = notifikasi ke Admin. Middleware `aktif` (`AkunAktif`) mengeluarkan user nonaktif/terkunci.
- **Role**: 1 user = 1 role (`users.role_id`). Nama role di-*hardcode* sebagai konstanta `Role::ADMIN/HR/MANAGER/KARYAWAN` dan dicari dengan `Role::where('nama', ...)`.
  Ada peran turunan **"Manager HR"** = role Manager yang memimpin departemen berkode `HRD` (`User::isManagerHr()`); ia mendapat hak role HR juga dan menjadi approver payroll.
- **Privilege**: tabel `privileges` per role × menu × aksi (view/create/update/delete/approve). Diterapkan lewat middleware route `akses:{menu.url},{aksi}` (`CekAkses` → `User::bisa()` → `Privilege::cekAkses()`).
  Helper `crud()` di `app/helpers.php` membuat route CRUD sekaligus middleware-nya. **Tidak ada Policy/Gate.**
- **Menu/sidebar**: `AppServiceProvider` (view composer `layouts.app`) membangun sidebar dari `menus` + `privileges`, ditambah aturan sembunyi/ganti label per role yang di-*hardcode*.
- **Lingkup data**: `User::karyawanIds()` / `batasi()` (Admin/HR = semua, Manager = timnya, Karyawan = dirinya).
- **Multi-tenant: BELUM.** Tidak ada `company_id` di tabel mana pun, tidak ada global scope. Semua query modul lama membaca seluruh data.

## 5. Modul payroll lama

**Lokasi**

| Lapisan | File |
|---|---|
| Service | `app/Services/PayrollService.php` (rekap, hitung, generate, finalisasi, PDF, `tanggalTerkunci`), `app/Services/ApprovalService.php` (kontrak & payroll) |
| Rumus | `app/Support/Rumus.php` (parser sendiri, `float`) |
| Model | `Payroll`, `PayrollDetail`, `Payslip`, `PayslipComponent`, `MasterPayroll`, `Pajak`, `GolonganTer`, `TarifTer`, `Approval`, `ApprovalMapping` |
| Controller | `PayrollController`, `PayslipController`, `LaporanController`, `ApprovalController`, `Master/KomponenController`, `Master/MasterPayrollController`, `Master/PajakController` |
| View | `resources/views/payroll/*`, `slip/*`, `laporan/*`, `master/komponen/*`, `master/master-payroll.blade.php`, `master/pajak/*` |
| Route (menu `url`) | `payroll`, `laporan`, `slip`, `approval`, `komponen`, `master-payroll`, `pajak` |
| Seeder | `DatabaseSeeder`, `TarifTerSeeder` |
| Test | `AutoApprovePayrollTest`, `ManagerTidakLihatGajiTest`, `MasterPayrollPeriodeTest`, `MultiManagerRumusTest` |

**Tabel milik modul lama**: `payslip_component`, `master_payroll`, `payroll` (unique `periode` → hanya 1 payroll per bulan), `payroll_detail`,
`payslip`, `golongan_ter`, `pajak`, `tarif_ter`, plus `approval`/`approval_mapping` (dipakai bersama kontrak).

**Yang dipakai modul lain dari payroll lama** (alasan modul lama tidak boleh disentuh):

| Pemakai | Ketergantungan |
|---|---|
| `Controller::pastikanTidakTerkunci()` (dipakai Absensi/Cuti/Lembur/Import) | `PayrollService::tanggalTerkunci()` → `payroll.status` + `MasterPayroll::rentang()` |
| `Controller::rentangFilter()` (filter periode di banyak halaman) | `MasterPayroll::aktif()->rentang()` |
| `AttendanceController` (rekap tim, riwayat saya) | `PayrollService::rekapKaryawan()` |
| `DashboardController` (semua role) | `PayrollService`, `MasterPayroll`, `Payroll`, `Payslip` |
| `ImportController` | `MasterPayroll::untuk()`, `ApprovalService` (impor kontrak) |
| `KontrakController` + `kontrak_komponen` | `PayslipComponent::untukKontrak()`; approval kontrak via `ApprovalService` |
| `KaryawanController::destroy` | cek `payslips` / `payroll_detail` sebelum hapus |
| `CariController` | `Payslip` |
| `Karyawan::pajak()` | `pajak` (status PTKP) |
| `Leave` (logika lama) | `PayrollService::rekapKaryawan` memakai `$cuti[$l->jenis]` dengan kunci tetap cuti/izin/sakit |

## 6. Konflik nama: `docs/schema.sql` vs database sekarang

Opsi: **(a)** pakai ulang tabel yang ada + kolom baru lewat migration baru · **(b)** tabel baru berprefix `pay_` · **(c)** gabungan.
Kolom "Usulan" adalah rekomendasi saya. **Semua masih menunggu keputusan Anda.**

### 6.1 Konflik nama persis

| Target | Ada sekarang | Perbedaan utama | Usulan | Alasan |
|---|---|---|---|---|
| `users` | `users` | Target: `company_id`, `employee_id`, `approver_user_id`, `name`, `email` unique NOT NULL. Sekarang: login `username`, `karyawan_id`, `email` nullable. | **(a)** | Login dan sesi harus tetap satu. Tambah `company_id` (nullable) dan `approver_user_id` (nullable). `karyawan_id` dipakai sebagai `employee_id`. Login tetap `username`; `email` tidak dipaksa unique. |
| `roles` | `roles` | Target: `company_id`, `code`, unique per perusahaan. Sekarang `nama` unique global. | **(a)**, butuh izin khusus | Tambah `company_id` (nullable) dan `code`. Agar tiap perusahaan punya role "HR" sendiri, unique `nama` harus diganti dengan unique (`company_key`,`nama`). Itu mengubah index lama, jadi **perlu izin**. Risiko: kode lama `Role::where('nama','HR')` dan `User::idsRole('HR')` akan mengambil role/user dari semua perusahaan (notifikasi lama bisa bocor antar perusahaan). |
| `menus` | `menus` | Target: `code`, `route`, `sort_order`, `is_platform`, `is_active`. Sekarang `url` = kode akses. | **(a)** | Menu payroll baru cukup ditambah sebagai baris baru dengan `url` baru (mis. `pay-runs`). Usul tambah satu kolom `is_active` saja, untuk menonaktifkan menu lama setelah modul baru diterima. |

### 6.2 Nama hampir sama (tunggal vs jamak)

Bentrok dengan nama default model Laravel, dan mudah tertukar saat membaca kode.

| Target | Ada sekarang | Perbedaan | Usulan | Alasan |
|---|---|---|---|---|
| `attendances` | `attendance` | Target: 1 baris = hadir, `late_hours`, `import_batch_id`. Sekarang: `status` NOT NULL (hadir/terlambat/alfa), alfa disimpan sebagai baris. | **(b)** `pay_attendances` | Arti baris berbeda (alfa sebagai baris vs alfa diturunkan dari hari kerja). Kalau digabung, rekap lama ikut berubah. |
| `leaves` | `leave` | Target: 1 baris per hari + `leave_type_id`. Sekarang: rentang tanggal + `jenis` varchar(10). | **(b)** `pay_leaves` | Tipe leave baru (mis. `sakit_bersurat`, 14 karakter) tidak muat di varchar(10), dan `PayrollService::rekapKaryawan` lama akan **error** (`$cuti[$jenis]` undefined) bila menemui jenis selain cuti/izin/sakit. |
| `overtimes` | `overtime` | Target: hanya `duration_hours`. Sekarang `jam_mulai`/`jam_selesai` NOT NULL. | **(b)** `pay_overtimes` | CSV lembur di spec tidak punya jam mulai/selesai, jadi baris baru tidak bisa diisi tanpa jam palsu. |
| `payslips` | `payslip` | Target dari snapshot run. Sekarang dari `payroll_detail`. | **(b)** `pay_payslips` | Modul baru dan lama harus bisa jalan berdampingan. |

Konsekuensi (b) untuk absensi/leave/lembur: selama transisi, menu Absensi lama dan dashboard lama **tidak melihat** data yang
diimpor lewat modul baru, dan sebaliknya. Opsi tambahan (bisa diputuskan nanti): aksi satu kali menyalin data lama ke tabel `pay_*` (hanya baca dari tabel lama).

### 6.3 Konsep sama, nama berbeda (duplikasi konsep)

| Target | Padanan sekarang | Usulan | Alasan |
|---|---|---|---|
| `role_menu_permissions` | `privileges` (kolom identik) | **(a)** pakai `privileges` apa adanya | Tidak perlu tabel baru. Hak akses menu baru = baris baru. |
| `employees` | `karyawan` | **(a)** + kolom baru | Users, kontrak lama, dan semua menu memakai `karyawan`. Kolom baru (nullable): `company_id`, `employee_no`, `position_id`, `resign_date`, `ptkp_status`, `has_npwp`, `npwp` (encrypted), `bank_account_no` (encrypted). `no_rekening` lama (teks biasa) tetap untuk modul lama. **Perlu keputusan**: apakah `employee_no` diisi dari `nik` lama (`K0001`)? Lihat §8 soal password slip. |
| `departments` | `departemen` | **(a)** + `company_id`, `is_active` | `kode` unique global: dua perusahaan tidak bisa sama-sama punya departemen `IT`. Mengubahnya = mengubah index lama, **perlu izin**. Alternatif: seed memakai kode berbeda per perusahaan. |
| `positions` | `karyawan.jabatan` (teks) | Tabel baru `pay_positions` + `karyawan.position_id` | `jabatan` lama tetap ada. |
| `contracts`, `contract_components` | `kontrak`, `kontrak_komponen` | **(b)** `pay_contracts`, `pay_contract_components` | Kontrak lama punya alur approval, `gaji_pokok` sebagai kolom, dan FK ke `payslip_component`. Kontrak baru: gaji pokok = komponen, 1 kontrak aktif via generated column, tanpa approval. Menggabungkan keduanya akan mengubah perilaku modul lama. Konsekuensi: kontrak dientri ulang (atau disalin satu kali) di modul baru. |
| `components` | `payslip_component` | **(b)** `pay_components` | Semantik lama (`dasar_bayar`, `rumus`, `tipe_nilai`) berbeda total. |
| `payroll_period_configs` | `master_payroll.tgl_cutoff` | **(b)** `pay_period_configs` | |
| `payroll_masters` + versi/variabel/param/item | `master_payroll` | **(b)** `pay_masters`, `pay_master_versions`, `pay_variables`, `pay_params`, `pay_items` | |
| `payroll_runs`, `payroll_run_employees`, `payroll_run_items`, `payroll_run_reference_versions` | `payroll`, `payroll_detail` | **(b)** `pay_runs`, `pay_run_employees`, `pay_run_items`, `pay_run_reference_versions` | `payroll.periode` unique (1 run per bulan) bertentangan dengan alur baru. |
| `reference_tables/_versions/_rows` | `golongan_ter`, `pajak`, `tarif_ter` | **(b)** `pay_reference_tables`, `pay_reference_table_versions`, `pay_reference_table_rows` | Isi TER dari `database/seeders/data/ter_pph21.csv`, bukan dari `TarifTerSeeder` (§7). |
| `import_batches`, `import_batch_rows` | `import_excel` (log saja) + data di session | **(b)** `pay_import_batches`, `pay_import_batch_rows` | |
| `audit_logs` | `log_aktivitas` (teks bebas) | Tabel baru `audit_logs` (tanpa prefix) | Struktur berbeda (old/new JSON). Bersifat lintas modul, nama belum dipakai. |
| `leave_types`, `holidays`, `tax_monthly_histories` | tidak ada | Tabel baru `pay_leave_types`, `pay_holidays`, `pay_tax_monthly_histories` | |
| `companies` | tidak ada | Tabel baru `companies` (tanpa prefix) | Tenant berlaku untuk seluruh aplikasi. |
| Approval payroll (`users.approver_user_id`, `payroll_runs.approver_user_id`) | `approval`, `approval_mapping`, aturan "Manager HR" | Tidak memakai tabel `approval` lama | `ApprovalService::setujui` lama memanggil `Payroll::findOrFail` → baris approval jenis baru akan merusak alur lama. |

**Ringkasan usulan kebijakan umum** (agar mudah diputuskan sekaligus):
1. Tabel bersama aplikasi (`users`, `roles`, `menus`, `privileges`, `karyawan`, `departemen`) → **(a)**: kolom baru nullable lewat migration baru, tanpa mengubah kolom lama.
2. Semua tabel domain payroll baru → **(b)** prefix `pay_`. Hanya `companies` dan `audit_logs` yang tanpa prefix.
3. Model baru di `app/Domain/Payroll/...` dengan `$table` eksplisit dan global scope `BelongsToCompany` **hanya pada model baru**. Model lama tidak diberi scope.

## 7. Temuan di luar tugas (dilaporkan, tidak diperbaiki)

1. **Test lama gagal sebelum ada perubahan apa pun**: `php artisan test` → 14 test, 2 lulus, **12 error** `could not find driver (sqlite)`.
   Penyebab: `extension=pdo_sqlite` masih dikomentari di `C:\laragon\bin\php\php-8.4.27-Win32-vs17-x64\php.ini` (baris 938).
   **Status: selesai** (lihat §9 butir 7). Baseline sekarang 14/14 lulus di SQLite dan di MySQL test.
2. **Data TER belum ada**: `database/seeders/data/ter_pph21.csv` tidak ditemukan. Sesuai AGENTS.md, seeder TER/PTKP baru tidak akan dibuat sampai file ini Anda isi.
   (`TarifTerSeeder` lama berisi angka TER yang di-*hardcode* tanpa sumber yang bisa diverifikasi; tidak dipakai ulang.)
3. Modul lama memakai `float` untuk uang (`Rumus`, cast `gaji_pokok => float`, `rupiah((float))`). Ini di luar lingkup dan tidak disentuh.
4. `karyawan.no_rekening` disimpan sebagai teks biasa (tidak terenkripsi).
5. Library yang disarankan spec belum terpasang: `mpdf/mpdf`, `league/csv`, `symfony/expression-language`, `phpstan`. Menambah dependensi butuh izin.
6. README dan `composer setup` memakai `migrate:fresh` / `migrate --force`. Bertentangan dengan aturan branch ini; README tidak diubah.
7. `docs/schema.sql` identik dengan `table_schema.sql` di root (duplikat file).
8. `AGENTS.md.old` / `CLAUDE.md.old` berisi instruksi memasang Laravel Boost. **Diabaikan** karena bertentangan dengan "jangan menambah dependensi".

## 8. Keputusan yang menunggu Anda

1. **Konflik skema §6**: setujui kebijakan umum di akhir §6, atau pilih per baris.
2. **Mengubah unique index lama** `roles.nama` dan `departemen.kode` menjadi unique per perusahaan (boleh atau tidak?).
3. **Data lama tanpa perusahaan**: usul membuat satu perusahaan default (mis. `DEFAULT`) dan mengisi `company_id` baris lama (`users`, `roles`, `karyawan`, `departemen`) dengan id itu lewat migration (UPDATE yang bisa di-rollback ke NULL). Setuju?
4. **Role**: nama sekarang `Admin`/`Karyawan`, sedangkan spec/scope memakai `Administrator`/`employee`. Spec juga menambah role **Super Admin** (`company_id NULL`). Menambah role baru butuh izin Anda.
5. **Approver payroll**: model lama "Manager HR = manager departemen HRD (bisa >1, cukup satu yang approve)" vs spec "`users.approver_user_id` per user HR". Ikut spec?
6. **Password PDF slip**: "2 digit pertama `employee_no`". Nomor karyawan sekarang berformat `K0001` (huruf). Apakah `employee_no` baru harus numerik, atau 2 karakter pertama apa adanya?
   Library PDF: tambah `mpdf/mpdf` (spec), atau pakai proteksi dompdf (`$canvas->get_cpdf()->setEncryption(...)`, tanpa dependensi baru)?
7. **Test**: aktifkan `pdo_sqlite` di php.ini lokal (disarankan), atau test di MySQL terpisah.
8. **File TER**: isi `database/seeders/data/ter_pph21.csv` (diperlukan paling lambat Fase 5/8).
9. **Login**: tetap `username` (usulan, agar aplikasi lain tidak rusak) atau pindah ke email seperti spec?

## 9. Keputusan (2026-11-11)

| # | Keputusan |
|---|---|
| 1 | Kebijakan umum §6 **disetujui**: tabel bersama → (a) kolom baru nullable; tabel domain payroll → (b) prefix `pay_`; `companies` dan `audit_logs` tanpa prefix; scope tenant hanya di model baru. |
| 2 | Unique `roles.nama` dan `departemen.kode` **boleh** diubah menjadi unique per perusahaan. |
| 3 | Dibuat perusahaan default; `company_id` baris lama diisi id perusahaan itu. |
| 4 | Role lama **tidak di-rename**. Ditambah role **Super Admin** (`company_id NULL`). |
| 5 | Approver payroll memakai `users.approver_user_id`. Isi awal: user HR → user Manager HR (manager departemen `HRD` yang punya akun; bila lebih dari satu, id user terkecil). |
| 6 | PDF slip memakai **dompdf** (tanpa mpdf). Password = **2 digit terakhir nomor karyawan + 4 digit terakhir nomor rekening**. *Ini menyimpang dari `PAYROLL_SPEC.md` (spec: 2 digit pertama); keputusan Anda menggantikan spec.* |
| 7 | `pdo_sqlite` diaktifkan (php.ini Laragon, backup `php.ini.bak-2026-11-11`). Dibuat DB MySQL khusus test `penggajian_test` + `phpunit.mysql.xml` (`force="true"` agar tidak pernah menyentuh DB dev). |
| 8 | Login tetap memakai `username`. |

Cara menjalankan test:

```bash
php artisan test                                      # SQLite :memory: (cepat)
php artisan test --configuration=phpunit.mysql.xml    # MySQL penggajian_test (generated column, CHECK, perilaku MySQL)
```

## 10. Tambahan setelah AGENTS.md diperbarui (Fase 1a, 2026-11-11)

- **Pembaruan kebijakan §6 butir 3**: AGENTS.md sekarang mewajibkan scope tenant di **semua** model, termasuk model lama. Kalimat "scope hanya di model baru" di §6/§9 tidak berlaku lagi.
- **Klasifikasi tabel** (dipakai migration Fase 1a):
  - Bertenant (`company_id`, 21 tabel): `users`, `roles`, `privileges`, `karyawan`, `departemen`, `departemen_manager`, `kontrak`, `kontrak_komponen`, `attendance`, `leave`, `overtime`, `notifikasi`, `approval`, `approval_mapping`, `log_aktivitas`, `import_excel`, `payslip_component`, `master_payroll`, `payroll`, `payroll_detail`, `payslip`.
    `users`, `roles`, `privileges` tetap boleh `company_id NULL` (level platform / Super Admin).
  - Global: `menus` (sesuai `docs/schema.sql`).
  - Nasional: `golongan_ter`, `pajak`, `tarif_ter` (tanpa kolom `company_id`).
  - Infrastruktur Laravel: tidak bertenant.
- **Unique index yang diganti per perusahaan**: `roles.nama` (via `company_key`), `departemen.kode`, `karyawan.nik`, `kontrak.nomor`, `master_payroll.periode`, `payroll.periode`. `users.username` tetap global.
- **Temuan untuk fase 1c/1d** (belum diperbaiki):
  - Menu "Pajak & Tarif TER" saat ini bisa diubah HR (`privileges` HR = vcud). Bila tabel TER nasional hanya boleh diubah Super Admin, hak HR berubah. Ini **perubahan perilaku lama** dan perlu keputusan.
  - Validasi controller lama masih unique global (mis. `unique:karyawan,nik`), lebih ketat dari DB. Akan diaudit di 1c.
  - `Kontrak::buatNomor()` menghitung urutan dari semua kontrak (lintas perusahaan). Perlu dibatasi per perusahaan di 1c.
- `docs/schema.sql` mendefinisikan `roles.company_key` sebagai STORED. Migration memakai VIRTUAL karena kolom STORED tidak bisa ditambah lewat ALTER di SQLite (unique index tetap berfungsi sama).

## 11. Keputusan Fase 1a (2026-11-11)

| # | Keputusan |
|---|---|
| 1 | Migration 1a disetujui dan **sudah dijalankan di DB dev** setelah backup `storage/app/private/backups/penggajian_karyawan-sebelum-1a-20261111-151418.sql` (mysqldump, 33 tabel). Hasil: 0 baris `company_id NULL` di 21 tabel bertenant. |
| 2 | Klasifikasi tabel §10 disetujui. TER/PTKP nasional; **HR boleh kehilangan hak ubah menu "Pajak & Tarif TER"** (diterapkan di 1d). |
| 3 | Sebelum 1b dibuat **tes karakterisasi otomatis**: halaman utama per role (status + isi) dan alur payroll lama. |
| 4 | `scope.md`, `table_schema.sql`, `AGENTS.md.old`, `CLAUDE.md.old` dibiarkan tidak di-commit. |

## 12. Tes karakterisasi (sebelum 1b) dan temuan baru

- Tes di `tests/Feature/Karakterisasi/` merekam perilaku aplikasi lama dengan data `DatabaseSeeder` (deterministik) pada waktu terkunci **2026-11-11 10:00 WIB**:
  - `HalamanPerRoleTest`: 5 user (`admin`, `hr`, `manager.hr`, `manager.it`, `rizky`) × 53 URL GET (daftar, form, detail, PDF, JSON). Direkam: status, redirect, content-type, isi HTML (token CSRF dinormalisasi).
  - `AlurLamaTest`: generate → ajukan → Manager HR setujui (slip terbit, nominal per karyawan), tolak tanpa/dengan catatan → generate ulang, kunci absensi di periode final, approval perpanjangan kontrak, import absensi CSV (baris error → preview → simpan).
  - Snapshot per driver di `__snapshots__/{sqlite,mysql}/`. Memperbarui snapshot hanya dengan sengaja: `KARAKTERISASI_UPDATE=1`, dan perubahannya harus dijelaskan.
  - Sudah diuji: dua kali jalan berturut-turut identik; snapshot yang diubah sengaja terdeteksi dengan diff.
- **Temuan baru (lingkungan)**: ekstensi PHP `zip` tidak aktif (`;extension=zip` di php.ini Laragon, baris 952). Akibatnya **export Laporan Excel dan unduh template import .xlsx gagal** (`Class "ZipArchive" not found`) di lingkungan lokal ini, sudah sebelum perubahan apa pun. Tes Excel ditandai *skipped* sampai `zip` diaktifkan. Perlu keputusan pemilik.

## 13. Fase 1b: scope tenant di model lama (2026-11-11)

**Desain konteks perusahaan** (`App\Domain\Company\Tenancy\CurrentCompany`), urutan:
1. `runAs($id)` eksplisit (seeder, job, login sebelum user terautentikasi).
2. User login → `company_id` user (dibaca dengan `Auth::hasUser()`, tanpa memicu query user).
3. Request web tanpa user → tidak ada konteks: query kosong, create ditolak (*fail closed*). Penanda request: middleware `TenantRequestContext`, dipasang paling depan grup `web` agar route model binding ikut terbatasi.
4. Fallback → hanya dipasang `tests/TestCase.php` (perusahaan DEFAULT) untuk kode test di luar request.
5. Console tanpa konteks (artisan, scheduler, migration) → mode sistem: baca tidak dibatasi, create ditolak (wajib `runAs`). Contoh: `kontrak:aktifkan` tetap memproses semua kontrak (logikanya per karyawan, jadi aman lintas perusahaan).

**Perubahan di kode lama** (hanya yang dibutuhkan tenant):
- 20 model di `app/Models` + pivot baru `DepartemenManager`: `use BelongsToCompany;` (3 baris per model). `Departemen::managers()` memakai `->using(DepartemenManager::class)` agar `sync()` mengisi `company_id`.
- `config/auth.php`: driver `tenant-eloquent` (`TenantAwareUserProvider`, mencari user tanpa scope; username unik global).
- `AuthController`: login & lupa password mencari username tanpa scope, lalu proses lama dijalankan dalam konteks perusahaan user (penghitung gagal login, kunci akun, notifikasi Admin perusahaan yang sama).
- `DatabaseSeeder`: dijalankan dalam `runAs(DEFAULT)`; dua `DB::table()->insert` (attendance, overtime) diberi `company_id` eksplisit.
- `bootstrap/app.php`, `bootstrap/providers.php`: middleware dan provider tenant.

**Hasil**: semua tes karakterisasi (halaman per role, alur payroll/absensi/kontrak/import, login, export Excel) lulus **tanpa snapshot diubah**.

**Temuan untuk 1c** (belum diperbaiki):
- Validasi masih global: `Rule::unique('departemen')` di `DepartemenController` (kode yang sama di perusahaan lain ditolak), `exists:karyawan,id` untuk `manager_ids` (HR perusahaan B bisa memilih karyawan perusahaan A sebagai manager). Pola sama perlu dicek di semua controller (`unique:`, `exists:`).
- `User::idsRole()` dan `Role::where('nama', ...)` sudah terbatasi lewat scope dalam request, tetapi perlu dicek pemakaiannya di luar request (notifikasi dari console/job).
- File PDF slip disimpan di `slip/{periode}/{nik}.pdf` tanpa folder per perusahaan (NIK bisa sama antar perusahaan → file tertimpa).
- Cache dan job: belum ada pemakaian di kode lama (dicek ulang di 1c).
- **Urutan tidak deterministik (ditemukan saat 1b di MySQL)**: `OvertimeController@index` (`orderByDesc('tanggal')`) dan `PayslipController@index` (`orderByDesc('payroll.periode')`) hanya mengurutkan satu kolom yang nilainya banyak kembar, dengan `paginate(10)`. Baris mana yang tampil di halaman 1 tidak ditentukan; setelah filter `company_id` ditambahkan, MySQL memilih rencana query lain sehingga isi halaman 1 `/lembur` dan `/slip` (role HR, Manager HR) berbeda dari snapshot. **Data dan jumlah baris sama**, hanya pilihan baris kembar yang berbeda. SQLite tidak terpengaruh. Menunggu keputusan pemilik (lihat ringkasan 1b).

## 14. Keputusan 1b (2026-11-11)

| # | Keputusan |
|---|---|
| 1 | Urutan tidak deterministik (§13): **ditambah tiebreaker `id`** di `OvertimeController@index` (`orderBy('id')`) dan `PayslipController@index` (`orderBy('payslip.id')`), lalu snapshot terkait dibuat ulang. |
| 2 | **Migration dan seeder boleh membaca lintas perusahaan.** |
| 3 | **Artisan dan scheduler tidak boleh melewati scope secara umum**: tugas berjalan per perusahaan dengan konteks eksplisit (`runAs`). Lintas perusahaan hanya lewat pemanggilan eksplisit `CurrentCompany::lintasPerusahaan($alasan, ...)` yang diberi komentar alasannya. |

**Penerapan** (menggantikan butir 5 desain di §13):
- Console tanpa konteks sekarang *fail closed* (query kosong, create ditolak), sama seperti request web tanpa user.
- `CurrentCompany::lintasPerusahaan($alasan, fn)` membaca semua perusahaan; alasan wajib (string kosong ditolak); create di dalamnya tetap ditolak. Bingkai terdalam berlaku (`runAs` di dalam `lintasPerusahaan` membatasi lagi ke satu perusahaan).
- Migration otomatis lintas perusahaan lewat event `MigrationsStarted`/`MigrationsEnded` (`TenancyServiceProvider`).
- Seeder: `DatabaseSeeder` membuat data dalam `runAs(DEFAULT)`; bila seeder perlu membaca lintas perusahaan, pakai `lintasPerusahaan()` dengan alasan.
- `kontrak:aktifkan` (scheduler 00:05): diulang per perusahaan dengan `runAs`, semua perusahaan diproses seperti sebelumnya.

## 15. Fase 1c: query yang melewati scope (2026-11-11)

| Jenis | Temuan | Tindakan |
|---|---|---|
| `DB::table`, `DB::select`, raw | Tidak ada di `app/` | – (seeder sudah diperbaiki di 1b) |
| Validasi `unique:`/`exists:` | 15 aturan di 10 controller memakai query builder (lintas perusahaan) | Diganti `TenantRule::unique/exists` (dibatasi perusahaan aktif). Tetap global dengan sengaja: `users.username`, `users.karyawan_id`, tabel nasional `pajak`/`golongan_ter`, `menus`. |
| Join manual | `payslip` ⋈ `payroll` (PayslipController, CariController, DashboardController) | Aman: basis query `Payslip` ber-scope, join lewat FK ke data perusahaan yang sama. |
| File | PDF slip `slip/{periode}/{nik}.pdf` | Menjadi `slip/{company_id}/{periode}/{nik}.pdf`. Slip lama tetap memakai path yang tersimpan di `payslip.file_pdf`. |
| Export | Excel/PDF laporan dihitung sebelum stream dari query ber-scope | Aman. |
| Cache, job, event | Tidak dipakai di kode lama | – (aturan konteks untuk job baru: wajib `runAs`). |

## 16. Fase 1d: Super Admin, pemilihan perusahaan, privilege per perusahaan (2026-11-11)

- **Role platform** "Super Admin" (`company_id NULL`) + menu global **Perusahaan** (`url perusahaan`) + hak Super Admin (dashboard v; perusahaan, pajak, users, roles, menus vcud). Isinya di `PasangHakPlatform` (idempoten), dipanggil migration `2026_11_12_000001` (hanya instalasi berisi data), `DatabaseSeeder` (instalasi baru), dan `super-admin:buat`.
- **Akun Super Admin** hanya dibuat lewat `php artisan super-admin:buat {username} [--email=] [--password=]` (tidak bisa dari UI).
- **Pemilihan perusahaan**: Super Admin memilih perusahaan aktif di menu Perusahaan (disimpan di sesi). Tanpa pilihan, data bertenant tertutup. Label sidebar: "Super Admin · KODE".
- **Perusahaan baru** (`BuatPerusahaan`): role, hak akses, dan approval mapping disalin dari DEFAULT sebagai templat; setelah itu diatur Admin perusahaan masing-masing.
- **Pajak & Tarif TER**: HR (semua perusahaan) lihat saja; tombol tambah/ubah/hapus disembunyikan bagi yang tidak punya hak ubah. Super Admin vcud.
- **Pengamanan menu platform**: `Menu::untukPerusahaan()` menyembunyikan menu Perusahaan dari editor hak akses role, Manajemen Menu, dan hitungan di dashboard Admin; ubah/hapus menu platform hanya Super Admin; route `/perusahaan` memakai middleware `super-admin` (lapisan kedua, terlepas dari isi `privileges`).
- **Login akun platform**: diproses lintas perusahaan dengan alasan tercatat; notifikasi "akun terkunci"/"lupa password" untuk akun Super Admin **tidak** dikirim ke Admin perusahaan (mencegah bocornya akun platform).
- Kode lama yang disentuh: `User::role()` dan `Privilege::cekAkses()` tanpa scope (dibatasi `role_id` milik user sendiri), `DashboardController` (Super Admin diarahkan ke Perusahaan; hitungan menu), `RoleController`/`MenuController` (menu platform), `AppServiceProvider` (label), view Pajak (tombol ubah).
- **Snapshot karakterisasi yang berubah (disengaja)**: halaman Pajak untuk HR dan Manager HR (tombol ubah hilang, keputusan §11 butir 2). Semua snapshot lain identik.
- **Menunggu keputusan**: Manajemen Menu bersifat global, tetapi Admin tiap perusahaan masih bisa menambah/mengubah/menghapus menu non-platform (berdampak ke semua perusahaan). Usul: Manajemen Menu khusus Super Admin. Belum diubah.

## 17. Fase 1e: tes kebocoran tenant dan company_id NOT NULL (2026-11-11)

- **Tes kebocoran** (`KebocoranTenantTest` + fixture `DataPerusahaanLengkap`): perusahaan kedua diisi data di 21 tabel bertenant dengan penanda `RAHASIA-B`. Daftar URL diambil otomatis dari router (semua GET, parameter diisi id), ditambah varian cari/filter/export:
  - 5 user perusahaan A membuka semua URL: penanda B tidak pernah muncul (HTML, JSON `/cari`, isi Excel; PDF dilewati karena terkompresi, datanya dari query yang sama).
  - id milik B dibuka dari A: tidak pernah tampil (403/404, atau redirect ke data A).
  - 17 route model binding milik B selalu 403/404.
  - user perusahaan B (4 role) tidak melihat penanda data A.
  - Uji mutasi: tanpa `BelongsToCompany` di `Karyawan`, tes gagal di `/users/create` dan `/karyawan/{id}` (tes terbukti mendeteksi kebocoran).
- **Migration `2026_11_12_000002_company_id_not_null`**: 18 tabel data perusahaan menjadi NOT NULL (`users`, `roles`, `privileges` tetap nullable untuk data platform). Menolak berjalan bila masih ada NULL. Diverifikasi di DB test MySQL berisi data: rollback hanya mengubah nullability 18 kolom tersebut.
- Hasil: 77 tes lulus di SQLite dan MySQL.

### Migration yang menunggu persetujuan untuk DB dev
| Migration | Mengubah data lama? |
|---|---|
| `2026_11_12_000001_super_admin_dan_menu_perusahaan` | Ya (privileges HR Pajak, role/menu baru) → backup dulu |
| `2026_11_12_000002_company_id_not_null` | Tidak (hanya constraint) |

## 18. Fase 2: data master payroll baru (2026-11-11)

**Skema** (migration baru, belum dijalankan di DB dev):
- `2026_11_13_000001_create_pay_master_tables`: `audit_logs`, `pay_period_configs` (satu per perusahaan), `pay_holidays`, `pay_positions`, `pay_leave_types`, `pay_components` (uang `DECIMAL(15,2)`), unique per perusahaan.
- `2026_11_13_000002_add_payroll_columns_to_karyawan`: kolom baru di `karyawan`: `position_id`, `resign_date`, `has_npwp`, `npwp` (terenkripsi), `bank_account_no` (terenkripsi, diisi dari `no_rekening`). Kolom lama tidak diubah.
- `2026_11_13_000003_menu_payroll_baru`: grup menu "Payroll Baru" + 6 menu, **tanpa hak akses bawaan**.

**Keputusan pemetaan (mengikuti jawaban pemilik soal "nomor karyawan")**: `karyawan.nik` (mis. `K0001`) dipakai sebagai nomor karyawan (`employee_no`) sekaligus kunci pencocokan CSV; status PTKP dari `pajak_id` (`pajak.status_ptkp`); tidak ada kolom duplikat.

**Rekening**: modul lama tetap memakai `no_rekening` (teks biasa). `PayrollServiceProvider` menyalin terenkripsi ke `bank_account_no` setiap `no_rekening` berubah. Tampilan payroll hanya menampilkan 4 digit terakhir.

**Aktivasi modul**: `php artisan payroll:aktifkan {kode_perusahaan}` memberi hak bawaan (HR kelola, Admin lihat) ke role perusahaan itu. Sampai diaktifkan, sidebar dan alur lama tidak berubah. Admin juga bisa memberi hak lewat Role & Privilege.

**Halaman** (`/pay/...`): Karyawan Payroll, Jabatan, Kalender Libur, Tipe Leave, Katalog Komponen, Cut Off. Jabatan/tipe leave/komponen hanya dinonaktifkan; hari libur boleh dihapus. Semua perubahan dicatat `AuditLogger` (NPWP/rekening tidak pernah ditulis ke log). Nilai uang divalidasi `decimal:0,2` dan disimpan tanpa float.

**Snapshot karakterisasi yang berubah (disengaja)**: halaman Admin (hitungan menu di dashboard, daftar Manajemen Menu, baris menu baru tanpa centang di Role & Privilege). Snapshot role lain identik.

**Tes**: `MasterDataTest` (6), tes kebocoran diperluas ke halaman `/pay/*` (uji mutasi: daftar tanpa scope terdeteksi di `/pay/jabatan`).

## 19. Fase 3: kontrak berversi (2026-11-11)

- Migration `2026_11_14_000001_create_pay_contracts_tables` (belum dijalankan di DB dev): `pay_contracts` (kolom generated STORED `active_employee_id` + unique = 1 kontrak aktif per karyawan; CHECK tanggal di MySQL) dan `pay_contract_components` (dengan `company_id`). Menu "Kontrak Payroll" tanpa hak bawaan.
- Action: `BuatKontrak` (nilai default katalog **disalin**; hanya komponen aktif & `is_contractable`), `PerbaruiKontrak` (kontrak lama nonaktif, versi baru dengan `previous_contract_id`, nilai disalin), `NonaktifkanKontrak` (manual). Semua dalam transaksi dan tercatat di `audit_logs`.
- Tidak ada ubah kontrak di tempat; kontrak kedaluwarsa ditandai "Kedaluwarsa" dan dinonaktifkan manual oleh HR.
- Tes: `KontrakTest` (5), tes kebocoran mencakup `/pay/kontrak?lihat=`/`?perbarui=` id perusahaan lain. Snapshot Admin berubah (+1 menu).

## 20. Fase 4: absensi, leave, lembur + import CSV (2026-11-11)

- Migration `2026_11_15_000001_create_pay_attendance_tables` (belum dijalankan di DB dev): `pay_attendances`, `pay_leaves` (satu baris per hari), `pay_overtimes` (CHECK durasi > 0 di MySQL), `pay_import_batches`, `pay_import_batch_rows`; semua ber-`company_id`. Menu Absensi/Leave/Lembur/Import Payroll tanpa hak bawaan.
- `ValidatorKehadiran` dipakai import CSV **dan** input manual: NIK harus ada & aktif di perusahaan aktif; tipe leave aktif; format tetap (tanggal `YYYY-MM-DD`, jam `HH:MM`, angka maks. 2 desimal, 0–24); absensi dan leave di tanggal sama ditolak (dua arah, termasuk di dalam file yang sama); duplikat di file ditolak; leave rentang dipecah per hari kerja (Sabtu/Minggu dilewati); lembur boleh lebih dari satu per hari.
- Alur import: unggah (CSV koma/titik koma, BOM Excel ditangani, header wajib sesuai template, maks. 5.000 baris) → preview (baris error merah) → perbaiki/hapus baris (validasi ulang seluruh batch) → simpan hanya bila semua baris valid (divalidasi ulang dalam transaksi) / batalkan. Template CSV bisa diunduh.
- Penguncian periode final: titik pengecekan `KunciPeriode` sudah dipakai validator dan ubah/hapus manual; logikanya diisi di Fase 6.
- **Keputusan menunggu konfirmasi**: import karyawan (`type = employees` di schema) **tidak** dibuat di modul baru; data inti karyawan tetap dari menu dan import lama.
- Temuan teknis: default route Laravel ditambahkan setelah parameter URL, sehingga parameter controller skalar terisi berdasarkan posisi; controller kehadiran membaca `jenis` dari route by name.
- Tes: `KehadiranImportTest` (5), tes kebocoran mencakup halaman dan id kehadiran/import perusahaan lain.

## 21. Fase 5: tabel referensi dan mesin rumus (2026-11-11)

**Skema** (migration `2026_11_16_000001`, belum dijalankan di DB dev): `pay_reference_tables` (+`company_key` STORED, `company_id NULL` = nasional), `pay_reference_table_versions`, `pay_reference_table_rows`, `pay_masters`, `pay_master_versions`, `pay_variables`, `pay_params`, `pay_items` (+ CHECK di MySQL sesuai schema). Menu Tabel Referensi & Master Payroll tanpa hak bawaan.

**Mesin rumus** (`app/Domain/Payroll/Engine`):
- Parser sendiri (tanpa `eval`, tanpa SQL dari input HR), hasil AST di-cache. Operator `+ - * / = != <> < <= > >= AND OR NOT`, fungsi whitelist: `IF` (malas), `MIN`, `MAX`, `ROUND` (half-up), `FLOOR`, `CEIL`, `ABS`, `WORKDAYS` (Senin-Jumat minus libur perusahaan), `DAYS`, `MONTHS_BETWEEN`, `LOOKUP`, `LOOKUP_TEXT`, `CONTRACT('kode')`.
- Semua angka bcmath (string) skala 20 digit; nilai akhir tiap komponen dibulatkan half-up ke rupiah.
- Variabel `base`: baris sumber diambil dengan query Eloquent terparameter (absensi, leave, lembur, libur, karyawan, kontrak dalam periode), lalu `filter_expression`/`value_expression` dievaluasi per baris memakai mesin yang sama atas field whitelist (`row.date`, `row.type`, `row.hours`, ...); agregat count/sum/avg/min/max/count_distinct_date.
- Variabel bawaan: `period_start`, `period_end`, `join_date`, `resign_date`, `ptkp_status`, `has_npwp`, `workdays`; `gross_taxable` khusus komponen PPh21.
- Urutan: parameter → variabel (sort_order) → komponen normal (sort_order) → komponen `pph21` selalu terakhir (bruto = nilai akhir penambah kena pajak). Override (Fase 6) dipakai sebagai nilai akhir dan mengalir ke komponen sesudahnya.
- `ValidasiMaster`: rumus hanya merujuk yang ada **di atasnya** (mencegah sirkular), kode unik lintas variabel/parameter/komponen, field `row.x` sesuai sumber, komponen di `CONTRACT()` dan tabel di `LOOKUP()` harus ada, maksimal satu PPh21.
- Tabel referensi: milik perusahaan didahulukan, lalu nasional; versi terbit yang berlaku pada akhir periode (`effective_from`); versi yang dipakai dicatat (untuk Fase 6).

**Alur**: master dibuat dengan versi 1 draft → isi variabel/parameter/komponen (nama, jenis, kena pajak disalin dari katalog) → terbit hanya bila valid → versi terbit tidak bisa diubah, salin ke versi baru. Uji rumus untuk satu karyawan dan periode di halaman versi. Tabel nasional hanya dibuat/diubah Super Admin (`BelongsToCompanyOrNational`).

**Tes**: `MesinRumusTest` (49, unit), `KalkulatorTest` (5, skenario Perusahaan A dengan tarif TER **fiktif**), `MasterReferensiTest` (2), tes kebocoran mencakup halaman referensi/master perusahaan lain.

**Catatan proses**: percobaan menjalankan tes MySQL paralel lewat git worktree dihentikan: `vendor` berbagi (junction) membuat autoload Composer memakai kode folder utama, jadi hasilnya bukan verifikasi commit yang terisolasi. Tes MySQL kembali dijalankan berurutan.

## 22. Fase 6: alur payroll (2026-11-11)

- Migration `2026_11_17_000001_create_pay_runs_tables` (belum dijalankan di DB dev; **mengubah data `users`**: backup dulu): `users.approver_user_id` (+ isi awal HR → Manager HR perusahaan yang sama), `pay_runs`, `pay_run_employees` (kolom generated `blocking_key` + unique `uq_pre_one_active_per_period`), `pay_run_items`, `pay_run_reference_versions`, `pay_tax_monthly_histories`. Menu Generate Payroll, Approval Payroll, Approver Payroll tanpa hak bawaan.
- Alur: buat run (periode dari cut off) → preview lewat queue job (`ProsesPratinjau`, per potongan 200 karyawan, konteks perusahaan eksplisit) → ubah sel (override, tanpa audit log; komponen turunan termasuk PPh21 dihitung ulang dari nilai override; "kembalikan ke hasil rumus") → generate lewat queue (`ProsesGenerate`: hitung ulang data terbaru dengan override dipertahankan, `is_blocking = 1`, status `pending_approval`, approver dari `users.approver_user_id`, kunci versi master dan versi tabel referensi yang dipakai) → Manager approver menyetujui (status `approved`, isi `pay_tax_monthly_histories`, event `PayrollDisetujui` untuk Fase 7) atau menolak dengan alasan (`is_blocking = 0`, karyawan bebas untuk payroll baru).
- Hanya karyawan aktif dengan kontrak `is_active = 1`; yang sudah punya payroll aktif di periode beririsan, tanpa kontrak, atau rumusnya error dilewati dan dicatat di `skipped_employees`. Peringatan kontrak kedaluwarsa ditampilkan sebelum preview.
- Draft dihapus fisik; selain draft tidak bisa dihapus/diubah. Hanya approver yang bisa melihat dan memutuskan (user lain 404/403).
- `KunciPeriode` diisi: absensi/leave/lembur di periode karyawan yang sudah di-generate (pending/approved) ditolak; run yang ditolak melepas kunci.
- Halaman Approver Payroll (Admin): mengatur approver tiap user HR (harus Manager aktif, bukan diri sendiri).
- Tes: `AlurPayrollTest` (5), fixture `SiapkanPayroll`; tes kebocoran mencakup halaman run/approval.
- **Menunggu keputusan**: Manager HR yang juga membuat payroll (role Manager dengan hak HR di modul lama) belum dicakup halaman Approver Payroll, karena halaman itu hanya menampilkan user ber-role HR.

## 23. Fase 7: slip PDF berpassword dan akses karyawan (2026-11-11)

- Migration `2026_11_18_000001_create_pay_payslips_table` (belum dijalankan di DB dev): `pay_payslips` (satu per karyawan per run). **Tambahan di luar schema**: kolom `note` untuk alasan PDF belum terbit. Menu "Slip Gaji Payroll" tanpa hak bawaan (bawaan saat diaktifkan: Karyawan, Manager, HR lihat).
- Penerbitan: event `PayrollDisetujui` → job `TerbitkanSlip` (konteks perusahaan eksplisit) → baris slip + PDF dompdf dari **snapshot run** (bukan kontrak/master terkini): nama & alamat perusahaan, data karyawan, komponen penambah/pengurang dengan nilai akhir, THP, penanda tangan + gambar tanda tangan (`companies.signer_*`, `signature_path`).
- Password PDF (keputusan pemilik): 2 digit **terakhir** angka nomor karyawan + 4 digit terakhir nomor rekening (`PasswordSlip`; rekening didekripsi hanya saat membuat PDF). Enkripsi lewat CPDF dompdf (`setEncryption`, hak cetak), owner password acak per file. Bila nomor karyawan/rekening tidak cukup, PDF **tidak** diterbitkan tanpa password; slip diberi catatan.
- File di disk privat `payslips/{company_id}/{run_id}/{nomor}.pdf`; tanda tangan di `signatures/{company_id}/` (diunggah Super Admin di menu Perusahaan).
- Akses: karyawan hanya slip miliknya (`employee_id = users.karyawan_id`), user dengan hak lihat Generate Payroll (HR) melihat slip perusahaannya; slip perusahaan lain 404.
- Tes: `SlipTest` (7), tes kebocoran mencakup slip & unduh perusahaan lain.

## 24. Fase 8: seed data demo dan uji akhir (2026-11-11)

- `PayrollDemoSeeder` (manual: `php artisan db:seed --class=PayrollDemoSeeder`; tidak masuk `DatabaseSeeder`, tidak menghapus data, menolak berjalan dua kali). Butuh data `DatabaseSeeder` (role perusahaan DEFAULT sebagai templat).
  - Perusahaan **DEMOA** (PT Demo Sejahtera, 12 karyawan, cut off lintas bulan 26–25) dan **DEMOB** (PT Contoh Makmur, 10 karyawan, cut off kalender). Role Admin/HR/Manager/Karyawan, menu payroll baru aktif. Login: `hr.demoa`, `manager.demoa`, `admin.demoa`, `staf.demoa` (dan `.demob`), password `password`. Approver HR = manager perusahaannya.
  - 2 departemen, 2 jabatan, kontrak aktif dengan nilai berbeda per karyawan, 4 tipe leave (termasuk `sakit_bersurat`), absensi/leave/lembur Sep–Nov 2026 dengan alpha, terlambat, lembur hari kerja dan akhir pekan.
  - Master "Payroll Usual" **berbeda**: DEMOA uang makan `kontrak / 22 × (hadir + cuti)`, potongan alpha, lembur Rp25.000/jam, BPJS dari kontrak; DEMOB lembur 1,5×/2× upah per jam (pembagi 173, hari kerja vs akhir pekan/libur), potongan terlambat Rp20.000/jam, tunjangan transport dari kontrak.
  - **Libur nasional 2026**: hanya yang bertanggal tetap (1 Jan, 1 Mei, 1 Jun, 17 Agu, 25 Des). Libur keagamaan/lunar mengikuti SKB 3 Menteri dan **harus ditambahkan HR**; tanggalnya tidak dikarang.
  - File contoh import: `database/seeders/data/contoh_import/{absensi,leave,lembur}_{valid,error}.csv` (NIK perusahaan DEMOA, periode Desember 2026).
- **TER/PTKP**: `database/seeders/data/ter_pph21.csv` **belum ada**, jadi tabel TER dan komponen PPh21 dilewati (seeder memberi peringatan). Format yang dibaca seeder:
  ```
  tabel,kunci,batas_bawah,batas_atas,hasil_angka,hasil_teks
  kategori_ptkp,TK/0,,,,A            <- status PTKP -> kategori TER
  ter_bulanan,A,0,5400000,0,          <- kategori, rentang bruto, tarif (desimal, mis. 0.0025 untuk 0,25%)
  ```
  (baris di atas hanya contoh format; angkanya wajib dari PP 58/2023 / PMK 168/2023). Bila file tersedia, seeder membuat tabel nasional `ter_bulanan` dan `kategori_ptkp` lalu memasang komponen PPh21 `ROUND(gross_taxable * LOOKUP('ter_bulanan', LOOKUP_TEXT('kategori_ptkp', ptkp_status), gross_taxable))`.
- Tes: `PayrollDemoSeederTest` (3): data dua perusahaan, isolasi, aturan berbeda per perusahaan dihitung ulang dari snapshot variabel, jalur dengan file TER (berisi angka uji fiktif di file sementara).

## 25. Ringkasan akhir dan yang menunggu pemilik

### Migration yang belum dijalankan di DB dev (urut)
| Migration | Ubah data lama? |
|---|---|
| `2026_11_12_000001_super_admin_dan_menu_perusahaan` | Ya (hak Pajak HR, role/menu baru) |
| `2026_11_12_000002_company_id_not_null` | Tidak |
| `2026_11_13_000001_create_pay_master_tables` | Tidak |
| `2026_11_13_000002_add_payroll_columns_to_karyawan` | Ya (kolom baru `bank_account_no` diisi terenkripsi dari `no_rekening`) |
| `2026_11_13_000003_menu_payroll_baru` | Menu baru saja |
| `2026_11_14_000001_create_pay_contracts_tables` | Tidak |
| `2026_11_15_000001_create_pay_attendance_tables` | Tidak |
| `2026_11_16_000001_create_pay_reference_and_master_tables` | Tidak |
| `2026_11_17_000001_create_pay_runs_tables` | Ya (`users.approver_user_id` isi awal) |
| `2026_11_18_000001_create_pay_payslips_table` | Tidak |

Semua diuji up → rollback → up di DB `penggajian_test` berisi data. Saran: backup lalu jalankan sekaligus, kemudian `php artisan payroll:aktifkan DEFAULT` bila modul baru ingin dicoba di perusahaan DEFAULT, dan `php artisan super-admin:buat {username}` untuk akun Super Admin. Queue: `php artisan queue:work --tries=1 --timeout=0` (preview, generate, slip berjalan di background).

### Keputusan yang menunggu
1. **File `ter_pph21.csv`** (format di §24) dari peraturan resmi.
2. **Manajemen Menu** global masih bisa diubah Admin tiap perusahaan (§16). Usul: khusus Super Admin.
3. **Import karyawan** di modul baru tidak dibuat; data inti karyawan tetap dari modul lama (§20).
4. **Approver untuk Manager HR** yang membuat payroll (§22).
5. **Libur keagamaan 2026** diisi HR dari SKB 3 Menteri (§24).
6. **Modul payroll lama**: masih aktif berdampingan; dinonaktifkan setelah modul baru diterima (lewat hak akses menu, tanpa menghapus kode/data).
7. Tambahan di luar `docs/schema.sql` yang perlu disetujui: `pay_payslips.note`, `company_id` di tabel anak (`pay_contract_components`, `pay_import_batch_rows`, versi/baris referensi, variabel/parameter/item), `roles.company_key` VIRTUAL.

## 26. Keputusan akhir pemilik dan tindak lanjut (2026-11-11)

| # | Keputusan | Tindak lanjut |
|---|---|---|
| 1 | Backup DB dev sudah dibuat pemilik; 10 migration (§25) disetujui | **Sudah dijalankan di DB dev**: 0 pending, 120/120 rekening terenkripsi, role Super Admin + 17 menu payroll baru, HR Pajak lihat-saja, approver HR = `manager.hr`, data lama utuh. |
| 2 | Manajemen Menu khusus Super Admin | `PasangHakPlatform` mencabut hak role perusahaan atas menu `menus`; route `/menus` memakai middleware `super-admin`. Untuk DB dev: migration baru `2026_11_19_000001_menu_khusus_super_admin` (**menunggu persetujuan**, mengubah data privileges). Snapshot Admin berubah (tautan Manajemen Menu hilang dari sidebar, `/menus` 403). |
| 3 | Import karyawan tetap modul lama | Tidak ada perubahan. |
| 4 | Payroll hanya dibuat role HR, approver lewat `users.approver_user_id` | `AlurPayroll` memeriksa hak **role user sendiri** (`Privilege::cekAkses`) untuk buat/preview/ubah sel/generate/hapus; hak warisan Manager HR dari modul lama tidak berlaku. |
| 5 | Libur keagamaan diisi HR sendiri | Tidak ada perubahan (seeder hanya libur bertanggal tetap). |
| 6 | Tambahan di luar `schema.sql` disetujui | `pay_payslips.note`, `company_id` di tabel anak, `roles.company_key` VIRTUAL. |
| 7 | File TER dari pemilik | Dipindah ke `database/seeders/data/ter_pph21.csv` (lokasi yang dibaca seeder). Dicek strukturnya: kategori PTKP 8 status (A/B/C), TER A/B/C 44/40/41 lapisan mulai 0, tanpa celah/tumpang tindih, tarif tidak turun, lapisan terakhir tak terbatas. Kebenaran angka terhadap PP 58/2023 / PMK 168/2023 tanggung jawab pemilik. |

Tes baru: `KeputusanAksesTest` (2). `PayrollDemoSeederTest` jalur "TER belum ada" sekarang memakai path file uji yang sengaja tidak ada, karena file resmi sudah tersedia.

## 27. Persiapan pemakaian oleh pemilik (2026-11-12)

- Backup DB dev `storage/app/private/backups/penggajian_karyawan-sebelum-menu-super-admin-20261112-085902.sql`, lalu migration `2026_11_19_000001_menu_khusus_super_admin` **dijalankan di DB dev** (disetujui pemilik). Hasil: hak `menus` role Admin/HR/Manager/Karyawan DEFAULT = 0, Super Admin = 1.
- Akun platform `superadmin` dibuat dengan `php artisan super-admin:buat` (password awal dibangkitkan, diberikan sekali ke pemilik).
- Form Tambah Perusahaan (Super Admin) diberi centang **Aktifkan modul Payroll Baru** (bawaan tercentang): memanggil `PasangMenuPayroll::berikanHak` hanya untuk perusahaan baru itu; perusahaan lain (termasuk DEFAULT) tidak berubah. Tes: `SuperAdminTest::test_perusahaan_baru_bisa_langsung_mengaktifkan_payroll_baru`.
- Rumus/master payroll diisi pemilik sendiri; `PayrollDemoSeeder` **tidak** dijalankan di DB dev.
- TER: pemilik memilih tabel **nasional**. Logika dipindah dari `PayrollDemoSeeder` ke `TerPph21Seeder` (idempoten, dipakai ulang demo). Dijalankan di DB dev: `ter_bulanan` 125 lapisan, `kategori_ptkp` 8 baris (company_id NULL). Contoh pemakaian di rumus: `ROUND(gross_taxable * LOOKUP('ter_bulanan', LOOKUP_TEXT('kategori_ptkp', ptkp_status), gross_taxable))`.
- Seeder data perusahaan baru (permintaan pemilik): `DataPerusahaanSeeder` + `php artisan payroll:isi-data {kode} [--karyawan=12]` untuk perusahaan yang sudah dibuat dan masih kosong. Mengisi hak menu payroll, cut off kalender, libur tetap 2026, tipe leave, jabatan, 2 departemen, karyawan, user admin/hr/manager/staf.{kode} (password `password`, approver HR = Manager), 9 komponen, kontrak aktif, absensi/leave/lembur periode lalu dan berjalan. **Tanpa master/rumus.** Menolak bila perusahaan sudah berisi karyawan/departemen atau username contoh sudah dipakai. `PayrollDemoSeeder` memakai kelas yang sama (perilaku demo dan tesnya tetap). Tes: `DataPerusahaanSeederTest`.
- Catatan lingkungan: `php` di PATH Git Bash adalah PHP 8.5 tanpa pdo_sqlite; test dan server dijalankan dengan PHP Laragon 8.4.27.

## 28. Perbaikan: tautan menu Payroll Baru 404 (2026-11-12)

- Gejala (dilaporkan pemilik): semua menu grup Payroll Baru membuka halaman tidak ditemukan.
- Penyebab (bug Fase 2-7): sidebar membuat tautan `url($menu->url)`, sehingga menu `pay-karyawan` mengarah ke `/pay-karyawan`, padahal halamannya di `/pay/karyawan`. Url menu dipakai juga sebagai kunci hak akses (`akses:pay-karyawan`), jadi data menu tidak diubah.
- Perbaikan: `PasangMenuPayroll::alamat()` / `Menu::alamat()` (url `pay-x` dari daftar MENU menjadi `pay/x`; url menu lain tetap) dipakai di sidebar `layouts/app.blade.php` dan pencarian `CariController`; penanda menu aktif untuk `/pay/{x}` di `AppServiceProvider`. Menu lama tidak berubah (snapshot karakterisasi tetap).
- Tes: `MenuSidebarTest` membuka setiap tautan sidebar untuk setiap role (termasuk Super Admin) dan memastikan semua 17 menu Payroll Baru terbuka.

## 29. Modul payroll baru diterima: penggabungan form karyawan dan pengalihan kontrak (2026-11-12)

Keputusan pemilik: (1) data Karyawan Payroll digabung ke form Data Karyawan; (2) menu Kontrak diarahkan ke kontrak baru; (3) dikerjakan sekarang (modul baru diterima); (4) data kontrak lama **diabaikan** (tidak disalin ke `pay_contracts`; dianggap tidak ada).

Dampak yang sudah dijelaskan ke pemilik sebelum diputuskan: Proses Payroll lama, approval kontrak oleh Manager, kartu kontrak di Profil, dan cek boleh-lembur bergantung pada kontrak lama.

Rancangan: saklar per perusahaan `companies.payroll_baru` (migration `2026_11_20_000001_add_payroll_baru_to_companies`, bawaan false). Kode lama tidak dihapus; perusahaan yang belum beralih (dan tes karakterisasi) berperilaku seperti semula.

- `ModulPayroll::aktif()` membaca saklar perusahaan konteks. `ModulPayroll::MENU_DIGANTIKAN`: `kontrak` -> Kontrak Payroll, `payroll` & `laporan` -> Generate Payroll, `slip` -> Slip Gaji Payroll, `komponen` (Komponen Gaji lama) -> Katalog Komponen, `pay-karyawan` -> Data Karyawan.
- `AlihkanKePayrollBaru` (dipakai `php artisan payroll:aktifkan {kode}`, pembuatan perusahaan oleh Super Admin, dan `payroll:isi-data`): hak bawaan payroll baru, saklar on, hak role perusahaan atas menu yang digantikan dicabut (menu tersembunyi). Perusahaan lain tidak berubah. Centang "Aktifkan modul Payroll Baru" (§27) dihapus: perusahaan baru selalu memakai payroll baru.
- Middleware `modul-lama:{menu}` pada route lama (kontrak, kontrak-pdf, payroll, laporan, slip, komponen, pay/karyawan): bila saklar on, dialihkan ke halaman pengganti.
- Form Data Karyawan (`karyawan/form`): bagian "Data payroll" (jabatan payroll, tanggal resign, punya NPWP, NPWP terenkripsi; kosong = tidak diubah) hanya bila saklar on; hanya diproses dari form lengkap (penanda `data_payroll`), sehingga panel cepat "Ubah Karyawan" tidak menghapusnya. Perubahan kolom payroll dicatat di `audit_logs` (NPWP/rekening tidak ikut).
- Detail karyawan, Tim Saya, Profil & Kontrak: kontrak dari `pay_contracts` (partial `payroll-baru/_kontrak-karyawan`, `profil/_kontrak-payroll`); tombol Unduh Kontrak (PDF kontrak lama) disembunyikan.
- Cek boleh-lembur (`Karyawan::berhakLembur`, daftar di Absensi): saklar on = punya kontrak payroll aktif (nilai lembur dihitung rumus master, bukan komponen kontrak).
- Dashboard: HR (kartu Kontrak Payroll Aktif & Payroll dari `pay_runs`, pengingat kontrak habis / payroll ditolak, tombol Proses Payroll ke Generate Payroll); Manager (tanpa estimasi upah lembur dari gaji pokok lama); Karyawan (slip lama tidak ditampilkan, lembur "dihitung saat payroll").
- Import Excel jenis `kontrak` ditolak bila saklar on.
- Tes: `ModulPayrollBaruTest` (5), `SuperAdminTest` disesuaikan, `MenuSidebarTest` (menu yang digantikan dikecualikan).

Belum berubah (di luar permintaan, menunggu keputusan bila perlu): menu Absensi/Cuti/Lembur/Import Excel lama tetap ada berdampingan dengan Absensi/Leave/Lembur/Import CSV payroll baru (mesin rumus hanya membaca data `pay_*`); menu Approval lama tetap (cuti/lembur); Periode (master payroll lama) tetap karena dipakai filter/penguncian absensi lama; job harian `kontrak:aktifkan` tetap berjalan (tidak berdampak bila tidak ada kontrak lama baru).

## 30. Template CSV import payroll per jenis (2026-11-12)

- Permintaan pemilik: template unduhan untuk setiap tipe import di menu Import CSV (Payroll Baru).
- Halaman Import CSV: kartu "Template CSV" dengan tombol unduh per jenis (Absensi, Leave, Lembur), keterangan tiap kolom (`JenisKehadiran::keteranganKolom()`), dan daftar kode tipe leave aktif perusahaan.
- Isi template (`TemplateKehadiran`): header tetap + baris contoh memakai NIK karyawan aktif perusahaan sendiri (utamakan yang punya kontrak payroll aktif), kode tipe leave aktif, dan tanggal hari kerja terakhir; fallback contoh bawaan bila perusahaan belum punya karyawan. Tenant-scoped (NIK perusahaan lain tidak muncul).
- Tes: `KehadiranImportTest::test_template_per_jenis_berisi_data_perusahaan_sendiri_dan_langsung_valid` (template diunggah apa adanya -> semua baris valid).
- Catatan untuk pengguna: NIK berawalan nol (mis. `0001`) hilang nolnya bila CSV dibuka/disimpan dengan Excel; edit dengan Notepad/Google Sheets atau format kolom nik sebagai Text.

## 31. Pilihan "PPh21 TER otomatis" di master payroll (2026-11-12)

- Permintaan pemilik: rumus PPh21 TER tidak perlu diketik di setiap master; cukup dipilih.
- Form komponen master: Cara hitung punya pilihan ketiga **PPh21 TER otomatis (tabel nasional)**. `KelolaMasterPayroll::simpanItem` mengubahnya menjadi `calc_type = formula`, `item_role = pph21`, rumus `Item::RUMUS_PPH21_TER` (`ROUND(gross_taxable * LOOKUP('ter_bulanan', LOOKUP_TEXT('kategori_ptkp', ptkp_status), gross_taxable))`). Tanpa migration, mesin rumus tidak berubah; rumus tetap tersimpan di versi master (bisa diaudit, versi lama tidak berubah).
- Dasar: PPh21 TER adalah aturan nasional (PP 58/2023), bukan aturan perusahaan; angka tarif tetap dari tabel referensi nasional (sesuai PAYROLL_SPEC aturan 1). Item yang rumusnya identik ditampilkan "PPh21 TER otomatis" (termasuk master `contoh` dan `pkp` MTS di DB dev).
- Batasan yang dijelaskan ke pemilik: metode TER hanya untuk masa Januari-November; masa Desember/resign (hitung setahun Pasal 17 dikurangi TER yang sudah dipotong) belum dibuat (PAYROLL_SPEC: fase 2; data `pay_tax_monthly_histories` sudah disimpan).
- Tes: `MasterReferensiTest::test_pilihan_pph21_ter_otomatis_tanpa_mengetik_rumus`.

## 32. Dashboard & Absensi staf mengikuti payroll baru (2026-11-12)

Permintaan pemilik: login staf, dashboard dan Absensi/Cuti/Lembur belum mengikuti payroll baru; slip gaji jangan tampil di dashboard.

- Bila `companies.payroll_baru` aktif, role Karyawan memakai tampilan baru (tampilan lama tetap untuk perusahaan yang belum beralih, snapshot karakterisasi tidak berubah):
  - Dashboard (`dashboard/karyawan-payroll-baru.blade.php`): kehadiran, leave per tipe, jam lembur periode cut off dari `pay_attendances/pay_leaves/pay_overtimes`; **tanpa kartu slip gaji** (slip tetap di menu Slip Gaji).
  - `/absensi` (`absensi/saya-payroll-baru.blade.php`): riwayat harian sendiri dari tabel `pay_*`, filter Hadir/Leave/Lembur, tautan ke `/pay/slip`.
  - Ringkasan dihitung `App\Domain\Payroll\KehadiranKaryawan` (data apa adanya; tidak ada aturan alpha/potongan/uang makan, itu urusan master payroll). Konsep lama "alfa" dan "sisa cuti tahunan" tidak ditampilkan karena tidak ada di modul baru.
- Hak menu: `AlihkanKePayrollBaru::hakKaryawan()` memberi role Karyawan lihat `absensi`, dan menutup `cuti` & `lembur` lama (isinya sudah di halaman Absensi). Diterapkan di dev untuk DEFAULT dan MTS (sebelumnya: DEFAULT Karyawan lihat absensi/cuti/lembur; MTS Karyawan tidak punya ketiganya). Backup setelah perubahan: `penggajian_karyawan-setelah-hak-karyawan-absensi-20261112-131404.sql`.
- Belum: tampilan Manager (rekap tim di `/absensi`, input lembur di `/lembur`) dan HR (`/absensi`, `/import`, `/pajak`, `/master-payroll`, `/approval`) masih modul lama; dibahas bersama permintaan penataan menu.

## 33. Manager mengikuti payroll baru (2026-11-12)

Permintaan pemilik (login Manager): Dashboard sesuai payroll baru; Approval cukup satu (Approval Payroll); Slip Gaji hanya slip milik manager yang login.

- Dashboard Manager (`dashboard/manager-payroll-baru.blade.php`, bila `payroll_baru` aktif): payroll (`pay_runs`) berstatus menunggu dengan `approver_user_id` = user ini, kehadiran tim hari ini dan periode cut off, serta lembur tim dari tabel `pay_*`. Tidak ada nominal gaji.
- `/approval` lama masuk `ModulPayroll::MENU_DIGANTIKAN` dan dialihkan ke `/pay/approval`; hak menu `approval` dicabut untuk semua role di perusahaan yang beralih.
- `/pay/slip`: role Manager, termasuk Manager HR yang untuk menu lain bertindak sebagai HR, hanya melihat slip miliknya. Sebelumnya Manager HR melihat semua slip lewat hak `pay-run` milik HR.
- `AlihkanKePayrollBaru::HAK_MENU_LAMA`: role Manager tidak lagi punya akses Absensi/Cuti/Lembur lama, yang datanya dari tabel lama. Menu Data Karyawan dan Profil **tetap** (bukan payroll); bisa ditutup bila pemilik menghendaki.
- Diterapkan di dev untuk DEFAULT dan MTS. Backup sebelum perubahan: `penggajian_karyawan-sebelum-hak-manager-20261112-135019.sql`.
- Catatan: Manager HR (`manager.hr`, `manager.mts`) tetap melihat menu HR karena aturan lama "Manager HR bertindak sebagai HR" (`User::bisa`). Tidak diubah di sini; masuk penataan menu HR.

## 34. HR mengikuti payroll baru; data master tertentu dikelola Super Admin (2026-11-12)

Permintaan pemilik (login HR): menu HR = Dashboard (payroll baru), Data Karyawan, Absensi/Leave/Lembur Payroll, Import CSV payroll, Master Payroll, Generate Payroll, Slip Gaji (milik sendiri), Manajemen User. Jawaban atas pertanyaan:

- **Jabatan, Tipe Leave, Cut Off, Tabel Referensi, Departemen dikelola Super Admin**. Kontrak, Katalog Komponen, Kalender Libur tetap di HR.
- **Import:** hanya Import CSV payroll; Import Excel lama ditutup dan dialihkan.

Perubahan:

- `AlihkanKePayrollBaru::HAK_PERAN` (menggantikan `hakKaryawan`/`HAK_MENU_LAMA`) memuat hak role Karyawan, Manager, HR setelah beralih. HR: `users` vcud baru; ditutup: absensi/cuti/lembur/import/pajak/master-payroll lama, departemen, pay-jabatan, pay-tipe-leave, pay-cutoff, pay-referensi. `PasangMenuPayroll::MENU` tidak lagi memberi HR hak bawaan atas empat menu pay-* itu.
- `PasangHakPlatform::HAK_SUPER_ADMIN` + departemen, pay-jabatan, pay-tipe-leave, pay-cutoff (vu), pay-referensi. `AlihkanKePayrollBaru` memanggil `PasangHakPlatform` setelah menu payroll dibuat.
- Middleware baru `perlu-perusahaan` (`PerluPerusahaan`): Super Admin tanpa perusahaan terpilih diarahkan ke menu Perusahaan dengan pesan, karena data master itu milik satu perusahaan.
- `ModulPayroll::MENU_DIGANTIKAN` + `import` → `/pay/import`, `master-payroll` (Periode) → `/pay/cutoff`, `pajak` → `/pay/referensi`.
- Dashboard HR (`dashboard/hr-payroll-baru.blade.php`) seluruhnya dari modul baru: karyawan tanpa kontrak payroll, hadir/leave hari ini, lembur periode, kontrak aktif/habis, progres run terakhir, input leave & lembur terbaru, hari hadir per departemen, pengingat. `hrPayrollBaru()` lama (yang mencampur data absensi lama) diganti.
- `/pay/slip`: **semua role hanya slip miliknya** (termasuk HR). Rincian gaji semua karyawan dilihat HR di Generate Payroll.
- Diterapkan di dev untuk DEFAULT dan MTS. Backup sebelum perubahan: `penggajian_karyawan-sebelum-hak-hr-20261112-150545.sql`.
- Catatan: Manager HR mendapat menu HR lewat aturan lama `User::bisa`; role Admin masih punya hak lihat menu pay-* (bawaan sebelumnya), tidak diubah.

## 35. Susunan menu, Admin semua menu, Jabatan satu (2026-11-12)

Permintaan pemilik: Admin membuka semua menu; Jabatan cukup satu (pakai payroll baru); nama dan grup menu di database sesuai susunan Data HRIS / Payroll / Konfigurasi / Master Data. Pemilik minta langsung kode tanpa menjalankan test.

- `App\Domain\Payroll\Actions\TataMenu` (idempoten, dipanggil `AlihkanKePayrollBaru`) mengatur `menus.parent_id/nama/urutan/icon`:
  - Tanpa grup: Dashboard, Profil, Absensi Saya (`absensi`, riwayat karyawan sendiri), Slip Gaji (`pay-slip`).
  - Data HRIS: Karyawan, Kontrak, Absensi, Leave, Lembur, Import CSV, Approver.
  - Payroll: Master Payroll, Generate Payroll, Approval.
  - Konfigurasi: Tabel Referensi, Cut Off, Katalog Komponen, Kalender Libur.
  - Master Data: Jabatan, Departemen, Tipe Leave, Company (`perusahaan`, khusus Super Admin), Manajemen User, Role dan Privilege, Manajemen Menu (khusus Super Admin).
  - Modul Lama: 12 menu lama yang digantikan (tidak dihapus, tanpa hak sehingga tidak tampil).
  - Grup "Payroll Baru" dipakai ulang dan diganti nama "Payroll"; `PasangMenuPayroll` mengenali kedua nama.
- **Belum ada di daftar pemilik, ditempatkan sementara (menunggu konfirmasi):** Import CSV → Data HRIS; Slip Gaji, Profil, Absensi Saya → tanpa grup; Manajemen Menu → Master Data; Pajak & Tarif TER lama → Modul Lama (Super Admin tetap punya hak, tampil di grup Modul Lama).
- Admin: `AlihkanKePayrollBaru::hakAdmin()` memberi role Admin hak atas semua menu di grup di atas (kecuali menu platform dan Modul Lama): vcud; Dashboard/Slip v, Approval va, Cut Off vu.
- Form Data Karyawan (payroll baru): satu field **Jabatan** berupa pilihan master Jabatan (`position_id`, wajib); kolom teks `karyawan.jabatan` diisi otomatis dari nama jabatan. Field "Jabatan payroll" terpisah dihapus. Label sidebar lama "Absensi, Cuti & Lembur" tidak dipakai lagi di perusahaan payroll baru.
- Diterapkan di dev. Backup sebelum perubahan: `penggajian_karyawan-sebelum-tata-menu-20261112-151224.sql`.
- **Test belum dijalankan** untuk §34–§35 secara penuh (atas permintaan pemilik). Test yang memeriksa teks "Payroll Baru" di sidebar kemungkinan perlu disesuaikan.

## 36. Super Admin membuat akun Admin perusahaan (2026-11-12)

Pertanyaan pemilik: setelah Super Admin membuat perusahaan, bagaimana membuat Admin yang mengelola datanya? Sebelumnya harus: buat perusahaan → Pilih → Manajemen User → tambah user role Admin (tidak ada petunjuk).

- Form **Tambah Perusahaan** kini memuat bagian "Akun Admin perusahaan" (username, email opsional, password + ulangi; wajib). Perusahaan, role/hak bawaan, aktivasi payroll baru, dan akun Admin dibuat dalam **satu transaksi**: tidak ada perusahaan tanpa pengelola.
- Tombol **Admin** (ikon user-cog) di tiap baris perusahaan membuka drawer "Admin Perusahaan": daftar akun Admin perusahaan itu dan form tambah Admin, **tanpa perlu memilih perusahaan**. Route `POST /perusahaan/{id}/admin` (`super-admin` + `akses:perusahaan,update`).
- `App\Domain\Company\Actions\BuatAdminPerusahaan`: membuat user dengan role Admin milik perusahaan itu (`runAs`), `karyawan_id` kosong. Username unik global; boleh titik (seperti `manager.hr`). Aturan username di Manajemen User lama tidak diubah.
- Admin perusahaan login sendiri, lalu mengelola user/role/data perusahaannya (Admin membuka semua menu perusahaan, §35).
- Catatan: `payroll:isi-data {kode}` menolak bila username `admin.{kode}` sudah dipakai. Bila Admin dibuat dengan username itu, pakai username lain atau isi data dummy sebelum membuat Admin.
