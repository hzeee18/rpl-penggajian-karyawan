@extends('layouts.app')
@section('title', 'Preview import · ' . $judul)
@section('breadcrumb', 'Import Excel / Preview')
@section('content')
<x-page-header :judul="'Preview import · ' . $judul" />
<div class="alert alert-success"><i data-lucide="circle-check"></i> {{ count($import['data']) }} baris dari <strong>{{ $import['file'] }}</strong> lolos validasi. Cek lagi sebelum disimpan.</div>
<div class="card">
    <div class="table-responsive" style="max-height:60vh">
        <table class="table table-sm table-striped mb-0">
            <thead class="sticky-top bg-white"><tr><th>#</th>@foreach ($kolom as $k)<th>{{ $k }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($import['data'] as $i => $row)
                <tr><td>{{ $i + 1 }}</td>@foreach ($kolom as $k)<td>{{ $row[$k] }}</td>@endforeach</tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white text-end">
        <a href="{{ route('import.index') }}" class="btn btn-light">Batal</a>
        <form method="post" action="{{ route('import.simpan') }}" class="d-inline" data-confirm="Simpan {{ count($import['data']) }} baris data?">@csrf<button class="btn btn-primary"><i data-lucide="save"></i> Simpan semua</button></form>
    </div>
</div>
@endsection
