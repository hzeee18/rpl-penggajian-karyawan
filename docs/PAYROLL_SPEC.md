# PAYROLL_SPEC.md (spesifikasi teknis modul payroll)

Acuan teknis modul payroll baru. Dibaca bersama `AGENTS.md` (aturan kerja di branch ini). Bagian "Stack" hanya acuan: versi framework mengikuti proyek yang ada, jangan di-upgrade. Skema database: `docs/schema.sql`.
Dokumen kebutuhan lengkap ada di doc "Kebutuhan Sistem Penggajian Dinamis".

## Ringkasan produk

Aplikasi payroll multi-perusahaan (multi-tenant). Bukan HRIS: absensi, leave, dan lembur masuk lewat CSV.
Semua aturan perusahaan (variabel, rumus, komponen, tarif) disimpan di **database** dan diatur HR. Kode hanya berisi mesin yang mengevaluasinya.

## Stack

- PHP **8.5.12**, Laravel **13** (Laravel 12 juga mendukung PHP 8.5; Laravel 13 butuh PHP >= 8.3). Cek ulang dengan `composer why-not php 8.5.12` sebelum menaikkan versi.
- MySQL 8.0.16+ (perlu `CHECK` constraint dan generated column).
- Queue: database atau Redis, untuk preview dan generate payroll di background.
- Disarankan: `maatwebsite/excel` atau `league/csv` (import CSV), `mpdf/mpdf` (PDF slip dengan password, `SetProtection`), `bcmath` (uang).
- Test: Pest atau PHPUnit, `phpstan` level 8, `laravel/pint`.

## Perintah

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan queue:work --tries=1 --timeout=0
php artisan test
vendor/bin/pint
vendor/bin/phpstan analyse
```

## Aturan utama (jangan dilanggar)

1. **Tidak ada aturan perusahaan di kode.** Dilarang menulis `if ($company->code === 'A')`, tarif lembur, pembagi uang makan, tarif TER, atau nama komponen sebagai konstanta. Kalau sebuah aturan butuh perubahan kode agar bisa berbeda antar perusahaan, desainnya salah: pindahkan ke variabel, parameter, komponen, atau tabel referensi.
2. **Multi-tenant.** Semua tabel berdata perusahaan punya `company_id`. Gunakan global scope `BelongsToCompany` pada model, dan jangan pernah query lintas perusahaan kecuali di konteks Super Admin. Tabel TER nasional memakai `company_id = NULL`.
3. **Update = data baru.** Kontrak, master payroll, dan tabel referensi berversi. Versi yang sudah dipakai payroll final (`locked_at` terisi) tidak boleh diubah, hanya dibuat versi baru (salin).
4. **Payroll final tidak boleh berubah.** Setelah status `pending_approval`, data run tidak bisa diedit. Slip diambil dari snapshot `payroll_run_*`, bukan dari kontrak atau master terkini.
5. **Uang.** Simpan `DECIMAL(15,2)`. Hitung dengan `bcmath` atau string desimal, jangan `float`. Pembulatan ke rupiah terdekat (half up) pada nilai akhir tiap komponen.
6. **Data sensitif** (`npwp`, `bank_account_no`) memakai cast `encrypted` Laravel. Jangan di-log, jangan ditampilkan penuh di UI.
7. **Bahasa dan mata uang:** UI berbahasa Indonesia, Rupiah, zona waktu `Asia/Jakarta`.

## Arsitektur yang disarankan

Struktur modular di `app/Domain/` (atau `app/Modules/`):

```
Company/ Auth/ Employee/ Contract/ Attendance/ Leave/ Overtime/ Import/
Component/ Reference/ PayrollMaster/ Payroll/ Payslip/ Audit/
```

Tiap modul: `Models`, `Actions` (satu use case per class), `Policies`, `Http`, `Data` (DTO). Controller tipis, logika di Action/Service.

### Mesin rumus (`Payroll/Engine`)

Komponen inti, dibuat sekali dan diuji berat:

- **Parser ekspresi dengan whitelist.** Jangan pernah `eval()` atau menjalankan PHP dari database. Opsi: `symfony/expression-language` dengan fungsi terdaftar, atau parser sendiri.
- Fungsi tersedia: `IF`, `MIN`, `MAX`, `ROUND`, fungsi tanggal, `WORKDAYS(start, end)` (Senin sampai Jumat dikurangi `holidays`), `LOOKUP(kode_tabel, kunci, nilai)`.
- **Variabel `base`:** dibangun dari `source` + `filter_expression` + `value_expression` + `aggregate` dalam periode cut off. Terjemahkan ke query terparameter atas kolom yang di-whitelist per sumber data (jangan menyambung string SQL dari input HR).
- **Variabel `derived`, parameter, komponen:** dievaluasi berurutan menurut `sort_order`. Rumus hanya boleh merujuk yang ada di atasnya. Tolak simpan bila rujukan tidak dikenal atau sirkular.
- Komponen `item_role = 'pph21'` selalu dievaluasi terakhir. Dasar bruto = jumlah `final_amount` komponen penambah ber-`is_taxable`.
- Komponen `calc_type = 'contract'`: nilai dari `contract_components`; bila komponen tidak ada di kontrak, nilainya 0 dan rumus tetap jalan.
- Sediakan **uji rumus** untuk satu karyawan sebelum versi master dipublikasikan.

### Alur payroll

- Status `payroll_runs.status`: `draft` → `pending_approval` → `approved` | `rejected`. Tidak ada status "Dibayar" (fase 2).
- `job_status` menandai proses background. Preview dan generate dijalankan lewat queue job, pakai chunk (ribuan karyawan). UI cukup menampilkan "sedang diproses" lalu refresh saat selesai.
- Hanya karyawan dengan kontrak `is_active = 1` yang ikut. Karyawan yang sudah punya payroll aktif di periode yang sama di-skip dan dicatat di `skipped_employees`. Penjaga utamanya: unique key `uq_pre_one_active_per_period` (via `is_blocking`); set `is_blocking = 1` saat generate, dan `0` bila run ditolak.
- Edit sel di preview: ubah `final_amount`, set `is_overridden = 1`, **tanpa audit log**. Hitung ulang tidak menimpa sel override. Komponen turunan dihitung dari nilai override. Sediakan aksi "kembalikan ke hasil rumus".
- Draft dihapus fisik. Run yang sudah `pending_approval` ke atas tidak bisa dihapus.
- Manager menolak → HR membuat payroll baru dari awal (karyawan bebas lagi).
- Setelah approve, buat `payslips` (job) dan PDF-nya.

### Kontrak

- Hanya 1 kontrak aktif per karyawan (ada unique key via kolom generated `active_employee_id`). Memperbarui kontrak: nonaktifkan yang lama, buat yang baru dengan `previous_contract_id`, salin nilai komponen.
- Nilai default komponen **disalin** ke `contract_components` saat kontrak dibuat; perubahan katalog tidak memengaruhi kontrak lama.
- Kontrak kedaluwarsa dinonaktifkan **manual** oleh HR. Sistem hanya memberi peringatan sebelum preview.

### Import CSV

Alur wajib: upload → simpan ke `import_batches` + `import_batch_rows` → preview (baris error merah, misal NIK tidak ditemukan) → perbaiki atau hapus baris → insert. Kolom CSV tetap per tipe. Tolak absensi dan leave di tanggal yang sama untuk karyawan yang sama. Leave di Sabtu atau Minggu diabaikan. Data periode yang sudah final dikunci.

### Slip PDF

- Memuat nama perusahaan, tanda tangan (`companies.signer_*`, `signature_path`), rincian komponen dengan nilai akhir, dan THP.
- Password PDF: **2 digit pertama `employee_no` + 4 digit terakhir nomor rekening** (dekripsi hanya saat generate).
- Karyawan hanya bisa melihat slip miliknya (policy: `payslips.employee_id` = `users.employee_id`).

### Akses

Satu user satu role. Privilege diambil dari `role_menu_permissions` (lihat, tambah, ubah, hapus, approve), bukan di-hardcode. Terapkan lewat Policy/Gate yang membaca tabel tersebut. Super Admin (`company_id NULL`) mengelola perusahaan dan tabel TER nasional.

## Konvensi kode

- `declare(strict_types=1);` di setiap file. Pakai `readonly` class, enum PHP (backed enum) untuk status, dan tipe eksplisit.
- Nama tabel/kolom snake_case (ikuti `schema.sql`). Migration Laravel dibuat dari DDL ini; kolom generated memakai `->virtualAs()` / `->storedAs()` atau `DB::statement`.
- Satu use case = satu Action class dengan method `handle()`. Operasi yang menyentuh banyak tabel dibungkus `DB::transaction`.
- Validasi lewat Form Request. Pesan error bahasa Indonesia.
- Audit trail via `AuditLogger` (tabel `audit_logs`) untuk generate, approve/reject, kontrak, master payroll, variabel, tabel referensi, dan master data. **Jangan** mencatat edit sel preview.
- Jangan menambah dependensi baru tanpa alasan. Utamakan memakai fitur Laravel yang sudah ada.

## Testing

Wajib ada tes untuk:

- Parser dan evaluator rumus: operator, fungsi, rujukan tak dikenal, sirkular, pembulatan.
- Variabel `base` per sumber data, termasuk filter dan periode lintas bulan.
- Skenario Perusahaan A: uang makan `kontrak / 22 × (hadir + cuti)`, potongan alpha, lembur `25.000 × jam`.
- Lookup tabel referensi (TER) di batas rentang.
- Pencegahan payroll ganda, ditolak lalu dibuat ulang, override tidak tertimpa hitung ulang.
- Isolasi tenant: user perusahaan A tidak bisa membaca data perusahaan B.
- Password dan akses slip.

## Di luar scope (fase 2)

PPh21 masa Desember dan resign, laporan (rekap, export transfer bank, bukti potong 1721-A1), multi-level approval dan delegasi, notifikasi, status "Dibayar". Tetap simpan `tax_monthly_histories` sejak awal agar fase 2 tidak butuh migrasi besar.

## Yang boleh dan tidak boleh dilakukan agen

- Boleh: membuat migration, model, action, test, seeder dari `schema.sql` dan dokumen kebutuhan.
- Tanya dulu sebelum: mengubah struktur tabel yang sudah ada di `schema.sql`, menambah role/status baru, atau menyimpang dari aturan di atas.
- Jangan: menaruh aturan perusahaan di kode, memakai `float` untuk uang, menjalankan ekspresi dari DB lewat `eval`, atau menghapus data yang seharusnya dinonaktifkan (karyawan, komponen).
