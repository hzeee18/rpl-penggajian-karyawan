@extends('layouts.app')
@section('title', $j->label() . ' Payroll')
@section('breadcrumb', 'Payroll Baru / ' . $j->label())
@section('content')
@php
    $menu = 'pay-' . $j->slug();
    $u = auth()->user();
    $tgl = fn ($r) => $r->attendance_date ?? $r->leave_date ?? $r->overtime_date;
    $nilaiForm = function (string $k) use ($ubah, $j) {
        if (! $ubah) return old($k);
        return old($k, match ($k) {
            'nik' => $ubah->employee->nik,
            'tanggal' => ($ubah->attendance_date ?? $ubah->overtime_date)?->format('Y-m-d'),
            'tanggal_mulai', 'tanggal_selesai' => $ubah->leave_date?->format('Y-m-d'),
            'jam_masuk' => $ubah->check_in ? substr($ubah->check_in, 0, 5) : null,
            'jam_keluar' => $ubah->check_out ? substr($ubah->check_out, 0, 5) : null,
            'jam_terlambat' => $ubah->late_hours,
            'durasi_jam' => $ubah->duration_hours,
            'kode_tipe' => $ubah->leaveType?->code,
            'keterangan' => $ubah->note,
            default => null,
        });
    };
@endphp
<x-page-header :judul="$j->label() . ' Payroll'" :sub="'Periode ' . tgl($awal) . ' s/d ' . tgl($akhir) . '. Data juga bisa diimpor dari CSV.'">
    @if ($u->bisa('pay-import'))<a href="{{ route('pay.import.index') }}" class="btn btn-light"><i data-lucide="upload"></i> Import CSV</a>@endif
    @if ($u->bisa($menu, 'create'))<a href="{{ request()->fullUrlWithQuery(['baru' => 1, 'ubah' => null]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Tambah</a>@endif
</x-page-header>

<div class="md-grid">
    <div class="card">
        <form method="get" class="d-flex gap-2 p-3 pb-0 flex-wrap">
            <input type="month" name="periode" value="{{ $periode }}" class="form-control" style="max-width:180px">
            <select name="karyawan" class="form-select" style="max-width:280px"><option value="">Semua karyawan</option>
                @foreach ($karyawan as $k)<option value="{{ $k->id }}" @selected(request('karyawan') == $k->id)>{{ $k->nik }} · {{ $k->nama }}</option>@endforeach</select>
            <button class="btn btn-light">Tampilkan</button>
        </form>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Tanggal</th><th>Karyawan</th>
                    @if ($j === \App\Domain\Payroll\Import\JenisKehadiran::Absensi)<th>Masuk</th><th>Keluar</th><th class="text-end">Terlambat (jam)</th>
                    @elseif ($j === \App\Domain\Payroll\Import\JenisKehadiran::Leave)<th>Tipe</th><th>Keterangan</th>
                    @else<th class="text-end">Durasi (jam)</th><th>Keterangan</th>@endif
                    <th>Sumber</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($data as $r)
                    <tr class="{{ $ubah?->id === $r->id ? 'terpilih' : '' }}">
                        <td class="text-nowrap">{{ tgl($tgl($r), 'D, j M Y') }}</td>
                        <td>{!! orang($r->employee->nama, $r->employee->nik) !!}</td>
                        @if ($j === \App\Domain\Payroll\Import\JenisKehadiran::Absensi)
                            <td>{{ $r->check_in ? substr($r->check_in, 0, 5) : '-' }}</td><td>{{ $r->check_out ? substr($r->check_out, 0, 5) : '-' }}</td><td class="text-end">{{ $r->late_hours }}</td>
                        @elseif ($j === \App\Domain\Payroll\Import\JenisKehadiran::Leave)
                            <td>{{ $r->leaveType->name }}</td><td>{{ $r->note }}</td>
                        @else
                            <td class="text-end">{{ $r->duration_hours }}</td><td>{{ $r->note }}</td>
                        @endif
                        <td>{{ $r->import_batch_id ? 'Import #' . $r->import_batch_id : 'Manual' }}</td>
                        <td class="text-nowrap"><div class="d-inline-flex gap-2">
                            @if ($u->bisa($menu, 'update'))<a href="{{ request()->fullUrlWithQuery(['ubah' => $r->id, 'baru' => null]) }}" class="btn btn-light btn-icon" title="Ubah"><i data-lucide="pencil"></i></a>@endif
                            @if ($u->bisa($menu, 'delete'))<form method="post" action="{{ route('pay.' . $j->slug() . '.destroy', $r->id) }}" data-confirm="Hapus data ini?">@csrf @method('delete')<button class="btn btn-outline-danger btn-icon" title="Hapus"><i data-lucide="trash-2"></i></button></form>@endif
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-empty ikon="calendar" judul="Belum ada data di periode ini" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($data->lastPage() > 1) @include('partials.halaman', ['data' => $data, 'label' => 'data']) @endif
    </div>

    @if ($baru || $ubah)
        <x-panel :judul="($ubah ? 'Ubah ' : 'Tambah ') . $j->label()" :sub="$ubah ? $ubah->employee->nama : 'Aturan sama dengan import CSV'" :buka="true"
                 :action="$ubah ? route('pay.' . $j->slug() . '.update', $ubah->id) : route('pay.' . $j->slug() . '.store')" :method="$ubah ? 'put' : 'post'">
            @error('data')<div class="alert alert-danger small">{{ implode(' ', (array) $errors->get('data')) }}</div>@enderror
            @foreach ($j->kolom() as $k)
                <div class="mb-field"><label class="form-label">{{ str_replace('_', ' ', ucfirst($k)) }}</label>
                    @if ($k === 'nik')
                        <select name="nik" class="form-select" required>@foreach ($karyawan as $e)<option value="{{ $e->nik }}" @selected($nilaiForm('nik') === $e->nik)>{{ $e->nik }} · {{ $e->nama }}</option>@endforeach</select>
                    @elseif ($k === 'kode_tipe')
                        <select name="kode_tipe" class="form-select" required>@foreach ($tipeLeave as $t)<option value="{{ $t->code }}" @selected($nilaiForm('kode_tipe') === $t->code)>{{ $t->name }}</option>@endforeach</select>
                    @elseif (str_starts_with($k, 'tanggal'))
                        <input type="date" name="{{ $k }}" value="{{ $nilaiForm($k) }}" class="form-control" @required($k !== 'tanggal_selesai')>
                    @elseif (str_starts_with($k, 'jam_') && $k !== 'jam_terlambat')
                        <input type="time" name="{{ $k }}" value="{{ $nilaiForm($k) }}" class="form-control">
                    @else
                        <input name="{{ $k }}" value="{{ $nilaiForm($k) }}" class="form-control" @if (in_array($k, ['jam_terlambat', 'durasi_jam'])) inputmode="decimal" @endif>
                    @endif
                </div>
            @endforeach
            @if ($j === \App\Domain\Payroll\Import\JenisKehadiran::Leave && ! $ubah)<x-catatan>Rentang dipecah per hari kerja; Sabtu dan Minggu dilewati.</x-catatan>@endif
            <div class="tombol"><a href="{{ request()->fullUrlWithQuery(['baru' => null, 'ubah' => null]) }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
        </x-panel>
    @endif
</div>
@endsection
