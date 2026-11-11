@extends('layouts.app')
@section('title', 'Kontrak Payroll')
@section('breadcrumb', 'Payroll Baru / Kontrak')
@section('content')
@php $u = auth()->user(); @endphp
<x-page-header judul="Kontrak Payroll" sub="Kontrak berversi: memperbarui kontrak membuat versi baru, kontrak lama dinonaktifkan. Satu karyawan hanya punya satu kontrak aktif.">
    @if ($u->bisa('pay-kontrak', 'create'))<a href="{{ route('pay.kontrak.index', ['baru' => 1]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Kontrak Baru</a>@endif
</x-page-header>

<div class="md-grid">
    <div class="card">
        <div class="d-flex gap-2 p-3 pb-0">
            @foreach (['aktif' => 'Aktif', 'riwayat' => 'Nonaktif', 'semua' => 'Semua'] as $k => $label)
                <a href="{{ route('pay.kontrak.index', ['status' => $k]) }}" class="btn btn-sm {{ $status === $k ? 'btn-primary' : 'btn-light' }}">{{ $label }}</a>
            @endforeach
        </div>
        <x-cari placeholder="Cari nama atau nomor karyawan..." />
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Karyawan</th><th>Kontrak</th><th>Berlaku</th><th class="text-end">Komponen</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($kontrak as $k)
                    <tr class="{{ $lihat?->id === $k->id ? 'terpilih' : '' }}">
                        <td>{!! orang($k->employee->nama, $k->employee->nik . ' · ' . $k->employee->departemen?->nama) !!}</td>
                        <td>{{ $k->contract_name }}@if ($k->previous_contract_id)<div class="sub text-muted">versi lanjutan</div>@endif</td>
                        <td class="text-nowrap">{{ tgl($k->start_date) }} – {{ $k->end_date ? tgl($k->end_date) : 'tidak terbatas' }}</td>
                        <td class="text-end">{{ $k->components->count() }}</td>
                        <td>{!! badge_status($k->is_active ? 'aktif' : 'nonaktif') !!}@if ($k->isExpired()) <span class="badge badge-soft-warning">Kedaluwarsa</span>@endif</td>
                        <td><a href="{{ request()->fullUrlWithQuery(['lihat' => $k->id, 'baru' => null, 'perbarui' => null]) }}" class="btn btn-light btn-icon" title="Lihat"><i data-lucide="eye"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty ikon="file-signature" judul="Belum ada kontrak" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($kontrak->lastPage() > 1) @include('partials.halaman', ['data' => $kontrak, 'label' => 'kontrak']) @endif
    </div>

    @if ($baru || $perbarui)
        @php $sumber = $perbarui ? $perbarui->components->pluck('amount', 'component_id') : collect(); @endphp
        <x-panel :judul="$perbarui ? 'Perbarui Kontrak' : 'Kontrak Baru'" :sub="$perbarui ? $perbarui->contract_name . ' akan dinonaktifkan' : 'Nilai komponen disalin dari katalog, bisa diubah'" :buka="true"
                 :action="$perbarui ? route('pay.kontrak.perbarui', $perbarui->id) : route('pay.kontrak.store')">
            @unless ($perbarui)
                <div class="mb-field"><label class="form-label">Karyawan</label>
                    <select name="employee_id" class="form-select" required><option value="">Pilih karyawan tanpa kontrak aktif</option>
                        @foreach ($karyawanTanpaKontrak as $e)<option value="{{ $e->id }}" @selected(old('employee_id') == $e->id)>{{ $e->nik }} · {{ $e->nama }}</option>@endforeach</select></div>
            @endunless
            <div class="mb-field"><label class="form-label">Nama kontrak</label><input name="contract_name" value="{{ old('contract_name', $perbarui?->contract_name) }}" class="form-control" required placeholder="mis. PKWT 2027"></div>
            <div class="row g-2 mb-field">
                <div class="col"><label class="form-label">Mulai</label><input type="date" name="start_date" value="{{ old('start_date') }}" class="form-control" required></div>
                <div class="col"><label class="form-label">Selesai</label><input type="date" name="end_date" value="{{ old('end_date') }}" class="form-control"></div>
            </div>
            <div class="mb-field"><label class="form-label">THP (informasi saja)</label><input name="info_thp" value="{{ old('info_thp', $perbarui?->info_thp) }}" class="form-control" inputmode="decimal"></div>
            <label class="form-label">Komponen kontrak</label>
            @foreach ($katalog as $c)
                @php $dipakai = $perbarui ? $sumber->has($c->id) : true; @endphp
                <div class="d-flex align-items-center gap-2 mb-2">
                    <input type="checkbox" name="komponen[{{ $c->id }}][pakai]" value="1" class="form-check-input" @checked(old("komponen.{$c->id}.pakai", $dipakai))>
                    <span class="flex-grow-1">{{ $c->name }} <span class="text-muted small">· {{ $c->type === 'earning' ? 'penambah' : 'pengurang' }}</span></span>
                    <input name="komponen[{{ $c->id }}][amount]" value="{{ old("komponen.{$c->id}.amount", $sumber[$c->id] ?? $c->default_amount) }}" class="form-control form-control-sm text-end" style="max-width:150px" inputmode="decimal">
                </div>
            @endforeach
            <div class="tombol"><a href="{{ route('pay.kontrak.index') }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
        </x-panel>
    @elseif ($lihat)
        <x-panel judul="Detail Kontrak" :sub="$lihat->employee->nik . ' · ' . $lihat->employee->nama" :buka="true">
            <dl class="row small">
                <dt class="col-5">Nama kontrak</dt><dd class="col-7">{{ $lihat->contract_name }}</dd>
                <dt class="col-5">Berlaku</dt><dd class="col-7">{{ tgl($lihat->start_date) }} – {{ $lihat->end_date ? tgl($lihat->end_date) : 'tidak terbatas' }}</dd>
                <dt class="col-5">THP (info)</dt><dd class="col-7">{{ $lihat->info_thp !== null ? rupiah($lihat->info_thp) : '-' }}</dd>
                <dt class="col-5">Status</dt><dd class="col-7">{!! badge_status($lihat->is_active ? 'aktif' : 'nonaktif') !!}</dd>
            </dl>
            <table class="table table-sm"><thead><tr><th>Komponen</th><th class="text-end">Nominal</th></tr></thead><tbody>
                @foreach ($lihat->components as $cc)<tr><td>{{ $cc->component->name }}</td><td class="text-end">{{ rupiah($cc->amount) }}</td></tr>@endforeach
            </tbody></table>
            <div class="fw-semibold small mb-1">Riwayat versi</div>
            <ul class="small ps-3">@foreach ($riwayat as $r)<li>{{ $r->contract_name }} · {{ tgl($r->start_date) }} {!! badge_status($r->is_active ? 'aktif' : 'nonaktif') !!}</li>@endforeach</ul>
            @if ($lihat->is_active)
                <div class="tombol">
                    @if ($u->bisa('pay-kontrak', 'delete'))
                        <form method="post" action="{{ route('pay.kontrak.nonaktifkan', $lihat->id) }}" data-confirm="Nonaktifkan kontrak {{ $lihat->contract_name }}?">@csrf<button class="btn btn-outline-danger">Nonaktifkan</button></form>
                    @endif
                    @if ($u->bisa('pay-kontrak', 'create'))<a href="{{ route('pay.kontrak.index', ['perbarui' => $lihat->id]) }}" class="btn btn-primary">Perbarui</a>@endif
                </div>
            @endif
        </x-panel>
    @endif
</div>
@endsection
