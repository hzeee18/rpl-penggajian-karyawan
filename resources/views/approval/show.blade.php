@extends('layouts.app')
@section('title', 'Approval')
@section('breadcrumb', 'Approval / Review ' . $approval->jenis_ref)
@section('content')
<x-page-header :judul="$approval->jenis_ref === 'payroll' ? 'Payroll ' . $ref->namaPeriode() : 'Kontrak ' . $ref->karyawan->nama" :sub="$approval->jenis_ref === 'payroll' ? 'Semua departemen · ' . $ringkasan->count() . ' karyawan' : 'Diajukan HR ' . $approval->created_at->translatedFormat('j M Y')">
    <a href="{{ route('approval.index') }}" class="btn btn-light"><i data-lucide="arrow-left"></i> Kembali</a>
</x-page-header>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            @if ($approval->jenis_ref === 'kontrak')
                <div class="card-header">Detail kontrak</div>
                <div class="card-body">@include('kontrak._detail', ['kontrak' => $ref])</div>
            @else
                <div class="card-header">Rincian per karyawan</div>
                @include('payroll._tabel', ['ringkasan' => $ringkasan, 'payroll' => $ref, 'link' => false])
            @endif
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            @if ($approval->jenis_ref === 'payroll')
                <div class="card-header">Payroll {{ $ref->namaPeriode() }}<span class="sub">Semua departemen · {{ $ringkasan->count() }} karyawan</span></div>
                <div class="card-body">
                    <div class="rinci"><span class="k">Total gaji pokok</span><span class="v">{{ rupiah($ringkasan->sum('gaji_pokok')) }}</span></div>
                    <div class="rinci"><span class="k">Total tunjangan & lembur</span><span class="v">{{ rupiah($ringkasan->sum('bruto') - $ringkasan->sum('gaji_pokok')) }}</span></div>
                    <div class="rinci"><span class="k">Potongan & PPh21</span><span class="v">{{ rupiah($ringkasan->sum('potongan')) }}</span></div>
                    <div class="rinci total"><span class="k">Gaji bersih</span><span class="v">{{ rupiah($ringkasan->sum('thp')) }}</span></div>
            @else
                <div class="card-header">Kontrak {{ $ref->jenis }}<span class="sub">{{ $ref->karyawan->nama }} · {{ $ref->karyawan->departemen?->nama }}</span></div>
                <div class="card-body">
                    <div class="rinci"><span class="k">Mulai</span><span class="v">{{ tgl($ref->tgl_mulai) }}</span></div>
                    <div class="rinci"><span class="k">Berakhir</span><span class="v">{{ $ref->tgl_akhir ? tgl($ref->tgl_akhir) : 'Tidak terbatas' }}</span></div>
                    @if (auth()->user()->bolehLihatGaji())<div class="rinci total"><span class="k">Gaji pokok</span><span class="v">{{ rupiah($ref->gaji_pokok) }}</span></div>@else<div class="rinci"><span class="k">Gaji pokok</span><span class="v text-muted">Rahasia (diatur HR)</span></div>@endif
            @endif
                @if ($approval->status === 'menunggu')
                    <form method="post" class="mt-3">
                        @csrf
                        <label class="form-label">Catatan</label>
                        <input name="catatan" value="{{ old('catatan') }}" class="form-control mb-3" placeholder="Wajib diisi kalau ditolak">
                        <div class="notice mb-3"><i data-lucide="circle-alert"></i><span>{{ $approval->jenis_ref === 'payroll' ? 'Setelah disetujui, payroll langsung final dan slip gaji semua karyawan terbit otomatis.' : 'Setelah disetujui, kontrak aktif dan kontrak lama karyawan ini dinonaktifkan.' }}</span></div>
                        <div class="d-flex gap-2 justify-content-end">
                            <button formaction="{{ route('approval.tolak', $approval) }}" class="btn btn-danger" >Tolak</button>
                            <button formaction="{{ route('approval.setujui', $approval) }}" class="btn btn-success" >Setujui</button>
                        </div>
                    </form>
                @else
                    <div class="mt-3">{!! badge_status($approval->status) !!} pada {{ tgl($approval->tgl_approval) }}</div>
                    @if ($approval->catatan)<div class="small text-muted mt-1">Catatan: {{ $approval->catatan }}</div>@endif
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
