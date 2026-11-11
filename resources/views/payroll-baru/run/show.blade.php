@extends('layouts.app')
@section('title', $run->title)
@section('breadcrumb', 'Payroll Baru / Generate Payroll / ' . $run->title)
@section('content')
@php
    $u = auth()->user();
    $label = ['draft' => 'draft', 'pending_approval' => 'menunggu', 'approved' => 'disetujui', 'rejected' => 'ditolak'];
    $bisaEdit = $run->isDraft() && ! $run->sedangDiproses() && $u->bisa('pay-run', 'update');
@endphp
@if ($run->sedangDiproses())<meta http-equiv="refresh" content="5">@endif
<x-page-header :judul="$run->title" :sub="'Periode ' . tgl($run->period_start) . ' s/d ' . tgl($run->period_end) . ($run->masterVersion ? ' · ' . $run->masterVersion->master->name . ' v' . $run->masterVersion->version_no : '')" :status="$label[$run->status]">
    <a href="{{ route('pay.run.index') }}" class="btn btn-light">Kembali</a>
    @if ($run->isDraft() && ! $run->sedangDiproses() && $u->bisa('pay-run', 'delete'))
        <form method="post" action="{{ route('pay.run.destroy', $run->id) }}" data-confirm="Hapus payroll draft ini?">@csrf @method('delete')<button class="btn btn-light">Hapus</button></form>
    @endif
    @if ($bisaEdit && $total->n > 0)
        <form method="post" action="{{ route('pay.run.generate', $run->id) }}" data-confirm="Data yang sudah digenerate TIDAK BISA DIUBAH lagi dan akan dikirim ke Manager untuk disetujui. Lanjutkan?">@csrf<button class="btn btn-primary">Generate Payroll</button></form>
    @endif
</x-page-header>

@if ($errors->any())<div class="alert alert-danger">{{ implode(' ', $errors->all()) }}</div>@endif
@if ($run->sedangDiproses())<div class="alert alert-info">Sedang diproses ({{ $run->job_type === 'generate' ? 'generate' : 'preview' }})… halaman memuat ulang otomatis.</div>@endif
@if ($run->job_status === 'failed')<div class="alert alert-danger">Proses gagal: {{ $run->job_error }}</div>@endif
@if ($run->status === 'pending_approval')<div class="alert alert-warning">Menunggu persetujuan {{ $run->approver?->namaTampil() ?? 'approver' }}. Data tidak bisa diubah.</div>@endif
@if ($run->status === 'rejected')<div class="alert alert-danger">Ditolak: {{ $run->reject_reason }}. Buat payroll baru untuk periode ini.</div>@endif
@if ($kedaluwarsa->isNotEmpty())
    <div class="alert alert-warning"><div class="fw-semibold">Kontrak aktif yang sudah berakhir sebelum akhir periode (nonaktifkan manual bila perlu):</div>
        {{ $kedaluwarsa->map(fn ($k) => $k->employee->nik . ' ' . $k->employee->nama . ' (' . tgl($k->end_date) . ')')->join(', ') }}</div>
@endif

@if ($run->isDraft() && $u->bisa('pay-run', 'update'))
    <div class="card mb-3"><form method="post" action="{{ route('pay.run.pratinjau', $run->id) }}" class="card-body row g-2 align-items-end">@csrf
        <div class="col-md-3"><label class="form-label small">Master payroll</label><select name="master_id" class="form-select" required>
            @foreach ($master as $m)<option value="{{ $m->id }}" @selected($run->masterVersion?->master_id === $m->id)>{{ $m->name }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label small">Karyawan</label><select name="scope" class="form-select">
            @foreach (['all' => 'Semua', 'department' => 'Per departemen', 'individual' => 'Perorangan'] as $k => $v)<option value="{{ $k }}" @selected(($run->employee_scope ?? 'all') === $k)>{{ $v }}</option>@endforeach</select></div>
        <div class="col-md-5"><label class="form-label small">Pilihan (departemen / karyawan)</label><select name="pilihan[]" multiple class="form-select" data-mode="chips">
            <optgroup label="Departemen">@foreach ($departemen as $d)<option value="{{ $d->id }}">{{ $d->nama }}</option>@endforeach</optgroup>
            <optgroup label="Karyawan">@foreach ($semuaKaryawan as $e)<option value="{{ $e->id }}" @selected(in_array($e->id, $run->employee_selection ?? []))>{{ $e->nik }} · {{ $e->nama }}</option>@endforeach</optgroup></select>
            <div class="form-text">Pilih departemen bila lingkup "Per departemen", karyawan bila "Perorangan".</div></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" @disabled($run->sedangDiproses())>Lihat preview</button></div>
    </form></div>
@endif

<div class="row g-3 mb-3">
    <div class="col"><div class="card"><div class="card-body"><div class="small text-muted">Karyawan</div><div class="fs-5 fw-semibold">{{ $total->n }}</div></div></div></div>
    <div class="col"><div class="card"><div class="card-body"><div class="small text-muted">Total penambah</div><div class="fs-5 fw-semibold">{{ rupiah($total->penambah) }}</div></div></div></div>
    <div class="col"><div class="card"><div class="card-body"><div class="small text-muted">Total pengurang</div><div class="fs-5 fw-semibold">{{ rupiah($total->pengurang) }}</div></div></div></div>
    <div class="col"><div class="card"><div class="card-body"><div class="small text-muted">Total THP</div><div class="fs-5 fw-semibold">{{ rupiah($total->thp) }}</div></div></div></div>
</div>

<div class="card">@include('payroll-baru.run._tabel', ['bisaEdit' => $bisaEdit])</div>

@if (! empty($run->skipped_employees))
    <div class="card mt-3"><div class="card-body"><div class="fw-semibold mb-2">Karyawan dilewati ({{ count($run->skipped_employees) }})</div>
        <ul class="small mb-0">@foreach ($run->skipped_employees as $s)<li>{{ $s['nik'] }} {{ $s['nama'] }}: {{ $s['alasan'] }}</li>@endforeach</ul></div></div>
@endif
@endsection
