@extends('layouts.app')
@section('title', $j->label() . ' Payroll')
@section('breadcrumb', 'Payroll / ' . $j->label())
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
<x-page-header :judul="$j->label() . ' Payroll'" :sub="'Data ' . strtolower($j->label()) . ' karyawan per periode. Bisa diisi manual atau diimpor dari CSV.'">
    @if ($u->bisa('pay-import'))<a href="{{ route('pay.import.index') }}" class="btn btn-light"><i data-lucide="upload"></i> Import CSV</a>@endif
    @if ($u->bisa($menu, 'create'))<a href="{{ request()->fullUrlWithQuery(['baru' => 1, 'ubah' => null]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Tambah</a>@endif
</x-page-header>

<div class="md-grid">
    <div class="card">
        @php
            $tanpa = function (string $kunci, $nilai) use ($j) { $q = request()->except(['page', 'ubah', 'baru', 'karyawan']); $q[$kunci] = array_values(array_diff((array) ($q[$kunci] ?? []), [(string) $nilai])); return route('pay.' . $j->slug() . '.index', $q); };
            $adaFilter = $fKaryawan || $fDepartemen;
        @endphp
        <form class="card-search" method="get" data-auto-submit>
            @php $adaDiDaftar = $periode === 'bebas' || $daftarPeriode->contains('period_month', $periode); @endphp
            <div class="filter-bar filter-kehadiran {{ $periode === 'bebas' ? 'dengan-rentang' : '' }}">
                <div><label class="form-label">Periode</label>
                    <select name="periode" class="form-select" onchange="this.form.requestSubmit()">
                        @unless ($adaDiDaftar)<option value="{{ $periode }}" selected>{{ nama_periode($periode) }}</option>@endunless
                        @foreach ($daftarPeriode as $p)<option value="{{ $p->period_month }}" @selected($periode === $p->period_month)>{{ nama_periode($p->period_month) }}</option>@endforeach
                        <option value="bebas" @selected($periode === 'bebas')>Pilih tanggal sendiri…</option>
                    </select></div>
                @if ($periode === 'bebas')<x-rentang label="Rentang tanggal" :nilai-awal="$awal" :nilai-akhir="$akhir" />@endif
                <div><label class="form-label">Departemen</label>
                    <select name="departemen_id[]" multiple class="form-select" data-placeholder="Semua departemen" data-satuan="departemen">@foreach ($departemen as $d)<option value="{{ $d->id }}" @selected(in_array($d->id, $fDepartemen))>{{ $d->nama }}</option>@endforeach</select></div>
                <div><label class="form-label">Karyawan</label>
                    <select name="karyawan_id[]" multiple class="form-select" data-placeholder="Semua karyawan" data-satuan="karyawan">
                        @foreach ($karyawan->groupBy(fn ($k) => $k->departemen?->nama ?? 'Tanpa departemen')->sortKeys() as $namaDept => $isi)
                            <optgroup label="{{ $namaDept }}">@foreach ($isi as $k)<option value="{{ $k->id }}" @selected(in_array($k->id, $fKaryawan))>{{ $k->nama }} · {{ $k->nik }}</option>@endforeach</optgroup>
                        @endforeach</select></div>
            </div>
            <div class="filter-aktif">
                <span class="ringkas"><b class="text-body">{{ $data->total() }}</b> data {{ strtolower($j->label()) }} · {{ tgl($awal, 'j M Y') }} – {{ tgl($akhir, 'j M Y') }}@if ($daftarPeriode->isEmpty() && $periode !== 'bebas') · rentang mengikuti aturan Cut Off (<a href="{{ route('pay.libur.index') }}">Kalender Payroll</a>)@endif</span>
                @foreach ($departemen->whereIn('id', $fDepartemen) as $d)<a href="{{ $tanpa('departemen_id', $d->id) }}" class="chip-filter"><span class="label">Dept</span> {{ $d->nama }}<span class="x"><i data-lucide="x"></i></span></a>@endforeach
                @foreach ($karyawan->whereIn('id', $fKaryawan) as $k)<a href="{{ $tanpa('karyawan_id', $k->id) }}" class="chip-filter"><span class="label">Karyawan</span> {{ $k->nama }}<span class="x"><i data-lucide="x"></i></span></a>@endforeach
                @if ($adaFilter)<a href="{{ route('pay.' . $j->slug() . '.index', array_filter(['periode' => $periode, 'dari' => request('dari'), 'sampai' => request('sampai')])) }}" class="reset">Reset semua</a>@endif
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Tanggal</th><th>Karyawan</th>
                    @if ($j === \App\Domain\Payroll\Import\JenisKehadiran::Absensi)<th>Masuk</th><th>Keluar</th><th class="text-end">Terlambat (jam)</th>
                    @elseif ($j === \App\Domain\Payroll\Import\JenisKehadiran::Leave)<th>Tipe</th><th>Keterangan</th>
                    @else<th class="text-end">Durasi (jam)</th><th>Keterangan</th>@endif
                    <th>Sumber</th><th class="text-center">Aksi</th></tr></thead>
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
                        <td class="text-nowrap text-center"><div class="d-inline-flex gap-2 justify-content-center">
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
        <x-panel :judul="($ubah ? 'Ubah ' : 'Tambah ') . $j->label()" :sub="$ubah ? $ubah->employee->nama : 'Aturan pengecekannya sama dengan import CSV'" :buka="true"
                 :action="$ubah ? route('pay.' . $j->slug() . '.update', $ubah->id) : route('pay.' . $j->slug() . '.store')" :method="$ubah ? 'put' : 'post'">
            @error('data')<div class="alert alert-danger small">{{ implode(' ', (array) $errors->get('data')) }}</div>@enderror
            @foreach ($j->kolom() as $k)
                <div class="mb-field"><label class="form-label">{{ ['nik' => 'Karyawan', 'kode_tipe' => 'Tipe leave', 'jam_terlambat' => 'Terlambat (jam)', 'durasi_jam' => 'Durasi (jam)'][$k] ?? str_replace('_', ' ', ucfirst($k)) }}</label>
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
            @if ($j === \App\Domain\Payroll\Import\JenisKehadiran::Leave && ! $ubah)<x-catatan>Rentang dipecah per hari kerja; hari istirahat menurut pola hari kerja karyawan dilewati.</x-catatan>@endif
            <div class="tombol"><a href="{{ request()->fullUrlWithQuery(['baru' => null, 'ubah' => null]) }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
        </x-panel>
    @endif
</div>
@endsection
