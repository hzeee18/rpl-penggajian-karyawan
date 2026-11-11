@extends('layouts.app')
@section('title', 'Pajak & Tarif TER')
@section('breadcrumb', 'Master Data / Pajak & Tarif TER')
@section('content')
{{-- multi-tenant: tabel TER nasional, hanya Super Admin yang boleh mengubah (tombol ubah disembunyikan untuk yang lain) --}}
@php $bisaUbahPajak = auth()->user()->bisa('pajak', 'update'); @endphp
<x-page-header judul="Pajak & Tarif TER" sub="Golongan, lapisan, dan tarif TER PPh21. Bisa disesuaikan kalau aturan pajak berubah.">
    <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#modalRiwayat"><i data-lucide="history"></i> Riwayat</button>
    <a href="{{ route('import.index') }}" class="btn btn-light"><i data-lucide="upload"></i> Import Excel</a>
    @if ($bisaUbahPajak)<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalGolBaru"><i data-lucide="plus"></i> Tambah golongan</button>@endif
</x-page-header>

<div class="info-bar">
    <div class="item"><div class="k">Metode</div><div class="v">TER bulanan</div></div>
    <div class="item"><div class="k">Masa Desember</div><div class="v">Tarif Pasal 17</div></div>
    <div class="item"><div class="k">Biaya jabatan</div><div class="v">5%, maks Rp 500.000</div></div>
    <div class="item"><div class="k">Berlaku sejak</div><div class="v">{{ $versi->last() ? tgl($versi->last(), 'j M Y') : '-' }}</div></div>
    <button class="btn btn-light ms-auto" data-bs-toggle="modal" data-bs-target="#modalPengaturan"><i data-lucide="sliders-horizontal"></i> Pengaturan</button>
</div>

<div class="pajak-grid">
    <div>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-start">
                <span>Golongan TER<span class="sub">{{ $golongan->where('is_active', true)->count() }} golongan aktif</span></span>
                <span class="badge badge-soft-neutral">PP 58/2023</span>
            </div>
            <div class="card-body">
                @foreach ($golongan as $g)
                    <a href="{{ route('pajak.index', ['golongan' => $g->id]) }}" class="gol-card {{ $aktif?->id === $g->id ? 'aktif' : '' }}">
                        <span class="huruf">{{ $g->kode }}</span>
                        <div class="me-auto"><div class="fw-semibold">Golongan {{ $g->kode }}</div><div class="small text-muted">PTKP {{ $g->pajak->pluck('status_ptkp')->join(' · ') ?: '-' }}</div></div>
                        <div class="text-end"><div class="fw-semibold">{{ $g->tarif_count }}</div><div class="small text-muted">lapisan</div></div>
                    </a>
                @endforeach
                @if ($bisaUbahPajak)<button class="btn btn-dashed" data-bs-toggle="modal" data-bs-target="#modalGolBaru">+ Tambah golongan</button>@endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">Simulasi cepat<span class="sub">Cek golongan, lapisan, dan PPh21 sebulan</span></div>
            <form class="card-body" method="get">
                <input type="hidden" name="golongan" value="{{ $aktif?->id }}">
                <label class="form-label">Penghasilan bruto / bulan</label>
                <input data-rupiah name="sim_bruto" value="{{ request('sim_bruto', 'Rp 8.000.000') }}" class="form-control mb-3">
                <label class="form-label">Status PTKP</label>
                <select name="sim_ptkp" class="form-select mb-3" onchange="this.form.submit()">@foreach ($semuaPtkp as $p)<option value="{{ $p->id }}" @selected(request('sim_ptkp', $semuaPtkp->firstWhere('status_ptkp', 'TK/1')?->id) == $p->id)>{{ $p->status_ptkp }}</option>@endforeach</select>
                @if ($sim)
                    <div class="simulasi-hasil"><div class="small">Golongan {{ $sim->gol }} · lapisan {{ $sim->lapisan }} · tarif {{ number_format($sim->persen, 2, ',', '.') }}%</div><div class="fs-5 fw-bold">PPh21 {{ rupiah($sim->pph) }}</div></div>
                @else
                    <button class="btn btn-light w-100">Hitung</button>
                @endif
            </form>
        </div>
    </div>

    @if ($aktif)
    <div class="card">
        <div class="card-body pb-0">
            <div class="d-flex flex-wrap align-items-start gap-2">
                <div class="me-auto">
                    <div class="d-flex align-items-center gap-2"><span class="fs-5 fw-bold">Golongan {{ $aktif->kode }}</span>{!! $aktif->is_active ? '<span class="badge badge-soft-success">Aktif</span>' : '<span class="badge badge-soft-neutral">Nonaktif</span>' !!}</div>
                    <div class="my-2"><span class="small text-muted me-1">PTKP</span>@foreach ($aktif->pajak as $p)<span class="chip-ptkp">{{ $p->status_ptkp }}</span>@endforeach</div>
                    <div class="small text-muted">Berlaku sejak {{ $pilihVersi ? tgl($pilihVersi, 'j M Y') : '-' }} · dasar hukum {{ $tarif->first()?->dasar_hukum ?? 'PP 58/2023' }} · {{ $tarif->total() }} lapisan</div>
                </div>
                @if ($bisaUbahPajak)<form method="post" action="{{ route('pajak.golongan.destroy', $aktif) }}" data-confirm="Hapus golongan {{ $aktif->kode }} beserta lapisan tarifnya?">@csrf @method('delete')<button class="btn btn-ghost text-danger"><i data-lucide="trash-2"></i> Hapus</button></form>
                <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#modalGolUbah"><i data-lucide="pencil"></i> Ubah</button>@endif
            </div>
        </div>
        <form class="card-search d-flex gap-2 mt-3 border-top" method="get">
            <input type="hidden" name="golongan" value="{{ $aktif->id }}">
            <div class="input-search flex-grow-1"><input name="nominal" value="{{ request('nominal') }}" class="form-control" placeholder="Cari nominal, misalnya 8.000.000"><i data-lucide="search"></i></div>
            <select name="versi" class="form-select" style="width:220px" onchange="this.form.submit()">@foreach ($versi as $i => $v)<option value="{{ $v }}" @selected($pilihVersi === $v)>Versi: {{ tgl($v, 'j M Y') }}{{ $i === 0 ? ' (aktif)' : '' }}</option>@endforeach</select>
        </form>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th style="width:60px">No</th><th>Penghasilan bruto / bulan</th><th class="text-end">Tarif</th></tr></thead>
                <tbody>
                @forelse ($tarif as $t)
                    <tr class="{{ $cocok?->id === $t->id ? 'cocok' : '' }}">
                        <td>{{ $t->lapisan }}</td>
                        <td>{{ rupiah($t->batas_bawah) }} – {{ $t->batas_atas ? rupiah($t->batas_atas) : 'ke atas' }}</td>
                        <td class="text-end">{{ number_format($t->persen, 2, ',', '.') }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="3"><x-empty ikon="percent" judul="Belum ada lapisan tarif" pesan="Tambahkan lewat tombol Ubah." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('partials.halaman', ['data' => $tarif, 'label' => 'lapisan'])
    </div>
    @endif
</div>

{{-- Modal: tambah golongan --}}
<div class="modal fade" id="modalGolBaru" tabindex="-1"><div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="{{ route('pajak.golongan.store') }}">@csrf
        <div class="modal-header"><h5 class="modal-title fs-6 fw-semibold">Tambah golongan TER</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <label class="form-label">Kode</label><input name="kode" class="form-control mb-3" placeholder="D" required>
            <label class="form-label">Nama</label><input name="nama" class="form-control" placeholder="TER D (...)" required>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan</button></div>
    </form>
</div></div>

@if ($aktif)
{{-- Modal: ubah golongan + PTKP + tambah lapisan --}}
<div class="modal fade" id="modalGolUbah" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title fs-6 fw-semibold">Ubah Golongan {{ $aktif->kode }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <form method="post" action="{{ route('pajak.golongan.update', $aktif) }}" class="mb-4">@csrf @method('put')
                <div class="row g-3">
                    <div class="col-3"><label class="form-label">Kode</label><input name="kode" value="{{ $aktif->kode }}" class="form-control" required></div>
                    <div class="col-9"><label class="form-label">Nama</label><input name="nama" value="{{ $aktif->nama }}" class="form-control" required></div>
                    <div class="col-12"><label class="form-label">Status PTKP di golongan ini</label>
                        <div class="multi-chip">@foreach ($semuaPtkp as $p)<label><input type="checkbox" name="ptkp[]" value="{{ $p->id }}" @checked($p->golongan_ter_id === $aktif->id)><span>{{ $p->status_ptkp }}</span></label>@endforeach</div></div>
                    <div class="col-12 d-flex justify-content-between align-items-center">
                        <div class="form-check"><input type="checkbox" name="is_active" value="1" id="golAktif" class="form-check-input" @checked($aktif->is_active)><label for="golAktif" class="form-check-label">Golongan aktif</label></div>
                        <button class="btn btn-primary">Simpan golongan</button>
                    </div>
                </div>
            </form>
            <form method="post" action="{{ route('pajak.tarif.store') }}" class="border-top pt-3">@csrf
                <input type="hidden" name="golongan_ter_id" value="{{ $aktif->id }}"><input type="hidden" name="dasar_hukum" value="PP 58/2023">
                <div class="fw-semibold mb-2">Tambah lapisan tarif</div>
                <div class="row g-2 align-items-end">
                    <div class="col-2"><label class="form-label">Lapisan</label><input type="number" name="lapisan" value="{{ $tarif->total() + 1 }}" class="form-control" required></div>
                    <div class="col-3"><label class="form-label">Bruto dari</label><input type="number" name="batas_bawah" class="form-control" required></div>
                    <div class="col-3"><label class="form-label">Sampai</label><input type="number" name="batas_atas" class="form-control" placeholder="kosong = ke atas"></div>
                    <div class="col-2"><label class="form-label">Tarif %</label><input type="number" step="0.01" name="persen" class="form-control" required></div>
                    <div class="col-2"><label class="form-label">Berlaku</label><input type="date" name="berlaku_mulai" value="{{ $pilihVersi }}" class="form-control" required></div>
                </div>
                <div class="text-end mt-3"><button class="btn btn-light">Tambah lapisan</button></div>
            </form>
        </div>
    </div>
</div></div>
@endif

{{-- Modal: pengaturan metode --}}
<div class="modal fade" id="modalPengaturan" tabindex="-1"><div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title fs-6 fw-semibold">Pengaturan pajak</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body small">
            <p><b>Metode perhitungan: TER bulanan (PP 58/2023).</b> Januari–November, PPh21 = bruto sebulan × tarif TER sesuai golongan dari status PTKP karyawan.</p>
            <p><b>Masa pajak Desember: tarif Pasal 17.</b> Pajak setahun dihitung ulang lalu dikurangi yang sudah dipotong Januari–November.</p>
            <p class="mb-0 text-muted">Status PTKP dan golongannya diatur lewat tombol Ubah di tiap golongan.</p>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Tutup</button></div>
    </div>
</div></div>
{{-- Modal: riwayat peraturan pajak (Figma "HR / Pajak & Tarif TER - Riwayat Peraturan"). Klik "Lihat" = tampilan baca saja. --}}
@php
    $versiDb = \App\Models\TarifTer::selectRaw('berlaku_mulai, dasar_hukum')->groupBy('berlaku_mulai', 'dasar_hukum')->orderByDesc('berlaku_mulai')->get()->values();
    $golAll = \App\Models\GolonganTer::with('pajak')->orderBy('kode')->get();
    $ptkpSekarang = \App\Models\Pajak::orderBy('status_ptkp')->get();
    $riwayat = $versiDb->map(fn ($r, $i) => [
        'judul' => $r->dasar_hukum ?: 'Tarif TER',
        'rentang' => tgl($r->berlaku_mulai, 'j M Y') . ' – ' . ($i === 0 ? 'sekarang' : tgl(\Carbon\Carbon::parse($versiDb[$i - 1]->berlaku_mulai)->subDay(), 'j M Y')),
        'aktif' => $i === 0, 'ter' => \Carbon\Carbon::parse($r->berlaku_mulai)->toDateString(),
        'pasal17' => [[0, 60e6, 5], [60e6, 250e6, 15], [250e6, 500e6, 25], [500e6, 5e9, 30], [5e9, null, 35]],
        'ptkp' => null, 'catatan' => 'Januari–November pakai tarif efektif rata-rata (TER) bulanan dari bruto sebulan. Masa pajak Desember dihitung ulang setahun pakai tarif Pasal 17 di bawah.',
    ])->concat([
        ['judul' => 'UU 7/2021 (HPP)', 'rentang' => '1 Jan 2022 – 31 Des 2023', 'aktif' => false, 'ter' => null,
         'pasal17' => [[0, 60e6, 5], [60e6, 250e6, 15], [250e6, 500e6, 25], [500e6, 5e9, 30], [5e9, null, 35]],
         'ptkp' => 'PMK 101/2016',
         'catatan' => 'Belum ada TER. PPh21 bulanan = PPh21 setahun dari penghasilan neto dikurangi PTKP, pakai tarif progresif di bawah, lalu dibagi 12. Lapisan pertama naik jadi Rp 60 juta dan ada lapisan baru 35%.'],
        ['judul' => 'UU 36/2008 (PPh)', 'rentang' => '1 Jan 2009 – 31 Des 2021', 'aktif' => false, 'ter' => null,
         'pasal17' => [[0, 50e6, 5], [50e6, 250e6, 15], [250e6, 500e6, 25], [500e6, null, 30]],
         'ptkp' => 'PMK 101/2016 (berlaku 2016–2021)',
         'catatan' => 'Belum ada TER. PPh21 bulanan = PPh21 setahun dari penghasilan neto dikurangi PTKP, pakai tarif progresif di bawah, lalu dibagi 12.'],
    ]);
    // PTKP setahun sejak 2016: TK/0 Rp 54 jt, +Rp 4,5 jt kawin, +Rp 4,5 jt per tanggungan (maks 3)
    $ptkpLama = collect(['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'])->mapWithKeys(fn ($st) => [$st => 54e6 + (str_starts_with($st, 'K/') ? 4.5e6 : 0) + 4.5e6 * (int) substr($st, -1)]);
    $rpJt = fn ($v) => $v == 0 ? 'Rp 0' : 'Rp ' . number_format($v / 1e6, 0, ',', '.') . ($v >= 1e9 ? '' : ' jt');
    $rpLapis = fn ($a, $b) => $b === null ? 'di atas ' . ($a >= 1e9 ? 'Rp ' . ($a / 1e9) . ' M' : $rpJt($a)) : ($a >= 1e9 || $b >= 1e9 ? ($a >= 1e9 ? 'Rp ' . ($a / 1e9) . ' M' : $rpJt($a)) . ' – Rp ' . ($b / 1e9) . ' M' : $rpJt($a) . ' – ' . $rpJt($b));
@endphp
<div class="modal fade" id="modalRiwayat" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-riwayat">
<div class="modal-content">
    <div class="modal-header">
        <button type="button" class="btn btn-ghost btn-sm me-2 px-2 d-none" data-riwayat-kembali title="Kembali ke daftar"><i data-lucide="arrow-left"></i></button>
        <h5 class="modal-title" data-riwayat-judul>Riwayat Peraturan Pajak</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <div class="riwayat-daftar">
            @foreach ($riwayat as $i => $r)
                <div class="versi {{ $r['aktif'] ? 'aktif' : '' }} {{ $loop->last ? 'akhir' : '' }}">
                    <div class="garis"><span class="titik"></span></div>
                    <div class="kartu">
                        <div class="d-flex align-items-center gap-3">
                            <div class="flex-grow-1">
                                <div class="judul">{{ $r['judul'] }}</div>
                                <div class="rentang">{{ $r['rentang'] }}</div>
                            </div>
                            @if ($r['aktif'])<span class="badge badge-soft-success">Aktif</span>@else<span class="badge badge-soft-neutral">Tidak berlaku</span>@endif
                            <a href="#rp-{{ $i }}" class="lihat" data-riwayat-buka="#rp-{{ $i }}" data-judul="{{ $r['judul'] }}">Lihat <i data-lucide="chevron-right"></i></a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @foreach ($riwayat as $i => $r)
            <div class="riwayat-detail d-none" id="rp-{{ $i }}">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge badge-soft-neutral"><i data-lucide="eye" style="width:12px;height:12px"></i> Hanya lihat</span>
                    <span class="small text-muted">Berlaku {{ $r['rentang'] }}</span>
                </div>
                <p class="small text-secondary mb-3">{{ $r['catatan'] }}</p>

                @if ($r['ter'])
                    <div class="fw-semibold mb-2">Tarif TER bulanan</div>
                    <ul class="nav tab-nav mb-2" role="tablist">
                        @foreach ($golAll as $g)<li><a href="#rp-{{ $i }}-{{ $g->kode }}" class="{{ $loop->first ? 'active' : '' }}" data-bs-toggle="tab">Golongan {{ $g->kode }}</a></li>@endforeach
                    </ul>
                    <div class="tab-content mb-3">
                        @foreach ($golAll as $g)
                            @php $lap = \App\Models\TarifTer::where('golongan_ter_id', $g->id)->where('berlaku_mulai', $r['ter'])->orderBy('lapisan')->get(); @endphp
                            <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="rp-{{ $i }}-{{ $g->kode }}">
                                <div class="small text-muted mb-2">PTKP {{ $g->pajak->pluck('status_ptkp')->join(' · ') }} · {{ $lap->count() }} lapisan</div>
                                <div class="tabel-baca"><table class="table table-sm mb-0">
                                    <thead><tr><th style="width:48px">No</th><th>Bruto sebulan</th><th class="text-end">Tarif</th></tr></thead>
                                    <tbody>@foreach ($lap as $t)<tr><td>{{ $t->lapisan }}</td><td>{{ rupiah($t->batas_bawah) }} – {{ $t->batas_atas ? rupiah($t->batas_atas) : 'ke atas' }}</td><td class="text-end">{{ number_format($t->persen, 2, ',', '.') }}%</td></tr>@endforeach</tbody>
                                </table></div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="fw-semibold mb-2">Tarif Pasal 17 (setahun){{ $r['ter'] ? ' · masa Desember' : '' }}</div>
                <div class="tabel-baca mb-3"><table class="table table-sm mb-0">
                    <thead><tr><th>Penghasilan kena pajak setahun</th><th class="text-end">Tarif</th></tr></thead>
                    <tbody>@foreach ($r['pasal17'] as [$lo, $hi, $pct])<tr><td>{{ $rpLapis($lo, $hi) }}</td><td class="text-end">{{ $pct }}%</td></tr>@endforeach</tbody>
                </table></div>

                <div class="fw-semibold mb-2">PTKP setahun</div>
                <div class="tabel-baca"><table class="table table-sm mb-0">
                    <thead><tr><th>Status</th><th class="text-end">PTKP</th></tr></thead>
                    <tbody>
                        @if ($r['ter'])
                            @foreach ($ptkpSekarang as $pk)<tr><td>{{ $pk->status_ptkp }}</td><td class="text-end">{{ rupiah($pk->nilai_ptkp) }}</td></tr>@endforeach
                        @else
                            @foreach ($ptkpLama as $st => $v)<tr><td>{{ $st }}</td><td class="text-end">{{ rupiah($v) }}</td></tr>@endforeach
                        @endif
                    </tbody>
                </table></div>
                @if ($r['ptkp'])<div class="small text-muted mt-2">Sumber PTKP: {{ $r['ptkp'] }}.</div>@endif
            </div>
        @endforeach
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Tutup</button></div>
</div></div></div>
@endsection
