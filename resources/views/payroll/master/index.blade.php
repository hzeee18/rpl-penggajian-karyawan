@extends('layouts.app')
@section('title', 'Master Payroll')
@section('breadcrumb', 'Payroll / Master Payroll')
@section('content')
<x-page-header judul="Master Payroll" sub="Konfigurasi rumus payroll (mis. Payroll Default, Payroll THR). Setiap perubahan dibuat di versi baru.">
    @if (auth()->user()->bisa('pay-master', 'create'))<a href="{{ route('pay.master.index', ['baru' => 1]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Master Baru</a>@endif
</x-page-header>

<div class="md-grid">
    <div class="card"><div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Kode</th><th>Nama</th><th>Versi aktif</th><th>Draft</th><th>Status</th><th class="text-center">Aksi</th></tr></thead>
        <tbody>
        @forelse ($master as $m)
            @php $buka = $m->currentVersion ?? $m->versions->sortByDesc('version_no')->first(); @endphp
            <tr><td class="kode">{{ $m->code }}</td>
                <td><div class="fw-semibold">{{ $m->name }}</div>@if ($m->description)<div class="small text-muted">{{ $m->description }}</div>@endif</td>
                <td>@if ($m->currentVersion)<span class="badge badge-soft-primary">v{{ $m->currentVersion->version_no }} aktif</span>@else<span class="badge badge-soft-neutral">Belum terbit</span>@endif</td>
                @php $draft = $m->versions->filter(fn ($v) => $v->status === 'draft' && ! $v->locked_at)->sortByDesc('version_no'); @endphp
                <td>@if ($draft->isNotEmpty())<a href="{{ route('pay.master.versi', $draft->first()->id) }}" class="badge badge-soft-neutral text-decoration-none">v{{ $draft->first()->version_no }} draft{{ $draft->count() > 1 ? ' +' . ($draft->count() - 1) . ' lainnya' : '' }}</a>@else<span class="text-muted">-</span>@endif</td>
                <td>{!! badge_status($m->is_active ? 'aktif' : 'nonaktif') !!}</td>
                @php $u = auth()->user(); @endphp
                <td class="text-nowrap text-center"><div class="d-inline-flex gap-2 justify-content-center">
                    @if ($buka)<a href="{{ route('pay.master.versi', $buka->id) }}" class="btn btn-light btn-icon" title="Buka"><i data-lucide="eye"></i></a>@endif
                    @include('payroll.master._pilih-versi', ['versiList' => $m->versions, 'aktifId' => null])
                    @if (\App\Domain\Payroll\Actions\KelolaMasterPayroll::bisaDihapus($m))
                        @if ($u->bisa('pay-master', 'delete'))<form method="post" action="{{ route('pay.master.destroy', $m->id) }}" data-confirm="Hapus master {{ $m->name }} beserta semua draft-nya?">@csrf @method('delete')<button class="btn btn-outline-danger btn-icon" title="Hapus"><i data-lucide="trash-2"></i></button></form>@endif
                    @elseif ($u->bisa('pay-master', 'update'))
                        <form method="post" action="{{ route('pay.master.status', $m->id) }}" @if ($m->is_active) data-confirm="Nonaktifkan master {{ $m->name }}? Master ini tidak bisa dipilih saat generate payroll." @endif>@csrf<input type="hidden" name="aktif" value="{{ $m->is_active ? 0 : 1 }}">
                            <button class="btn btn-light btn-icon" title="{{ $m->is_active ? 'Nonaktifkan' : 'Aktifkan' }}"><i data-lucide="{{ $m->is_active ? 'power-off' : 'power' }}"></i></button></form>
                    @endif
                </div></td></tr>
        @empty
            <tr><td colspan="6"><x-empty ikon="sigma" judul="Belum ada master payroll" pesan="Buat master pertama, mis. Payroll Default, lalu isi parameter, variabel, dan komponennya." /></td></tr>
        @endforelse
        </tbody></table></div></div>

    @if (request()->has('baru'))
        <x-panel judul="Buat Master Payroll" sub="Versi 1 dibuat sebagai draft" :buka="true" :action="route('pay.master.store')">
            <div class="mb-field"><label class="form-label">Kode</label><input name="code" value="{{ old('code') }}" class="form-control" required placeholder="default"></div>
            <div class="mb-field"><label class="form-label">Nama</label><input name="name" value="{{ old('name') }}" class="form-control" required placeholder="Payroll Default"></div>
            <div class="mb-field"><label class="form-label">Keterangan</label><input name="description" value="{{ old('description') }}" class="form-control"></div>
            <div class="tombol"><a href="{{ route('pay.master.index') }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
        </x-panel>
    @endif
</div>
@endsection
