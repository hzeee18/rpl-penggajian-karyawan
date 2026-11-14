@extends('layouts.app')
@section('title', 'Cut Off Payroll')
@section('breadcrumb', 'Payroll / Cut Off')
@section('content')
<x-page-header judul="Cut Off Payroll" sub="Rentang tanggal data absensi, leave, dan lembur yang dihitung untuk satu periode payroll." />

<div class="card" style="max-width:640px">
    <form method="post" action="{{ route('pay.cutoff.update') }}" class="card-body">
        @csrf @method('put')
        <div class="row g-3">
            <div class="col-sm-6"><label class="form-label">Tanggal mulai</label><input type="number" name="start_day" min="1" max="31" value="{{ old('start_day', $config->start_day) }}" class="form-control" required>@error('start_day')<div class="text-danger small mt-1">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label">Tanggal selesai <span class="text-muted fw-normal">(31 = akhir bulan)</span></label><input type="number" name="end_day" min="1" max="31" value="{{ old('end_day', $config->end_day) }}" class="form-control" required>@error('end_day')<div class="text-danger small mt-1">{{ $message }}</div>@enderror</div>
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

<div class="card mt-3" style="max-width:640px">
    <form method="post" action="{{ route('pay.cutoff.prorata') }}" class="card-body">
        @csrf @method('put')
        <div class="fw-semibold">Gaji untuk karyawan yang tidak bekerja sebulan penuh</div>
        <p class="small text-muted mb-2">Berlaku untuk karyawan yang baru masuk, resign, atau ganti kontrak di tengah periode. Gaji dari kontrak (misalnya gaji pokok) dibayar sesuai jumlah hari kerjanya saja.</p>
        <select name="prorata_dasar" class="form-select" style="max-width:360px" @disabled(! auth()->user()->bisa('pay-cutoff', 'update'))>
            <option value="kerja" data-ket="Hari kerja mengikuti pola hari kerja karyawan dan dikurangi hari libur di Kalender Payroll" @selected($prorata === 'kerja')>Hitung per hari kerja</option>
            <option value="tidak" data-ket="Tetap dibayar penuh walau tidak sebulan penuh" @selected($prorata === 'tidak')>Selalu dibayar penuh</option>
        </select>
        <x-catatan>Contoh: gaji pokok Rp6.000.000, periode punya 22 hari kerja, karyawan masuk di tengah periode dan bekerja 11 hari kerja → dibayar 11/22 × Rp6.000.000 = Rp3.000.000. Kalau polanya Senin–Sabtu, hari Sabtu ikut dihitung.</x-catatan>
        @if (auth()->user()->bisa('pay-cutoff', 'update'))<button class="btn btn-primary mt-3">Simpan</button>@endif
    </form>
</div>
@endsection
