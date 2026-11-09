@extends('layouts.app')
@section('title', $overtime->exists ? 'Ubah lembur' : 'Input lembur')
@section('breadcrumb', 'Lembur')
@section('content')
<x-page-header :judul="$overtime->exists ? 'Ubah lembur' : 'Input lembur'" sub="Upah lembur = 1/173 × gaji pokok × 1,5 (jam pertama) dan × 2 (jam berikutnya)." />
<div class="card" style="max-width:640px">
    <form method="post" action="{{ $overtime->exists ? route('lembur.update', $overtime) : route('lembur.store') }}" class="card-body">
        @csrf @if ($overtime->exists) @method('put') @endif
        <div class="mb-3"><label class="form-label">{{ auth()->user()->isRole('Manager') ? 'Anggota tim' : 'Karyawan' }}</label>
            <select name="karyawan_id" class="form-select" required><option value="">Pilih</option>@foreach ($karyawan as $k)<option value="{{ $k->id }}" @selected(old('karyawan_id', $overtime->karyawan_id) == $k->id)>{{ $k->nik }} · {{ $k->nama }}</option>@endforeach</select></div>
        <div class="row">
            <div class="col-md-4 mb-3"><label class="form-label">Tanggal</label><input type="date" name="tanggal" value="{{ old('tanggal', $overtime->tanggal?->toDateString()) }}" class="form-control" required></div>
            <div class="col-md-4 mb-3"><label class="form-label">Jam mulai</label><input type="time" name="jam_mulai" value="{{ old('jam_mulai', substr($overtime->jam_mulai, 0, 5)) }}" class="form-control" required></div>
            <div class="col-md-4 mb-3"><label class="form-label">Jam selesai</label><input type="time" name="jam_selesai" value="{{ old('jam_selesai', substr($overtime->jam_selesai, 0, 5)) }}" class="form-control" required></div>
        </div>
        <div class="mb-3"><label class="form-label">Keterangan</label><input name="keterangan" value="{{ old('keterangan', $overtime->keterangan) }}" class="form-control" placeholder="Pekerjaan yang dikerjakan"></div>
        <p class="small text-muted">Durasi dihitung otomatis. Upah lembur = 1/173 × gaji pokok × 1,5 (jam pertama) dan × 2 (jam berikutnya). Hanya karyawan yang kontraknya punya komponen lembur yang bisa diinput.</p>
        <a href="{{ route('lembur.index') }}" class="btn btn-light">Batal</a> <button class="btn btn-primary">Simpan</button>
    </form>
</div>
@endsection
