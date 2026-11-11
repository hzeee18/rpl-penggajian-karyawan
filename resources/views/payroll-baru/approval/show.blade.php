@extends('layouts.app')
@section('title', $run->title)
@section('breadcrumb', 'Payroll Baru / Approval Payroll / ' . $run->title)
@section('content')
@php
    $label = ['pending_approval' => 'menunggu', 'approved' => 'disetujui', 'rejected' => 'ditolak', 'draft' => 'draft'];
    $kolom = $karyawan->getCollection()->flatMap->items->unique('code')->sortBy(fn ($i) => [$i->type === 'earning' ? 0 : 1, $i->sort_order])->values();
    $menunggu = $run->status === 'pending_approval' && auth()->user()->bisa('pay-approval', 'approve');
@endphp
<x-page-header :judul="$run->title" :sub="'Periode ' . tgl($run->period_start) . ' s/d ' . tgl($run->period_end) . ' · ' . $total->n . ' karyawan · total THP ' . rupiah($total->thp)" :status="$label[$run->status]">
    <a href="{{ route('pay.approval.index') }}" class="btn btn-light">Kembali</a>
    @if ($menunggu)
        <form method="post" action="{{ route('pay.approval.setujui', $run->id) }}" data-confirm="Setujui payroll ini? Slip gaji akan diterbitkan.">@csrf<button class="btn btn-primary">Setujui</button></form>
    @endif
</x-page-header>
@if ($errors->any())<div class="alert alert-danger">{{ implode(' ', $errors->all()) }}</div>@endif
@if ($run->status === 'rejected')<div class="alert alert-danger">Ditolak: {{ $run->reject_reason }}</div>@endif

<div class="card mb-3">@include('payroll-baru.run._tabel', ['bisaEdit' => false])</div>

@if ($menunggu)
    <div class="card"><form method="post" action="{{ route('pay.approval.tolak', $run->id) }}" class="card-body">@csrf
        <label class="form-label">Alasan menolak</label><textarea name="alasan" class="form-control mb-2" rows="2" required>{{ old('alasan') }}</textarea>
        <button class="btn btn-outline-danger">Tolak</button></form></div>
@endif
@endsection
