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
