@extends('layouts.app')
@section('title', 'Detail karyawan')
@section('breadcrumb', 'Karyawan / Detail')
@section('content')
<x-page-header :judul="'Detail karyawan'" />
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="avatar" style="width:52px;height:52px;font-size:1.3rem">{{ strtoupper(substr($karyawan->nama, 0, 1)) }}</span>
                    <div><div class="h5 mb-0">{{ $karyawan->nama }}</div><div class="text-muted">{{ $karyawan->jabatan }}</div></div>
                </div>
                <dl class="info">
                    <dt>NIK</dt><dd>{{ $karyawan->nik }}</dd>
                    <dt>Departemen</dt><dd>{{ $karyawan->departemen?->nama }}</dd>
                    <dt>Tanggal masuk</dt><dd>{{ tgl($karyawan->tgl_masuk) }} ({{ $karyawan->hitungMasaKerja() }} bulan)</dd>
                    <dt>Status</dt><dd>{{ ucfirst($karyawan->status_kepegawaian) }} · {!! badge_status($karyawan->is_active ? 'aktif' : 'nonaktif') !!}</dd>
                    <dt>Status PTKP</dt><dd>{{ $karyawan->pajak?->status_ptkp ?? '-' }}</dd>
                    <dt>Sisa cuti</dt><dd>{{ $karyawan->sisa_cuti }} hari</dd>
                    <dt>Kontak</dt><dd>{{ $karyawan->email ?? '-' }}<br>{{ $karyawan->telepon }}</dd>
                    <dt>Alamat</dt><dd>{{ $karyawan->alamat ?? '-' }}</dd>
                    <dt>Rekening</dt><dd>{{ $karyawan->nama_bank }} {{ $karyawan->no_rekening }}</dd>
                    <dt>Akun login</dt><dd>{{ $karyawan->user ? $karyawan->user->username . ' (' . $karyawan->user->role->nama . ')' : 'Belum ada' }}</dd>
                </dl>
                @if (auth()->user()->bisa('karyawan', 'update'))<a href="{{ route('karyawan.edit', $karyawan) }}" class="btn btn-outline-primary btn-sm"><i data-lucide="pencil"></i> Ubah data</a>@endif
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                Riwayat kontrak
                @if (auth()->user()->bisa('kontrak', 'create'))<a href="{{ route('kontrak.create', ['karyawan_id' => $karyawan->id]) }}" class="btn btn-sm btn-primary"><i data-lucide="plus"></i> Buat kontrak</a>@endif
            </div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th>Jenis</th><th>Periode</th><th class="text-uang">Gaji pokok</th><th>Komponen</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($karyawan->kontrak as $k)
                        <tr class="{{ $k->is_active ? 'table-success' : '' }}">
                            <td>{{ $k->jenis }}</td>
                            <td class="small">{{ tgl($k->tgl_mulai, 'j M Y') }} – {{ $k->tgl_akhir ? tgl($k->tgl_akhir, 'j M Y') : 'tidak terbatas' }}</td>
                            <td class="text-uang">{{ auth()->user()->bolehLihatGaji() ? rupiah($k->gaji_pokok) : 'Rahasia' }}</td>
                            <td class="small">{{ $k->komponen->map(fn ($x) => $x->component->nama)->join(', ') ?: '-' }}</td>
                            <td>{!! badge_status($k->status_approval) !!} @if ($k->is_active)<span class="badge badge-soft-success">aktif</span>@endif</td>
                            <td>@if (auth()->user()->bisa('kontrak'))<a href="{{ route('kontrak.show', $k) }}" class="btn btn-light btn-icon"><i data-lucide="eye"></i></a>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty"><i data-lucide="file-x"></i>Belum ada kontrak</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
