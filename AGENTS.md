# AGENTS.md

Branch ini melakukan dua hal pada aplikasi yang sudah ada (dibuat orang lain):

1. Mengubah **seluruh aplikasi menjadi multi-tenant** (multi-perusahaan).
2. Membangun **modul penggajian baru** yang dinamis di atasnya.

Semua pekerjaan dilakukan di branch terpisah. Branch `main` tidak disentuh dan tetap jalan seperti semula. Perilaku bisnis fitur lama (di luar payroll) **tidak boleh berubah**, hanya dibatasi per perusahaan.

Dokumen wajib dibaca sebelum bekerja:

- `docs/PAYROLL_SPEC.md`: aturan teknis dan alur modul payroll baru.
- `docs/schema.sql`: skema target (acuan, bukan kondisi database sekarang).
- `docs/AUDIT.md`: hasil audit Fase 0.
- Doc "Kebutuhan Sistem Penggajian Dinamis": aturan bisnis lengkap.

## Prinsip

1. **Multi-tenant menyeluruh.** Setiap data milik perusahaan punya `company_id` dan dibatasi otomatis lewat global scope. Ini berlaku untuk model lama dan baru. Super Admin (platform) adalah satu-satunya yang boleh melihat lintas perusahaan.
2. **Perilaku lama dipertahankan.** Yang diubah hanya pembatasan per perusahaan. Jangan mengubah logika bisnis, tampilan, atau alur fitur lama. Setelah multi-tenant, aplikasi dengan satu perusahaan default harus berperilaku sama seperti sebelumnya.
3. **Modul payroll lama jangan dihapus.** Cukup dibuat tenant-aware agar tidak merusak fitur yang bergantung padanya (penguncian absensi, filter periode, dashboard, import). Modul baru dipasang berdampingan; modul lama dinonaktifkan hanya setelah modul baru diterima.
4. **Ikuti proyek yang ada** untuk gaya kode, struktur folder, login, layout, komponen UI, dan versi framework/PHP. Jangan upgrade framework, ganti library, atau refactor massal.
5. **Aturan perusahaan tidak boleh ada di kode.** Lihat `docs/PAYROLL_SPEC.md`.
6. **Satu tugas, satu tujuan.** Temuan di luar tugas dilaporkan, tidak diperbaiki sendiri.

## Aturan multi-tenant

- Pakai satu trait (misal `BelongsToCompany`) yang memasang global scope dan mengisi `company_id` otomatis saat data dibuat (dari user login). Pasang di **semua** model bertenant.
- Global scope tidak berlaku untuk `DB::table()`, query mentah, `join` manual, export, dan job. Semuanya **wajib diaudit dan diberi filter `company_id` eksplisit**. Cari dengan `grep` (`DB::table`, `DB::select`, `whereRaw`, `selectRaw`, `->join(`).
- Job queue, event/notifikasi, dan cache membawa konteks perusahaan. Kunci cache harus memuat `company_id`. File upload dan PDF disimpan di folder per perusahaan.
- Unique index pada data bertenant dibuat per perusahaan, misal `unique(company_id, kode)`. Kecuali `users.username` yang tetap unik global (login tanpa memilih perusahaan).
- `company_id` awalnya nullable, diisi perusahaan default untuk semua data lama, lalu diubah `NOT NULL` pada migration terpisah setelah backfill diverifikasi.
- Tabel nasional (misal tarif TER) memakai `company_id NULL` dan hanya diubah Super Admin.
- Wajib ada **tes kebocoran tenant**: untuk tiap model bertenant, buat data di perusahaan A dan B, login sebagai A, pastikan tidak ada data B di halaman, API, export, dan dashboard.

## Fase 0: Audit (selesai)

Hasilnya di `docs/AUDIT.md`. Jika ada hal baru yang ditemukan, tambahkan ke file itu, jangan menimpanya.

## Fase kerja berikutnya

Satu fase = rangkaian commit kecil. Setiap fase selesai dengan test lulus dan ringkasan. Jangan melompat fase. Jika butuh keputusan yang belum ada, berhenti dan tanya.

1. **Multi-tenant aplikasi lama**
   - 1a. Schema: tabel `companies`, kolom `company_id` nullable di semua tabel bertenant, backfill perusahaan default, unique index per perusahaan.
   - 1b. Scope: trait `BelongsToCompany` dipasang di semua model bertenant, auto-isi `company_id`.
   - 1c. Audit dan perbaikan semua query yang melewati scope (raw, `DB::table`, join, export, job, cache, file).
   - 1d. Login, role Super Admin, pemilihan perusahaan untuk Super Admin, privilege per perusahaan.
   - 1e. Tes kebocoran tenant dan regresi fitur lama. Ubah `company_id` jadi `NOT NULL`.
2. **Data master payroll**: karyawan (kolom tambahan), jabatan, kalender libur, tipe leave, katalog komponen.
3. **Kontrak** berversi + komponen kontrak.
4. **Absensi, leave, lembur** + import CSV dengan preview.
5. **Tabel referensi** dan **mesin rumus** (variabel, parameter, komponen, master payroll berversi).
6. **Alur payroll**: preview, edit/override, generate (queue), approval Manager.
7. **Slip PDF** dengan password dan akses karyawan.
8. **Seed data dan test akhir.**

Fase 1 paling berisiko karena menyentuh kode lama. Test lama sedikit, jadi sebelum 1b buat **tes karakterisasi** (merekam perilaku sekarang) untuk halaman dan alur penting, atau daftar cek manual yang saya setujui, agar regresi ketahuan.

## Database dan migration

- Hanya **migration baru**. Dilarang mengedit migration lama, `migrate:fresh` di database yang berisi data, `db:wipe`, atau menghapus tabel/kolom yang ada.
- Setiap migration harus bisa di-rollback. Tampilkan isinya dan tunggu persetujuan sebelum menjalankannya.
- Backup database dev sebelum migration yang memodifikasi data lama (backfill).
- Jalankan hanya di database development/lokal. Jangan pernah menyentuh produksi atau staging.
- Test memakai database MySQL khusus test, tidak boleh sama dengan database dev.
- Jangan membaca atau mencetak `.env`.
- Tabel payroll baru diberi prefix `pay_` kecuali `companies` dan `audit_logs`. Tabel bersama yang sudah ada (`users`, `roles`, `menus`, `privileges`, `karyawan`, `departemen`) ditambah kolom lewat migration baru, tidak dibuat ulang.

## Keputusan yang sudah diambil

(Isi dari jawaban pemilik atas §8 `docs/AUDIT.md`. Selama belum diisi, tanyakan, jangan berasumsi.)

Diputuskan 2026-11-11, rincian di `docs/AUDIT.md` §9.

- Pendekatan tabel bersama vs baru: tabel bersama yang sudah ada ditambah kolom (migration baru); tabel payroll baru diberi prefix `pay_`; `companies` dan `audit_logs` tanpa prefix.
- Unique index per perusahaan: ya, termasuk `roles.nama` dan `departemen.kode` (unique global lama diganti unique per perusahaan). `users.username` tetap unik global.
- Perusahaan default dan backfill: dibuat perusahaan `DEFAULT`; semua data lama diisi `company_id` perusahaan ini.
- Penamaan role dan Super Admin: nama role lama tidak di-rename; ditambah role Super Admin (`company_id NULL`).
- Approver payroll (`users.approver_user_id`): dipakai; isi awal user HR → user Manager HR (manager departemen `HRD` yang punya akun; bila lebih dari satu, id user terkecil).
- Password PDF slip dan library PDF: dompdf (tanpa mpdf); password = 2 digit **terakhir** nomor karyawan + 4 digit terakhir nomor rekening (menggantikan aturan di `PAYROLL_SPEC.md`).
- Strategi test: `pdo_sqlite` aktif (`php artisan test`, SQLite `:memory:`) dan MySQL khusus test `penggajian_test` (`php artisan test --configuration=phpunit.mysql.xml`).
- Login: tetap `username`.

## Seed data (dibuat dari awal, terpisah)

- Buat `PayrollDemoSeeder`, dipanggil manual, **tidak** dimasukkan ke `DatabaseSeeder` bawaan dan tidak menghapus data yang ada.
- Isi minimal:
  - 2 perusahaan (untuk uji isolasi tenant), masing-masing dengan role Administrator, HR, Manager, Karyawan.
  - 10 sampai 20 karyawan per perusahaan, 2 departemen, kontrak aktif, nilai komponen berbeda antar karyawan.
  - Kalender libur nasional 2026 dan konfigurasi cut off (satu lintas bulan, satu kalender bulanan).
  - Absensi, leave (termasuk tipe baru), dan lembur untuk 2 periode, termasuk alpha, terlambat, dan lembur akhir pekan.
  - Master "Payroll Usual" Perusahaan A: uang makan `kontrak / 22 × (hadir + cuti)`, potongan alpha, lembur Rp25.000 per jam, BPJS dari kontrak, PPh21 TER.
  - Master berbeda di Perusahaan B (aturan lembur dan keterlambatan lain).
  - File CSV contoh untuk import (valid dan berisi baris error).
- **Tabel TER dan kategori PTKP: jangan mengarang angka.** Baca dari `database/seeders/data/ter_pph21.csv` yang diisi pemilik dari peraturan resmi. Jika belum ada, lewati seeder TER dan minta.
- Semua data dummy, tanpa nama atau NIK/NPWP/rekening asli. Perusahaan kedua hanya ada di seed dan database dev/test.

## Batas agen

- Boleh: membaca kode, membuat migration/model/action/seeder/test, memasang trait tenant di model lama, memperbaiki query agar tenant-aware, menjalankan test dan perintah baca-saja.
- Tanya dulu: mengubah rumus, menambah status/role baru, menyimpang dari `PAYROLL_SPEC.md`, mengubah perilaku bisnis fitur lama, atau mengubah file di luar yang dibutuhkan tenant atau payroll.
- Jangan: bekerja di branch `main`, memakai `float` untuk uang, `eval` ekspresi dari DB, menghapus data, atau menjalankan perintah destruktif.

## Definisi selesai (tiap tugas)

- Test lama tetap lulus, test baru untuk perilaku yang ditambah.
- Tidak ada perubahan di luar lingkup tugas.
- Migration aman dan bisa di-rollback.
- Ringkasan akhir: apa yang berubah, file yang disentuh, risiko, dan keputusan yang menunggu pemilik.
