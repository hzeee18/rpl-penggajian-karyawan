@extends('layouts.app')
@section('title', 'Slip Gaji')
@section('breadcrumb', 'Payroll Baru / Slip Gaji')
@section('content')
<x-page-header judul="Slip Gaji" :sub="$semua ? 'Slip gaji karyawan dari payroll yang sudah disetujui.' : 'Slip gaji Anda. PDF dilindungi password: 2 digit terakhir nomor karyawan + 4 digit terakhir nomor rekening.'" />
<div class="card">
    @if ($semua)<x-cari placeholder="Cari nama atau nomor karyawan..." />@endif
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Periode</th>@if ($semua)<th>Karyawan</th>@endif<th class="text-end">Take home pay</th><th>PDF</th><th></th></tr></thead>
        <tbody>
        @forelse ($slip as $s)
            <tr><td>{{ $s->runEmployee->run->title }}<div class="sub text-muted">{{ tgl($s->runEmployee->period_start) }} – {{ tgl($s->runEmployee->period_end) }}</div></td>
                @if ($semua)<td>{!! orang($s->runEmployee->full_name, $s->runEmployee->employee_no) !!}</td>@endif
                <td class="text-end">{{ rupiah($s->runEmployee->take_home_pay) }}</td>
                <td>{!! $s->file_path ? badge_status('terbit') : '<span class="small text-muted">' . e($s->note ?? 'sedang dibuat') . '</span>' !!}</td>
                <td class="text-nowrap"><a href="{{ route('pay.slip.show', $s->id) }}" class="btn btn-light btn-sm">Lihat</a>
                    @if ($s->file_path)<a href="{{ route('pay.slip.unduh', $s->id) }}" class="btn btn-light btn-sm"><i data-lucide="download"></i></a>@endif</td></tr>
        @empty
            <tr><td colspan="5"><x-empty ikon="file-text" judul="Belum ada slip gaji" /></td></tr>
        @endforelse
        </tbody></table></div>
    @if ($slip->lastPage() > 1) @include('partials.halaman', ['data' => $slip, 'label' => 'slip']) @endif
</div>
@endsection
