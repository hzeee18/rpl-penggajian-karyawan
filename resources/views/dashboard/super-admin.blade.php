@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<x-page-header judul="Dashboard Super Admin" sub="Ringkasan platform: semua perusahaan, pengguna, dan karyawan yang dikelola">
    <a href="{{ route('perusahaan.index', ['baru' => 1]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Tambah Perusahaan</a>
</x-page-header>

@include('dashboard._stat')

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">Karyawan Aktif per Perusahaan<span class="sub">Arahkan kursor ke batang untuk melihat jumlahnya</span></div>
            <div class="card-body"><div style="position:relative;height:{{ max(180, count($grafikKaryawan['label']) * 44) }}px"><canvas id="grafikKaryawan" role="img" aria-label="Grafik karyawan aktif per perusahaan"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">Slip Gaji Terbit<span class="sub">6 bulan terakhir, semua perusahaan</span></div>
            <div class="card-body"><div style="position:relative;height:220px"><canvas id="grafikSlip" role="img" aria-label="Grafik slip gaji terbit per bulan"></canvas></div></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">User Aktif per Role<span class="sub">Semua perusahaan</span></div>
            <div class="card-body">
                @php $maksRole = max((int) $perRole->max(), 1); @endphp
                @forelse ($perRole as $role => $n)
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1"><span>{{ $role }}</span><span class="small text-secondary">{{ $n }} user</span></div>
                        <div class="bar"><span style="width:{{ round($n / $maksRole * 100) }}%"></span></div>
                    </div>
                @empty
                    <x-empty ikon="users" judul="Belum ada user" />
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">Perlu Perhatian</div>
            <div class="card-body">
                @forelse ($perhatian as [$judul, $ket, $link])
                    @if ($link)<a href="{{ $link }}" class="d-block mb-3 text-reset">@else<div class="mb-3">@endif
                        <div class="fw-semibold">{{ $judul }}</div><div class="small text-muted">{{ $ket }}</div>
                    @if ($link)</a>@else</div>@endif
                @empty
                    <x-empty ikon="check-check" judul="Semua sudah beres" pesan="Setiap perusahaan aktif punya Admin dan tidak ada yang tertunda." />
                @endforelse
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-search d-flex align-items-center gap-2">
        <input type="search" id="cariPerusahaan" class="form-control" placeholder="Cari kode atau nama perusahaan..." style="max-width:320px" autocomplete="off">
        <span class="small text-muted ms-auto">{{ $perusahaan->count() }} perusahaan</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover" id="tabelPerusahaan">
            <thead><tr><th>Kode</th><th>Perusahaan</th><th class="text-center">Karyawan</th><th class="text-center">Kontrak payroll</th><th class="text-center">User aktif</th><th class="text-center">Admin</th><th>Payroll terakhir</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse ($perusahaan as $p)
                <tr data-cari="{{ strtolower($p->code . ' ' . $p->name) }}">
                    <td class="kode">{{ $p->code }}</td>
                    <td>{{ $p->name }}</td>
                    <td class="text-center">{{ $p->karyawan }}</td>
                    <td class="text-center">{{ $p->kontrak }}</td>
                    <td class="text-center">{{ $p->user }}</td>
                    <td class="text-center">@if ($p->admin){{ $p->admin }}@else<span class="badge badge-soft-warning">Belum ada</span>@endif</td>
                    <td>@if ($p->run)<div class="fw-medium">{{ $p->run->title }}</div><div class="small text-muted">{{ ['draft' => 'Draft', 'pending_approval' => 'Menunggu approval', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'][$p->run->status] ?? $p->run->status }}</div>@else<span class="text-muted">-</span>@endif</td>
                    <td>{!! badge_status($p->aktif ? 'aktif' : 'nonaktif') !!}</td>
                    <td class="text-nowrap"><div class="d-inline-flex gap-2">
                        <a href="{{ route('perusahaan.index', ['admin' => $p->id]) }}" class="btn btn-light btn-icon" title="Akun Admin perusahaan"><i data-lucide="user-cog"></i></a>
                        <a href="{{ route('perusahaan.index', ['ubah' => $p->id]) }}" class="btn btn-light btn-icon" title="Ubah perusahaan"><i data-lucide="pencil"></i></a>
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="9"><x-empty ikon="building" judul="Belum ada perusahaan" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    // cari perusahaan di tabel (tanpa memuat ulang halaman)
    const cari = document.getElementById('cariPerusahaan');
    cari?.addEventListener('input', () => {
        const q = cari.value.trim().toLowerCase();
        document.querySelectorAll('#tabelPerusahaan tbody tr[data-cari]').forEach(tr => { tr.hidden = q !== '' && !tr.dataset.cari.includes(q); });
    });

    const karyawan = @json($grafikKaryawan);
    const slip = @json($grafikSlip);
    const warna = getComputedStyle(document.documentElement).getPropertyValue('--primary-600').trim() || '#1b6fa3';
    const garis = getComputedStyle(document.documentElement).getPropertyValue('--border').trim() || '#e5e7eb';
    const teks = getComputedStyle(document.documentElement).getPropertyValue('--text-secondary').trim() || '#475569';

    function gambar() {
        const umum = {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { displayColors: false } },
        };
        const sumbu = { grid: { color: garis }, border: { display: false }, ticks: { color: teks, precision: 0 }, beginAtZero: true };
        const batang = { backgroundColor: warna, hoverBackgroundColor: warna, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 22 };

        new Chart(document.getElementById('grafikKaryawan'), {
            type: 'bar',
            data: { labels: karyawan.label, datasets: [{ label: 'Karyawan aktif', data: karyawan.data, ...batang }] },
            options: { ...umum, indexAxis: 'y', scales: { x: sumbu, y: { grid: { display: false }, border: { display: false }, ticks: { color: teks } } },
                plugins: { ...umum.plugins, tooltip: { ...umum.plugins.tooltip, callbacks: { label: c => c.parsed.x + ' karyawan aktif' } } } },
        });
        new Chart(document.getElementById('grafikSlip'), {
            type: 'bar',
            data: { labels: slip.label, datasets: [{ label: 'Slip terbit', data: slip.data, ...batang }] },
            options: { ...umum, scales: { y: sumbu, x: { grid: { display: false }, border: { display: false }, ticks: { color: teks } } },
                plugins: { ...umum.plugins, tooltip: { ...umum.plugins.tooltip, callbacks: { label: c => c.parsed.y + ' slip terbit' } } } },
        });
    }

    if (window.Chart) { gambar(); return; }
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
    s.onload = gambar;
    document.head.appendChild(s);
})();
</script>
@endpush
