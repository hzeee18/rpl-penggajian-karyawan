# Skenario uji payroll dan multi-tenant (data `SkenarioMultiTenantSeeder`)

Persiapan database dari nol (menghapus semua data di database):

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=DataBersihSeeder   # superadmin + MTS (122 karyawan); DEFAULT jadi templat role saja
composer run dev            # atau: php artisan serve + php artisan queue:work
```

Untuk uji isolasi multi-tenant (tambahan perusahaan BNS dan SBR) pakai `SkenarioMultiTenantSeeder` di database terpisah.
Bagian-bagiannya juga bisa dijalankan sendiri: `FlowUjiSeeder` (Super Admin + MTS), `KaryawanMassalSeeder` (+110 karyawan MTS).
Semua aman dijalankan ulang (yang sudah ada dilewati). Perusahaan `DEFAULT` hanya menyimpan templat role untuk perusahaan baru
dan tidak tampil di daftar perusahaan selama tidak berisi karyawan.

Semua password akun: `password`.

| Akun | Perusahaan | Peran |
|---|---|---|
| `superadmin` | – (platform) | Super Admin: kelola perusahaan, tabel nasional (TER), Manajemen Menu; memilih perusahaan untuk melihat datanya |
| `admin.mts` | MTS – PT Maju Terus Sejahtera | Admin perusahaan |
| `hr.mts` (Bunga Lestari) | MTS | HR |
| `manager.mts` (Adi Pratama, Manager HRD) | MTS | Manager (jadi penyetuju bila dipilih di Approver Payroll) |
| `staf.mts` (Dina Kartika, Operasional) | MTS | Karyawan |
| `m001.mts` … `m110.mts` | MTS | Karyawan (data massal) |
| `admin.bns` / `hr.bns` / `manager.bns` / `staf.bns` | BNS – PT Bina Nusa Sentosa | Admin / HR / Manager / Karyawan |
| `admin.sbr` / `hr.sbr` / `manager.sbr` / `staf.sbr` | SBR – PT Sinar Baru Raya | Admin / HR / Manager / Karyawan |

Perbedaan perusahaan:

| | MTS | BNS | SBR |
|---|---|---|---|
| Karyawan | 122 (NIK `U`/`M`) | 15 (NIK `N`) | 12 (NIK `S`) |
| Cut off | 26–25 lintas bulan | 26–25 lintas bulan | kalender bulanan |
| Master Payroll Default | uang makan per hadir+cuti, lembur Rp25.000/jam, alpha, BPJS %, PPh21 TER | sama dengan MTS (tanpa data payroll berjalan) | lembur 1,5x/2x upah jam, potongan terlambat, BPJS TK % |
| Payroll | Okt final, Nov dibuat ulang lewat Generate Payroll | belum ada | belum ada |

Bagian A–H di bawah memakai perusahaan **MTS**. Bagian I menguji multi-tenant.

Kondisi awal:

- **Kalender Payroll**: Oktober 2026 *Final*, November 2026 *Draft payroll*, Desember 2026 *Belum diproses*.
- **Pola hari kerja**: Kantor Senin–Jumat (bawaan), Operasional Senin–Minggu (departemen Operasional).
- **Hari libur**: libur nasional tetap 2026, cuti bersama 24 Des, libur khusus Finance 11 Des.
- **Kontrak**: sebagian besar tetap; Eko Saputra berakhir 31 Jan 2027 (hampir habis), Fajar Nugroho berakhir 31 Des 2027.
- **Prorata**: Rani Karyawan Baru masuk 10 Nov 2026; Sapto Karyawan Resign keluar 15 Nov 2026.
- **Karyawan massal** (bila `KaryawanMassalSeeder` dijalankan): 110 karyawan tambahan NIK `M0001`–`M0110` di departemen
  Operasional, Gudang (pola Senin–Sabtu), Marketing, Teknologi Informasi, Finance, dan HR; gaji pokok Rp4,5–15 juta;
  sebagian kontrak hampir habis, 4 masuk awal November (prorata), 2 resign Desember; absensi 26 Sep – 25 Des dengan alpha,
  terlambat, cuti/sakit/izin, dan lembur. Login masing-masing `m001.mts` … `m110.mts`. Total karyawan MTS menjadi 122.
- **Approval**: bawaan *tanpa approval* (payroll langsung final saat di-generate). Untuk menguji persetujuan, atur dulu di menu Approver Payroll (bagian A).
- **Payroll November** sudah di-preview (12 karyawan awal; klik **Hitung ulang** agar 110 karyawan massal ikut), Candra Wijaya punya penyesuaian Bonus Rp1.000.000 (kena pajak) dan Reimburse Rp250.000.

## A. Setelan (login `admin.mts`)

1. Menu **Approver Payroll**: bawaan "Payroll perlu persetujuan?" = *Tidak*. Ubah ke *Ya* → bagian "Siapa yang menyetujui?" muncul.
   Simpan tanpa memilih → ditolak "pilih minimal satu role atau user". Pilih user `manager.mts` (atau role Manager) → Simpan;
   tabel "Penyetuju yang berlaku" menampilkan siapa saja dan "Dipilih lewat".
2. Menu **Cut Off**: kartu "Gaji untuk karyawan yang tidak bekerja sebulan penuh" = *Hitung per hari kerja*.
3. (Opsional) Menu **Approver**: ubah "PDF slip pakai password?" ke *Tidak*, lalu nanti cek slip terunduh tanpa password.

## B. Kalender Payroll (login `hr.mts`)

1. Tab **Periode**: Oktober berstatus *Final* (hanya ikon kalender), November *Draft payroll*, Desember *Belum diproses*
   (ikon ubah dan hapus aktif).
2. Klik **Buat periode berikutnya** → modal konfirmasi menampilkan Januari 2027 (26 Des 2026 – 25 Jan 2027) → Ya.
3. Ubah tanggal selesai Desember jadi 24 Des → Januari ikut mulai 25 Des. Kembalikan lagi ke 25 Des.
4. Hapus periode Januari (hanya periode terakhir yang bisa dihapus; Desember menampilkan ikon hapus abu-abu).
5. Tab **Hari Libur**: pindah ke Oktober 2026 dengan tombol ‹. Tanggal 26 Sep – 25 Okt bergembok dan tidak bisa diklik
   (payroll Oktober sudah final). Pindah ke Desember, klik tanggal kosong → tambah libur; klik libur → ubah atau hapus.
6. Pilih "Lihat untuk: Operasional": Sabtu dan Minggu tidak lagi tampil sebagai hari istirahat. Pilih "Finance":
   11 Des tampil sebagai libur khusus.
7. Tab **Pola Hari Kerja**: coba tambah pola dengan kode "KW Shift" → tersimpan sebagai `kw_shift`.

## C. Data karyawan dan kontrak (login `hr.mts`)

1. Menu **Kontrak**: tombol **Perbarui** hanya muncul di baris Eko Saputra (berakhir ≤ 3 bulan). Chip *Segera berakhir* = 1.
2. Buka detail Fajar Nugroho → tombol **Perubahan gaji** (untuk kenaikan gaji walau kontrak masih lama).
3. Form kontrak tidak lagi berisi BPJS. BPJS dihitung di Master Payroll: Kes 1% dan TK 2% dari gaji pokok kontrak
   (parameter `tarif_bpjs_*` dan `batas_upah_bpjs_*`, batas 0 = tanpa batas).
4. Menu **Absensi / Leave / Lembur**: filter Periode (dropdown dari Kalender Payroll), "Pilih tanggal sendiri…",
   filter departemen dan karyawan (bisa banyak).
5. **Import CSV**: Unduh Template → Absensi (berisi NIK karyawan MTS). Tambahkan satu baris dengan NIK salah (mis. `X999`)
   → Upload File → preview menampilkan kolom NIK, Nama karyawan, Hasil cek, baris salah berwarna merah → perbaiki atau hapus baris → Simpan.

## D. Generate payroll November (login `hr.mts`)

1. **Generate Payroll** → buka *Payroll November 2026*.
2. Kartu **Persiapan sebelum generate**: Master payroll, Penyetuju, Preview = hijau; Kontrak/Absensi bisa berupa peringatan.
3. Cek ringkasan per komponen, tabel per departemen (klik 👁 untuk menyaring karyawan departemen itu), filter karyawan.
4. **Prorata hari kerja**: baris *Rani Karyawan Baru* (Operasional, pola Senin–Minggu) dan *Sapto Karyawan Resign* menampilkan
   gaji pokok lebih kecil dari kontrak, sebanding hari kerja yang dijalani.
5. **Penyesuaian**: baris Candra Wijaya punya kolom *Bonus (penyesuaian)* dan *Reimburse (penyesuaian)*; PPh21-nya
   naik karena bonus, tidak karena reimburse. Coba **Tambah penyesuaian** lain lalu hapus.
6. Klik **Generate Payroll** → status *Menunggu persetujuan*. Kalender Payroll: November jadi *Menunggu persetujuan*,
   hari libur 26 Okt – 25 Nov terkunci.

## E. Tarik kembali, tolak, dan setujui

1. (`hr.mts`) Di payroll November klik **Tarik kembali** (isi alasan) → status kembali *Draft*. Generate lagi.
2. (`manager.mts`) Menu **Approval** → buka November → **Tolak** dengan alasan → status *Ditolak*. (`hr.mts`) buat payroll
   November baru (periode yang sama), Lihat preview, Generate.
3. (`manager.mts`) **Setujui** → status *Final*, slip terbit. Kalender Payroll: November *Final*.

## F. Slip gaji

1. (`staf.mts`) Menu **Slip Gaji** → slip Oktober dan November → Unduh. Password PDF (bila setelan aktif) =
   2 digit terakhir nomor karyawan + 4 digit terakhir rekening; untuk Dina Kartika: `041111`.
2. (`admin.mts`) Ubah setelan password slip ke *Tidak* → (`staf.mts`) unduh ulang slip lama → terbuka tanpa password.

## G. Kasus kunci dan validasi

| Coba | Hasil yang diharapkan |
|---|---|
| Tambah/ubah/hapus hari libur di tanggal 26 Sep – 25 Okt | Ditolak: masuk payroll yang sudah final |
| Ubah tanggal periode Oktober | Tidak ada ikon ubah (sudah final) |
| Tambah penyesuaian di payroll yang menunggu persetujuan | Ditolak: payroll sudah di-generate |
| Manager yang tidak dipilih sebagai penyetuju membuka Approval | Bisa melihat, tidak ada tombol Setujui/Tolak |
| Buat payroll Februari 2027 (periode belum dibuat) | Ditolak: buat periode dulu di Kalender Payroll |
| Hapus draft Master Payroll yang masih satu-satunya versi | Ditolak |
| Kode pola berisi simbol, mis. `a@b` | "Kode hanya boleh huruf, angka, spasi, strip, atau garis bawah." |

## H. Uji skala besar (setelah `KaryawanMassalSeeder`)

1. (`hr.mts`) **Generate Payroll** → buat atau buka payroll November → **Lihat preview** / **Hitung ulang**. Semua 122 karyawan
   terhitung (di lokal sekitar 2 detik dengan worker antrean berjalan).
2. Tabel per departemen menampilkan 6 departemen; filter departemen dan karyawan tetap cepat; halaman tabel karyawan berpindah halaman.
3. Menu **Kontrak**: chip *Segera berakhir* bertambah (kontrak berakhir Jan/Feb 2027).
4. Kalender Payroll → "Lihat untuk: Gudang": hari Sabtu tampil sebagai hari kerja, Minggu hari istirahat.

## I. Multi-tenant (isolasi data dan Super Admin)

| Coba | Hasil yang diharapkan |
|---|---|
| Login `hr.bns` → Karyawan / Kontrak / Absensi | Hanya karyawan BNS (NIK `N…`); tidak ada NIK `S…`, `U…`, `M…` |
| Login `hr.bns` → buka URL detail payroll/karyawan milik MTS (ganti id di URL) | 404 |
| Login `admin.bns` → Manajemen User | Hanya user `*.bns`; tidak bisa membuat role Super Admin |
| Login `hr.sbr` → Kalender Payroll | Periode 1–akhir bulan (kalender bulanan), beda dengan BNS (26–25) |
| Login `hr.bns` → Master Payroll | Hanya "Payroll Default" milik BNS; ubahan di BNS tidak memengaruhi SBR/MTS |
| Login `superadmin` | Menu **Perusahaan** tampil (MTS, BNS, SBR, DEFAULT); pilih perusahaan dulu sebelum membuka data payroll |
| `superadmin` → Perusahaan → buat perusahaan baru | Role Admin/HR/Manager/Karyawan dibuat dari templat DEFAULT |
| Login `admin.mts` → menu Perusahaan / Manajemen Menu | Tidak tampil (khusus Super Admin) |
| `superadmin` → Perusahaan → ikon power pada BNS | Konfirmasi → BNS *Nonaktif*; login `hr.bns` ditolak "Perusahaan Anda sedang nonaktif"; ikon power lagi → aktif kembali |
| `superadmin` → Perusahaan → ikon tempat sampah pada SBR | Drawer "Hapus Permanen": ketik kode salah → ditolak; ketik `SBR` → perusahaan dan seluruh datanya terhapus. DEFAULT tidak punya tombol ini |
