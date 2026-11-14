# Sequence diagram (Mermaid)

Alur mengikuti kode branch `feature/multi-tenant-payroll`. Cara buka di draw.io: Arrange > Insert > Advanced > Mermaid, lalu tempel salah satu blok di bawah.

## 1. Login dan pemilihan perusahaan
```mermaid
sequenceDiagram
  autonumber
  actor U as User
  participant UI as Halaman Login
  participant AC as AuthController
  participant CC as CurrentCompany
  participant DB as Database
  U->>UI: Isi username dan password
  UI->>AC: login(username, password)
  AC->>DB: Cari user (username unik global)
  DB-->>AC: User + company_id + role
  alt Password salah
    AC->>DB: gagal_login + 1, kunci akun bila 5 kali
    AC-->>UI: Pesan gagal login
  else Super Admin (company_id NULL)
    AC-->>UI: Dashboard platform
    U->>UI: Pilih perusahaan di menu Company
    UI->>CC: pilihPerusahaan(companyId)
    CC-->>UI: Data dibatasi ke perusahaan terpilih
  else User perusahaan
    AC->>CC: Konteks = company_id user
    AC->>DB: Ambil menu dari Privilege role
    AC-->>UI: Dashboard dan menu sesuai role
  end
```

## 2. Import CSV absensi, leave, dan lembur
```mermaid
sequenceDiagram
  autonumber
  actor HR
  participant UI as Halaman Import CSV
  participant IC as ImportPayrollController
  participant UP as UnggahImport
  participant SI as SimpanImport
  participant DB as Database
  HR->>UI: Unduh template CSV per jenis
  HR->>UI: Pilih jenis dan upload file CSV
  UI->>IC: upload(jenis, file)
  IC->>UP: handle(jenis, file)
  UP->>DB: Simpan ImportBatch + ImportBatchRow (status previewing)
  loop Setiap baris
    UP->>UP: Validasi NIK, tanggal, bentrok absensi vs leave, periode terkunci
  end
  UP-->>UI: Preview, baris error ditandai merah
  opt Ada baris error
    HR->>UI: Perbaiki atau hapus baris
    UI->>IC: ubahBaris() / hapusBaris(), lalu validasi ulang
  end
  HR->>UI: Klik Simpan
  UI->>IC: simpan(batch)
  IC->>SI: handle(batch)
  SI->>DB: Insert Attendance / Leave / Overtime
  SI->>DB: Status batch = committed, catat AuditLog
  SI-->>UI: Jumlah data tersimpan
```

## 3. Membuat dan memperbarui kontrak
```mermaid
sequenceDiagram
  autonumber
  actor HR
  participant UI as Halaman Kontrak
  participant KC as KontrakPayrollController
  participant BK as BuatKontrak
  participant PK as PerbaruiKontrak
  participant DB as Database
  alt Kontrak baru
    HR->>UI: Isi kontrak dan nilai komponen
    UI->>KC: store(data)
    KC->>BK: handle(data, nilai)
    BK->>DB: Ambil default_amount dari katalog Component
    BK->>DB: Simpan Contract aktif + ContractComponent (nilai disalin)
  else Perbarui kontrak
    HR->>UI: Klik Perbarui pada kontrak aktif
    UI->>KC: perbarui(kontrak, data)
    KC->>PK: handle(lama, data)
    PK->>DB: Nonaktifkan kontrak lama
    PK->>BK: handle(data, nilai lama, previous_contract_id)
    BK->>DB: Simpan kontrak versi baru
  end
  KC->>DB: Catat AuditLog
  KC-->>UI: Kontrak tersimpan
```

## 4. Generate dan approval payroll
```mermaid
sequenceDiagram
  autonumber
  actor HR
  participant UI as Halaman Generate Payroll
  participant AP as AlurPayroll
  participant Q as Queue Worker
  participant K as Kalkulator
  participant DB as Database
  actor M as Penyetuju payroll
  HR->>UI: Buat payroll, pilih periode dari Kalender Payroll
  UI->>AP: buat(judul, bulan)
  AP->>DB: Rentang dari pay_periods (wajib sudah dibuat), simpan PayrollRun draft
  UI->>DB: Checklist kesiapan: master terbit, penyetuju, kontrak, absensi, libur
  HR->>UI: Pilih master payroll, klik Lihat preview
  UI->>AP: mulaiPratinjau(run, master, semua karyawan)
  AP->>Q: Dispatch ProsesPratinjau
  Q->>DB: Pilih karyawan aktif dengan kontrak berlaku di tanggal periode
  loop Setiap karyawan
    Q->>K: hitung(karyawan, segmen kontrak, periode, penyesuaian)
    K->>DB: Variabel dari absensi, leave, lembur, libur, pola hari kerja
    K->>K: Prorata komponen kontrak per hari kerja
    K->>DB: LOOKUP tabel referensi (TER)
    K-->>Q: Nilai per komponen + penyesuaian + PPh21 + gaji bersih
    Q->>DB: Simpan RunEmployee + RunItem
  end
  Q->>DB: Simpan karyawan dilewati + alasan
  opt HR menambah penyesuaian
    HR->>UI: Tambah penyesuaian (jenis, nominal, keterangan)
    UI->>AP: tambahPenyesuaian(run, data)
    AP->>DB: Simpan RunAdjustment, catat AuditLog
    AP->>K: Hitung ulang karyawan itu
  end
  HR->>UI: Klik Generate (aktif bila item wajib checklist terpenuhi)
  UI->>AP: generate(run)
  AP->>AP: Cek preview ada, tidak ada gaji bersih minus, penyetuju ada
  AP->>Q: Dispatch ProsesGenerate
  Q->>K: Hitung ulang dengan data terbaru
  Q->>DB: is_blocking = 1, kunci versi master dan TER
  alt Perusahaan tidak memakai approval
    Q->>DB: Status approved + TaxMonthlyHistory, terbitkan slip
  else Memakai approval
    Q->>DB: Status pending_approval, catat AuditLog
    opt HR menemukan kesalahan
      HR->>AP: tarikKembali(run, alasan)
      AP->>DB: Status draft, is_blocking = 0
    end
    M->>UI: Buka menu Approval (user/role yang dipilih di Approver Payroll)
    alt Disetujui (keputusan pertama yang dipakai)
      M->>AP: setujui(run)
      AP->>DB: Status approved, approver_user_id = yang menyetujui, TaxMonthlyHistory
      AP->>Q: Event PayrollDisetujui, dispatch TerbitkanSlip
      Q->>DB: Buat Payslip + PDF (berpassword bila setelan aktif)
    else Ditolak
      M->>AP: tolak(run, alasan)
      AP->>DB: Status rejected, is_blocking = 0
      AP-->>HR: HR membuat payroll baru
    end
  end
```

## 5. Karyawan mengunduh slip gaji
```mermaid
sequenceDiagram
  autonumber
  actor K as Karyawan
  participant UI as Halaman Slip Gaji
  participant SC as SlipPayrollController
  participant DB as Database
  participant FS as Penyimpanan File
  K->>UI: Buka menu Slip Gaji
  UI->>SC: index()
  SC->>DB: Slip dengan employee_id = karyawan login
  SC-->>UI: Daftar slip per periode
  K->>UI: Klik Unduh
  UI->>SC: unduh(id)
  SC->>DB: Pastikan slip milik karyawan login
  alt Bukan miliknya
    SC-->>UI: 403, hanya slip milik sendiri
  else Miliknya
    SC->>DB: Baca setelan perusahaan slip_pakai_password
    opt PDF lama beda dengan setelan sekarang
      SC->>FS: Buat ulang PDF (dengan / tanpa password)
    end
    SC->>FS: Ambil PDF dari folder per perusahaan
    SC-->>K: File PDF
  end
```
