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
