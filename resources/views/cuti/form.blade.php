@extends('layouts.app')
@section('title', $leave->exists ? 'Ubah cuti/izin/sakit' : 'Input cuti/izin/sakit')
@section('breadcrumb', 'Absensi, Cuti & Lembur')
@section('content')
<x-page-header :judul="$leave->exists ? 'Ubah cuti/izin/sakit' : 'Input cuti/izin/sakit'" sub="Cuti mengurangi sisa jatah tahunan sebanyak hari kerja." />
<div class="card" style="max-width:640px">
    <form method="post" action="{{ $leave->exists ? route('cuti.update', $leave) : route('cuti.store') }}" class="card-body">
        @csrf @if ($leave->exists) @method('put') @endif
        <div class="mb-3"><label class="form-label">Karyawan</label>
            <select name="karyawan_id" class="form-select" required><option value="">Pilih</option>@foreach ($karyawan as $k)<option value="{{ $k->id }}" @selected(old('karyawan_id', $leave->karyawan_id) == $k->id)>{{ $k->nik }} · {{ $k->nama }} (sisa cuti {{ $k->sisa_cuti }})</option>@endforeach</select></div>
        <div class="mb-3"><label class="form-label">Jenis</label>
            <select name="jenis" class="form-select">@foreach (\App\Models\Leave::JENIS as $v => $l)<option value="{{ $v }}" @selected(old('jenis', $leave->jenis) === $v)>{{ $l }}</option>@endforeach</select></div>
        <x-rentang class="mb-3" label="Tanggal" awal="tgl_mulai" akhir="tgl_selesai" :nilai-awal="$leave->tgl_mulai" :nilai-akhir="$leave->tgl_selesai" required />
        <div class="mb-3"><label class="form-label">Keterangan</label><input name="keterangan" value="{{ old('keterangan', $leave->keterangan) }}" class="form-control"></div>
        <p class="small text-muted">Cuti mengurangi sisa jatah cuti sebanyak hari kerja (Senin–Jumat). Izin dan sakit tidak mengurangi jatah.</p>
        <a href="{{ route('cuti.index') }}" class="btn btn-light">Batal</a> <button class="btn btn-primary">Simpan</button>
    </form>
</div>
@endsection
