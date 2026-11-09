@extends('layouts.app')
@section('title', $komponen->exists ? 'Ubah komponen gaji' : 'Tambah komponen gaji')
@section('breadcrumb', 'Master Data / Komponen Gaji')
@section('content')
<x-page-header :judul="$komponen->exists ? 'Ubah komponen gaji' : 'Tambah komponen gaji'" sub="Atur jenis, dasar bayar, dan nilai default komponen." />
<div class="card" style="max-width:640px">
    <form method="post" action="{{ $komponen->exists ? route('komponen.update', $komponen) : route('komponen.store') }}" class="card-body">
        @csrf @if ($komponen->exists) @method('put') @endif
        <div class="mb-3"><label class="form-label">Nama komponen</label><input name="nama" value="{{ old('nama', $komponen->nama) }}" class="form-control" required></div>
        <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Jenis</label>
                <select name="jenis" class="form-select">@foreach (['penambah' => 'Penambah', 'pengurang' => 'Pengurang'] as $v => $l)<option value="{{ $v }}" @selected(old('jenis', $komponen->jenis) === $v)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-md-6 mb-3"><label class="form-label">Dasar bayar</label>
                <select name="dasar_bayar" class="form-select">@foreach (\App\Models\PayslipComponent::DASAR_BAYAR as $v => $l)<option value="{{ $v }}" @selected(old('dasar_bayar', $komponen->dasar_bayar) === $v)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-md-6 mb-3"><label class="form-label">Tipe nilai</label>
                <select name="tipe_nilai" class="form-select">@foreach (['nominal' => 'Nominal (Rp)', 'persen' => 'Persen dari gaji pokok'] as $v => $l)<option value="{{ $v }}" @selected(old('tipe_nilai', $komponen->tipe_nilai) === $v)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-md-6 mb-3"><label class="form-label">Nilai default</label><input type="number" step="0.01" min="0" name="nilai_default" value="{{ old('nilai_default', $komponen->nilai_default ?? 0) }}" class="form-control" required></div>
            <div class="col-md-6 mb-3"><label class="form-label">Status</label>
                <select name="status_berlaku" class="form-select">@foreach (['aktif', 'nonaktif'] as $v)<option value="{{ $v }}" @selected(old('status_berlaku', $komponen->status_berlaku) === $v)>{{ ucfirst($v) }}</option>@endforeach</select></div>
            <div class="col-md-6 mb-3 d-flex align-items-end"><div class="form-check"><input type="checkbox" name="kena_pajak" value="1" id="pajak" class="form-check-input" @checked(old('kena_pajak', $komponen->kena_pajak))><label class="form-check-label" for="pajak">Kena pajak (masuk bruto PPh 21)</label></div></div>
        </div>
        <a href="{{ route('komponen.index') }}" class="btn btn-light">Batal</a>
        <button class="btn btn-primary">Simpan</button>
    </form>
</div>
@endsection
