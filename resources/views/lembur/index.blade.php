@extends('layouts.app')
@php $u = auth()->user(); $mgr = $u->isManagerTim(); $boleh = $u->bisa('lembur', 'create'); @endphp
@section('title', $mgr ? 'Lembur Tim' : 'Lembur')
@section('breadcrumb', $mgr ? 'Lembur Tim' : 'Absensi / Lembur')
@section('content')
<x-page-header :judul="$mgr ? 'Lembur Tim' : 'Lembur'" :sub="$mgr ? 'Input lembur anggota tim kamu. Langsung tercatat, tanpa approval.' : 'Lembur hanya untuk karyawan yang kontraknya punya komponen lembur.'">
    @if ($boleh)<a href="{{ route('lembur.index', ['periode' => $periode, 'baru' => 1]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Input Lembur</a>@endif
</x-page-header>

<div class="{{ $boleh ? 'md-grid' : '' }}">
    <div class="card">
        <x-pilih-periode :periode="$periode" />
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Tanggal</th><th>Jam · Keterangan</th><th>Karyawan</th><th class="text-end">Durasi</th>@if ($boleh)<th>Aksi</th>@endif</tr></thead>
                <tbody>
                @forelse ($lembur as $l)
                    <tr class="{{ $form->id === $l->id ? 'terpilih' : '' }}">
                        <td class="fw-semibold text-nowrap">{{ tgl($l->tanggal, 'j M') }}</td>
                        <td>{{ substr($l->jam_mulai, 0, 5) }}–{{ substr($l->jam_selesai, 0, 5) }} · {{ $l->keterangan ?? '-' }}</td>
                        <td>{!! orang($l->karyawan->nama, $l->karyawan->jabatan) !!}</td>
                        <td class="text-end">{{ $l->durasi + 0 }} jam</td>
                        @if ($boleh)
                            <td class="text-nowrap"><a href="{{ route('lembur.index', ['periode' => $periode, 'ubah' => $l->id]) }}" class="btn btn-light btn-icon" title="Ubah"><i data-lucide="pencil"></i></a>
                                @unless ($mgr)@include('partials.hapus', ['url' => route('lembur.destroy', $l)])@endunless</td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty ikon="clock" judul="Belum ada lembur bulan ini" pesan="Lembur yang kamu input untuk tim akan tercatat di sini." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($lembur->lastPage() > 1) @include('partials.halaman', ['data' => $lembur, 'label' => 'lembur']) @endif
    </div>

    @if ($boleh)
        <x-panel :judul="$form->exists ? 'Ubah Lembur' : 'Input Lembur'" :sub="$mgr && $dept ? 'Departemen ' . $dept->nama . ' · ' . $karyawan->count() . ' anggota' : 'Karyawan yang berhak lembur'"
                 :action="$form->exists ? route('lembur.update', $form) : route('lembur.store')" :method="$form->exists ? 'put' : 'post'">
            <div class="mb-field"><label class="form-label">Karyawan</label>
                <select name="karyawan_id" class="form-select" required><option value="">Pilih karyawan</option>@foreach ($karyawan as $k)<option value="{{ $k->id }}" @selected(old('karyawan_id', $form->karyawan_id) == $k->id)>{{ $k->nama }}</option>@endforeach</select></div>
            <div class="mb-field"><label class="form-label">Tanggal</label><input type="date" name="tanggal" value="{{ old('tanggal', $form->tanggal?->toDateString()) }}" class="form-control" required></div>
            <div class="row g-2 mb-field">
                <div class="col"><label class="form-label">Jam mulai</label><input type="time" name="jam_mulai" id="jm" value="{{ old('jam_mulai', substr($form->jam_mulai, 0, 5)) }}" class="form-control" required></div>
                <div class="col"><label class="form-label">Jam selesai</label><input type="time" name="jam_selesai" id="js" value="{{ old('jam_selesai', substr($form->jam_selesai, 0, 5)) }}" class="form-control" required></div>
            </div>
            <div class="mb-field"><label class="form-label">Keterangan</label><input name="keterangan" value="{{ old('keterangan', $form->keterangan) }}" class="form-control" placeholder="mis. Closing gudang akhir bulan"></div>
            <x-catatan>Durasi dihitung otomatis: <b id="durasi">-</b>. Lembur langsung masuk ke perhitungan payroll, tanpa approval.{{ $mgr ? ' HR tetap bisa melihat dan mengubah.' : '' }}</x-catatan>
            <div class="tombol"><a href="{{ route('lembur.index', ['periode' => $periode]) }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
        </x-panel>
        @push('scripts')
        <script>
            (() => { const m = jm, s = js, o = durasi; const hit = () => { if (!m.value || !s.value) return; let [a, b] = m.value.split(':').map(Number), [c, d] = s.value.split(':').map(Number); let mnt = (c * 60 + d) - (a * 60 + b); if (mnt <= 0) mnt += 1440; o.textContent = (Math.round(mnt / 6) / 10).toString().replace('.', ',') + ' jam'; }; m.oninput = s.oninput = hit; hit(); })();
        </script>
        @endpush
    @endif
</div>
@endsection
