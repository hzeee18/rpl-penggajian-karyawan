@extends('layouts.app')
@section('title', 'Cut Off Payroll')
@section('breadcrumb', 'Payroll Baru / Cut Off')
@section('content')
<x-page-header judul="Cut Off Payroll" sub="Rentang tanggal data absensi, leave, dan lembur yang dihitung untuk satu periode payroll." />

<div class="card" style="max-width:640px">
    <form method="post" action="{{ route('pay.cutoff.update') }}" class="card-body">
        @csrf @method('put')
        <div class="row g-3">
            <div class="col-sm-6"><label class="form-label">Tanggal mulai</label><input type="number" name="start_day" min="1" max="31" value="{{ old('start_day', $config->start_day) }}" class="form-control" required></div>
            <div class="col-sm-6"><label class="form-label">Tanggal selesai</label><input type="number" name="end_day" min="1" max="31" value="{{ old('end_day', $config->end_day) }}" class="form-control" required></div>
        </div>
        <div class="form-check mt-3"><input type="checkbox" name="start_in_previous_month" value="1" class="form-check-input" id="prev" @checked(old('start_in_previous_month', $config->start_in_previous_month))>
            <label class="form-check-label" for="prev">Mulai di bulan sebelumnya (lintas bulan, mis. 26 s/d 25)</label></div>
        <x-catatan>Contoh hasil:
            @foreach ($contoh as $periode => $r)
                <div>Periode {{ nama_periode($periode) }}: {{ $r ? tgl($r[0]) . ' s/d ' . tgl($r[1]) : 'konfigurasi tidak valid' }}</div>
            @endforeach
        </x-catatan>
        @if (auth()->user()->bisa('pay-cutoff', 'update'))<button class="btn btn-primary mt-3">Simpan</button>@endif
    </form>
</div>
@endsection
