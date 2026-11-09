@extends('layouts.app')
@section('title', $attendance->exists ? 'Ubah absensi' : 'Input absensi')
@section('breadcrumb', 'Absensi, Cuti & Lembur')
@section('content')
<x-page-header :judul="$attendance->exists ? 'Ubah absensi' : 'Input absensi'" sub="Cuti, izin, dan sakit dicatat di tab Cuti, Izin & Sakit." />
<div class="card" style="max-width:640px">
    <form method="post" action="{{ $attendance->exists ? route('absensi.update', $attendance) : route('absensi.store') }}" class="card-body">
        @csrf @if ($attendance->exists) @method('put') @endif
        <div class="mb-3"><label class="form-label">Karyawan</label>
            <select name="karyawan_id" class="form-select" required><option value="">Pilih</option>@foreach ($karyawan as $k)<option value="{{ $k->id }}" @selected(old('karyawan_id', $attendance->karyawan_id) == $k->id)>{{ $k->nik }} · {{ $k->nama }}</option>@endforeach</select></div>
        <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Tanggal</label><input type="date" name="tanggal" value="{{ old('tanggal', $attendance->tanggal?->toDateString()) }}" class="form-control" required></div>
            <div class="col-md-6 mb-3"><label class="form-label">Status</label>
                <select name="status" class="form-select">@foreach (\App\Models\Attendance::STATUS as $v => $l)<option value="{{ $v }}" @selected(old('status', $attendance->status) === $v)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-md-6 mb-3"><label class="form-label">Jam masuk</label><input type="time" name="jam_masuk" value="{{ old('jam_masuk', $attendance->jam_masuk ? substr($attendance->jam_masuk, 0, 5) : '') }}" class="form-control"></div>
            <div class="col-md-6 mb-3"><label class="form-label">Jam keluar</label><input type="time" name="jam_keluar" value="{{ old('jam_keluar', $attendance->jam_keluar ? substr($attendance->jam_keluar, 0, 5) : '') }}" class="form-control"></div>
        </div>
        <p class="small text-muted">Cuti, izin, dan sakit dicatat di menu Cuti, Izin, Sakit.</p>
        <a href="{{ route('absensi.index') }}" class="btn btn-light">Batal</a> <button class="btn btn-primary">Simpan</button>
    </form>
</div>
@endsection
