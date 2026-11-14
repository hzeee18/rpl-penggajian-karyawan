{{-- Tab Hari Libur: kalender bulanan (AUDIT.md §64). Param: $bulan (awal bulan), $libur (tanggal => Collection<Holiday>), $departemen, $dept, $hariKerja, $terkunci (tanggal => status) --}}
@include('payroll.kalender._tab', ['aktif' => 'libur'])
@php
    $warna = ['nasional' => 'danger', 'cuti_bersama' => 'warning', 'perusahaan' => 'primary'];
    $awal = $bulan->startOfWeek(\Carbon\Carbon::MONDAY);
    $akhir = $bulan->endOfMonth()->endOfWeek(\Carbon\Carbon::SUNDAY);
    $tautBulan = fn ($b) => route('pay.libur.index', array_filter(['tab' => 'libur', 'bulan' => $b->format('Y-m'), 'departemen' => $dept?->id]));
    $u = auth()->user();
@endphp
<div class="card mb-3"><div class="card-body">
    <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
        <div class="d-flex align-items-center gap-2">
            <a href="{{ $tautBulan($bulan->subMonth()) }}" class="btn btn-light btn-icon" title="Bulan sebelumnya"><i data-lucide="chevron-left"></i></a>
            <div class="fw-semibold" style="min-width:150px;text-align:center">{{ $bulan->translatedFormat('F Y') }}</div>
            <a href="{{ $tautBulan($bulan->addMonth()) }}" class="btn btn-light btn-icon" title="Bulan berikutnya"><i data-lucide="chevron-right"></i></a>
            @unless ($bulan->isSameMonth(now()))<a href="{{ $tautBulan(now()->toImmutable()) }}" class="btn btn-light btn-sm">Hari ini</a>@endunless
        </div>
        <form method="get" class="d-flex align-items-center gap-2"><input type="hidden" name="tab" value="libur"><input type="hidden" name="bulan" value="{{ $bulan->format('Y-m') }}">
            <label class="small fw-semibold text-muted text-nowrap">Lihat untuk</label>
            <select name="departemen" class="form-select" style="max-width:220px" onchange="this.form.submit()"><option value="">Semua departemen</option>
                @foreach ($departemen as $dp)<option value="{{ $dp->id }}" @selected($dept?->id === $dp->id)>{{ $dp->nama }}</option>@endforeach</select>
        </form>
        <div class="d-flex gap-3 small text-muted ms-auto">@foreach (\App\Domain\Payroll\Models\Holiday::JENIS as $k => $v)<span><span class="badge badge-soft-{{ $warna[$k] }}">&nbsp;</span> {{ $v }}</span>@endforeach</div>
    </div>
    <div class="kalender-payroll" style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px">
        @foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $h)<div class="small fw-semibold text-muted text-center text-uppercase">{{ $h }}</div>@endforeach
        @foreach (\Carbon\CarbonPeriod::create($awal, $akhir) as $d)
            @php
                $tgl = $d->toDateString();
                $dalam = $d->month === $bulan->month;
                $semua = $dalam ? ($libur[$tgl] ?? collect()) : collect();
                $l = $semua->first(); // libur semua departemen didahulukan
                $istirahat = ! in_array($d->isoWeekday(), $hariKerja, true);
                $kunci = $dalam ? ($terkunci[$tgl] ?? null) : null;
                $tautan = ! $dalam || $kunci ? null : ($l ? route('pay.libur.index', ['tab' => 'libur', 'bulan' => $bulan->format('Y-m'), 'ubah' => $l->id])
                    : ($u->bisa('pay-libur', 'create') ? route('pay.libur.index', ['tab' => 'libur', 'bulan' => $bulan->format('Y-m'), 'baru' => 1, 'holiday_date' => $tgl]) : null));
                $kelas = 'border rounded p-2 small text-decoration-none text-reset d-block position-relative ' . (! $dalam ? 'bg-light text-muted' : ($l ? 'badge-soft-' . $warna[$l->type] : ($istirahat ? 'bg-light' : '')));
                $isi = '<div class="fw-semibold d-flex align-items-center gap-1">' . $d->day . ($kunci ? ' <i data-lucide="lock" class="ms-auto text-muted" style="width:14px;height:14px"></i>' : '') . '</div>'
                    . $semua->map(fn ($h) => '<div class="fw-semibold" style="line-height:1.2">' . e($h->name) . ($h->departemen ? ' <span class="fw-normal">(' . e($h->departemen->nama) . ')</span>' : '') . '</div>')->join('')
                    . ($l === null && $dalam && $istirahat ? '<div class="text-muted">Hari istirahat</div>' : '');
                $judul = $kunci ? 'Terkunci: tanggal ini masuk payroll yang ' . $kunci : ($l ? 'Ubah atau hapus hari libur ini' : 'Tambah hari libur di tanggal ini');
            @endphp
            @if ($tautan)
                <a href="{{ $tautan }}" class="{{ $kelas }}" style="min-height:72px" title="{{ $judul }}">{!! $isi !!}</a>
            @else
                <div class="{{ $kelas }}" style="min-height:72px" @if ($dalam) title="{{ $judul }}" @endif>{!! $isi !!}</div>
            @endif
        @endforeach
    </div>
</div></div>
