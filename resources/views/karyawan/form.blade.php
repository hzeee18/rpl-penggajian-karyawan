@extends('layouts.app')
@section('title', $karyawan->exists ? 'Ubah karyawan' : 'Tambah karyawan')
@section('breadcrumb', 'Karyawan / Data Karyawan')
@section('content')
<x-page-header :judul="$karyawan->exists ? 'Ubah karyawan' : 'Tambah karyawan'" sub="NIK wajib unik. Status PTKP dipakai untuk menghitung PPh21 TER." />
<form method="post" action="{{ $karyawan->exists ? route('karyawan.update', $karyawan) : route('karyawan.store') }}" class="card" style="max-width:900px">
    @csrf @if ($karyawan->exists) @method('put') @endif
    <div class="card-body">
        <h6 class="text-muted mb-3">Data kepegawaian</h6>
        <div class="row">
            <div class="col-md-4 mb-3"><label class="form-label">NIK</label><input name="nik" value="{{ old('nik', $karyawan->nik) }}" class="form-control" required></div>
            <div class="col-md-8 mb-3"><label class="form-label">Nama lengkap</label><input name="nama" value="{{ old('nama', $karyawan->nama) }}" class="form-control" required></div>
            <div class="col-md-4 mb-3"><label class="form-label">Departemen</label>
                <select name="departemen_id" class="form-select" required><option value="">Pilih</option>@foreach ($departemen as $d)<option value="{{ $d->id }}" @selected(old('departemen_id', $karyawan->departemen_id) == $d->id)>{{ $d->nama }}</option>@endforeach</select></div>
            <div class="col-md-4 mb-3"><label class="form-label">Jabatan</label><input name="jabatan" value="{{ old('jabatan', $karyawan->jabatan) }}" class="form-control" required></div>
            <div class="col-md-4 mb-3"><label class="form-label">Tanggal masuk</label><input type="date" name="tgl_masuk" value="{{ old('tgl_masuk', $karyawan->tgl_masuk?->toDateString()) }}" class="form-control" required></div>
            <div class="col-md-4 mb-3"><label class="form-label">Status kepegawaian</label>
                <select name="status_kepegawaian" class="form-select">@foreach (['tetap', 'kontrak', 'harian'] as $s)<option value="{{ $s }}" @selected(old('status_kepegawaian', $karyawan->status_kepegawaian) === $s)>{{ ucfirst($s) }}</option>@endforeach</select></div>
            <div class="col-md-4 mb-3"><label class="form-label">Status PTKP</label>
                <select name="pajak_id" class="form-select" required><option value="">Pilih</option>@foreach ($pajak as $p)<option value="{{ $p->id }}" @selected(old('pajak_id', $karyawan->pajak_id) == $p->id)>{{ $p->status_ptkp }}</option>@endforeach</select></div>
            <div class="col-md-4 mb-3"><label class="form-label">Sisa cuti (hari)</label><input type="number" name="sisa_cuti" value="{{ old('sisa_cuti', $karyawan->sisa_cuti) }}" class="form-control" required></div>
        </div>
        <h6 class="text-muted mb-3 mt-2">Data pribadi & rekening</h6>
        <div class="row">
            <div class="col-md-4 mb-3"><label class="form-label">Jenis kelamin</label>
                <select name="jenis_kelamin" class="form-select"><option value="">-</option><option value="L" @selected(old('jenis_kelamin', $karyawan->jenis_kelamin) === 'L')>Laki-laki</option><option value="P" @selected(old('jenis_kelamin', $karyawan->jenis_kelamin) === 'P')>Perempuan</option></select></div>
            <div class="col-md-4 mb-3"><label class="form-label">Email</label><input type="email" name="email" value="{{ old('email', $karyawan->email) }}" class="form-control"></div>
            <div class="col-md-4 mb-3"><label class="form-label">Telepon</label><input name="telepon" value="{{ old('telepon', $karyawan->telepon) }}" class="form-control"></div>
            <div class="col-12 mb-3"><label class="form-label">Alamat</label><textarea name="alamat" rows="2" class="form-control">{{ old('alamat', $karyawan->alamat) }}</textarea></div>
            <div class="col-md-4 mb-3"><label class="form-label">Nama bank</label><input name="nama_bank" value="{{ old('nama_bank', $karyawan->nama_bank) }}" class="form-control"></div>
            <div class="col-md-4 mb-3"><label class="form-label">No. rekening</label><input name="no_rekening" value="{{ old('no_rekening', $karyawan->no_rekening) }}" class="form-control"></div>
            <div class="col-md-4 mb-3 d-flex align-items-end"><div class="form-check"><input type="checkbox" name="is_active" value="1" id="aktif" class="form-check-input" @checked(old('is_active', $karyawan->is_active))><label class="form-check-label" for="aktif">Karyawan aktif</label></div></div>
        </div>
    </div>
    <div class="card-footer bg-white text-end">
        <a href="{{ route('karyawan.index') }}" class="btn btn-light">Batal</a>
        <button class="btn btn-primary">Simpan</button>
    </div>
</form>
@endsection
