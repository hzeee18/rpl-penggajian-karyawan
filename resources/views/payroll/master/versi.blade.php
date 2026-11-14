@extends('layouts.app')
@section('title', $versi->master->name . ' v' . $versi->version_no)
@section('breadcrumb', 'Payroll / Master Payroll / ' . $versi->master->code)
@section('content')
@php
    $u = auth()->user();
    $draft = $versi->bisaDiubah() && $u->bisa('pay-master', 'update');
    $ubahVar = $form === 'variabel' ? $versi->variables->firstWhere('id', $ubah) : null;
    $ubahParam = $form === 'param' ? $versi->params->firstWhere('id', $ubah) : null;
    $ubahItem = $form === 'item' ? $versi->items->firstWhere('id', $ubah) : null;
    $status = $versi->locked_at ? 'terkunci (dipakai payroll final)' : ($versi->status === 'published' ? 'terbit' : 'draft');
@endphp
@php
    $statusBadge = $versi->locked_at ? 'terkunci' : ($versi->status === 'published' ? 'terbit' : 'draft');
    $caraHitung = fn ($r) => $r->caraOtomatis() !== null
        ? ($r->caraOtomatis() === \App\Domain\Payroll\Models\Item::CARA_PPH21_TER ? 'PPh21 TER otomatis' : 'PPh21 Desember/resign otomatis')
        : ($r->calc_type === 'contract' ? 'Dari kontrak' : 'Rumus');
    $judulForm = ['variabel' => 'variabel', 'param' => 'parameter', 'item' => 'komponen'];
@endphp
<x-page-header :judul="$versi->master->name . ' · v' . $versi->version_no" :status="$statusBadge"
    :sub="($versi->master->current_version_id === $versi->id ? 'Versi aktif yang dipakai payroll. ' : '') . ($versi->locked_at ? 'Sudah dipakai payroll final, tidak bisa diubah; salin ke versi baru untuk perubahan.' : ($draft ? 'Draft: isi parameter, variabel, dan komponen, lalu terbitkan.' : 'Versi terbit tidak bisa diubah; salin ke versi baru untuk perubahan.'))">
    <a href="{{ route('pay.master.index') }}" class="btn btn-light">Kembali</a>
    @include('payroll.master._pilih-versi', ['versiList' => $versi->master->versions()->get(), 'aktifId' => $versi->id, 'label' => 'v' . $versi->version_no])
    @if ($versi->bisaDiubah() && $u->bisa('pay-master', 'delete') && $versi->master->current_version_id !== $versi->id)
        <form method="post" action="{{ route('pay.master.versi.destroy', $versi->id) }}" data-confirm="Hapus draft v{{ $versi->version_no }}? Semua isinya ikut terhapus.">@csrf @method('delete')<button class="btn btn-outline-danger"><i data-lucide="trash-2"></i> Hapus draft</button></form>
    @endif
    @if ($u->bisa('pay-master', 'create'))<form method="post" action="{{ route('pay.master.salin', $versi->id) }}">@csrf<button class="btn btn-light"><i data-lucide="copy"></i> Salin ke versi baru</button></form>@endif
    @if ($draft)<form method="post" action="{{ route('pay.master.terbitkan', $versi->id) }}" data-confirm="Terbitkan versi ini? Versi terbit tidak bisa diubah.">@csrf<button class="btn btn-primary" @disabled($masalah !== [])><i data-lucide="send"></i> Terbitkan</button></form>@endif
</x-page-header>
@if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
@if ($masalah !== [])<div class="mb-3"><x-catatan tipe="danger"><div class="fw-semibold">Belum bisa diterbitkan:</div><ul class="mb-0 ps-3">@foreach ($masalah as $m)<li>{{ $m }}</li>@endforeach</ul></x-catatan></div>@endif

<div class="md-grid">
<div>
        @foreach ([['Parameter', 'param', $versi->params, ['code' => 'Kode', 'name' => 'Nama', 'value' => 'Nilai']],
                   ['Variabel', 'variabel', $versi->variables, ['sort_order' => '#', 'code' => 'Kode', 'name' => 'Nama', 'kind' => 'Jenis', 'source' => 'Sumber', 'aggregate' => 'Agregat', 'filter_expression' => 'Filter', 'value_expression' => 'Nilai', 'formula' => 'Rumus']],
                   ['Komponen', 'item', $versi->items, ['sort_order' => '#', 'code' => 'Kode', 'name' => 'Nama', 'type' => 'Jenis', 'calc_type' => 'Hitung', 'formula' => 'Rumus', 'item_role' => 'Peran']]] as [$judul, $jenis, $daftar, $kolom])
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center"><span class="fw-semibold me-auto">{{ $judul }} <span class="badge badge-soft-neutral ms-1">{{ $daftar->count() }}</span></span>
                    @if ($draft)<a href="{{ route('pay.master.versi', ['id' => $versi->id, 'form' => $jenis]) }}" class="btn btn-light btn-sm"><i data-lucide="plus"></i> Tambah</a>@endif</div>
                <div class="table-responsive"><table class="table table-hover mb-0">
                    <thead><tr>@foreach ($kolom as $label)<th>{{ $label }}</th>@endforeach @if ($draft)<th class="text-center">Aksi</th>@endif</tr></thead>
                    <tbody>
                    @forelse ($daftar as $r)
                        <tr class="{{ $ubah === $r->id && $form === $jenis ? 'terpilih' : '' }}">@foreach ($kolom as $k => $label)
                            <td class="{{ $k === 'code' ? 'kode' : '' }} {{ $k === 'sort_order' ? 'text-muted' : '' }}">
                                @if ($k === 'type'){!! badge_status($r->type === 'earning' ? 'pendapatan' : 'potongan') !!}
                                @elseif ($k === 'calc_type')<span class="badge badge-soft-{{ $r->calc_type === 'contract' ? 'info' : 'primary' }}">{{ $caraHitung($r) }}</span>
                                @elseif ($k === 'item_role'){{ $r->item_role === 'pph21' ? 'PPh21' : 'Normal' }}
                                @elseif ($k === 'kind'){{ $r->kind === 'base' ? 'Dari data' : 'Rumus' }}
                                @elseif ($k === 'value' && $jenis === 'param')@php $angka = rtrim(rtrim((string) $r->value, '0'), '.'); $desimal = str_contains($angka, '.') ? strlen(substr($angka, strpos($angka, '.') + 1)) : 0; @endphp<span class="text-uang">{{ number_format((float) $angka, $desimal, ',', '.') }}</span>
                                @elseif (in_array($k, ['formula', 'filter_expression', 'value_expression']))@if (filled($r->{$k}) && ! ($k === 'formula' && $r instanceof \App\Domain\Payroll\Models\Item && $r->caraOtomatis() !== null))<code class="small">{{ $r->{$k} }}</code>@else<span class="text-muted">-</span>@endif
                                @else{{ $r->{$k} ?? '-' }}@endif
                            </td>@endforeach
                            @if ($draft)<td class="text-nowrap text-center"><div class="d-inline-flex gap-2 justify-content-center"><a href="{{ route('pay.master.versi', ['id' => $versi->id, 'form' => $jenis, 'ubah' => $r->id]) }}" class="btn btn-light btn-icon" title="Ubah"><i data-lucide="pencil"></i></a>
                                <form method="post" action="{{ route('pay.master.isi.destroy', [$versi->id, $jenis, $r->id]) }}" data-confirm="Hapus {{ $r->code }}?">@csrf @method('delete')<button class="btn btn-outline-danger btn-icon" title="Hapus"><i data-lucide="trash-2"></i></button></form></div></td>@endif</tr>
                    @empty
                        <tr><td colspan="{{ count($kolom) + 1 }}"><x-empty ikon="inbox" :judul="'Belum ada ' . strtolower($judul)" /></td></tr>
                    @endforelse
                    </tbody></table></div>
            </div>
        @endforeach

        <div class="card mb-3"><div class="card-header fw-semibold">Uji rumus untuk satu karyawan <span class="small fw-normal text-muted">· simulasi, tidak menyimpan apa pun</span></div>
        <form method="get" class="card-body">
            <input type="hidden" name="form" value="{{ $form }}">
            <div class="filter-bar filter-bar-3">
                <div><label class="form-label">Karyawan</label><select name="uji_karyawan" class="form-select">@foreach ($karyawan as $k)<option value="{{ $k->id }}" @selected(request('uji_karyawan') == $k->id)>{{ $k->nik }} · {{ $k->nama }}</option>@endforeach</select></div>
                <div><label class="form-label">Periode</label><input type="month" name="uji_periode" value="{{ request('uji_periode', now()->format('Y-m')) }}" class="form-control"></div>
                <div><button class="btn btn-primary w-100"><i data-lucide="calculator"></i> Hitung</button></div>
            </div>
            @if ($ujiError)<div class="alert alert-danger mt-3 mb-0">{{ $ujiError }}</div>@endif
            @if ($uji)
                <div class="row g-3 mt-1">
                    <div class="col-md-5"><div class="fw-semibold mb-1">Variabel</div><table class="table table-sm">@foreach ($uji->variabel as $k => $v)<tr><td>{{ $k }}</td><td class="text-end">{{ $v }}</td></tr>@endforeach</table></div>
                    <div class="col-md-7"><div class="fw-semibold mb-1">Komponen</div><table class="table table-sm">
                        @foreach ($uji->item as $i)<tr><td>{{ $i['name'] }}</td><td>{!! badge_status($i['type'] === 'earning' ? 'pendapatan' : 'potongan') !!}</td><td class="text-end text-uang">{{ rupiah($i['final']) }}</td></tr>@endforeach
                        <tr class="fw-semibold"><td>Take home pay</td><td></td><td class="text-end text-uang">{{ rupiah($uji->thp) }}</td></tr></table></div>
                </div>
            @endif
        </form></div>

        <div class="card small"><div class="card-header"><a class="fw-semibold text-reset text-decoration-none d-flex align-items-center" data-bs-toggle="collapse" href="#panduanRumus" role="button" aria-expanded="false">Panduan rumus <i data-lucide="chevron-down" class="ms-auto"></i></a></div>
        <div class="collapse" id="panduanRumus"><div class="card-body">
            <div>Operator: + - * / ( ) = != &lt; &lt;= &gt; &gt;= AND OR NOT</div>
            <div>Fungsi: IF, MIN, MAX, ROUND, FLOOR, CEIL, ABS, WORKDAYS(a,b), DAYS(a,b), MONTHS_BETWEEN(a,b), LOOKUP(tabel, kunci, nilai), LOOKUP_TEXT(tabel, kunci), CONTRACT('kode_komponen')</div>
            <div class="mt-2 fw-semibold">Variabel bawaan</div>
            <ul class="ps-3 mb-2">@foreach ($bawaan as $k => $d)<li><code>{{ $k }}</code> {{ $d }}</li>@endforeach</ul>
            <div class="fw-semibold">Field baris per sumber (row.x)</div>
            <ul class="ps-3 mb-0">@foreach ($sumberField as $s => $f)<li>{{ $s }}: {{ implode(', ', array_keys($f)) }}</li>@endforeach</ul>
            <div class="mt-2 text-muted">Rumus hanya boleh merujuk parameter, variabel, dan komponen yang urutannya di atasnya.</div>
        </div></div></div>
</div>
        @if ($draft && $form)
            @php $u2 = $ubahVar ?? $ubahParam ?? $ubahItem; @endphp
            <x-panel :buka="true" :judul="($u2 ? 'Ubah ' : 'Tambah ') . $judulForm[$form]" :sub="$versi->master->name . ' · v' . $versi->version_no" :action="route('pay.master.isi.store', [$versi->id, $form])">
                <input type="hidden" name="ubah_id" value="{{ $u2?->id }}">
                @if ($form === 'item')
                    <div class="mb-field"><label class="form-label">Komponen katalog</label><select name="component_id" class="form-select">@foreach ($komponen as $c)<option value="{{ $c->id }}" data-ket="Kode {{ $c->code }} · {{ $c->type === 'earning' ? 'pendapatan' : 'potongan' }}" @selected(old('component_id', $u2?->component_id) == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
                @endif
                <div class="mb-field"><label class="form-label">Kode</label><input name="code" value="{{ old('code', $u2?->code) }}" class="form-control" required></div>
                @if ($form !== 'item')<div class="mb-field"><label class="form-label">Nama</label><input name="name" value="{{ old('name', $u2?->name) }}" class="form-control" required></div>@endif
                @if ($form === 'param')
                    <div class="mb-field"><label class="form-label">Nilai</label><input name="value" value="{{ old('value', $u2?->value) }}" class="form-control" required></div>
                @elseif ($form === 'variabel')
                    <div class="mb-field"><label class="form-label">Jenis</label><select name="kind" class="form-select"><option value="base" data-ket="Dihitung dari baris data (absensi, leave, lembur, dll.)" @selected(old('kind', $u2?->kind) === 'base')>Dari data</option><option value="derived" data-ket="Dihitung dari rumus variabel lain" @selected(old('kind', $u2?->kind) === 'derived')>Rumus</option></select></div>
                    <div class="mb-field"><label class="form-label">Sumber (base)</label><select name="source" class="form-select"><option value="">-</option>@foreach ($sumberField as $s => $f)<option value="{{ $s }}" @selected(old('source', $u2?->source) === $s)>{{ $s }}</option>@endforeach</select></div>
                    <div class="mb-field"><label class="form-label">Agregat (base)</label><select name="aggregate" class="form-select"><option value="">-</option>@foreach (['count', 'sum', 'avg', 'min', 'max', 'count_distinct_date'] as $a)<option @selected(old('aggregate', $u2?->aggregate) === $a)>{{ $a }}</option>@endforeach</select></div>
                    <div class="mb-field"><label class="form-label">Filter per baris (base)</label><input name="filter_expression" value="{{ old('filter_expression', $u2?->filter_expression) }}" class="form-control font-monospace" placeholder="row.type = 'cuti'"></div>
                    <div class="mb-field"><label class="form-label">Nilai per baris (base, kosong = 1)</label><input name="value_expression" value="{{ old('value_expression', $u2?->value_expression) }}" class="form-control font-monospace" placeholder="row.hours"></div>
                    <div class="mb-field"><label class="form-label">Rumus (derived)</label><input name="formula" value="{{ old('formula', $u2?->formula) }}" class="form-control font-monospace"></div>
                @else
                    @php $cara = old('calc_type', $u2?->caraOtomatis() ?? $u2?->calc_type); @endphp
                    <div class="mb-field"><label class="form-label">Cara hitung</label><select name="calc_type" class="form-select">
                        <option value="contract" @selected($cara === 'contract')>Nilai kontrak</option>
                        <option value="formula" @selected($cara === 'formula')>Rumus</option>
                        <option value="{{ \App\Domain\Payroll\Models\Item::CARA_PPH21_TER }}" data-ket="Memakai tabel TER nasional" @selected($cara === \App\Domain\Payroll\Models\Item::CARA_PPH21_TER)>PPh21 TER otomatis</option>
                        <option value="{{ \App\Domain\Payroll\Models\Item::CARA_PPH21_DESEMBER }}" data-ket="Pasal 17 setahun dikurangi PPh21 yang sudah dipotong" @selected($cara === \App\Domain\Payroll\Models\Item::CARA_PPH21_DESEMBER)>PPh21 Desember/resign otomatis</option>
                    </select>
                        <div class="form-text">PPh21 TER / Desember otomatis: rumus dan peran pph21 diisi sistem, kolom Rumus/Peran diabaikan. Desember/resign: pajak Pasal 17 setahun dikurangi PPh21 yang sudah dipotong (payroll yang sudah disetujui).</div></div>
                    <div class="mb-field"><label class="form-label">Rumus</label><input name="formula" value="{{ old('formula', $u2?->caraOtomatis() !== null ? '' : $u2?->formula) }}" class="form-control font-monospace" placeholder="CONTRACT('uang_makan') / pembagi * (hadir + cuti)"></div>
                    <div class="mb-field"><label class="form-label">Peran</label><select name="item_role" class="form-select"><option value="normal">Normal</option><option value="pph21" data-ket="Dihitung paling akhir, setelah semua komponen lain" @selected(old('item_role', $u2?->item_role) === 'pph21')>PPh21</option></select></div>
                @endif
                @if ($form !== 'param')<div class="mb-field"><label class="form-label">Urutan</label><input type="number" name="sort_order" value="{{ old('sort_order', $u2?->sort_order ?? 10) }}" class="form-control" required></div>@endif
                <div class="tombol"><a href="{{ route('pay.master.versi', $versi->id) }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
            </x-panel>
        @endif
</div>
@endsection
