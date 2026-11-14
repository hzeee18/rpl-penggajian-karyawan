@extends('layouts.app')
@section('title', 'Approval Payroll')
@section('breadcrumb', 'Payroll / Approval Payroll')
@section('content')
@php $label = ['pending_approval' => 'menunggu', 'approved' => 'disetujui', 'rejected' => 'ditolak']; @endphp
<x-page-header judul="Approval Payroll" sub="Payroll yang menunggu persetujuan. Keputusan yang pertama masuk yang dipakai." />
<div class="card"><div class="table-responsive"><table class="table table-hover">
    <thead><tr><th>Judul</th><th>Periode</th><th>Diajukan oleh</th><th>Status</th><th>Diputuskan oleh</th><th class="text-center">Aksi</th></tr></thead>
    <tbody>
    @forelse ($run as $r)
        <tr><td>{{ $r->title }}</td><td class="text-nowrap">{{ tgl($r->period_start) }} – {{ tgl($r->period_end) }}</td><td>{{ $r->creator?->namaTampil() }}</td>
            <td>{!! badge_status($label[$r->status]) !!}</td><td>{{ $r->status === 'pending_approval' ? '-' : ($r->approver?->namaTampil() ?? 'Otomatis') }}</td><td class="text-center"><a href="{{ route('pay.approval.show', $r->id) }}" class="btn btn-light btn-icon" title="Buka"><i data-lucide="eye"></i></a></td></tr>
    @empty
        <tr><td colspan="6"><x-empty ikon="circle-check" judul="Tidak ada pengajuan" /></td></tr>
    @endforelse
    </tbody></table></div>
    @if ($run->lastPage() > 1) @include('partials.halaman', ['data' => $run, 'label' => 'payroll']) @endif
</div>
@endsection
