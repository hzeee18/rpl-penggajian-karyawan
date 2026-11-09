{{-- Satu input rentang tanggal (klik tanggal awal lalu tanggal akhir). Nilai dikirim lewat dua field tersembunyi. --}}
@props(['label' => 'Rentang tanggal', 'awal' => 'dari', 'akhir' => 'sampai', 'nilaiAwal' => null, 'nilaiAkhir' => null, 'required' => false, 'disabled' => false, 'nonaktif' => []])
@php
    $a = old($awal, $nilaiAwal instanceof \DateTimeInterface ? $nilaiAwal->format('Y-m-d') : $nilaiAwal);
    $b = old($akhir, $nilaiAkhir instanceof \DateTimeInterface ? $nilaiAkhir->format('Y-m-d') : $nilaiAkhir);
@endphp
<div {{ $attributes->merge(['class' => '']) }} data-rentang-wrap>
    @if ($label)<label class="form-label">{{ $label }}</label>@endif
    <div class="input-ikon">
        <input type="text" class="form-control" data-rentang data-nonaktif='@json($nonaktif)' placeholder="dd/mm/yyyy – dd/mm/yyyy" autocomplete="off" @required($required) @disabled($disabled)>
        <i data-lucide="calendar-range"></i>
    </div>
    <input type="hidden" name="{{ $awal }}" value="{{ $a }}" data-rentang-awal @disabled($disabled)>
    <input type="hidden" name="{{ $akhir }}" value="{{ $b }}" data-rentang-akhir @disabled($disabled)>
</div>
