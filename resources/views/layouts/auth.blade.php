<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Penggajian Karyawan</title>
    <link rel="icon" href="{{ asset('img/logo-unindra.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="{{ asset('css/app.css') }}?v=3" rel="stylesheet">
</head>
<body>
@php $bulan = now()->subMonthNoOverflow(); @endphp
<div class="login">
    {{-- Panel ilustrasi kiri (frame Umum / Login) --}}
    <section class="login-panel">
        <span class="deko atas"></span><span class="deko bawah"></span>
        <div class="login-kampus">
            <span class="logo"><img src="{{ asset('img/logo-unindra.png') }}" alt="Logo Unindra"></span>
            <div><div class="nama">Universitas Indraprasta PGRI</div><div class="sub">Aplikasi Penggajian Karyawan</div></div>
        </div>

        <div class="login-konten">
            <div class="login-mock">
                <div class="mock-card mock-slip">
                    <div class="d-flex justify-content-between align-items-center text-secondary"><span>Slip Gaji · {{ $bulan->translatedFormat('F Y') }}</span><span class="badge badge-soft-success">Dibayar</span></div>
                    <div class="text-secondary mt-3">Gaji bersih</div>
                    <div class="fw-bold" style="font-size:24px">Rp 7.845.000</div>
                    <div class="border-top my-3"></div>
                    @foreach ([['Gaji pokok', 'Rp 6.500.000', ''], ['Tunjangan', 'Rp 1.200.000', ''], ['Lembur', 'Rp 450.000', ''], ['PPh21 & BPJS', '− Rp 305.000', 'text-danger']] as [$l, $v, $c])
                        <div class="d-flex justify-content-between mb-2"><span class="text-secondary">{{ $l }}</span><b class="{{ $c }}">{{ $v }}</b></div>
                    @endforeach
                </div>
                <div class="mock-card mock-approval">
                    <span class="avatar" style="background:#16a34a;color:#fff"><i data-lucide="check"></i></span>
                    <div><div class="fw-semibold">Payroll {{ $bulan->translatedFormat('F') }} disetujui</div><div class="text-secondary">Manager HR · baru saja</div></div>
                </div>
                <div class="mock-card mock-hadir">
                    <div class="d-flex justify-content-between"><span class="text-secondary">Kehadiran</span><b>21/22 hari</b></div>
                    <div class="bar my-2"><span style="width:95%"></span></div>
                    <div class="d-flex align-items-end gap-2" style="height:40px">
                        @foreach ([55, 75, 50, 90, 70, 100, 85] as $i => $h)<span style="flex:1;height:{{ $h }}%;border-radius:3px;background:{{ $i === 5 ? '#1b6fa3' : '#d5ecf8' }}"></span>@endforeach
                    </div>
                </div>
            </div>
            <span class="login-tag">TUGAS BESAR RPL · KELOMPOK 6 · KELAS X7N</span>
            <h2>Rancang Bangun Aplikasi Penggajian Karyawan Berbasis Web</h2>
            <p>Kontrak, absensi, lembur, PPh21 TER, sampai slip gaji dalam satu aplikasi.</p>
        </div>

        <div class="login-anggota">
            <div class="small opacity-75 mb-2">Disusun oleh</div>
            <div class="anggota">
                @foreach (config('kelompok.anggota') as [$nama, $npm, $ini])
                    <div class="item"><span class="avatar">{{ $ini }}</span><div><div class="fw-semibold small">{{ $nama }}</div><div class="small opacity-75">{{ $npm }}</div></div></div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Panel kanan: form --}}
    <section class="login-form">
        <div class="isi">@yield('content')</div>
        <div class="powered">Powered by Kelompok 6 RPL · Kelas X7N · {{ now()->year }}</div>
    </section>
</div>
@include('partials.flash', ['tanpaError' => true])
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
<script>lucide.createIcons();</script>
</body>
</html>
