@extends('layouts.app')
@section('title', 'Tim Saya')
@section('breadcrumb', 'Tim Saya')
@section('content')
<x-page-header judul="Tim Saya" :sub="'Karyawan di Departemen ' . ($dept?->nama ?? '-') . ' (hanya lihat).'">
    <a href="{{ route('karyawan.index', ['export' => 1]) }}" class="btn btn-primary"><i data-lucide="file-down"></i> Export</a>
</x-page-header>

<div class="md-grid">
    <div class="card">
        <x-cari placeholder="Cari anggota tim..." />
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>NIK</th><th>Kontrak</th><th>Karyawan</th><th class="text-center">Sisa cuti</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($karyawan as $k)
                    @php $kt = $k->kontrakAktif; $menunggu = $k->kontrak->firstWhere('status_approval', 'menunggu'); @endphp
                    <tr class="{{ $pilih?->id === $k->id ? 'terpilih' : '' }}">
                        <td class="kode">{{ $k->nik }}</td>
                        <td>
                            @if ($menunggu && ! $kt)<span class="badge badge-soft-warning">Menunggu approval</span>
                            @elseif (! $kt)<span class="badge badge-soft-neutral">Belum ada</span>
                            @elseif ($kt->jenis === 'PKWTT')<span class="badge badge-soft-success">PKWTT</span>
                            @else<span class="badge {{ now()->diffInDays($kt->tgl_akhir, false) < 60 ? 'badge-soft-warning' : 'badge-soft-info' }}">PKWT s.d. {{ $kt->tgl_akhir->translatedFormat('M Y') }}</span>@endif
                        </td>
                        <td>{!! orang($k->nama, $k->jabatan) !!}</td>
                        <td class="text-center">{{ $k->sisa_cuti }}</td>
                        <td><a href="{{ route('karyawan.index', ['lihat' => $k->id] + request()->only('q', 'page')) }}" class="btn btn-light btn-icon" title="Lihat"><i data-lucide="eye"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty ikon="users" judul="Belum ada anggota tim" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($pilih)
        <x-panel :judul="$pilih->nama" :sub="$pilih->nik . ' · ' . $pilih->jabatan">
            @php $kt = $pilih->kontrakAktif; @endphp
            <div class="rinci"><span class="k">Kontrak</span><span class="v">{{ $kt ? $kt->jenis . ($kt->tgl_akhir ? ' s.d. ' . tgl($kt->tgl_akhir, 'j M Y') : '') : '-' }}</span></div>
            <div class="rinci"><span class="k">Mulai kerja</span><span class="v">{{ tgl($pilih->tgl_masuk, 'M Y') }}</span></div>
            <div class="rinci mb-3"><span class="k">Sisa cuti</span><span class="v">{{ $pilih->sisa_cuti }} hari</span></div>
            <x-catatan>Manager hanya bisa melihat data tim. Perubahan data dilakukan oleh HR.</x-catatan>
            <div class="tombol"><a href="{{ route('karyawan.index') }}" class="btn btn-light">Tutup</a><a href="{{ route('absensi.index', ['karyawan_id' => $pilih->id]) }}" class="btn btn-primary">Lihat Absensi</a></div>
        </x-panel>
    @endif
</div>
@endsection
