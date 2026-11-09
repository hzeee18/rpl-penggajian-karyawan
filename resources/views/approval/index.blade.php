@extends('layouts.app')
@section('title', 'Approval')
@section('breadcrumb', 'Approval')
@section('content')
<x-page-header judul="Approval" :sub="auth()->user()->isManagerHr() ? 'Payroll semua departemen dan kontrak staf HR yang menunggu persetujuan Anda.' : 'Kontrak karyawan divisi Anda yang menunggu persetujuan.'">
    <a href="{{ route('approval.index', ['status' => $status === 'semua' ? 'menunggu' : 'semua']) }}" class="btn btn-light">{{ $status === 'semua' ? 'Yang Menunggu' : 'Lihat Riwayat' }}</a>
</x-page-header>

<div class="md-grid">
    <div class="card">
        <x-cari placeholder="Cari pengajuan..." />
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>No.</th><th>Jenis</th><th>Diajukan oleh</th><th class="text-center">Tahap</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($approval as $a)
                    <tr class="{{ $pilih?->id === $a->id ? 'terpilih' : '' }}">
                        <td class="kode">{{ $a->jenis_ref === 'payroll' ? 'PR' : 'KT' }}-{{ str_pad($a->ref_id, 2, '0', STR_PAD_LEFT) }}</td>
                        <td><span class="badge badge-soft-info">{{ ucfirst($a->jenis_ref) }}</span> @if ($status === 'semua'){!! badge_status($a->status) !!}@endif</td>
                        <td>{!! orang($a->pengaju?->namaTampil() ?? 'HR', ($a->pengaju?->role?->nama ?? 'HR') . ' · ' . $a->created_at->translatedFormat('j M')) !!}</td>
                        <td class="text-center">{{ $a->level }} / {{ \App\Models\ApprovalMapping::where('jenis_ref', $a->jenis_ref)->count() }}</td>
                        <td><a href="{{ route('approval.index', ['lihat' => $a->id, 'status' => $status]) }}" class="btn btn-light btn-icon" title="Lihat"><i data-lucide="eye"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty ikon="clipboard-check" judul="Semua sudah beres" pesan="Tidak ada kontrak atau payroll yang menunggu approval kamu." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($pilih && $ref)
        <x-panel :judul="$pilih->jenis_ref === 'payroll' ? 'Payroll ' . $ref->namaPeriode() : 'Kontrak ' . $ref->karyawan->nama"
                 :sub="$pilih->jenis_ref === 'payroll' ? 'Semua departemen · ' . $ringkasan->count() . ' karyawan' : $ref->karyawan->jabatan . ' · ' . $ref->karyawan->departemen?->nama">
            @include('approval._keputusan')
            <div class="mt-3 small"><a href="{{ route('approval.show', $pilih) }}" class="fw-semibold">Lihat rincian lengkap <i data-lucide="chevron-right"></i></a></div>
        </x-panel>
    @endif
</div>
@endsection
