@extends('layouts.app')
@section('title', $versi->master->name . ' v' . $versi->version_no)
@section('breadcrumb', 'Payroll Baru / Master Payroll / ' . $versi->master->code)
@section('content')
@php
    $u = auth()->user();
    $draft = $versi->bisaDiubah() && $u->bisa('pay-master', 'update');
    $ubahVar = $form === 'variabel' ? $versi->variables->firstWhere('id', $ubah) : null;
    $ubahParam = $form === 'param' ? $versi->params->firstWhere('id', $ubah) : null;
    $ubahItem = $form === 'item' ? $versi->items->firstWhere('id', $ubah) : null;
    $status = $versi->locked_at ? 'terkunci (dipakai payroll final)' : ($versi->status === 'published' ? 'terbit' : 'draft');
@endphp
<x-page-header :judul="$versi->master->name . ' · v' . $versi->version_no" :sub="'Status: ' . $status . ($versi->master->current_version_id === $versi->id ? ' · versi aktif' : '')">
    <a href="{{ route('pay.master.index') }}" class="btn btn-light">Kembali</a>
    @if ($u->bisa('pay-master', 'create'))<form method="post" action="{{ route('pay.master.salin', $versi->id) }}">@csrf<button class="btn btn-light">Salin ke versi baru</button></form>@endif
    @if ($draft)<form method="post" action="{{ route('pay.master.terbitkan', $versi->id) }}" data-confirm="Terbitkan versi ini? Versi terbit tidak bisa diubah.">@csrf<button class="btn btn-primary" @disabled($masalah !== [])>Terbitkan</button></form>@endif
</x-page-header>
@if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
@if ($masalah !== [])<div class="alert alert-warning"><div class="fw-semibold">Belum bisa diterbitkan:</div><ul class="mb-0">@foreach ($masalah as $m)<li>{{ $m }}</li>@endforeach</ul></div>@endif

<div class="row g-3">
    <div class="col-xl-8">
        @foreach ([['Parameter', 'param', $versi->params, ['code' => 'Kode', 'name' => 'Nama', 'value' => 'Nilai']],
                   ['Variabel', 'variabel', $versi->variables, ['sort_order' => '#', 'code' => 'Kode', 'kind' => 'Jenis', 'source' => 'Sumber', 'aggregate' => 'Agregat', 'filter_expression' => 'Filter', 'value_expression' => 'Nilai', 'formula' => 'Rumus']],
                   ['Komponen', 'item', $versi->items, ['sort_order' => '#', 'code' => 'Kode', 'type' => 'Jenis', 'calc_type' => 'Hitung', 'formula' => 'Rumus', 'item_role' => 'Peran']]] as [$judul, $jenis, $daftar, $kolom])
            <div class="card mb-3">
                <div class="card-header d-flex"><span class="fw-semibold me-auto">{{ $judul }}</span>
                    @if ($draft)<a href="{{ route('pay.master.versi', ['id' => $versi->id, 'form' => $jenis]) }}" class="btn btn-light btn-sm">+ Tambah</a>@endif</div>
                <div class="table-responsive"><table class="table table-sm mb-0">
                    <thead><tr>@foreach ($kolom as $label)<th>{{ $label }}</th>@endforeach @if ($draft)<th></th>@endif</tr></thead>
                    <tbody>
                    @forelse ($daftar as $r)
                        <tr>@foreach ($kolom as $k => $label)<td class="{{ in_array($k, ['formula', 'filter_expression', 'value_expression']) ? 'font-monospace small' : '' }}">{{ $k === 'type' ? ($r->type === 'earning' ? 'penambah' : 'pengurang') : $r->{$k} }}</td>@endforeach
                            @if ($draft)<td class="text-nowrap"><a href="{{ route('pay.master.versi', ['id' => $versi->id, 'form' => $jenis, 'ubah' => $r->id]) }}" class="btn btn-light btn-icon btn-sm"><i data-lucide="pencil"></i></a>
                                <form method="post" action="{{ route('pay.master.isi.destroy', [$versi->id, $jenis, $r->id]) }}" class="d-inline">@csrf @method('delete')<button class="btn btn-outline-danger btn-icon btn-sm"><i data-lucide="trash-2"></i></button></form></td>@endif</tr>
                    @empty
                        <tr><td colspan="{{ count($kolom) + 1 }}" class="text-muted small">Belum ada {{ strtolower($judul) }}.</td></tr>
                    @endforelse
                    </tbody></table></div>
            </div>
        @endforeach

        <div class="card mb-3"><form method="get" class="card-body">
            <input type="hidden" name="form" value="{{ $form }}">
            <div class="fw-semibold mb-2">Uji rumus untuk satu karyawan</div>
            <div class="d-flex gap-2 flex-wrap">
                <select name="uji_karyawan" class="form-select" style="max-width:320px">@foreach ($karyawan as $k)<option value="{{ $k->id }}" @selected(request('uji_karyawan') == $k->id)>{{ $k->nik }} · {{ $k->nama }}</option>@endforeach</select>
                <input type="month" name="uji_periode" value="{{ request('uji_periode', now()->format('Y-m')) }}" class="form-control" style="max-width:180px">
                <button class="btn btn-light">Hitung</button>
            </div>
            @if ($ujiError)<div class="alert alert-danger mt-3 mb-0">{{ $ujiError }}</div>@endif
            @if ($uji)
                <div class="row mt-3 small">
                    <div class="col-md-5"><div class="fw-semibold">Variabel</div><table class="table table-sm">@foreach ($uji->variabel as $k => $v)<tr><td>{{ $k }}</td><td class="text-end">{{ $v }}</td></tr>@endforeach</table></div>
                    <div class="col-md-7"><div class="fw-semibold">Komponen</div><table class="table table-sm">
                        @foreach ($uji->item as $i)<tr><td>{{ $i['name'] }}</td><td class="text-muted">{{ $i['type'] === 'earning' ? '+' : '-' }}</td><td class="text-end">{{ rupiah($i['final']) }}</td></tr>@endforeach
                        <tr class="fw-semibold"><td>Take home pay</td><td></td><td class="text-end">{{ rupiah($uji->thp) }}</td></tr></table></div>
                </div>
            @endif
        </form></div>
    </div>

    <div class="col-xl-4">
        @if ($draft && $form)
            @php $u2 = $ubahVar ?? $ubahParam ?? $ubahItem; @endphp
            <div class="card mb-3"><form method="post" action="{{ route('pay.master.isi.store', [$versi->id, $form]) }}" class="card-body">
                @csrf <input type="hidden" name="ubah_id" value="{{ $u2?->id }}">
                <div class="fw-semibold mb-2">{{ $u2 ? 'Ubah' : 'Tambah' }} {{ ['variabel' => 'variabel', 'param' => 'parameter', 'item' => 'komponen'][$form] }}</div>
                @if ($form === 'item')
                    <div class="mb-2"><label class="form-label small">Komponen katalog</label><select name="component_id" class="form-select">@foreach ($komponen as $c)<option value="{{ $c->id }}" @selected(old('component_id', $u2?->component_id) == $c->id)>{{ $c->code }} · {{ $c->name }}</option>@endforeach</select></div>
                @endif
                <div class="mb-2"><label class="form-label small">Kode</label><input name="code" value="{{ old('code', $u2?->code) }}" class="form-control" required></div>
                @if ($form !== 'item')<div class="mb-2"><label class="form-label small">Nama</label><input name="name" value="{{ old('name', $u2?->name) }}" class="form-control" required></div>@endif
                @if ($form === 'param')
                    <div class="mb-2"><label class="form-label small">Nilai</label><input name="value" value="{{ old('value', $u2?->value) }}" class="form-control" required></div>
                @elseif ($form === 'variabel')
                    <div class="mb-2"><label class="form-label small">Jenis</label><select name="kind" class="form-select"><option value="base" @selected(old('kind', $u2?->kind) === 'base')>base (dari data)</option><option value="derived" @selected(old('kind', $u2?->kind) === 'derived')>derived (rumus)</option></select></div>
                    <div class="mb-2"><label class="form-label small">Sumber (base)</label><select name="source" class="form-select"><option value="">-</option>@foreach ($sumberField as $s => $f)<option value="{{ $s }}" @selected(old('source', $u2?->source) === $s)>{{ $s }}</option>@endforeach</select></div>
                    <div class="mb-2"><label class="form-label small">Agregat (base)</label><select name="aggregate" class="form-select"><option value="">-</option>@foreach (['count', 'sum', 'avg', 'min', 'max', 'count_distinct_date'] as $a)<option @selected(old('aggregate', $u2?->aggregate) === $a)>{{ $a }}</option>@endforeach</select></div>
                    <div class="mb-2"><label class="form-label small">Filter per baris (base)</label><input name="filter_expression" value="{{ old('filter_expression', $u2?->filter_expression) }}" class="form-control font-monospace" placeholder="row.type = 'cuti'"></div>
                    <div class="mb-2"><label class="form-label small">Nilai per baris (base, kosong = 1)</label><input name="value_expression" value="{{ old('value_expression', $u2?->value_expression) }}" class="form-control font-monospace" placeholder="row.hours"></div>
                    <div class="mb-2"><label class="form-label small">Rumus (derived)</label><input name="formula" value="{{ old('formula', $u2?->formula) }}" class="form-control font-monospace"></div>
                @else
                    <div class="mb-2"><label class="form-label small">Cara hitung</label><select name="calc_type" class="form-select"><option value="contract" @selected(old('calc_type', $u2?->calc_type) === 'contract')>Nilai kontrak</option><option value="formula" @selected(old('calc_type', $u2?->calc_type) === 'formula')>Rumus</option></select></div>
                    <div class="mb-2"><label class="form-label small">Rumus</label><input name="formula" value="{{ old('formula', $u2?->formula) }}" class="form-control font-monospace" placeholder="CONTRACT('uang_makan') / pembagi * (hadir + cuti)"></div>
                    <div class="mb-2"><label class="form-label small">Peran</label><select name="item_role" class="form-select"><option value="normal">normal</option><option value="pph21" @selected(old('item_role', $u2?->item_role) === 'pph21')>pph21 (dihitung paling akhir)</option></select></div>
                @endif
                @if ($form !== 'param')<div class="mb-2"><label class="form-label small">Urutan</label><input type="number" name="sort_order" value="{{ old('sort_order', $u2?->sort_order ?? 10) }}" class="form-control" required></div>@endif
                <a href="{{ route('pay.master.versi', $versi->id) }}" class="btn btn-light">Batal</a> <button class="btn btn-primary">Simpan</button>
            </form></div>
        @endif

        <div class="card small"><div class="card-body">
            <div class="fw-semibold mb-1">Panduan rumus</div>
            <div>Operator: + - * / ( ) = != &lt; &lt;= &gt; &gt;= AND OR NOT</div>
            <div>Fungsi: IF, MIN, MAX, ROUND, FLOOR, CEIL, ABS, WORKDAYS(a,b), DAYS(a,b), MONTHS_BETWEEN(a,b), LOOKUP(tabel, kunci, nilai), LOOKUP_TEXT(tabel, kunci), CONTRACT('kode_komponen')</div>
            <div class="mt-2 fw-semibold">Variabel bawaan</div>
            <ul class="ps-3 mb-2">@foreach ($bawaan as $k => $d)<li><code>{{ $k }}</code> {{ $d }}</li>@endforeach</ul>
            <div class="fw-semibold">Field baris per sumber (row.x)</div>
            <ul class="ps-3 mb-0">@foreach ($sumberField as $s => $f)<li>{{ $s }}: {{ implode(', ', array_keys($f)) }}</li>@endforeach</ul>
            <div class="mt-2 text-muted">Rumus hanya boleh merujuk parameter, variabel, dan komponen yang urutannya di atasnya.</div>
        </div></div>
    </div>
</div>
@endsection
