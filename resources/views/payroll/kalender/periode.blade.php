@extends('layouts.app')
@section('title', 'Kalender Payroll')
@section('breadcrumb', 'Payroll / Kalender Payroll')
@section('content')
@php
    $u = auth()->user();
    $badge = \App\Domain\Payroll\Models\PayPeriod::STATUS;
@endphp
<x-page-header judul="Kalender Payroll" sub="Rentang tanggal untuk setiap bulan payroll. Periode baru selalu dimulai sehari setelah periode sebelumnya berakhir.">
    @if ($u->bisa('pay-libur', 'create'))
        <form method="post" action="{{ route('pay.libur.periode.store') }}" @if ($rencana) data-confirm="Buat periode {{ nama_periode($rencana[0]) }} ({{ tgl($rencana[1]) }} – {{ tgl($rencana[2]) }})? Tanggalnya dihitung otomatis dari aturan Cut Off dan bisa diubah nanti selama belum dipakai payroll." @endif>@csrf
            <button class="btn btn-primary" @disabled(! $config)><i data-lucide="plus"></i> Buat periode berikutnya</button></form>
    @endif
</x-page-header>
@include('payroll.kalender._tab', ['aktif' => 'periode'])
@if ($errors->any())<div class="alert alert-danger">{{ implode(' ', $errors->all()) }}</div>@endif
@unless ($config)<div class="mb-3"><x-catatan>Atur aturan cut off dulu di menu Cut Off sebelum membuat periode.</x-catatan></div>@endunless

<div class="md-grid">
    <div class="card"><div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Periode</th><th>Rentang tanggal</th><th class="text-center">Hari</th><th class="text-center" title="Menurut pola hari kerja bawaan perusahaan">Hari kerja</th><th class="text-center">Libur</th><th>Status</th><th class="text-center" style="width:160px">Aksi</th></tr></thead>
        <tbody>
        @forelse ($periode as $p)
            @php $r = $ringkas[$p->id]; @endphp
            <tr class="{{ $ubah?->id === $p->id ? 'terpilih' : '' }}"><td class="fw-semibold">{{ nama_periode($p->period_month) }}</td>
                <td class="text-nowrap">{{ tgl($p->start_date) }} – {{ tgl($p->end_date) }}</td>
                <td class="text-center">{{ $r['hari'] }}</td><td class="text-center">{{ $r['kerja'] }}</td><td class="text-center">{{ $r['libur'] }}</td>
                <td><span class="badge badge-soft-{{ $badge[$r['status']][1] }}">{{ $badge[$r['status']][0] }}</span></td>
                @php
                    $terbuka = $r['status'] === 'terbuka';
                    $alasanHapus = ! $terbuka ? 'Tidak bisa dihapus karena sudah dipakai payroll' : (! $loop->first ? 'Hanya periode terakhir yang bisa dihapus, supaya tanggal periode tetap menyambung' : null);
                @endphp
                <td class="text-nowrap" style="width:160px"><div class="d-flex justify-content-center gap-2">
                    <a href="{{ route('pay.libur.index', ['tab' => 'libur', 'bulan' => $p->period_month]) }}" class="btn btn-light btn-icon" title="Lihat kalender"><i data-lucide="calendar-days"></i></a>
                    @if ($terbuka && $u->bisa('pay-libur', 'update'))<a href="{{ route('pay.libur.index', ['periode_ubah' => $p->id]) }}" class="btn btn-light btn-icon" title="Ubah tanggal"><i data-lucide="pencil"></i></a>@endif
                    @if ($terbuka && $u->bisa('pay-libur', 'delete'))
                        @if ($alasanHapus === null)
                            <form method="post" action="{{ route('pay.libur.periode.destroy', $p->id) }}" data-confirm="Hapus periode {{ nama_periode($p->period_month) }}?">@csrf @method('delete')<button class="btn btn-outline-danger btn-icon" title="Hapus periode"><i data-lucide="trash-2"></i></button></form>
                        @else<span class="d-inline-block" tabindex="0" title="{{ $alasanHapus }}"><button type="button" class="btn btn-light btn-icon" disabled><i data-lucide="trash-2"></i></button></span>@endif
                    @endif
                </div></td></tr>
        @empty
            <tr><td colspan="7"><x-empty ikon="calendar-range" judul="Belum ada periode" pesan="Klik Buat periode berikutnya untuk membuat periode pertama." /></td></tr>
        @endforelse
        </tbody></table></div></div>

    @if ($ubah)
        @php $sesudah = $periode->firstWhere('period_month', \Carbon\CarbonImmutable::createFromFormat('!Y-m', $ubah->period_month)->addMonthNoOverflow()->format('Y-m')); @endphp
        <x-panel :buka="true" :judul="'Ubah tanggal periode ' . nama_periode($ubah->period_month)" sub="Bisa diubah karena belum diproses payroll" :action="route('pay.libur.periode.update', $ubah->id)" method="put">
            <div class="mb-field"><label class="form-label">Tanggal mulai</label><input class="form-control" value="{{ tgl($ubah->start_date) }}" readonly>
                <div class="form-text">Selalu sehari setelah periode sebelumnya berakhir.</div></div>
            <div class="mb-field"><label class="form-label">Tanggal selesai</label><input type="date" name="end_date" value="{{ old('end_date', $ubah->end_date->toDateString()) }}" min="{{ $ubah->start_date->toDateString() }}" class="form-control" required></div>
            @if ($sesudah)<div class="mb-field"><x-catatan tipe="warning">Periode {{ nama_periode($sesudah->period_month) }} akan ikut bergeser dan dimulai sehari setelah tanggal ini.</x-catatan></div>@endif
            <div class="tombol"><a href="{{ route('pay.libur.index') }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
        </x-panel>
    @endif
</div>
@endsection
