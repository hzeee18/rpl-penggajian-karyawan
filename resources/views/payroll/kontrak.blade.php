@extends('layouts.app')
@section('title', 'Kontrak Karyawan')
@section('breadcrumb', 'Payroll / Kontrak')
@section('content')
@php
    $u = auth()->user();
    $param = fn (array $tambah = []) => array_filter($tambah + ['q' => request('q'), 'status' => request('status'), 'page' => request('page')]);
    $gajiPokok = fn ($k) => $k->components->first(fn ($c) => $c->component?->code === 'gaji_pokok')?->amount;
    $sisaHari = fn ($k) => $k->end_date ? (int) now()->startOfDay()->diffInDays($k->end_date, false) : null;
    $statusK = function ($k) use ($sisaHari) {
        if (! $k->is_active) return [$k->id && \App\Domain\Payroll\Models\Contract::where('previous_contract_id', $k->id)->exists() ? 'Sudah diperbarui' : 'Nonaktif', 'badge-soft-neutral'];
        $sisa = $sisaHari($k);
        return match (true) {
            $sisa !== null && $sisa < 0 => ['Kedaluwarsa', 'badge-soft-danger'],
            $k->hampirBerakhir() => ['Segera berakhir', 'badge-soft-warning'],
            default => ['Aktif', 'badge-soft-success'],
        };
    };
    $teksSisa = fn ($sisa) => $sisa >= 0 ? 'sisa ' . ($sisa > 60 ? round($sisa / 30) . ' bulan' : $sisa . ' hari') : 'sudah lewat';
@endphp
<x-page-header judul="Kontrak Karyawan" sub="Satu baris per kontrak. Perbarui kontrak untuk kenaikan gaji atau perpanjangan; kontrak lama tersimpan di riwayat.">
    @if ($u->bisa('pay-kontrak', 'create'))<a href="{{ route('pay.kontrak.index', $param(['baru' => 1])) }}" class="btn btn-primary"><i data-lucide="plus"></i> Kontrak Karyawan Baru</a>@endif
</x-page-header>

<div class="md-grid">
    <div class="card">
        <div class="card-search d-flex flex-wrap gap-2 align-items-center">
            <form method="get" class="flex-grow-1" style="max-width:420px">
                @if (request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
                <div class="input-ikon"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Cari nama atau NIK karyawan..."><i data-lucide="search"></i></div>
            </form>
            <div class="chips ms-auto">
                @foreach (['aktif' => 'Aktif', 'berakhir' => 'Segera berakhir', 'riwayat' => 'Nonaktif', 'semua' => 'Semua'] as $k => $label)
                    <a href="{{ route('pay.kontrak.index', array_filter(['status' => $k, 'q' => request('q')])) }}" class="chip {{ $status === $k ? 'active' : '' }}">{{ $label }} <b>{{ $hitung[$k] }}</b></a>
                @endforeach
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Nama kontrak</th><th>Karyawan</th><th>Masa berlaku</th><th class="text-end">Gaji pokok</th><th>Status</th><th class="text-center">Aksi</th></tr></thead>
                <tbody>
                @forelse ($kontrak as $k)
                    @php [$lbl, $cls] = $statusK($k); $sisa = $sisaHari($k); $gp = $gajiPokok($k); @endphp
                    <tr class="{{ $lihat?->id === $k->id ? 'terpilih' : '' }}">
                        <td><span class="kode">{{ $k->contract_name }}</span>@if ($k->previous_contract_id)<div class="small text-muted">perpanjangan / perubahan</div>@endif</td>
                        <td>{!! orang($k->employee->nama, $k->employee->nik . ' · ' . ($k->employee->departemen?->nama ?? '-')) !!}</td>
                        <td class="text-nowrap">{{ tgl($k->start_date, 'j M Y') }} – {{ $k->end_date ? tgl($k->end_date, 'j M Y') : 'tetap' }}
                            @if ($k->is_active && $sisa !== null)<div class="small {{ $k->hampirBerakhir() ? 'text-warning-emphasis fw-semibold' : 'text-muted' }}">{{ $teksSisa($sisa) }}</div>@endif</td>
                        <td class="text-uang">{{ $gp !== null ? jt($gp) : '-' }}</td>
                        <td><span class="badge {{ $cls }}">{{ $lbl }}</span></td>
                        <td class="text-nowrap text-center">
                            <a href="{{ route('pay.kontrak.index', $param(['lihat' => $k->id])) }}" class="btn btn-light btn-icon" title="Lihat detail & riwayat"><i data-lucide="eye"></i></a>
                            @if ($k->hampirBerakhir() && $u->bisa('pay-kontrak', 'create'))<a href="{{ route('pay.kontrak.index', $param(['perbarui' => $k->id])) }}" class="btn btn-light btn-icon" title="Perbarui kontrak"><i data-lucide="refresh-cw"></i></a>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty ikon="file-text" judul="Tidak ada kontrak" pesan="Coba ubah kata kunci atau filter status." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($kontrak->lastPage() > 1) @include('partials.halaman', ['data' => $kontrak, 'label' => 'kontrak']) @endif
    </div>

    @if ($baru || $perbarui || $ubah)
        @php
            $asal = $perbarui ?? $ubah; // kontrak yang nilainya ditampilkan di form
            $sumber = $asal ? $asal->components->pluck('amount', 'component_id') : collect();
            if ($ubah) {
                [$judulForm, $subForm, $aksi] = ['Ubah Kontrak', $ubah->employee->nama . ' · koreksi kontrak ini, tidak membuat versi baru', route('pay.kontrak.ubah', $ubah->id)];
            } elseif ($perbarui) {
                [$judulForm, $subForm, $aksi] = ['Perbarui Kontrak', $perbarui->contract_name . ' akan dinonaktifkan', route('pay.kontrak.perbarui', $perbarui->id)];
            } else {
                [$judulForm, $subForm, $aksi] = ['Kontrak Baru', 'Nilai komponen disalin dari katalog, bisa diubah', route('pay.kontrak.store')];
            }
        @endphp
        <x-panel :judul="$judulForm" :sub="$subForm" :buka="true" :action="$aksi" :method="$ubah ? 'put' : 'post'">
            @unless ($asal)
                <div class="mb-field"><label class="form-label">Karyawan</label>
                    <select name="employee_id" class="form-select" required><option value="">Pilih karyawan tanpa kontrak aktif</option>
                        @foreach ($karyawanTanpaKontrak as $e)<option value="{{ $e->id }}" @selected(old('employee_id') == $e->id)>{{ $e->nik }} · {{ $e->nama }}</option>@endforeach</select></div>
            @endunless
            <div class="mb-field"><label class="form-label">Nama kontrak</label><input name="contract_name" value="{{ old('contract_name', $asal?->contract_name) }}" class="form-control" required placeholder="mis. PKWT 2027"></div>
            <div class="row g-2 mb-field">
                <div class="col"><label class="form-label">Mulai</label><input type="date" name="start_date" value="{{ old('start_date', $ubah?->start_date?->toDateString()) }}" class="form-control" required></div>
                <div class="col"><label class="form-label">Selesai</label><input type="date" name="end_date" value="{{ old('end_date', $ubah?->end_date?->toDateString()) }}" class="form-control"></div>
            </div>
            <div class="mb-field"><label class="form-label">THP (informasi saja)</label><input name="info_thp" value="{{ old('info_thp', $asal?->info_thp) }}" class="form-control" inputmode="decimal"></div>
            <label class="form-label">Komponen kontrak</label>
            @foreach ($katalog as $c)
                @php $dipakai = $asal ? $sumber->has($c->id) : true; @endphp
                <div class="d-flex align-items-center gap-2 mb-2">
                    <input type="checkbox" name="komponen[{{ $c->id }}][pakai]" value="1" class="form-check-input" @checked(old("komponen.{$c->id}.pakai", $dipakai))>
                    <span class="flex-grow-1">{{ $c->name }} <span class="text-muted small">· {{ $c->type === 'earning' ? 'pendapatan' : 'potongan' }}</span></span>
                    <input name="komponen[{{ $c->id }}][amount]" value="{{ old("komponen.{$c->id}.amount", $sumber[$c->id] ?? $c->default_amount) }}" class="form-control form-control-sm text-end" style="max-width:150px" data-rupiah>
                </div>
            @endforeach
            <div class="tombol"><a href="{{ route('pay.kontrak.index', $param()) }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
        </x-panel>
    @elseif ($lihat)
        @php [$lbl, $cls] = $statusK($lihat); $sisa = $sisaHari($lihat); @endphp
        <x-panel :judul="$lihat->contract_name" :sub="$lihat->employee->nama . ' · ' . ($lihat->employee->departemen?->nama ?? '-')" :buka="true">
            <ul class="nav tab-nav mb-3" role="tablist">
                <li><a href="#tabDetail" class="active" data-bs-toggle="tab" role="tab">Detail</a></li>
                <li><a href="#tabRiwayat" data-bs-toggle="tab" role="tab">Riwayat <span class="badge badge-soft-neutral ms-1">{{ $riwayat->count() }}</span></a></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="tabDetail" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="badge {{ $cls }}">{{ $lbl }}</span>
                        @if ($lihat->is_active && $sisa !== null)<span class="small {{ $lihat->hampirBerakhir() ? 'text-warning-emphasis fw-semibold' : 'text-muted' }}">{{ $sisa >= 0 ? 'Berakhir dalam ' . $sisa . ' hari' : 'Sudah lewat' }}</span>@endif
                    </div>
                    @if ($lihat->previous)<div class="small text-muted mb-2">Perpanjangan dari <a href="{{ route('pay.kontrak.index', ['lihat' => $lihat->previous->id]) }}" class="fw-semibold">{{ $lihat->previous->contract_name }}</a></div>@endif
                    <dl class="row info mb-0">
                        <dt class="col-sm-4">Karyawan</dt><dd class="col-sm-8">{{ $lihat->employee->nama }} ({{ $lihat->employee->nik }})</dd>
                        <dt class="col-sm-4">Masa berlaku</dt><dd class="col-sm-8">{{ tgl($lihat->start_date) }} – {{ $lihat->end_date ? tgl($lihat->end_date) : 'tidak terbatas' }}</dd>
                        <dt class="col-sm-4">THP (informasi)</dt><dd class="col-sm-8">{{ $lihat->info_thp !== null ? rupiah($lihat->info_thp) : '-' }}</dd>
                    </dl>
                    <table class="table table-sm mt-2"><thead><tr><th>Komponen gaji</th><th class="text-end">Nilai</th></tr></thead><tbody>
                        @foreach ($lihat->components as $cc)<tr><td>{{ $cc->component->name }} <span class="small text-muted">· {{ $cc->component->type === 'earning' ? 'pendapatan' : 'potongan' }}</span></td><td class="text-end text-uang">{{ rupiah($cc->amount) }}</td></tr>@endforeach
                    </tbody></table>
                    @if ($terkunci)<div class="small text-muted">Kontrak ini sudah dipakai payroll yang menunggu persetujuan atau sudah disetujui, jadi tidak bisa diubah. Gunakan Perbarui untuk mengubah nominal.</div>@endif
                </div>
                <div class="tab-pane fade" id="tabRiwayat" role="tabpanel">
                    @foreach ($riwayat as $r)
                        @php [$rl, $rc] = $statusK($r); $rgp = $gajiPokok($r); @endphp
                        <div class="versi {{ $r->id === $lihat->id ? 'aktif' : '' }} {{ $loop->last ? 'akhir' : '' }}">
                            <div class="garis"><span class="titik"></span></div>
                            <div class="kartu">
                                <div class="d-flex align-items-start gap-2">
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="judul">{{ $r->contract_name }}</div>
                                        <div class="rentang">{{ tgl($r->start_date, 'j M Y') }} – {{ $r->end_date ? tgl($r->end_date, 'j M Y') : 'tetap' }}@if ($rgp !== null) · {{ rupiah($rgp) }}@endif</div>
                                    </div>
                                    <span class="badge {{ $rc }}">{{ $rl }}</span>
                                </div>
                                @if ($r->id !== $lihat->id)<a href="{{ route('pay.kontrak.index', ['lihat' => $r->id]) }}" class="lihat mt-2">Lihat <i data-lucide="chevron-right"></i></a>@endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="tombol flex-wrap">
                <a href="{{ route('pay.kontrak.index', $param()) }}" class="btn btn-light me-auto">Tutup</a>
                @if ($lihat->is_active)
                    @if ($u->bisa('pay-kontrak', 'delete'))
                        <form method="post" action="{{ route('pay.kontrak.nonaktifkan', $lihat->id) }}" data-confirm="Nonaktifkan kontrak {{ $lihat->contract_name }}?">@csrf<button class="btn btn-outline-danger">Nonaktifkan</button></form>
                    @endif
                    @if ($u->bisa('pay-kontrak', 'update') && ! $terkunci)<a href="{{ route('pay.kontrak.index', ['ubah' => $lihat->id]) }}" class="btn btn-light"><i data-lucide="pencil"></i> Ubah</a>@endif
                    @if ($u->bisa('pay-kontrak', 'create'))
                        {{-- H-3 bulan: perpanjang kontrak (tombol utama). Selain itu tetap bisa membuat kontrak baru untuk perubahan gaji. --}}
                        <a href="{{ route('pay.kontrak.index', ['perbarui' => $lihat->id]) }}" class="btn {{ $lihat->hampirBerakhir() ? 'btn-primary' : 'btn-light' }}"><i data-lucide="refresh-cw"></i> {{ $lihat->hampirBerakhir() ? 'Perbarui kontrak' : 'Perubahan gaji' }}</a>
                    @endif
                @endif
            </div>
        </x-panel>
    @endif
</div>
@endsection
