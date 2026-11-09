@extends('layouts.app')
@php $u = auth()->user(); $boleh = $u->bisa('cuti', 'create'); @endphp
@section('title', 'Cuti, Izin & Sakit')
@section('breadcrumb', 'Absensi / Cuti, Izin & Sakit')
@section('content')
<x-page-header judul="Cuti, Izin & Sakit" sub="Cuti mengurangi sisa jatah tahunan. Izin dan sakit tidak.">
    <a href="{{ route('absensi.index') }}" class="btn btn-light"><i data-lucide="arrow-left"></i> Input Harian</a>
    @if ($boleh)<a href="{{ route('import.index', ['jenis' => 'cuti']) }}" class="btn btn-light">Import Excel</a>
        <a href="{{ route('cuti.index', ['periode' => $periode, 'baru' => 1]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Input Cuti / Izin / Sakit</a>@endif
</x-page-header>

<div class="{{ $boleh ? 'md-grid' : '' }}">
    <div class="card">
        <x-pilih-periode :periode="$periode" />
        <div class="card-search">@include('cuti._jenis')</div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Tanggal</th><th>Jenis</th><th>Karyawan</th><th>Keterangan</th>@if ($boleh)<th>Aksi</th>@endif</tr></thead>
                <tbody>
                @forelse ($cuti as $c)
                    <tr class="{{ $form->id === $c->id ? 'terpilih' : '' }}">
                        <td class="fw-semibold text-nowrap">{{ tgl($c->tgl_mulai, 'j M') }}{{ $c->tgl_selesai->ne($c->tgl_mulai) ? '–' . tgl($c->tgl_selesai, 'j M') : '' }} <span class="small text-muted fw-normal">({{ $c->jumlahHari() }} hari)</span></td>
                        <td>{!! badge_status($c->jenis) !!}</td>
                        <td>{!! orang($c->karyawan->nama, 'Sisa cuti ' . $c->karyawan->sisa_cuti . ' hari') !!}</td>
                        <td class="text-secondary">{{ $c->keterangan ?? '-' }}</td>
                        @if ($boleh)<td class="text-nowrap">@include('partials.aksi', ['ubah' => route('cuti.index', ['periode' => $periode, 'ubah' => $c->id]), 'hapus' => route('cuti.destroy', $c)])</td>@endif
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty ikon="calendar-x" judul="Belum ada data" pesan="Cuti, izin, dan sakit di periode ini akan muncul di sini." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($cuti->lastPage() > 1) @include('partials.halaman', ['data' => $cuti, 'label' => 'data']) @endif
    </div>

    @if ($boleh)
        <x-panel :judul="$form->exists ? 'Ubah Cuti / Izin / Sakit' : 'Input Cuti / Izin / Sakit'" sub="Langsung final, tanpa pengajuan"
                 :action="$form->exists ? route('cuti.update', $form) : route('cuti.store')" :method="$form->exists ? 'put' : 'post'">
            <div class="mb-field"><label class="form-label">Karyawan</label>
                <select name="karyawan_id" class="form-select" required><option value="">Pilih karyawan</option>@foreach ($karyawan as $k)<option value="{{ $k->id }}" @selected(old('karyawan_id', $form->karyawan_id) == $k->id)>{{ $k->nama }} (sisa cuti {{ $k->sisa_cuti }})</option>@endforeach</select></div>
            <div class="mb-field"><label class="form-label">Jenis</label>
                <select name="jenis" class="form-select">@foreach (\App\Models\Leave::JENIS as $v => $l)<option value="{{ $v }}" @selected(old('jenis', $form->jenis) === $v)>{{ $l }}</option>@endforeach</select></div>
            <x-rentang class="mb-field" label="Tanggal" awal="tgl_mulai" akhir="tgl_selesai" :nilai-awal="$form->tgl_mulai" :nilai-akhir="$form->tgl_selesai" required />
            <div class="mb-field"><label class="form-label">Keterangan</label><input name="keterangan" value="{{ old('keterangan', $form->keterangan) }}" class="form-control" placeholder="mis. Surat dokter"></div>
            <x-catatan>Cuti mengurangi sisa jatah sebanyak hari kerja (Senin–Jumat).</x-catatan>
            <div class="tombol"><a href="{{ route('cuti.index', ['periode' => $periode]) }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
        </x-panel>
    @endif
</div>
@endsection
