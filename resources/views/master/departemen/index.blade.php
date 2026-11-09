@extends('layouts.app')
@section('title', 'Departemen')
@section('breadcrumb', 'Master Data / Departemen')
@section('content')
<x-page-header judul="Departemen" sub="Setiap departemen bisa punya lebih dari satu manager sebagai approver.">
    <a href="{{ route('departemen.index', ['baru' => 1]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Tambah Departemen</a>
</x-page-header>

<div class="md-grid">
    <div class="card">
        <x-cari placeholder="Cari departemen..." />
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Kode</th><th>Departemen</th><th>Manager</th><th class="text-center">Karyawan</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($departemen as $d)
                    <tr class="{{ $ubah->id === $d->id ? 'terpilih' : '' }}">
                        <td class="kode">{{ $d->kode }}</td>
                        <td>{{ $d->nama }}</td>
                        <td>@forelse ($d->managers as $m)<div class="{{ $loop->first ? '' : 'mt-2' }}">{!! orang($m->nama, $m->jabatan . ($m->user?->isRole('Manager') ? '' : ' · belum punya akun Manager')) !!}</div>@empty<span class="text-muted">Belum ada</span>@endforelse</td>
                        <td class="text-center">{{ $d->karyawan_count }}</td>
                        <td class="text-nowrap">@include('partials.aksi', ['ubah' => route('departemen.index', ['ubah' => $d->id]), 'hapus' => route('departemen.destroy', $d)])</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty ikon="building-2" judul="Belum ada departemen" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-panel :judul="$ubah->exists ? 'Ubah Departemen' : 'Tambah Departemen'" :sub="$ubah->exists ? $ubah->kode . ' · ' . $ubah->nama : 'Departemen baru'"
             :action="$ubah->exists ? route('departemen.update', $ubah) : route('departemen.store')" :method="$ubah->exists ? 'put' : 'post'">
        <div class="mb-field"><label class="form-label">Kode departemen</label><input name="kode" value="{{ old('kode', $ubah->kode) }}" class="form-control" maxlength="10" required></div>
        <div class="mb-field"><label class="form-label">Nama departemen</label><input name="nama" value="{{ old('nama', $ubah->nama) }}" class="form-control" required></div>
        @php $dipilih = collect(old('manager_ids', $ubah->exists ? $ubah->managers->pluck('id')->all() : []))->map(fn ($v) => (int) $v)->all(); @endphp
        <div class="mb-field"><label class="form-label">Manager departemen <span class="text-muted fw-normal">(bisa lebih dari satu)</span></label>
            <select name="manager_ids[]" multiple class="form-select" data-mode="chips" data-placeholder="Pilih manager">@foreach ($karyawan as $k)<option value="{{ $k->id }}" @selected(in_array($k->id, $dipilih))>{{ $k->nama }} · {{ $k->departemen?->kode }}{{ $k->user?->isRole('Manager') ? '' : ' (belum akun Manager)' }}</option>@endforeach</select>
            <div class="form-text">Hanya yang punya akun ber-role Manager yang menerima pengajuan approval.</div></div>
        <x-catatan>Pengajuan kontrak karyawan departemen ini dikirim ke semua manager di atas. Cukup salah satu yang setujui atau tolak, yang lain otomatis tercatat "dilewati". {{ $ubah->kode === 'HRD' ? 'Semua manager HRD adalah Manager HR dan bisa menyetujui payroll.' : 'Payroll disetujui Manager HR (manager departemen HRD).' }}</x-catatan>
        <div class="tombol"><a href="{{ route('departemen.index') }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
    </x-panel>
</div>
@endsection
