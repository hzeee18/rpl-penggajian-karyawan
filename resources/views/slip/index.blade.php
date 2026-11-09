@extends('layouts.app')
@php
    $hr = auth()->user()->isRole('Admin') || auth()->user()->actsAsHr();
    $pilih = $slip->firstWhere('id', request('lihat')) ?? (request()->has('tutup') ? null : $slip->first());
    $svc = app(\App\Services\PayrollService::class);
@endphp
@section('title', 'Slip Gaji')
@section('breadcrumb', 'Slip Gaji')
@section('content')
<x-page-header judul="Slip Gaji" :sub="auth()->user()->isManagerHr() ? 'Slip terbit setelah payroll kamu setujui di menu Approval.' : 'Slip terbit setelah payroll disetujui Manager HR.'">
    @if ($pilih)<a href="{{ route('slip.download', $pilih) }}" class="btn btn-primary"><i data-lucide="file-down"></i> Unduh PDF</a>@endif
</x-page-header>

<div class="{{ $pilih ? 'md-grid' : '' }}">
    <div class="card">
        <x-cari :placeholder="$hr ? 'Cari karyawan...' : 'Cari periode...'" />
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Periode</th><th>Status</th>@if ($hr)<th>Karyawan</th>@else<th>Disetujui</th>@endif<th class="text-center">Hadir</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($slip as $s)
                    @php
                        $ap = $s->payroll->approvals()->where('status', 'disetujui')->with('approver.karyawan')->first();
                        $m = \App\Models\MasterPayroll::untuk($s->payroll->periode);
                        [$aw, $ak] = $m->rentang($s->payroll->periode);
                        $hadir = \App\Models\Attendance::where('karyawan_id', $s->karyawan_id)->whereBetween('tanggal', [$aw->toDateString(), $ak->toDateString()])->whereIn('status', ['hadir', 'terlambat'])->count();
                    @endphp
                    <tr class="{{ $pilih?->id === $s->id ? 'terpilih' : '' }}">
                        <td class="fw-semibold">{{ \Carbon\Carbon::parse($s->payroll->periode . '-01')->translatedFormat('M y') }}</td>
                        <td><span class="badge badge-soft-success">Terbit</span></td>
                        <td>@if ($hr){!! orang($s->karyawan->nama, $s->karyawan->nik) !!}@else{!! orang($ap?->approver?->namaTampil() ?? 'Manager HR', 'Manager HR · ' . ($ap ? tgl($ap->tgl_approval, 'j M') : '')) !!}@endif</td>
                        <td class="text-center">{{ $hadir }}/{{ $m->hari_kerja }}</td>
                        <td class="text-nowrap">
                            <a href="{{ request()->fullUrlWithQuery(['lihat' => $s->id, 'tutup' => null]) }}" class="btn btn-light btn-icon" title="Lihat"><i data-lucide="eye"></i></a>
                            <a href="{{ route('slip.download', $s) }}" class="btn btn-light btn-icon" title="Unduh PDF"><i data-lucide="download"></i></a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty ikon="receipt" judul="Slip gaji belum tersedia" pesan="Slip terbit setelah payroll disetujui Manager HR." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($slip->lastPage() > 1) @include('partials.halaman', ['data' => $slip, 'label' => 'slip']) @endif
    </div>

    @if ($pilih)
        <x-panel :judul="'Slip Gaji ' . $pilih->payroll->namaPeriode()" :sub="$pilih->karyawan->nama . ' · ' . $pilih->karyawan->jabatan">
            @foreach ($pilih->details()->sortBy(fn ($d) => [$d->component->jenis === 'pengurang', $d->payslip_component_id]) as $d)
                @php $sat = match ($d->component->dasar_bayar) { 'lembur' => ' (' . ($d->jumlah + 0) . ' jam)', 'alfa' => ' (' . ($d->jumlah + 0) . ' hari)', 'pajak' => ' (TER)', default => '' }; @endphp
                <div class="rinci"><span class="k">{{ $d->component->dasar_bayar === 'pajak' ? 'PPh21' : ucfirst(strtolower($d->component->nama)) }}{{ $sat }}</span><span class="v">{{ $d->component->jenis === 'pengurang' ? '- ' : '' }}{{ rupiah($d->nominal) }}</span></div>
            @endforeach
            <div class="rinci total"><span class="k">Gaji bersih</span><span class="v">{{ rupiah($pilih->thp) }}</span></div>
            <div class="tombol"><a href="{{ request()->fullUrlWithQuery(['lihat' => null, 'tutup' => 1]) }}" class="btn btn-light">Tutup</a><a href="{{ route('slip.download', $pilih) }}" class="btn btn-primary">Unduh PDF</a></div>
        </x-panel>
    @endif
</div>
@endsection
