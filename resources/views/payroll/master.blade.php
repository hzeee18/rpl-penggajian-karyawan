@extends('layouts.app')
@section('title', $d['halaman'] ?? $d['judul'])
@section('breadcrumb', 'Payroll / ' . ($d['halaman'] ?? $d['judul']))
@section('content')
@php
    $menu = \Illuminate\Support\Str::after($d['route'], 'pay.');
    $bisaTambah = auth()->user()->bisa('pay-' . $menu, 'create');
    $bisaUbah = auth()->user()->bisa('pay-' . $menu, 'update');
    $bisaHapus = auth()->user()->bisa('pay-' . $menu, 'delete');
    $tampil = function ($row, $kolom) use ($d) {
        $v = $row->{$kolom};
        foreach ($d['field'] as $f) {
            if ($f['name'] === $kolom && isset($f['opsi'])) return $f['opsi'][$v] ?? $v;
        }
        if ($v instanceof \Carbon\CarbonInterface) return tgl($v);
        if ($kolom === 'default_amount') return rupiah($v);
        return $v;
    };
@endphp
<x-page-header :judul="$d['halaman'] ?? $d['judul']" :sub="$d['sub']">
    @if ($bisaTambah)<a href="{{ route($d['route'] . '.index', ($d['param'] ?? []) + ['baru' => 1]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Tambah</a>@endif
</x-page-header>

@isset($d['atas'])@include($d['atas'], $atas)@endisset
<div class="md-grid">
    <div class="card">
        <x-cari placeholder="Cari {{ strtolower($d['judul']) }}..." />
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr>@foreach ($d['kolom'] as $label)<th>{{ $label }}</th>@endforeach @if (array_key_exists('is_active', $daftar->first()?->getAttributes() ?? []))<th>Status</th>@endif<th class="text-center">Aksi</th></tr></thead>
                <tbody>
                @forelse ($daftar as $row)
                    <tr class="{{ $ubah->id === $row->id ? 'terpilih' : '' }}">
                        @foreach ($d['kolom'] as $kolom => $label)<td class="{{ $loop->first ? 'kode' : '' }}">{{ $tampil($row, $kolom) }}</td>@endforeach
                        @if (array_key_exists('is_active', $row->getAttributes()))<td>{!! badge_status($row->is_active ? 'aktif' : 'nonaktif') !!}</td>@endif
                        @php $alasanKunci = isset($kunci) ? $kunci($row) : null; $bolehUbahBaris = $bisaUbah && $alasanKunci === null; @endphp
                        <td class="text-nowrap text-center"><div class="d-inline-flex gap-2 justify-content-center">
                            <a href="{{ route($d['route'] . '.index', ($d['param'] ?? []) + ['ubah' => $row->id]) }}" class="btn btn-light btn-icon" title="{{ $alasanKunci ?? ($bolehUbahBaris ? 'Ubah' : 'Lihat') }}"><i data-lucide="{{ $bolehUbahBaris ? 'pencil' : ($alasanKunci ? 'lock' : 'eye') }}"></i></a>
                            @if ($bisaHapus && $alasanKunci === null && ($d['hapus'] === 'fisik' || $row->is_active))
                                <form method="post" action="{{ route($d['route'] . '.destroy', $row->id) }}" data-confirm="{{ $d['hapus'] === 'fisik' ? 'Hapus' : 'Nonaktifkan' }} {{ $row->name }}?">@csrf @method('delete')
                                    @if ($d['hapus'] === 'fisik')<button class="btn btn-outline-danger btn-icon" title="Hapus"><i data-lucide="trash-2"></i></button>@else<button class="btn btn-light btn-icon" title="Nonaktifkan"><i data-lucide="power-off"></i></button>@endif</form>
                            @endif
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($d['kolom']) + 2 }}"><x-empty ikon="inbox" :judul="'Belum ada ' . strtolower($d['judul'])" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-panel :judul="$ubah->exists ? 'Ubah ' . $d['judul'] : 'Tambah ' . $d['judul']" :sub="$ubah->exists ? ($ubah->code ?? tgl($ubah->holiday_date)) . ' · ' . $ubah->name : $d['judul'] . ' baru'"
             :action="$ubah->exists ? route($d['route'] . '.update', $ubah->id) : route($d['route'] . '.store')" :method="$ubah->exists ? 'put' : 'post'">
        @foreach ($d['field'] as $f)
            @php $nilai = old($f['name'], $ubah->exists ? $ubah->{$f['name']} : ($f['type'] === 'checkbox' ? true : request()->query($f['name']))); @endphp
            @if ($f['type'] === 'checkbox')
                <div class="form-check mb-field"><input type="checkbox" name="{{ $f['name'] }}" value="1" class="form-check-input" id="f-{{ $f['name'] }}" @checked($nilai)><label class="form-check-label" for="f-{{ $f['name'] }}">{{ $f['label'] }}</label></div>
            @elseif ($f['type'] === 'select')
                <div class="mb-field"><label class="form-label">{{ $f['label'] }}</label><select name="{{ $f['name'] }}" class="form-select" @unless ($f['opsional'] ?? false) required @endunless>@foreach ($f['opsi'] as $k => $v)<option value="{{ $k }}" @selected((string) $nilai === (string) $k)>{{ $v }}</option>@endforeach</select>@isset($f['bantuan'])<div class="form-text">{{ $f['bantuan'] }}</div>@endisset</div>
            @else
                <div class="mb-field"><label class="form-label">{{ $f['label'] }}</label>
                    <input type="{{ $f['type'] }}" name="{{ $f['name'] }}" value="{{ $nilai instanceof \Carbon\CarbonInterface ? $nilai->format('Y-m-d') : $nilai }}" class="form-control" @if ($f['type'] === 'number') step="0.01" min="0" @endif required>
                    @isset($f['bantuan'])<div class="form-text">{{ $f['bantuan'] }}</div>@endisset</div>
            @endif
        @endforeach
        @php $kunciForm = $ubah->exists && isset($kunci) ? $kunci($ubah) : null; @endphp
        @if ($kunciForm)<x-catatan tipe="warning">{{ $kunciForm }}</x-catatan>@endif
        @if (($ubah->exists ? $bisaUbah : $bisaTambah) && ! $kunciForm)
            <div class="tombol"><a href="{{ route($d['route'] . '.index', $d['param'] ?? []) }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
        @endif
    </x-panel>
</div>
@endsection
