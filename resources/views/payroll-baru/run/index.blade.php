@extends('layouts.app')
@section('title', 'Generate Payroll')
@section('breadcrumb', 'Payroll Baru / Generate Payroll')
@section('content')
@php $label = ['draft' => 'draft', 'pending_approval' => 'menunggu', 'approved' => 'disetujui', 'rejected' => 'ditolak']; @endphp
<x-page-header judul="Generate Payroll" sub="Buat payroll, lihat preview, perbaiki bila perlu, lalu generate untuk disetujui Manager.">
    @if (auth()->user()->bisa('pay-run', 'create'))<a href="{{ route('pay.run.index', ['baru' => 1]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Payroll Baru</a>@endif
</x-page-header>

<div class="md-grid">
    <div class="card"><div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Judul</th><th>Periode</th><th>Status</th><th>Dibuat</th><th></th></tr></thead>
        <tbody>
        @forelse ($run as $r)
            <tr><td>{{ $r->title }}</td><td class="text-nowrap">{{ tgl($r->period_start) }} – {{ tgl($r->period_end) }}</td>
                <td>{!! badge_status($label[$r->status]) !!}@if ($r->sedangDiproses()) <span class="badge badge-soft-info">diproses</span>@endif</td>
                <td>{{ $r->creator?->username }}</td>
                <td><a href="{{ route('pay.run.show', $r->id) }}" class="btn btn-light btn-sm">Buka</a></td></tr>
        @empty
            <tr><td colspan="5"><x-empty ikon="calculator" judul="Belum ada payroll" /></td></tr>
        @endforelse
        </tbody></table></div>
        @if ($run->lastPage() > 1) @include('partials.halaman', ['data' => $run, 'label' => 'payroll']) @endif
    </div>

    @if (request()->has('baru'))
        <x-panel judul="Payroll Baru" sub="Periode mengikuti konfigurasi cut off" :buka="true" :action="route('pay.run.store')">
            <div class="mb-field"><label class="form-label">Judul</label><input name="title" value="{{ old('title', 'Payroll ' . now()->translatedFormat('F Y')) }}" class="form-control" required></div>
            <div class="mb-field"><label class="form-label">Periode</label><input type="month" name="periode" value="{{ old('periode', now()->format('Y-m')) }}" class="form-control" required>
                @error('periode')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="tombol"><a href="{{ route('pay.run.index') }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Buat</button></div>
        </x-panel>
    @endif
</div>
@endsection
