@extends('layouts.app')
@section('title', 'Slip Gaji ' . $re->full_name)
@section('breadcrumb', 'Payroll Baru / Slip Gaji')
@section('content')
<x-page-header :judul="'Slip Gaji ' . $re->run->title" :sub="$re->full_name . ' · ' . $re->employee_no . ' · ' . tgl($re->period_start) . ' s/d ' . tgl($re->period_end)">
    <a href="{{ route('pay.slip.index') }}" class="btn btn-light">Kembali</a>
    @if ($slip->file_path)<a href="{{ route('pay.slip.unduh', $slip->id) }}" class="btn btn-primary"><i data-lucide="file-down"></i> Unduh PDF</a>@endif
</x-page-header>
@if (! $slip->file_path)<div class="alert alert-warning">{{ $slip->note ?? 'PDF sedang dibuat.' }}</div>@endif
<div class="row g-3">
    @foreach (['earning' => ['Penambah', $re->total_earnings], 'deduction' => ['Pengurang', $re->total_deductions]] as $jenis => [$judul, $total])
        <div class="col-md-6"><div class="card"><div class="card-body">
            <div class="fw-semibold mb-2">{{ $judul }}</div>
            <table class="table table-sm mb-0">
                @foreach ($re->items->where('type', $jenis) as $i)<tr><td>{{ $i->name }}</td><td class="text-end">{{ rupiah($i->final_amount) }}</td></tr>@endforeach
                <tr class="fw-semibold"><td>Total</td><td class="text-end">{{ rupiah($total) }}</td></tr>
            </table></div></div></div>
    @endforeach
</div>
<div class="card mt-3"><div class="card-body d-flex"><span class="fw-semibold me-auto">Take home pay</span><span class="fs-5 fw-semibold">{{ rupiah($re->take_home_pay) }}</span></div></div>
@endsection
