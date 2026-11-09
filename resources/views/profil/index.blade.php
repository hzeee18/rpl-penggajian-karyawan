@extends('layouts.app')
@section('title', 'Profil & Kontrak')
@section('breadcrumb', 'Profil & Kontrak')
@section('content')
@if (! $karyawan)
    <x-empty ikon="user-x" judul="Akun belum terhubung ke data karyawan" pesan="Hubungi Admin." />
@else
@php $kt = $karyawan->kontrakAktif; $kode = fn ($k) => $k->kode; $bln = fn ($k) => $k->tgl_akhir ? (int) round($k->tgl_mulai->diffInMonths($k->tgl_akhir)) . ' bulan' : ''; @endphp
<x-page-header judul="Profil & Kontrak" sub="Data diri dan kontrak kerja Anda. Perubahan data dilakukan oleh HR.">
    @if ($kt)<a href="{{ route('kontrak.pdf', $kt) }}" class="btn btn-primary"><i data-lucide="file-down"></i> Unduh Kontrak</a>@endif
</x-page-header>

<div class="profil-head">
    <span class="avatar" style="width:56px;height:56px;font-size:18px">{{ inisial($karyawan->nama) }}</span>
    <div class="me-auto">
        <div class="fs-5 fw-bold">{{ $karyawan->nama }}</div>
        <div class="text-secondary">{{ $karyawan->jabatan }} · Departemen {{ $karyawan->departemen?->nama }}</div>
        <div class="small text-muted">NIK {{ $karyawan->nik }} · Bergabung {{ tgl($karyawan->tgl_masuk, 'F Y') }}</div>
    </div>
    <span class="badge {{ $karyawan->is_active ? 'badge-soft-success' : 'badge-soft-neutral' }}">{{ $karyawan->is_active ? 'Karyawan aktif' : 'Nonaktif' }}</span>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Data Pribadi</div>
            <div class="card-body">
                @foreach ([['NIK', $karyawan->nik], ['Nama lengkap', $karyawan->nama], ['Email', $karyawan->email ?? '-'], ['No. HP', $karyawan->telepon ?? '-'],
                    ['Status PTKP', ($karyawan->pajak?->status_ptkp ?? '-') . (isset(\App\Http\Controllers\ProfilController::PTKP[$karyawan->pajak?->status_ptkp]) ? ' (' . \App\Http\Controllers\ProfilController::PTKP[$karyawan->pajak->status_ptkp] . ')' : '')],
                    ['Mulai kerja', tgl($karyawan->tgl_masuk)]] as [$k, $v])
                    <div class="d-flex py-2 border-bottom"><span class="text-muted" style="width:130px">{{ $k }}</span><span class="fw-medium">{{ $v }}</span></div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between">Kontrak Aktif @if ($kt)<span class="badge badge-soft-success">Aktif</span>@endif</div>
            <div class="card-body">
                @if ($kt)
                    @php $ap = $kt->approvals->firstWhere('status', 'disetujui'); @endphp
                    @foreach ([['No. kontrak', $kode($kt)], ['Jenis', $kt->jenis . ' ' . $bln($kt)], ['Periode', tgl($kt->tgl_mulai, 'j M Y') . ' – ' . ($kt->tgl_akhir ? tgl($kt->tgl_akhir, 'j M Y') : 'tidak terbatas')],
                        ['Gaji pokok', rupiah($kt->gaji_pokok) . ' / bulan'], ['Komponen gaji', $kt->komponen->map(fn ($k) => $k->component->nama)->join(', ') ?: '-'],
                        ['Disetujui oleh', $ap ? $ap->approver?->namaTampil() . ' (' . ($ap->approver?->karyawan?->jabatan ?? 'Manager') . ')' : 'Data awal']] as [$k, $v])
                        <div class="d-flex py-2 border-bottom"><span class="text-muted" style="width:130px">{{ $k }}</span><span class="fw-medium">{{ $v }}</span></div>
                    @endforeach
                @else
                    <x-empty ikon="file-x" judul="Belum ada kontrak aktif" />
                @endif
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Riwayat Kontrak</div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>No. kontrak</th><th>Jenis</th><th>Periode</th><th>Gaji pokok</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            @foreach ($karyawan->kontrak->where('status_approval', 'disetujui') as $k)
                <tr>
                    <td class="kode">{{ $kode($k) }}</td>
                    <td>{{ $k->jenis }} {{ $bln($k) }}</td>
                    <td class="text-secondary">{{ tgl($k->tgl_mulai, 'j M Y') }} – {{ $k->tgl_akhir ? tgl($k->tgl_akhir, 'j M Y') : 'tidak terbatas' }}</td>
                    <td>{{ rupiah($k->gaji_pokok) }}</td>
                    <td><span class="badge {{ $k->is_active ? 'badge-soft-success' : 'badge-soft-neutral' }}">{{ $k->is_active ? 'Aktif' : ($k->tgl_mulai->isFuture() ? 'Akan datang' : 'Selesai') }}</span></td>
                    <td class="text-nowrap"><a href="{{ route('kontrak.pdf', $k) }}" class="btn btn-light btn-icon" title="Lihat"><i data-lucide="eye"></i></a> <a href="{{ route('kontrak.pdf', $k) }}" class="btn btn-light btn-icon" title="Unduh"><i data-lucide="download"></i></a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
