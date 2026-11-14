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
- `AktifkanModulPayroll` (dipakai `php artisan payroll:aktifkan {kode}`, pembuatan perusahaan oleh Super Admin, dan `payroll:isi-data`): hak bawaan payroll baru, saklar on, hak role perusahaan atas menu yang digantikan dicabut (menu tersembunyi). Perusahaan lain tidak berubah. Centang "Aktifkan modul Payroll Baru" (§27) dihapus: perusahaan baru selalu memakai payroll baru.
- Middleware `modul-lama:{menu}` pada route lama (kontrak, kontrak-pdf, payroll, laporan, slip, komponen, pay/karyawan): bila saklar on, dialihkan ke halaman pengganti.
- Form Data Karyawan (`karyawan/form`): bagian "Data payroll" (jabatan payroll, tanggal resign, punya NPWP, NPWP terenkripsi; kosong = tidak diubah) hanya bila saklar on; hanya diproses dari form lengkap (penanda `data_payroll`), sehingga panel cepat "Ubah Karyawan" tidak menghapusnya. Perubahan kolom payroll dicatat di `audit_logs` (NPWP/rekening tidak ikut).
- Detail karyawan, Tim Saya, Profil & Kontrak: kontrak dari `pay_contracts` (partial `payroll/_kontrak-karyawan`, `profil/_kontrak-payroll`); tombol Unduh Kontrak (PDF kontrak lama) disembunyikan.
- Cek boleh-lembur (`Karyawan::berhakLembur`, daftar di Absensi): saklar on = punya kontrak payroll aktif (nilai lembur dihitung rumus master, bukan komponen kontrak).
- Dashboard: HR (kartu Kontrak Payroll Aktif & Payroll dari `pay_runs`, pengingat kontrak habis / payroll ditolak, tombol Proses Payroll ke Generate Payroll); Manager (tanpa estimasi upah lembur dari gaji pokok lama); Karyawan (slip lama tidak ditampilkan, lembur "dihitung saat payroll").
- Import Excel jenis `kontrak` ditolak bila saklar on.
- Tes: `ModulPayrollTest` (5), `SuperAdminTest` disesuaikan, `MenuSidebarTest` (menu yang digantikan dikecualikan).

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
- Hak menu: `AktifkanModulPayroll::hakKaryawan()` memberi role Karyawan lihat `absensi`, dan menutup `cuti` & `lembur` lama (isinya sudah di halaman Absensi). Diterapkan di dev untuk DEFAULT dan MTS (sebelumnya: DEFAULT Karyawan lihat absensi/cuti/lembur; MTS Karyawan tidak punya ketiganya). Backup setelah perubahan: `penggajian_karyawan-setelah-hak-karyawan-absensi-20261112-131404.sql`.
- Belum: tampilan Manager (rekap tim di `/absensi`, input lembur di `/lembur`) dan HR (`/absensi`, `/import`, `/pajak`, `/master-payroll`, `/approval`) masih modul lama; dibahas bersama permintaan penataan menu.

## 33. Manager mengikuti payroll baru (2026-11-12)

Permintaan pemilik (login Manager): Dashboard sesuai payroll baru; Approval cukup satu (Approval Payroll); Slip Gaji hanya slip milik manager yang login.

- Dashboard Manager (`dashboard/manager-payroll-baru.blade.php`, bila `payroll_baru` aktif): payroll (`pay_runs`) berstatus menunggu dengan `approver_user_id` = user ini, kehadiran tim hari ini dan periode cut off, serta lembur tim dari tabel `pay_*`. Tidak ada nominal gaji.
- `/approval` lama masuk `ModulPayroll::MENU_DIGANTIKAN` dan dialihkan ke `/pay/approval`; hak menu `approval` dicabut untuk semua role di perusahaan yang beralih.
- `/pay/slip`: role Manager, termasuk Manager HR yang untuk menu lain bertindak sebagai HR, hanya melihat slip miliknya. Sebelumnya Manager HR melihat semua slip lewat hak `pay-run` milik HR.
- `AktifkanModulPayroll::HAK_MENU_LAMA`: role Manager tidak lagi punya akses Absensi/Cuti/Lembur lama, yang datanya dari tabel lama. Menu Data Karyawan dan Profil **tetap** (bukan payroll); bisa ditutup bila pemilik menghendaki.
- Diterapkan di dev untuk DEFAULT dan MTS. Backup sebelum perubahan: `penggajian_karyawan-sebelum-hak-manager-20261112-135019.sql`.
- Catatan: Manager HR (`manager.hr`, `manager.mts`) tetap melihat menu HR karena aturan lama "Manager HR bertindak sebagai HR" (`User::bisa`). Tidak diubah di sini; masuk penataan menu HR.

## 34. HR mengikuti payroll baru; data master tertentu dikelola Super Admin (2026-11-12)

Permintaan pemilik (login HR): menu HR = Dashboard (payroll baru), Data Karyawan, Absensi/Leave/Lembur Payroll, Import CSV payroll, Master Payroll, Generate Payroll, Slip Gaji (milik sendiri), Manajemen User. Jawaban atas pertanyaan:

- **Jabatan, Tipe Leave, Cut Off, Tabel Referensi, Departemen dikelola Super Admin**. Kontrak, Katalog Komponen, Kalender Libur tetap di HR.
- **Import:** hanya Import CSV payroll; Import Excel lama ditutup dan dialihkan.

Perubahan:

- `AktifkanModulPayroll::HAK_PERAN` (menggantikan `hakKaryawan`/`HAK_MENU_LAMA`) memuat hak role Karyawan, Manager, HR setelah beralih. HR: `users` vcud baru; ditutup: absensi/cuti/lembur/import/pajak/master-payroll lama, departemen, pay-jabatan, pay-tipe-leave, pay-cutoff, pay-referensi. `PasangMenuPayroll::MENU` tidak lagi memberi HR hak bawaan atas empat menu pay-* itu.
- `PasangHakPlatform::HAK_SUPER_ADMIN` + departemen, pay-jabatan, pay-tipe-leave, pay-cutoff (vu), pay-referensi. `AktifkanModulPayroll` memanggil `PasangHakPlatform` setelah menu payroll dibuat.
- Middleware baru `perlu-perusahaan` (`PerluPerusahaan`): Super Admin tanpa perusahaan terpilih diarahkan ke menu Perusahaan dengan pesan, karena data master itu milik satu perusahaan.
- `ModulPayroll::MENU_DIGANTIKAN` + `import` → `/pay/import`, `master-payroll` (Periode) → `/pay/cutoff`, `pajak` → `/pay/referensi`.
- Dashboard HR (`dashboard/hr-payroll-baru.blade.php`) seluruhnya dari modul baru: karyawan tanpa kontrak payroll, hadir/leave hari ini, lembur periode, kontrak aktif/habis, progres run terakhir, input leave & lembur terbaru, hari hadir per departemen, pengingat. `hrDashboard()` lama (yang mencampur data absensi lama) diganti.
- `/pay/slip`: **semua role hanya slip miliknya** (termasuk HR). Rincian gaji semua karyawan dilihat HR di Generate Payroll.
- Diterapkan di dev untuk DEFAULT dan MTS. Backup sebelum perubahan: `penggajian_karyawan-sebelum-hak-hr-20261112-150545.sql`.
- Catatan: Manager HR mendapat menu HR lewat aturan lama `User::bisa`; role Admin masih punya hak lihat menu pay-* (bawaan sebelumnya), tidak diubah.

## 35. Susunan menu, Admin semua menu, Jabatan satu (2026-11-12)

Permintaan pemilik: Admin membuka semua menu; Jabatan cukup satu (pakai payroll baru); nama dan grup menu di database sesuai susunan Data HRIS / Payroll / Konfigurasi / Master Data. Pemilik minta langsung kode tanpa menjalankan test.

- `App\Domain\Payroll\Actions\TataMenu` (idempoten, dipanggil `AktifkanModulPayroll`) mengatur `menus.parent_id/nama/urutan/icon`:
  - Tanpa grup: Dashboard, Profil, Absensi Saya (`absensi`, riwayat karyawan sendiri), Slip Gaji (`pay-slip`).
  - Data HRIS: Karyawan, Kontrak, Absensi, Leave, Lembur, Import CSV, Approver.
  - Payroll: Master Payroll, Generate Payroll, Approval.
  - Konfigurasi: Tabel Referensi, Cut Off, Katalog Komponen, Kalender Libur.
  - Master Data: Jabatan, Departemen, Tipe Leave, Company (`perusahaan`, khusus Super Admin), Manajemen User, Role dan Privilege, Manajemen Menu (khusus Super Admin).
  - Modul Lama: 12 menu lama yang digantikan (tidak dihapus, tanpa hak sehingga tidak tampil).
  - Grup "Payroll Baru" dipakai ulang dan diganti nama "Payroll"; `PasangMenuPayroll` mengenali kedua nama.
- **Belum ada di daftar pemilik, ditempatkan sementara (menunggu konfirmasi):** Import CSV → Data HRIS; Slip Gaji, Profil, Absensi Saya → tanpa grup; Manajemen Menu → Master Data; Pajak & Tarif TER lama → Modul Lama (Super Admin tetap punya hak, tampil di grup Modul Lama).
- Admin: `AktifkanModulPayroll::hakAdmin()` memberi role Admin hak atas semua menu di grup di atas (kecuali menu platform dan Modul Lama): vcud; Dashboard/Slip v, Approval va, Cut Off vu.
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

## 37. Dashboard Super Admin (2026-11-12)

Permintaan pemilik: dashboard Super Admin berisi kartu dan grafik (jumlah perusahaan, user aktif, karyawan dikelola, karyawan per perusahaan, dll.), interaktif.

- `/dashboard` untuk Super Admin tidak lagi dialihkan ke menu Perusahaan; menampilkan `dashboard/super-admin.blade.php`:
  - Kartu: perusahaan terdaftar (aktif/nonaktif), user aktif, karyawan dikelola (+ yang punya kontrak payroll aktif), payroll menunggu approval (+ slip terbit bulan ini).
  - Grafik (Chart.js 4.4.1 dari jsDelivr, dimuat hanya di halaman ini; tooltip saat hover): karyawan aktif per perusahaan (batang horizontal), slip gaji terbit 6 bulan terakhir (kolom). Satu seri, satu warna `--primary-600`, tanpa legenda.
  - User aktif per role (bar), "Perlu Perhatian" (perusahaan aktif tanpa Admin, karyawan tanpa kontrak payroll, payroll menunggu approval).
  - Tabel per perusahaan (cari tanpa reload): karyawan, kontrak payroll, user aktif, jumlah Admin, payroll terakhir, status, aksi Admin/Ubah/Pilih.
- Data lintas perusahaan diambil dengan `DB::table` agregat per `company_id` (sama seperti halaman Perusahaan): hanya angka, tanpa data pribadi karyawan. Dashboard perusahaan lain tidak berubah.

## 38. Cleansing data dev untuk QA manual (2026-11-12)

Permintaan pemilik: hapus seluruh data transaksi untuk QA manual dari awal, tanpa menghapus menu, Super Admin, dan data pokok. Jawaban pemilik: kontrak payroll **dihapus**; perusahaan **mulai kosong** (hanya Super Admin dan DEFAULT dengan user lamanya); master payroll & tabel referensi perusahaan **dihapus**.

- Hanya database dev `penggajian_karyawan` (skrip menolak database lain). Backup sebelum: `penggajian_karyawan-sebelum-cleansing-qa-20261112-155105.sql`. PDF slip dipindah (bukan dihapus) ke `storage/app/private/backups/arsip-file-sebelum-cleansing-qa-20261112/`.
- Dihapus: semua transaksi payroll baru (absensi/leave/lembur, import, run, slip, riwayat pajak), kontrak payroll, master payroll; transaksi modul lama (absensi, cuti, lembur, kontrak, payroll, slip, approval, import); notifikasi, log aktivitas, audit log, sesi, cache, antrean; tabel referensi milik perusahaan; seluruh karyawan; perusahaan MTS beserta user, role, privilege, dan master datanya.
- Tetap: menu (42), DEFAULT dengan role/privilege dan 15 user (tautan ke karyawan dikosongkan), departemen DEFAULT (manager dikosongkan), Super Admin, tabel nasional TER & PTKP, tabel pajak lama.
- Akibat: semua user perlu login ulang; user DEFAULT belum terhubung ke karyawan sehingga Manager HR belum dikenali sebagai HR sampai karyawan dan manager departemen diisi ulang. `/absensi` untuk akun tanpa karyawan kini menampilkan pesan, bukan 404.

## 39. Perusahaan DEFAULT ikut dihapus (2026-11-12)

Permintaan pemilik: perusahaan DEFAULT juga dihapus semua (lanjutan §38). Database dev kini hanya berisi Super Admin, menu, dan tabel nasional TER/PTKP.

- Kode: `BuatPerusahaan` tidak lagi bergantung pada DEFAULT. Bila templat DEFAULT tidak ada, perusahaan baru mendapat role Admin, HR, Manager, Karyawan dengan hak menu umum dari `BuatPerusahaan::ROLE_BAWAAN`. Hak payroll baru, HR, Admin, dan Super Admin tetap dipasang `AktifkanModulPayroll`. Approval mapping lama tidak dibuat (hanya dipakai modul lama). Test: `SuperAdminTest::test_perusahaan_baru_tetap_bisa_dibuat_tanpa_templat_default`.
- Dev: semua baris ber-`company_id` milik DEFAULT dihapus (user, role, privilege, departemen, approval mapping, master_payroll lama), lalu baris `companies`. Backup sebelum: `penggajian_karyawan-sebelum-hapus-default-20261112-155449.sql`.
- **Temuan:** sebagian hak Super Admin (users, roles, menus, departemen, pajak, pay-*) ternyata tersimpan dengan `company_id` DEFAULT, bukan NULL. Kemungkinan dari backfill Fase 1a, atau dari baris yang dibuat sebelum role Super Admin ada. Baris itu ikut terhapus bersama DEFAULT, lalu dipasang ulang dengan `PasangHakPlatform` (`company_id` NULL). Kini 11 hak Super Admin, semuanya `company_id` NULL. Di instalasi lain yang masih punya DEFAULT, hak itu tetap berfungsi (cekAkses tanpa scope), hanya penandaannya keliru.
- Seeder (`DatabaseSeeder`, `PayrollDemoSeeder`) masih memakai DEFAULT sebagai perusahaan instalasi; tidak diubah. Untuk mengisi data dummy: buat perusahaan dari menu Company, lalu `php artisan payroll:isi-data {kode}`.

## 40. Super Admin hanya Dashboard & Perusahaan; Admin perusahaan mengelola semuanya (2026-11-12)

Keputusan pemilik (mengganti sebagian §34): Super Admin hanya mengakses Dashboard dan Perusahaan, dan dari sana membuat perusahaan beserta akun Admin-nya. **Admin perusahaan mendapat semua akses kecuali Perusahaan.**

- `PasangHakPlatform::HAK_SUPER_ADMIN` = dashboard (v), perusahaan (vcud). `handle()` mencabut hak Super Admin lain yang pernah dipasang (users, roles, menus, pajak, departemen, pay-jabatan, pay-tipe-leave, pay-cutoff, pay-referensi). Diterapkan di dev.
- Jabatan, Departemen, Tipe Leave, Cut Off, Tabel Referensi kini dikelola **Admin perusahaan** (hak sudah ada lewat `AktifkanModulPayroll::hakAdmin()`, §35). HR tetap tidak punya menu itu (§34).
- Tombol "Pilih" / "Keluar dari perusahaan aktif" dihapus dari halaman Perusahaan dan dashboard Super Admin. Route pilih/keluar dan middleware `perlu-perusahaan` dibiarkan sebagai cadangan.
- **Perlu diketahui pemilik:**
  - **Manajemen Menu** tidak diberikan ke Admin perusahaan. Tabel `menus` dipakai bersama semua perusahaan, jadi perubahan oleh satu Admin akan mengubah menu perusahaan lain. Susunan menu diatur kode (`TataMenu`), sehingga saat ini tidak ada role yang membuka menu ini.
  - **Tabel nasional TER/PTKP** (company_id NULL) hanya bisa diubah Super Admin, dan Super Admin kini tidak punya menu Tabel Referensi. Perubahan tarif nasional dilakukan lewat file `database/seeders/data/ter_pph21.csv` lalu `TerPph21Seeder`. Admin perusahaan tetap bisa melihat dan memakai tabel nasional, dan membuat tabel referensi milik perusahaannya sendiri.

## 41. Ubah kontrak payroll di tempat, terpisah dari Perbarui (2026-11-12)

Permintaan pemilik: `/pay/kontrak` bisa diedit (mis. koreksi nominal) tanpa membuat kontrak baru; Perbarui tetap menonaktifkan kontrak lama dan membuat kontrak baru.

- `App\Domain\Payroll\Actions\UbahKontrak` + route `PUT /pay/kontrak/{id}` (`akses:pay-kontrak,update`): mengubah nama, tanggal, THP info, dan komponen/nominal kontrak **aktif** di tempat (komponen yang tidak dicentang dilepas, yang baru ditambahkan). Audit log `updated` dengan nilai lama/baru per kode komponen.
- Sesuai `PAYROLL_SPEC.md` aturan 3–4: kontrak yang sudah dipakai payroll **diajukan/disetujui** (`Contract::terkunci()`: ada `pay_run_employees.contract_id` pada run `pending_approval`/`approved`) **tidak bisa diubah**. Tombol Ubah disembunyikan dan diganti keterangan, `?ubah=` dialihkan, PUT ditolak. Perubahan nominal lewat Perbarui. Run draft/ditolak tidak mengunci; pratinjau draft perlu diulang setelah kontrak diubah.
- Panel Detail Kontrak: tombol **Ubah** (koreksi) dan **Perbarui** (versi baru).

## 42. Approver payroll diisi dari form Manajemen User (2026-11-12)

Masukan pemilik saat QA: approver payroll kosong dan tidak ada alur yang jelas bagi pengguna awam untuk mengisinya. Sebelumnya hanya bisa lewat menu terpisah Data HRIS → Approver.

- Form **Tambah/Ubah user** (perusahaan payroll baru) memuat field **Approver payroll** (daftar user Manager aktif, kecuali user itu sendiri). Disimpan hanya bila role yang dipilih HR; role lain → `approver_user_id` NULL. Validasi sama dengan menu Approver: Manager aktif, bukan diri sendiri. `approver_user_id` bukan kolom fillable `User` (model lama tidak diubah), jadi disimpan eksplisit (`forceFill`). Tampilan mode lama tidak berubah (snapshot karakterisasi lulus).
- Halaman **Generate Payroll**: HR tanpa approver aktif melihat peringatan dan cara mengisinya. HR dengan approver melihat siapa yang akan menyetujui.
- Halaman **Approver** (§41) tetap ada: kolom "Approver saat ini", keterangan alur, peringatan bila belum ada Manager.

## 43. README dan seed data QA terkini (2026-11-12)

Permintaan pemilik: README untuk teman satu tim, dan seed yang sesuai data terkini di database. Jawaban pemilik: data karyawan di database QA adalah data dummy buatan sendiri (disalin apa adanya); payroll dan slip ikut.

- `php artisan seed:ekspor-qa` (baca saja) menulis `database/seeders/data/qa_snapshot.json`: semua tabel kecuali tabel sistem (migrations, sessions, cache, jobs, dll.). Kolom generated dilewati, kolom terenkripsi (`karyawan.npwp`, `karyawan.bank_account_no`, `pay_run_employees.bank_account_no`) didekripsi, password tidak disalin.
- `DataQaSeeder` (tidak dipanggil `DatabaseSeeder`): hanya untuk database baru (menolak bila sudah ada user/karyawan). Tabel snapshot dikosongkan (mis. perusahaan DEFAULT bawaan migrasi) lalu diisi dengan id yang sama, kolom terenkripsi dienkripsi ulang dengan `APP_KEY` setempat, semua password = `password`, PDF slip dibuat ulang. Foreign key dimatikan selama impor (SQLite: `defer_foreign_keys`).
- Diuji: dipasang di `penggajian_test` (MySQL); jumlah baris semua tabel sama, NPWP/rekening terbaca, login berhasil, 3 PDF slip dibuat. Test `DataQaSeederTest` (SQLite & MySQL). Efek samping: PDF slip MTS di `storage/app/private/payslips/3/4/` dibuat ulang (storage dipakai bersama dev & test); isinya dari data yang sama.
- `README.md` ditulis ulang untuk branch ini: kebutuhan, langkah instalasi (`migrate:fresh` lalu `db:seed --class=DataQaSeeder`, wajib `queue:work`), akun, isi data, password PDF slip, alur per role, cara memperbarui seed, test, dan masalah umum. README lama (aplikasi lama) tetap ada di branch `main`.

## 44. PPh21 masa Desember / resign: riwayat pajak, iuran pengurang pajak, pilihan otomatis (2026-11-13)

Permintaan pemilik: PPh21 Desember (Pasal 17 setahun dikurangi PPh21 yang sudah dipotong) dibuat di master payroll baru "Payroll Desember", dengan pilihan siap pakai seperti "PPh21 TER otomatis". Bagian "fase 2" `PAYROLL_SPEC.md` yang dimajukan atas persetujuan pemilik. Angka pajak tetap di tabel referensi nasional, bukan di kode.

- **Sumber data `tax_histories`** (`SumberData`): field `tax_month`, `gross_taxable`, `tax_deduction`, `pph21` dari `pay_tax_monthly_histories` karyawan itu, tahun pajak = tahun akhir periode, bulan < bulan akhir periode. Riwayat hanya ditulis saat payroll disetujui, jadi hanya payroll approved yang terhitung. Dibatasi perusahaan oleh global scope.
- **Variabel bawaan khusus komponen PPh21** (`Kalkulator::BAWAAN_PPH21`): `tax_deduction` (iuran bulan ini), `ytd_gross_taxable`, `ytd_tax_deduction`, `ytd_pph21`, `tax_months` (jumlah bulan berbeda yang tercatat + 1). Ikut tersimpan di snapshot variabel run.
- **Iuran pengurang pajak dicatat** (keputusan pemilik): centang baru "Pengurang pajak" di katalog komponen (`pay_components.is_tax_deductible`), disalin ke item master (`pay_items.is_tax_deductible`) seperti `is_taxable`. Jumlahnya (komponen pengurang ber-centang, nilai akhir) disimpan di `pay_run_employees.tax_deduction` dan `pay_tax_monthly_histories.tax_deduction` saat disetujui.
- **Pilihan "PPh21 Desember/resign otomatis"** (`Item::CARA_PPH21_DESEMBER`, rumus `Item::RUMUS_PPH21_DESEMBER`): PKP = bruto setahun − min(persen × bruto, maks_bulanan × `tax_months`) − iuran setahun − PTKP setahun, dibulatkan ke bawah ke ribuan; pajak = `dasar` + (PKP − `batas`) × `tarif`; hasil = pajak − `ytd_pph21`. Hasil negatif (lebih potong) tetap dicatat apa adanya (keputusan pemilik: "yang penting tercatat"); pengembalian ke karyawan belum diatur.
- **Tabel nasional baru** `ptkp_setahun`, `biaya_jabatan`, `pasal17` lewat `Pph21TahunanSeeder` dari `database/seeders/data/pph21_tahunan.csv` (diisi pemilik; format sama dengan `ter_pph21.csv`). Bila file belum ada, seeder dilewati dan master Desember tidak bisa diterbitkan (validasi tabel referensi). Logika baca CSV `TerPph21Seeder` dipisah ke `TerPph21Seeder::pasangCsv` (perilaku TER tidak berubah). Perintah `seed:perusahaan` ikut memanggilnya (idempoten).
- Migration: `2026_11_21_000001_add_tax_histories_source_to_pay_variables` (enum `pay_variables.source` + `tax_histories`; rollback ditolak bila masih dipakai), `2026_11_21_000002_add_tax_deduction_columns` (empat kolom baru dengan nilai bawaan). Keduanya hanya menambah; rollback diuji di SQLite dan MySQL test.
- Batasan: karyawan pindahan (bruto/PPh pemberi kerja sebelumnya) belum didukung (ditunda pemilik); tambahan tarif tanpa NPWP tidak dihitung; `tax_months` dihitung dari riwayat di perusahaan ini; payroll kedua di bulan Desember yang sama tidak membaca payroll Desember pertama (riwayat bulan berjalan tidak ikut).
- Temuan (tidak diperbaiki): `AlurPayroll::setujui` tidak mengisi `ter_category` dan `ter_rate` di `pay_tax_monthly_histories`.
- Temuan lingkungan: PHP default Laragon (8.5) tanpa `pdo_sqlite`; test SQLite dijalankan dengan PHP 8.4 Laragon. `DataQaSeederTest` gagal bila dijalankan dalam suite penuh (lulus sendiri), sudah terjadi sebelum perubahan ini.

## 45. Pengecekan generate payroll (2026-11-12)

Laporan pemilik: "kayaknya ada bug kalau generate payroll". Hasil penelusuran di database dev dan test:

- **Run macet "antre"** (run Desember di dev): bukan bug kode. Pratinjau/generate memakai queue `database`, tetapi yang dijalankan hanya `php artisan serve`, jadi job tidak pernah diproses. Satu kali `queue:work --once` menyelesaikan pratinjau (3 karyawan, tanpa error). Ditambah peringatan di halaman run bila status `queued` lebih dari 30 detik, berisi perintah `composer run dev` / `php artisan queue:work`.
- **Bug: approver payroll bisa kosong.** `AlurPayroll::generate` memeriksa approver milik user yang menekan Generate, tetapi `ProsesGenerate` menyimpan approver milik **pembuat** run. Bila HR lain yang generate dan pembuat run tidak punya approver, run menjadi "menunggu persetujuan" tanpa approver dan tidak pernah bisa disetujui. Diperbaiki: approver diambil dari user yang generate (fallback ke pembuat run). Test `AlurPayrollTest::test_approver_mengikuti_user_yang_generate_bukan_pembuat_run`.
- **Bug kecil: judul bawaan run** selalu "Payroll {bulan berjalan}" walau periode yang dipilih bulan lain (run Desember berjudul "Payroll November 2026"). Judul bawaan sekarang ikut bulan periode selama belum diketik manual.
- **Bug modul lama: Cetak PDF Laporan Payroll** kehabisan memori (128 MB) untuk ±120 karyawan (fatal dompdf, juga terlihat di log dev 2026-11-08) dan membuat `php artisan test` berhenti di `HalamanPerRoleTest@hr`. `LaporanController::pdf` menaikkan `memory_limit` ke 512M khusus aksi ini.
- **Test tidak stabil:** `DataQaSeederTest` gagal bila jalan setelah test yang memakai `$seed = true` (koneksi SQLite `:memory:` dipakai ulang, data seeder tertinggal di luar transaksi). Test ini sekarang `#[RunInSeparateProcess]`.
- **Catatan (belum diubah, keputusan pemilik):** cut off perusahaan dev `start_day 1`, `end_day 30`, bulan yang sama. Akibatnya tanggal 31 (Des, Jan, Mar, dst.) tidak masuk periode mana pun, jadi absensi/lembur tanggal itu tidak terhitung. Pertimbangkan validasi atau peringatan di menu Cut Off.

Hasil: `php artisan test` 190/190 lulus (sebelumnya berhenti di tengah).

## 46. Cut off tanpa celah dan audit scope (2026-11-12)

- **Cut off:** konfigurasi yang membuat tanggal tidak masuk periode mana pun (atau masuk dua periode) sekarang ditolak di menu Cut Off. Satu bulan: mulai harus 1 dan selesai 31 (= akhir bulan, otomatis 28/29/30 di bulan pendek). Lintas bulan: mulai = selesai + 1 (mis. 26 s/d 25). Test `MasterDataTest::test_cut_off_yang_menyisakan_tanggal_kosong_ditolak`. Data dev perusahaan MTS diubah dari 1–30 ke 1–31; run draft Desember diperbarui ke 1–31 Des, judul "Payroll Desember 2026", lalu preview diulang (3 karyawan).
- **Audit scope perusahaan:** semua `DB::table`, `join`, `selectRaw`, `withoutGlobalScope` di `app/` dicek ulang. `CompanyScope` memakai kolom berkualifikasi (aman untuk `join`); query mentah di job payroll hanya ke `pay_run_reference_versions` berdasarkan run yang sudah lolos scope; `DB::table` di dashboard Super Admin memang lintas perusahaan (agregat); `AktifkanModulPayroll` membatasi privilege ke role perusahaan itu; job membawa `company_id` (`runAs`); PDF slip di `payslips/{company_id}/...`. Tidak ada celah baru.
- **Audit cakupan karyawan di run:** `PemilihKaryawan` memakai model ber-scope, jadi id karyawan/departemen perusahaan lain yang diselipkan ke pilihan diabaikan; ubah sel mencari karyawan lewat `$run->employees()`. Ditambah test `AlurPayrollTest::test_cakupan_karyawan_tidak_bisa_menarik_karyawan_perusahaan_lain` (perorangan, per departemen, semua).

Hasil: `php artisan test` 192/192 lulus.

## 47. Tindak lanjut code review payroll (2026-11-13)

Keputusan pemilik atas review flow payroll:

- **Setelan approval per perusahaan:** kolom baru `companies.payroll_butuh_approval` (bawaan true). Admin mengatur di menu Approver Payroll. Bila false, `ProsesGenerate` langsung memanggil `AlurPayroll::finalkan` (status approved, riwayat pajak, audit `approved` bertanda otomatis) lalu event slip; approver tidak wajib.
- **Approver ikut di run:** boleh hanya bila karyawan approver berada di departemen HR (`Departemen::KODE_HR`, konstanta modul lama). Dicek saat generate dan saat setujui.
- **Nilai negatif:** ubah sel hanya menerima angka >= 0; generate ditolak bila ada THP < 0 (nama karyawan disebut).
- **Klik ganda / proses bersamaan:** preview, generate, setujui, tolak mengunci baris run (`lockForUpdate`) dan memeriksa ulang status di dalam transaksi.
- **LOOKUP** tabel referensi diurutkan `range_from, id` agar rentang bersinggungan selalu memberi hasil sama.
- **Pencarian slip:** `orWhere` dikelompokkan.
- **Karyawan resign** sebelum awal periode dilewati dengan alasan.
- Belum dijalankan di database dev: migration `2026_11_22_000001_add_payroll_butuh_approval_to_companies` (menunggu persetujuan).
- Test: 204/204 lulus (10 test baru di `AlurPayrollTest`).

## 48. Batalkan proses preview/generate yang macet (2026-11-13)

Disetujui pemilik (saran #2 review). Proses berstatus antre/berjalan lebih dari 15 menit (`PayrollRun::BATAS_MACET_MENIT`, `macet()`) memunculkan tombol **Batalkan proses** di halaman run (hak `pay-run,update`, route `POST /pay/run/{id}/batalkan`). `AlurPayroll::batalkanProses` mengunci baris run, membuang hasil preview setengah jadi, melepas `is_blocking`, menandai `job_status = failed` dengan pesan, dan mencatat audit `job_cancelled`. Job yang telat selesai setelah dibatalkan tidak menimpa status: `ProsesPratinjau`/`ProsesGenerate` memeriksa `PayrollRun::prosesMasihBerlaku()` (job_status masih `running` dan `job_started_at` sama dengan milik job itu) sebelum menyimpan hasil. Test `AlurPayrollTest::test_batalkan_proses_yang_macet`.

## 49. Approval payroll berbasis role (2026-11-13)

Keputusan pemilik: Manager bisa lebih dari satu, jadi approval tidak lagi ke satu orang (`users.approver_user_id`). Ini **mengganti keputusan §9 dan §42** soal approver per user HR.

- Penyetuju = user aktif yang role-nya punya hak `pay-approval: approve` **dan** karyawannya di departemen HR (`Departemen::KODE_HR`). Logika di `App\Domain\Payroll\Run\PenyetujuPayroll` (`boleh()`, `daftar()`).
- Semua penyetuju melihat dan bisa menyetujui/menolak semua payroll yang menunggu. Keputusan pertama yang dipakai; keputusan kedua ditolak ("tidak sedang menunggu persetujuan"). `pay_runs.approver_user_id` sekarang berarti **siapa yang memutuskan** (kosong selama menunggu, atau bila langsung final tanpa approval).
- Generate (bila perusahaan butuh approval) ditolak kalau belum ada penyetuju sama sekali.
- Halaman Approval terbuka untuk semua role yang punya menunya (mis. Admin melihat saja); tombol Setujui/Tolak hanya untuk penyetuju. Kolom baru "Diputuskan oleh".
- Menu Approver Payroll sekarang berisi setelan perusahaan + daftar penyetuju (baca saja). Field "Approver payroll" di form Manajemen User dihapus (kode `UserController` kembali seperti `main`); kolom `users.approver_user_id` tidak dihapus, hanya tidak dipakai lagi.
- Pengecekan "approver di luar HR yang gajinya ikut di run" (§47) tidak diperlukan lagi: penyetuju selalu orang HR.

## 50. Setelan PDF slip pakai password (2026-11-13)

Keputusan pemilik: password slip jadi setelan perusahaan. Migration `2026_11_22_000002_add_slip_pakai_password_to_companies` (`companies.slip_pakai_password`, bawaan true = perilaku sekarang). Diatur Admin di menu Approver Payroll. Bila false, `BuatPdfSlip` membuat PDF tanpa enkripsi dan slip tetap terbit walau nomor rekening kosong. Berlaku untuk slip yang terbit setelah setelan disimpan (slip lama tidak dibuat ulang). Test `SlipTest::test_setelan_tanpa_password_pdf_terbit_tanpa_enkripsi_walau_rekening_kosong`.

## 51. Kontrak dipilih dari tanggal + prorata (2026-11-13)

Keputusan pemilik ("pakai best practice"):

- `App\Domain\Payroll\Run\KontrakPeriode` memilih kontrak yang **berlaku di tanggal periode**: kontrak aktif + kontrak lama yang sudah diperbarui (punya penerus), rentang tanggalnya beririsan dengan periode. Kontrak yang dinonaktifkan manual tidak dipakai. Tiap kontrak jadi segmen yang dibatasi awal periode / tanggal masuk, akhir periode / tanggal resign, tanggal akhir kontrak, dan sehari sebelum kontrak berikutnya.
- Karyawan tanpa segmen dilewati: "tidak punya kontrak yang berlaku di periode ini" (dulu: kontrak aktif apa pun dipakai walau sudah habis atau belum mulai).
- Item master "dari kontrak" bertipe **penambah** dibayar prorata: jumlah (nilai kontrak segmen × hari segmen ÷ hari periode). Item potongan dari kontrak tetap penuh (kontrak terbaru). Fungsi `CONTRACT()` di rumus tetap nilai penuh kontrak terbaru. Variabel bawaan baru `period_days` dan `paid_days`; bila prorata, snapshot run mencatat `paid_days` dan `prorata`.
- Dasar prorata = setelan perusahaan (migration `2026_11_22_000003_add_prorata_dasar_to_companies`, bawaan `kalender`): `kalender` (hari kalender), `kerja` (Senin–Jumat dikurangi libur), `tidak` (penuh kontrak terbaru). Diatur di menu Cut Off.
- Peringatan "kontrak berakhir sebelum akhir periode" di halaman run diperbarui: sisa hari tidak dibayar.
- Test `ProrataKontrakTest` (8 kasus: penuh, karyawan baru, hari kerja, tanpa prorata, resign, ganti kontrak, kontrak habis, nonaktif manual, kontrak belum mulai).
- Catatan untuk pemilik: aturan "potongan dari kontrak tidak diprorata" ada di mesin hitung. Bila perusahaan ingin BPJS/potongan ikut prorata, ubah item itu jadi rumus memakai `paid_days / period_days`.

## 52. "Tambah penyesuaian" menggantikan ubah sel (2026-11-13)

Keputusan pemilik: ubah sel dihapus. Penggantinya tabel `pay_run_adjustments` (migration `2026_11_22_000004_create_pay_run_adjustments_table`): HR menambah penyesuaian per karyawan di payroll draft dengan jenis dari Katalog Komponen aktif, nominal > 0, keterangan wajib (drawer "Tambah penyesuaian" di halaman run). Penyesuaian:

- dihitung sesudah item master dan **sebelum PPh21**: penambah kena pajak (mis. Bonus, THR) menambah bruto; Reimburse (tidak kena pajak) tidak; potongan pengurang pajak ikut iuran;
- dijumlah per komponen jadi satu baris rincian `adj_{component_id}` bernama "{Komponen} (penyesuaian)", tampil di tabel preview, approval (dengan daftar keterangan), dan slip;
- disimpan per run + karyawan, jadi tetap ada saat preview diulang; tercatat di audit log (ubah sel dulu tidak);
- tidak bisa ditambah/dihapus setelah generate.

Route `PUT /pay/run/{id}/karyawan/{karyawan}` dan `AlurPayroll::ubahSel` dihapus. Kolom `pay_run_items.is_overridden` tetap (data lama). Test di `AlurPayrollTest` diganti ke penyesuaian.

## 53. Kalender Payroll: tab Periode + Hari Libur (2026-11-13)

Keputusan pemilik (saran mockup "Master Periode & Kalender Libur", digabung jadi satu menu). Migration `2026_11_22_000005_create_pay_periods_and_holiday_type`:

- Tabel `pay_periods` (satu baris per bulan payroll per perusahaan). Periode dibuat dari aturan Cut Off; bila periode bulan sebelumnya ada, mulai = selesainya + 1 (menyambung). Tombol "Buat periode berikutnya"; membuat payroll untuk bulan yang belum punya periode juga membuat periodenya (`KelolaPeriode::untukBulan`). `AlurPayroll::buat` sekarang mengambil rentang dari sini.
- Tanggal selesai bisa diubah selama periode belum dipakai payroll (draft/menunggu/final); periode bulan berikutnya ikut bergeser mulainya, dan ditolak bila periode berikutnya sudah dipakai. Status: Belum dipakai, Ada draft payroll, Menunggu approval, Terkunci · payroll final. Perubahan tercatat di audit log.
- `pay_holidays.type` (nasional / cuti bersama / perusahaan, bawaan nasional). Tab Hari Libur menampilkan kalender satu periode berwarna per jenis + daftar CRUD yang sama seperti sebelumnya.
- Menu `pay-libur` diganti nama "Kalender Payroll" (URL dan hak akses tetap). Aturan Cut Off dan setelan prorata tetap di menu Cut Off (Admin).
- Test `KalenderPayrollTest`. Snapshot karakterisasi `admin/roles.html` diperbarui hanya untuk nama menu ini.

Belum dijalankan di database dev: migration `2026_11_22_000002` s/d `2026_11_22_000005` (menunggu persetujuan). Hasil: `php artisan test` 216/216 lulus.

## 54. Generate Payroll dari periode yang sudah dibuat; libur bisa diklik di kalender (2026-11-13)

Permintaan pemilik: "di menu generate payroll harusnya sudah tergenerate, jadi buat periode dulu" (seperti alur modul lama: menu Periode lalu Proses Payroll per periode).

- `AlurPayroll::buat` tidak lagi membuat periode otomatis: periode bulan itu harus sudah ada di Kalender Payroll > Periode, bila belum ditolak dengan pesan yang mengarah ke sana.
- Halaman Generate Payroll punya kartu **Periode berikutnya** (periode paling awal yang belum punya payroll) dengan tombol "Generate payroll {bulan}"; bila tidak ada, tombol "Buka Kalender Payroll". Drawer Payroll Baru memilih periode dari daftar (dengan statusnya), bukan isian bulan bebas.
- Tab Hari Libur: tanggal di kalender bisa diklik. Libur yang ada membuka form ubah, tanggal lain membuka form tambah dengan tanggal terisi. Ubah/hapus lewat tabel tetap ada.
- `DataPerusahaanSeeder` membuat periode Okt–Des 2026; test helper `SiapkanPayroll` membuat periode Nov 2026.
- Test `KalenderPayrollTest` (5 kasus). Hasil: `php artisan test` 218/218 lulus.

## 55. Pola hari kerja per departemen/karyawan, libur khusus departemen (2026-11-13)

Keputusan pemilik: operasional bisa masuk Sabtu/Minggu, jadi hari kerja tidak dikunci Senin–Jumat. Ini **menyimpang dari `PAYROLL_SPEC.md`** (`WORKDAYS` = Senin–Jumat dikurangi holidays), atas persetujuan pemilik.

- Migration `2026_11_22_000006_create_pay_work_patterns` (belum dijalankan di dev): tabel `pay_work_patterns` (kode, nama, `work_days` hari ISO mis. `1,2,3,4,5`, `is_default`), kolom nullable `departemen.pay_work_pattern_id` dan `karyawan.pay_work_pattern_id`, kolom `pay_holidays.departemen_id` (NULL = semua departemen). Unique libur diganti `(company_id, holiday_date, departemen_id)`; libur khusus departemen di tanggal yang sudah libur semua departemen ditolak di form. Rollback gagal bila sudah ada libur khusus departemen di tanggal yang sama dengan libur lain (harus dibersihkan dulu).
- Urutan pola: karyawan (pengecualian) → departemen → pola bawaan perusahaan → Senin–Jumat (tanpa data pola, perilaku sama seperti sebelumnya). `Employee::hariKerja()`, `WorkPattern::hariUntuk()`.
- Mesin hitung: `workdays`, `WORKDAYS()`, dan prorata dasar hari kerja memakai pola karyawan dan libur yang berlaku untuknya (semua departemen + departemennya). Baris absensi/leave/lembur punya field baru `row.is_workday` (mis. lembur di hari istirahat). Uang makan berbasis `hadir` tidak berubah (dari absensi nyata).
- Import leave: hari di luar pola karyawan dilewati (dulu Sabtu/Minggu).
- Kalender Payroll: tab baru **Pola Hari Kerja** (CRUD pola, pola per departemen, pengecualian karyawan; hak `pay-libur`). Tab Hari Libur: kolom "Berlaku untuk" dan pilihan "Lihat untuk" departemen; kalender menandai hari istirahat menurut pola. Ringkasan hari kerja di tab Periode memakai pola bawaan.
- Batasan: pola = hari tetap per minggu. Shift bergilir per orang (jadwal berbeda tiap minggu) belum didukung; untuk itu rumus sebaiknya memakai absensi nyata (`hadir`).
- Halaman lama (form departemen/karyawan) tidak diubah. Test `PolaHariKerjaTest` (4). Hasil: `php artisan test` 222/222 lulus.

## 56. Hapus periode yang belum dipakai (2026-11-14)

Permintaan pemilik: "yang belum dipakai bisa dihapus". Tab Periode punya tombol hapus untuk periode **paling akhir** yang belum dipakai payroll (`KelolaPeriode::hapus`, `DELETE /pay/libur/periode/{id}`, hak `pay-libur,delete`, tercatat di audit log). Periode di tengah tidak bisa dihapus supaya periode tetap menyambung; periode yang dipakai payroll (draft/menunggu/final) ditolak. Pola hari kerja dan hari libur sudah bisa dihapus sebelumnya (pola yang dihapus membuat departemen/karyawan pemakainya kembali ke pola bawaan). Test `KalenderPayrollTest::test_periode_yang_belum_dipakai_bisa_dihapus`. Hasil: 223/223 lulus.

## 57. Rapikan tampilan dan permintaan kecil (2026-11-14)

Hanya tampilan, alur dan logika tidak berubah kecuali disebut:

- **Master Payroll**: daftar dengan badge versi (aktif/terbit/draft/terkunci) dan tombol buka; halaman versi dengan ringkasan, tabel ber-badge (jenis, cara hitung, rumus sebagai kode), form tambah/ubah di drawer, uji rumus dengan filter bar, panduan rumus bisa dilipat. Field dan route sama.
- **Import CSV**: mengikuti Figma "HR / Import Excel": tabel riwayat lebar penuh (No. IM-x, status Berhasil/Ada error/Siap disimpan/Dibatalkan, file + pengunggah, baris valid), tombol Unduh Template (dropdown) dan Upload File (drawer berisi keterangan kolom per jenis). Halaman preview punya ringkasan baris valid/error.
- **Kontrak**: mengikuti Figma "HR / Kontrak" dan "Detail & Riwayat (Drawer)": chip Aktif/Segera berakhir (≤ 90 hari)/Nonaktif/Semua dengan jumlah, kolom gaji pokok dan sisa masa berlaku, tombol Perbarui di baris, drawer tab Detail/Riwayat bergaya linimasa.
- **Profil & detail karyawan**: tabel Riwayat Kontrak mengikuti Figma "Karyawan / Profil & Kontrak" (no. kontrak, periode, gaji pokok, komponen, status, aksi).
- **Absensi/Leave/Lembur (HR)**: filter bar periode + multi pilih departemen + multi pilih karyawan dikelompokkan per departemen, chip filter aktif, reset. Parameter lama `karyawan` tetap diterima.
- **Pemilih bulan**: semua `input type=month` diganti pemilih bulan buatan sendiri di `public/js/form.js` (Safari desktop tidak punya kalender bulan, jadi filter di Absensi Saya tidak bisa dipakai). Versi aset dinaikkan (`app.css?v=25`, `form.js?v=10`), snapshot karakterisasi ikut diperbarui hanya untuk nomor versi ini.
- **Generate Payroll**: catatan "Pilih departemen bila lingkup..." dihapus.
- **Slip tanpa password (perilaku)**: saat diunduh, PDF dibuat ulang bila status password file berbeda dari setelan sekarang; slip lama ikut tanpa password setelah setelan diubah ke Tidak (termasuk slip yang dulu belum terbit karena rekening kosong), dan kembali berpassword bila setelan diubah ke Ya. Test `SlipTest::test_slip_lama_diunduh_sesuai_setelan_password_saat_ini`.
- Hasil: `php artisan test` 224/224 lulus.

## 58. Tarik kembali payroll + koreksi payroll final (2026-11-14)

Keputusan pemilik (saran koreksi payroll yang salah):

- **Tarik kembali**: payroll berstatus menunggu approval bisa dikembalikan ke draft oleh role ber-hak `pay-run,update` (HR), opsional dengan alasan (`AlurPayroll::tarikKembali`, `POST /pay/run/{id}/tarik`, audit `withdrawn`). Tanda blocking karyawan dilepas, `generated_at` dikosongkan; HR memperbaiki (preview ulang / penyesuaian) lalu generate lagi. Versi master/tabel referensi yang sudah terkunci tetap terkunci. Payroll yang sudah disetujui tidak bisa ditarik.
- **Payroll final yang salah**: tidak diubah. Halaman run final menampilkan arahan koreksi lewat Tambah penyesuaian di payroll periode berikutnya. **Payroll koreksi khusus periode yang sama belum dibuat**: butuh hitung selisih per komponen dan PPh21 ulang untuk karyawan yang sudah punya payroll final di periode itu (perubahan besar di pemilih karyawan dan riwayat pajak), menunggu keputusan pemilik.
- `saya-payroll-baru.blade.php`: sisa `</div>` dari penghapusan catatan "Data diinput HR" dibuang (tampilan Absensi Saya rusak).
- Test `AlurPayrollTest::test_tarik_kembali_payroll_yang_menunggu_approval`. Hasil: 225/225 lulus.

## 59. Filter periode absensi, hapus draft master, rapikan drawer (2026-11-14)

- **Filter periode** (absensi/leave/lembur HR): dropdown periode dari Kalender Payroll (default periode yang sedang berjalan, dengan status: terbuka/ada draft/menunggu approval/terkunci) + pilihan "Rentang bebas…" (pemilih rentang tanggal, maks. 1 tahun). Bulan yang belum punya periode memakai aturan Cut Off seperti sebelumnya, dengan tautan buat periode. Test `KalenderPayrollTest::test_filter_absensi_per_periode_kalender_dan_rentang_bebas`.
- **Master Payroll**: versi draft yang bukan versi aktif, bukan satu-satunya versi, dan belum dipakai payroll bisa dihapus (`KelolaMasterPayroll::hapusVersi`, `DELETE /pay/master/versi/{id}`, hak `pay-master,delete`, audit `deleted`, isi ikut terhapus lewat cascade). Daftar versi tidak lagi menumpuk: kolom Draft hanya menampilkan draft terbaru (+n lainnya) dan semua versi ada di dropdown riwayat. Kotak ringkasan 4 kolom di halaman versi dihapus. Test `MasterReferensiTest::test_versi_draft_bisa_dihapus_dan_daftar_versi_tidak_menumpuk`.
- **Preview import**: kolom memakai judul yang bisa dibaca (NIK, Tanggal, ...), ditambah kolom **Nama karyawan** (dicari dari NIK di perusahaan sendiri), kolom hasil validasi diganti nama "Hasil cek" agar tidak tertukar dengan kolom `keterangan` dari CSV.
- **Cek semua drawer** (kontrak baru/perbarui/ubah/lihat, master baru/isi, payroll baru, penyesuaian, upload & perbaiki baris import, absensi/leave/lembur, periode, hari libur, pola hari kerja, katalog komponen, jabatan, tipe leave) pada 1366 px: tidak ada isi yang keluar dari drawer. Diperbaiki: tombol drawer penyesuaian tanpa Batal/footer, nominal kontrak tampil "250000.00" (sekarang format Rupiah), label "Nik" di form kehadiran, catatan leave "Sabtu dan Minggu dilewati" disesuaikan pola hari kerja.
- Hasil: `php artisan test` 227/227 lulus.

## 60. Redaksi Kalender Payroll dan pembuatan periode lewat konfirmasi (2026-11-14)

- "Buat periode berikutnya" sekarang membuka modal konfirmasi berisi bulan dan tanggal yang dihitung otomatis (aturan Cut Off + menyambung dengan periode sebelumnya, `KelolaPeriode::rentangBaru`/`bulanBerikutnya`); setelah dibuat tidak lagi membuka form ubah tanggal. Mengubah tanggal selesai tetap lewat ikon pensil selama periode belum diproses (aturan geser periode tidak berubah).
- Label status periode disatukan di `PayPeriod::STATUS`: Belum diproses, Draft payroll, Menunggu persetujuan, Final (dipakai tab Periode dan drawer Payroll Baru). Dropdown filter periode absensi/leave/lembur tidak lagi menampilkan status.
- Catatan "Aturan bawaan dari Cut Off..." dihapus; hanya muncul ajakan mengatur Cut Off bila belum ada.
- Redaksi lain dirapikan (lihat laporan thread). Hasil: 227/227 lulus.

## 61. Redaksi modul payroll baru (2026-11-14)

Hanya teks, logika tidak berubah. Istilah disatukan: jenis komponen **Pendapatan / Potongan** (sebelumnya Penambah / Pengurang), **persetujuan** untuk kalimat (nama menu Approval/Approver tetap), **Manager HR** untuk penyetuju. Tabel hasil payroll tidak lagi menampilkan label jenis di bawah judul kolom; nilai potongan diberi tanda "−". Katalog Komponen, master payroll, kontrak, penyesuaian, ringkasan run, halaman slip, dan PDF slip memakai istilah baru (`badge_status` menerima `pendapatan`/`potongan`). Pesan error/sukses payroll dirapikan (lihat laporan thread). Halaman modul lama (dibekukan oleh tes karakterisasi) tidak diubah. Hasil: 227/227 lulus.

## 62. Keterangan select di bawah, tombol Perbarui H-3 bulan (2026-11-14)

- **Select**: teks opsi dibuat pendek; keterangan opsi terpilih (atribut `data-ket`) tampil sebagai teks bantu di bawah select dan berubah mengikuti pilihan (`public/js/form.js`, versi `form.js?v=11`). Diterapkan di: periode Payroll Baru (rentang + status), filter periode absensi/leave/lembur (rentang), jenis penyesuaian (pendapatan/potongan, kena pajak), periode kalender Hari Libur, pola per departemen (hari kerja), komponen katalog / jenis variabel / cara hitung / peran di Master Payroll, dasar prorata di Cut Off. Select karyawan "NIK · Nama" dibiarkan (sudah satu baris dan dipakai untuk mencari NIK). Select di modul lama tidak diubah (menunggu izin pemilik).
- **Perbarui kontrak**: tombol di daftar hanya muncul bila kontrak aktif berjangka berakhir ≤ 3 bulan lagi atau sudah lewat (`Contract::hampirBerakhir`, juga dipakai chip "Segera berakhir" dan penanda sisa waktu). Di drawer detail, kontrak yang masih lama tetap punya tombol "Perubahan gaji" (aksi yang sama: kontrak baru, kontrak lama dinonaktifkan) agar kenaikan gaji tetap bisa dicatat; kontrak tetap tanpa tanggal akhir tidak pernah memunculkan tombol di daftar. Test `KontrakTest::test_tombol_perbarui_hanya_muncul_h_min_3_bulan_sebelum_kontrak_berakhir`. Hasil: 228/228.

## 63. Kunci hari libur di periode payroll yang dikirim/final (2026-11-14)

- Tambah, ubah, atau hapus hari libur yang tanggalnya (lama maupun baru) masuk rentang payroll **menunggu persetujuan** atau **final** ditolak di server (`LiburController::periksaKunci`, pesan menyebut nama payroll). Payroll draft tidak dikunci karena dihitung ulang saat preview. Di daftar libur barisnya bergembok (bisa dilihat, tidak bisa diubah/dihapus); di kalender, tanggal kosong di periode terkunci tidak bisa diklik untuk menambah dan ada catatan. `MasterSederhanaController` punya hook `periksaKunci`/`alasanKunci` (bawaan tidak mengunci).
- **Pola hari kerja belum dikunci per periode**: pola berlaku "sekarang", sedangkan payroll yang sudah dikirim/final menyimpan hasil hitungnya (rincian komponen + snapshot variabel), jadi mengubah pola tidak mengubah payroll tersebut. Tab Pola menampilkan catatan ini. Usulan bila ingin lebih ketat (menunggu keputusan pemilik): pola berlaku mulai tanggal tertentu (riwayat pola per departemen/karyawan) sehingga periode lama tetap memakai pola lamanya.
- Test `KalenderPayrollTest::test_hari_libur_di_periode_yang_sudah_dikirim_atau_final_terkunci`. Hasil: 229/229.

## 64. Tab Hari Libur jadi kalender bulanan, redaksi prorata (2026-11-14)

- Tab Hari Libur tidak lagi memakai pilihan periode: kalender bulanan biasa dengan tombol bulan sebelumnya/berikutnya dan "Hari ini" (`?bulan=Y-m`); filter "Lihat untuk" departemen tetap. Tanggal yang masuk payroll menunggu persetujuan/final bergembok dan tidak bisa diklik (tooltip menjelaskan); banner kuning dan teks petunjuk klik dihapus. Setelah simpan/hapus, kembali ke bulan tanggal libur tersebut. Validasi server §63 tetap.
- Subjudul halaman: "Tanggal libur tidak dihitung sebagai hari kerja saat menghitung gaji."
- Kartu prorata di Cut Off: judul "Gaji untuk karyawan yang tidak bekerja sebulan penuh", pilihan "Hitung per hari kalender / Hitung per hari kerja / Selalu dibayar penuh" dengan contoh di bawah select; istilah paid_days/period_days dihapus dari teks (logika sama). Hasil: 229/229.

## 65. Prorata selalu per hari kerja (2026-11-14)

Keputusan pemilik: prorata gaji dari kontrak dihitung per **hari kerja** (pola hari kerja karyawan/departemen dikurangi hari libur yang berlaku untuknya, sama dengan `workdays`/`WORKDAYS`). Pilihan "per hari kalender" dihapus dari menu Cut Off; setelan tinggal "Hitung per hari kerja" (bawaan) dan "Selalu dibayar penuh". Tanpa migration: nilai lama `kalender` di `companies.prorata_dasar` (termasuk bawaan kolom) diperlakukan sebagai `kerja` oleh `Kalkulator::dasarProrata`; validasi hanya menerima `kerja|tidak`. Variabel bawaan baru `paid_workdays` (hari kerja yang tertutup kontrak/masa kerja); snapshot run mencatat `paid_days`, `paid_workdays`, `prorata`. Contoh (periode 26 Okt–25 Nov 2026, 23 hari kerja, gaji 5.700.000): masuk 10 Nov 2.941.935 → 2.973.913 (12/23); resign 15 Nov 3.861.290 → 3.717.391 (15/23); ganti kontrak 11 Nov ke 6,2 jt 5.941.935 → 5.939.130; kontrak habis 20 Nov 4.780.645 → 4.956.522 (20/23). Hasil: 229/229.

## 66. Detail Generate Payroll: checklist kesiapan, ringkasan, filter (2026-11-14)

- **Checklist "Persiapan sebelum generate"** (hanya saat draft): Master payroll terbit, Penyetuju payroll, dan Preview (ada & tidak ada gaji bersih minus) **wajib**, sama dengan pengecekan server saat generate; Kontrak karyawan, Absensi periode ini, dan Hari libur **opsional** (peringatan + tombol Buka ke menu terkait). Tombol Generate nonaktif selama item wajib belum terpenuhi. Catatan "Belum ada penyetuju payroll…" di halaman daftar dihapus (sudah jadi item checklist).
- **Lingkup karyawan**: form preview tidak lagi memilih departemen/perorangan; preview selalu untuk semua karyawan aktif (`scope=all`). Backend lingkup tetap ada.
- **Tampilan detail** mengikuti Figma "Riwayat Payroll - Detail Periode": kartu ringkasan per komponen + kartu Gaji bersih, tabel per departemen (kode, gaji bersih, departemen/manager tanpa avatar, jumlah karyawan, aksi lihat), filter departemen/karyawan (karyawan dikelompokkan per departemen) untuk tabel rincian, kolom rincian diberi jarak dan kolom "Gaji bersih", penyesuaian dengan jarak nominal–keterangan, riwayat persetujuan (stepper), daftar karyawan dilewati bisa dilipat.
- Test `AlurPayrollTest::test_detail_payroll_checklist_kesiapan_ringkasan_dan_filter`. Hasil: 230/230.

## 67. Data uji alur, dokumen, dan diagram (2026-11-14)

- `FlowUjiSeeder` (`php artisan db:seed --class=FlowUjiSeeder`): perusahaan **UJI** berisi semua kasus alur (lihat README §4b dan `docs/SKENARIO_UJI.md`). Memakai `DataPerusahaanSeeder` lalu menambah departemen Finance, pola hari kerja (Operasional Senin–Minggu), libur cuti bersama dan khusus departemen, variasi kontrak (tetap/hampir habis/lama), karyawan masuk & resign di tengah periode, jenis penyesuaian Bonus/Reimburse, Master "Payroll Usual" (PPh21 TER otomatis, potongan alpha memakai `paid_workdays`), payroll Oktober final (slip terbit), November draft + penyesuaian, Desember belum diproses. Antrean diproses `sync` dan `memory_limit` 512M selama seeding. Bila perusahaan UJI sudah ada, seeder tidak mengubah apa pun. Test `FlowUjiSeederTest`.
- Pesan validasi Bahasa Indonesia dilengkapi (`regex`, `between`, `required_without`, dll.) dan nama atribut kolom modul payroll baru. Kode pola hari kerja dinormalkan (huruf kecil, spasi/strip → garis bawah).
- README diperbarui (cara menjalankan, worker antrean, akun, data uji, alur baru, masalah umum). Catatan: di data QA (`DataQaSeeder`) manager `septiana` ada di departemen Finance sehingga **bukan** penyetuju (penyetuju harus Manager di departemen HRD); pakai data UJI untuk menguji approval.
- Diagram Mermaid diperbarui (salinan di `docs/uml/`, asal folder project `uml/`): use case, activity kontrak/master & Kalender Payroll/generate-approval, class diagram (PayPeriod, Holiday, WorkPattern, KontrakPeriode, RunAdjustment, PenyetujuPayroll, setelan Company), sequence generate-approval dan unduh slip, ERD (`pay_periods`, `pay_run_adjustments`, `pay_work_patterns`, kolom baru `companies`, `pay_holidays`, `departemen`, `karyawan`). File `.drawio` belum digambar ulang.
- Hasil: `php artisan test` 232/232.

## 68. Modul payroll lama dihapus; rapikan detail payroll (2026-11-14)

Keputusan pemilik: "modul lama di hapus saja". Ini mengganti prinsip AGENTS.md bahwa modul lama hanya dinonaktifkan.

- **Dihapus (route, controller, view):** Kontrak lama (+ unduh kontrak PDF), Proses Payroll lama, Riwayat/Laporan Payroll lama (Excel/PDF), Approval lama, Slip Gaji lama, Import Excel lama, Komponen Gaji lama, Pajak & Tarif TER lama, Periode (master payroll lama), Cuti/Izin/Sakit lama, Lembur lama, input & rekap Absensi lama (HR harian/semua, Absensi Tim Manager), Karyawan Payroll lama (`/pay/karyawan`), dashboard HR/Manager/Karyawan versi lama, middleware `modul-lama` (`AlihkanModulLama`), helper lama di `Controller`, partial/komponen yang tidak dipakai lagi (`filter-periode`, `tab-kehadiran`, `hapus`, `x-pilih-periode`, `x-riwayat-approval`).
- **Tetap:** menu Absensi hanya riwayat milik karyawan sendiri dari modul payroll. `ModulPayroll::aktif()` selalu `true`. Sidebar tidak pernah menampilkan menu lama walau hak lamanya masih ada di database. Notifikasi lama diarahkan ke halaman modul payroll yang setara. Pencarian header memakai slip modul payroll.
- **Tidak dihapus (sengaja):** tabel dan migration lama, model lama, `PayrollService`/`ApprovalService`, dan data contoh lama di `DatabaseSeeder` (masih dipakai seeder/test sebagai data karyawan). Menghapus tabel butuh migration baru dan backup; belum dilakukan.
- **Test dihapus:** seluruh tes karakterisasi modul lama (`tests/Feature/Karakterisasi`, snapshot halaman lama), `AutoApprovePayrollTest`, `KontrakPerbaruiTest`, `ManagerTidakLihatGajiTest`, `MasterPayrollPeriodeTest`, `MultiManagerRumusTest`. Tes multi-tenant/payroll yang menyebut route lama disesuaikan (sekarang 404).
- **Detail payroll (`/pay/run/{id}`):** kolom Karyawan dan Gaji bersih tetap terlihat saat tabel rincian digeser ke samping; penyesuaian menampilkan nama/NIK dan jenis/keterangan pajak dua baris. Dicek di browser dengan data UJI.
- Hasil: `php artisan test` 198/198.

## 69. Kalender Payroll masuk grup Payroll (2026-11-14)

Keputusan pemilik: menu Kalender Payroll dipindah dari grup Konfigurasi ke grup **Payroll** (urutan: Kalender Payroll, Master Payroll, Generate Payroll, Approval). `TataMenu` diperbarui dan migration `2026_11_22_000007_pindah_kalender_payroll_ke_grup_payroll` menyusun ulang tabel menus yang sudah ada (hanya parent_id/urutan; rollback mengembalikan ke Konfigurasi). Breadcrumb semua halaman modul payroll diganti dari "Payroll Baru / …" menjadi "Payroll / …" (modul lama sudah dihapus). Route dan hak akses tidak berubah. Hasil: 198/198.

## 70. Nama "Baru" dihapus; kartu ringkasan detail payroll (2026-11-14)

- Karena modul lama sudah dihapus, penamaan "Baru" diganti nama biasa: `app/Http/Controllers/PayrollBaru` → `app/Http/Controllers/Payroll` (namespace `App\Http\Controllers\Payroll`), `MasterPayrollBaruController` → `MasterPayrollController`, `resources/views/payroll-baru` → `resources/views/payroll`, `routes/payroll-baru.php` → `routes/payroll.php`, view dashboard `*-payroll-baru` → `hr`/`manager`/`karyawan`, `absensi/saya-payroll-baru` → `absensi/saya`, `AlihkanKePayrollBaru` → `AktifkanModulPayroll`, `tests/Feature/PayrollBaru` → `tests/Feature/Payroll`, `ModulPayrollBaruTest` → `ModulPayrollTest`, method dashboard/absensi tanpa akhiran PayrollBaru. Grup menu baru bernama "Payroll" (database lama bernama "Payroll Baru" tetap dikenali). Label "Payroll Baru"/"Master Payroll Baru" → "Buat Payroll"/"Buat Master Payroll". Kolom `companies.payroll_baru` dan nama migration lama tidak diganti (butuh migration; tidak berpengaruh ke tampilan). Catatan lama di AUDIT tetap memakai nama lama.
- Detail payroll: kartu ringkasan memakai grid rata (`minmax(210px, 1fr)`), ukuran nominal menyesuaikan lebar, kartu Gaji bersih di depan dan dua kolom (menonjol), label/keterangan seragam, potongan berwarna merah. Checklist kesiapan dengan tinggi minimum seragam dan form "Master payroll" (pilihan + tombol + teks bantu) dirapikan. Dicek di browser lebar 1366 dan 1024. Hasil: 198/198.

## 71. Detail payroll: section Master payroll, kartu penuh, kolom Gaji bersih (2026-11-14)

- Section "Master payroll" jadi satu baris: judul + teks bantu di kiri, pilihan master + tombol "Hitung ulang"/"Lihat preview" di kanan.
- Kartu ringkasan memakai flex-wrap (`flex: 1 1 200px`) sehingga setiap baris terisi penuh tanpa sisa kosong; warna merah pada potongan dihapus (cukup tanda "−"); kartu Gaji bersih tidak lagi dua kolom, hanya bingkai dan angka biru.
- Tabel departemen: urutan kolom Kode, Departemen/manager, Karyawan, Gaji bersih, Aksi (Gaji bersih tidak lagi terselip di antara kode dan nama).
- Tabel karyawan: kolom Gaji bersih (lengket di kanan) diberi latar biru muda dan angka biru, lebar minimum 160px. Dicek di browser lebar 1366. Hasil: 198/198.

## 72. Karyawan dummy massal di perusahaan UJI (2026-11-14)

- `database/seeders/KaryawanMassalSeeder.php` (dijalankan manual setelah `FlowUjiSeeder`, tidak masuk `DatabaseSeeder`): 110 karyawan fiktif (NIK `M0001`–`M0110`), akun `m001.uji`… (password `password`), departemen baru IT, MKT, GDG (pola baru `gudang` Senin–Sabtu), kontrak dengan gaji bervariasi (acak tetap `mt_srand(2026)`), sebagian hampir habis, masuk/resign di tengah periode, absensi/leave/lembur 26 Sep – 25 Des 2026 mengikuti pola hari kerja (insert massal dengan `company_id` eksplisit). Aman dijalankan ulang. Test `KaryawanMassalSeederTest`.
- Dicek di dev: preview payroll November untuk 122 karyawan selesai ±1,6 detik (dijalankan dalam transaksi lalu di-rollback).
- Backup database dev sebelum pembersihan data DEFAULT: `storage/backups/` (diabaikan git).

## 73. BPJS dihitung otomatis dari gaji pokok (2026-11-14)

- Keputusan pemilik: BPJS tidak lagi diketik di kontrak. Komponen `bpjs_tk` dan `bpjs_kes` di Katalog Komponen tidak lagi "nilai dari kontrak", sehingga hilang dari form kontrak (nilai lama di kontrak lama dibiarkan, tidak dipakai rumus).
- Master Payroll: `ROUND(IF(batas_upah_bpjs_x > 0, MIN(CONTRACT('gaji_pokok'), batas_upah_bpjs_x), CONTRACT('gaji_pokok')) * tarif_bpjs_x / 100)`. Parameter `tarif_bpjs_kes` = 1, `tarif_bpjs_tk` = 2, `batas_upah_bpjs_*` = 0 (tanpa batas; HR mengisi sesuai peraturan, angka tidak dikarang). Memakai gaji pokok kontrak penuh (bukan hasil prorata). Kasus khusus lewat "Tambah penyesuaian".
- Kode: tidak ada perubahan mesin rumus. Seeder: `FlowUjiSeeder::pasangBpjs()` dipakai `FlowUjiSeeder` dan `PayrollDemoSeeder`; `DataPerusahaanSeeder` dan `KaryawanMassalSeeder` tidak lagi mengisi nilai BPJS kontrak. Test `FlowUjiSeederTest` memeriksa Kes 1% dan TK 2%.
- Dev (perusahaan UJI): versi baru Master "Payroll Usual" (salinan + BPJS rumus) diterbitkan; katalog BPJS diubah. Versi lama tetap tersimpan.

## 74. Lima perbaikan tampilan dan data (2026-11-14)

- Kalender Payroll › Hari Libur: daftar di bawah kalender hanya berisi libur bulan yang ditampilkan (dan departemen yang dipilih). Hook baru `MasterSederhanaController::saringDaftar()`, dipakai `LiburController`.
- Nama kontrak dummy berupa kode, format nomor kontrak aplikasi lama: `PKWT/OPS/0012/XI/2026` (`DataPerusahaanSeeder::namaiKontrak()`, dipakai semua seeder). Kontrak UJI di dev dinamai ulang.
- Master Payroll: master yang belum pernah terbit (semua versi draft, tidak dipakai payroll) bisa dihapus (`KelolaMasterPayroll::hapusMaster`); master terbit hanya bisa dinonaktifkan/diaktifkan (`ubahStatus`, kolom Status, tombol power). Penyebab "Default" tidak bisa dihapus: sebelumnya hanya ada hapus versi, dan satu-satunya versi tidak boleh dihapus. Kode/nama "usual"/"Payroll Usual" diganti "default"/"Payroll Default" di seeder, test, dokumen, dan dev (draft "default" buatan pemilik yang kosong dihapus atas permintaannya).
- Generate Payroll: catatan "Payroll yang di-generate bisa disetujui oleh …" dihapus.
- Katalog Komponen (dan semua tabel MasterSederhana): kolom Aksi rata tengah; tombol nonaktifkan memakai gaya netral `btn-light` ikon `power-off` (hapus fisik tetap merah `trash-2`).

## 75. Seeder skenario multi-tenant dan akun Super Admin (2026-11-14)

- `FlowUjiSeeder` membuat akun `superadmin` (company_id NULL, role Super Admin) bila belum ada.
- `SkenarioMultiTenantSeeder`: FlowUji + KaryawanMassal + perusahaan MTS (PT Maju Terus Sejahtera, 26–25, varian a) dan SBR (PT Sinar Baru Raya, kalender bulanan, varian b) lewat `PayrollDemoSeeder::perusahaan()` (dibuat public). Akun `admin|hr|manager|staf.<kode>`. Test `SkenarioMultiTenantSeederTest` (isolasi kontrak antar perusahaan, daftar perusahaan Super Admin).
- Dev: dijalankan; MTS dan SBR dibuat. Perusahaan DEFAULT belum dibersihkan (menunggu konfirmasi pemilik).

## 76. Nama perusahaan data uji, hapus/nonaktif perusahaan, ikon aksi (2026-11-14)

- Perusahaan data alur (`FlowUjiSeeder::KODE`) bukan lagi "UJI – PT Uji Alur" tetapi **MTS – PT Maju Terus Sejahtera**; akun `admin|hr|manager|staf.mts`, karyawan massal `m001.mts`…; perusahaan kecil di `SkenarioMultiTenantSeeder` menjadi **BNS – PT Bina Nusa Sentosa** (NIK `N…`). Tidak ada lagi teks "uji" di data yang tampil. Dev dinamai ulang di tempat (kode, nama, username, email; slip PDF MTS dibuat ulang).
- Super Admin (keputusan pemilik: dua tombol): **Nonaktifkan** (soft delete, ikon `power-off`/`power`, data tetap, bisa diaktifkan lagi; login dan sesi akun perusahaan nonaktif ditolak di `AuthController` dan `AkunAktif`) dan **Hapus permanen** (ikon `trash-2`, drawer konfirmasi ketik kode perusahaan) lewat `App\Domain\Company\Actions\HapusPerusahaan`: semua baris ber-`company_id` dihapus dalam satu transaksi dengan urutan mengikuti foreign key (rujukan melingkar master↔versi dan user→approver diputus dulu), lalu folder `payslips/{id}`, `signatures/{id}`, `imports/{id}`. DEFAULT (templat role) tidak bisa dihapus/dinonaktifkan. Test di `SuperAdminTest` dan `SkenarioMultiTenantSeederTest` (hapus MTS lengkap tanpa sisa baris).
- Semua kolom aksi tabel: header "Aksi" rata tengah, sel rata tengah, tombol berupa ikon `btn-icon` bertooltip (`eye` buka/lihat, `pencil` ubah, `trash-2` hapus merah, `power-off` nonaktifkan, `download`, `refresh-cw` perbarui kontrak, `check` simpan pola). Tombol teks di baris tabel (Buka, Lihat, Perbarui, Hapus pengecualian, Simpan) diganti ikon.
- Catatan dev: saat dicek, perusahaan BNS dan SBR sudah tidak ada dan MTS bernama "PT Mitra Tiga Sepakat" (diubah pemilik lewat aplikasi pukul 17:16); tidak dibuat ulang.
- Detail payroll (lanjutan): kolom Gaji bersih tidak lagi berlatar biru (sebelumnya selector `:last-child` ikut mewarnai sel kosong "Belum ada data preview" dan header jadi dua warna). Sekarang kelas `kol-thp`: header sama seperti kolom lain, angka biru tebal, tetap lengket di kanan. Saat belum ada preview, tabel tidak ditampilkan, hanya empty state dengan petunjuk "Lihat preview". Badge "Belum siap" merah, selaras dengan ikon item wajib yang belum terpenuhi.

## 77. Penyetuju payroll diatur sendiri (user dan/atau role) (2026-11-14)

- Keputusan pemilik: penyetuju tidak lagi otomatis "Manager di departemen HR". Tabel baru `pay_payroll_approvers` (migration `2026_11_22_000008`, bisa di-rollback; backfill = penyetuju menurut aturan lama sebagai user agar generate tetap jalan). Model `PayrollApprover`.
- `PenyetujuPayroll`: boleh = akun aktif, role punya hak "Approval Payroll: approve", dan (user-nya dipilih ATAU role-nya dipilih). Tidak ada syarat jabatan/departemen. `daftar()` = gabungan user terpilih + anggota role terpilih. Kandidat yang bisa dipilih hanya role/user dengan hak approve (hak menu tetap diatur di Manajemen Role).
- Menu Approver Payroll: kartu "Siapa yang menyetujui payroll?" (pilih banyak role dan user), tabel "Penyetuju yang berlaku" dengan kolom "Dipilih lewat". Simpan kosong ditolak selama payroll butuh approval. Route `PUT pay/approver/penyetuju` (hak update Approver).
- Teks "Manager HR" di modul payroll diganti "penyetuju"; checklist kesiapan item Approval: "Penyetuju sudah diatur" / "Belum ada penyetuju; atur di menu Approver Payroll".
- Seeder: karyawan pertama (akun `manager.<kode>`) kini berjabatan **Manager** (posisi `MGR`), dan dipilih sebagai penyetuju lewat `PayrollApprover`. Test `ModulPayrollTest::test_penyetuju_payroll_diatur_lewat_role_atau_user`; `SiapkanPayroll` memilih manager.hr dan manager.hr2.
- Revisi (masukan pemilik): **default tanpa approval** dan tanpa penyetuju. Perusahaan baru (`BuatPerusahaan`) dibuat dengan `payroll_butuh_approval = false`; seeder tidak memilih penyetuju; migration `000008` tanpa backfill (diubah sebelum di-commit; di dev sudah terlanjur jalan, baris Adi dihapus manual atas permintaan pemilik dan MTS diset tanpa approval). Satu form di Approver Payroll: "Payroll perlu persetujuan?" + (bila Ya) role/user penyetuju **wajib** minimal satu (validasi saat simpan; generate tetap ditolak bila kosong; checklist kesiapan menandai item Approval). Semua role dan semua user aktif bisa dipilih (pilihan bisa dicari, multi). Penyetuju terpilih otomatis bisa membuka menu Approval walau role-nya tanpa hak menu (middleware `penyetuju-payroll`, sidebar), setujui/tolak dicek di `AlurPayroll`. Jabatan Adi Pratama di dev diubah ke Manager.

## 78. Database dev dibersihkan: hanya Super Admin dan MTS (2026-11-14)

- Atas permintaan langsung pemilik ("dibersihin aja ... hanya perusahaan mts"): backup `storage/backups/dev-20261114-181122-sebelum-bersih.sql`, lalu semua perusahaan selain DEFAULT dihapus permanen (`HapusPerusahaan`), DEFAULT dikosongkan menjadi templat murni (`HapusPerusahaan::kosongkanTemplat()`: hanya `roles`, `privileges`, `approval_mapping` yang disimpan; 120 karyawan, 15 user, data payroll lama terhapus), lalu `FlowUjiSeeder` + `KaryawanMassalSeeder` dijalankan ulang. Isi akhir: `superadmin`, perusahaan MTS (122 karyawan, 114 akun), tanpa approval bawaan.
- DEFAULT tanpa karyawan tidak tampil di daftar perusahaan maupun dashboard Super Admin (tetap dipakai sebagai templat role saat membuat perusahaan baru).
- `DataBersihSeeder`: langkah yang sama untuk database baru (`migrate:fresh --seed` lalu `db:seed --class=DataBersihSeeder`). Test `SuperAdminTest::test_default_dikosongkan_jadi_templat_dan_disembunyikan`.

## 79. Tabel departemen di detail payroll dan manager departemen (2026-11-14)

- Tabel ringkasan departemen sebelumnya hanya 5 kolom sehingga ruang tengah kosong. Percobaan membatasi lebar kartu (880px) dibatalkan karena tabel jadi kecil. Sekarang tabel tetap selebar kartu lain dan diisi kolom informatif: Kode, Departemen/manager, Karyawan, Pendapatan, Potongan, Gaji bersih, Aksi (kolom "Porsi" sempat dicoba lalu dihapus atas permintaan pemilik). Query `perDepartemen` ditambah `sum(total_earnings)` dan `sum(total_deductions)`. Dicek di browser lebar 1366 (tanpa scroll horizontal) dan 1920.
- Seeder: Adi Pratama hanya manager HRD (sebelumnya juga Operasional). Setiap departemen punya manager sendiri: HRD Adi, FIN dari FlowUjiSeeder, OPS/IT/MKT/GDG karyawan massal pertama di departemen itu. Dev (MTS) disamakan; sebelum karyawan massal dijalankan, Operasional tanpa manager.

## 80. Multi select: klik pilihan terpilih membatalkan; filter jadi form biasa (2026-11-14)

- Bug: di Tom Select, klik opsi yang sudah terpilih pada multi select tidak membatalkannya. `form.js` menambah `toggleMulti()` (klik/mousedown fase capture pada `.option.selected` memanggil `removeItem`) dan memakainya di dua varian multi select (ringkas untuk filter, chips untuk pilihan penyetuju; chips tidak lagi menyembunyikan opsi terpilih, melainkan menampilkan centang). Diuji di browser: pilih Finance lalu klik lagi → kosong.
- Form filter `data-auto-submit` kini form biasa (`data-turbo="false"`): halaman dimuat ulang penuh, tanpa Turbo/fetch; posisi scroll tetap dipulihkan lewat `sessionStorage`.
- Livewire belum terpasang dan tidak dipakai (stack: Blade, Bootstrap 5, Turbo Drive, Tom Select, JS biasa; fetch hanya untuk pencarian cepat header dan pratinjau rumus). Pemasangan menunggu keputusan pemilik karena menambah dependensi (AGENTS.md: tanya dulu).

## 81. Percobaan Livewire dibatalkan (rollback) (2026-11-14)

- Livewire sempat dipasang dan beberapa halaman diubah, tetapi tampilan berubah dan pemilik memutuskan TIDAK memakai Livewire. Rollback: `livewire/livewire` dicabut (composer.json/lock kembali sama dengan HEAD), Turbo dikembalikan, semua halaman (Approver Payroll, detail Generate Payroll, Absensi/Leave/Lembur, Katalog Komponen, Jabatan, Tipe Leave, Kalender Payroll, Approval) kembali ke controller + Blade; test dikembalikan ke versi HTTP. Snapshot sebelum rollback: `storage/backups/proyek-sebelum-rollback-livewire-*.tgz` dan `diff-*.patch`.
- Tetap dipertahankan: perbaikan toggle multi select (Tom Select), filter jadi form biasa, `Actions\AturApprover`, `Actions\KunciLibur`, penyetuju kustom, BPJS otomatis, tabel departemen, dan lainnya.
- Arahan pemilik berikutnya: AJAX hanya untuk aksi tertentu (perpindahan tab Kalender Payroll, pencarian cepat, pratinjau rumus); sisanya form biasa. Perpindahan tab tanpa reload BELUM dibuat.


## 82. QA otomatis end to end (2026-11-15)

Dua agen QA menguji paralel lewat HTTP (curl) di server uji dengan database SQLite sementara (data dev tidak disentuh): (A) daftar perusahaan, setup admin, hak akses, nonaktif/hapus perusahaan, sampai generate dan slip; (B) demo MTS: preview 122 karyawan, penyesuaian, approval, slip, kunci periode, import CSV, crawl ±150 halaman per peran.
- Lulus: isolasi tenant (id perusahaan lain 404/403), pesan validasi bahasa Indonesia, nonaktif/hapus perusahaan, approval wajib penyetuju, tarik kembali/tolak/setujui, kunci hari libur, import CSV dengan preview, slip berpassword dan akses hanya milik sendiri, tidak ada link rusak.
- Bug ditemukan dan diperbaiki: (1) Cut Off tidak bisa disimpan dari form browser (nilai form berupa teks, perbandingan ketat dengan angka selalu gagal; test lama memakai angka sehingga lolos) → `CutoffController` meng-cast angka, test baru memakai teks; (2) username dengan titik ditolak di Manajemen User padahal akun seed memakainya → aturan disamakan dengan akun admin perusahaan; (3) finalisasi payroll dengan antrean `sync` kena batas 30 detik (PDF 122 slip) → `TerbitkanSlip` memanggil `set_time_limit(0)`; (4) slip "Terbit" tanpa file PDF memberi 404 → unduh membuat ulang PDF bila file belum ada.
- Catatan: BPJS memakai gaji pokok kontrak penuh (bukan hasil prorata), sesuai §73; template CSV memakai tanggal contoh 2026-11-13 yang masuk periode final; header memakai branding aplikasi untuk semua tenant. Update Figma dan diagram draw.io belum dikerjakan.
