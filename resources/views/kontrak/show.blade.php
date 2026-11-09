@extends('layouts.app')
@section('title', 'Detail kontrak')
@section('breadcrumb', 'Karyawan / Kontrak')
@section('content')
<x-page-header :judul="'Detail kontrak'" />
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Kontrak {!! badge_status($kontrak->status_approval) !!} @if ($kontrak->is_active)<span class="badge badge-soft-success">aktif</span>@endif</span>
                @if ($kontrak->bisaDiubah())<a href="{{ route('kontrak.edit', $kontrak) }}" class="btn btn-sm btn-primary"><i data-lucide="pencil"></i> Revisi & ajukan ulang</a>@endif
            </div>
            <div class="card-body">@include('kontrak._detail')</div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">Riwayat approval</div>
            <div class="card-body"><x-riwayat-approval :approvals="$kontrak->approvals" /></div>
        </div>
    </div>
</div>
@endsection
