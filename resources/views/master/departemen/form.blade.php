@extends('layouts.app')
@section('title', $departemen->exists ? 'Ubah departemen' : 'Tambah departemen')
@section('breadcrumb', 'Master Data / Departemen')
@section('content')
<x-page-header :judul="$departemen->exists ? 'Ubah departemen' : 'Tambah departemen'" sub="Kode, nama, dan manager departemen." />
<div class="card" style="max-width:560px">
    <form method="post" action="{{ $departemen->exists ? route('departemen.update', $departemen) : route('departemen.store') }}" class="card-body">
        @csrf @if ($departemen->exists) @method('put') @endif
        <div class="mb-3"><label class="form-label">Kode</label><input name="kode" value="{{ old('kode', $departemen->kode) }}" class="form-control" required maxlength="10"></div>
        <div class="mb-3"><label class="form-label">Nama</label><input name="nama" value="{{ old('nama', $departemen->nama) }}" class="form-control" required></div>
        <div class="mb-3">
            <label class="form-label">Manager</label>
            <select name="manager_id" class="form-select"><option value="">— Belum ada —</option>
                @foreach ($karyawan as $k)<option value="{{ $k->id }}" @selected(old('manager_id', $departemen->manager_id) == $k->id)>{{ $k->nik }} · {{ $k->nama }}</option>@endforeach
            </select>
        </div>
        <a href="{{ route('departemen.index') }}" class="btn btn-light">Batal</a>
        <button class="btn btn-primary">Simpan</button>
    </form>
</div>
@endsection
