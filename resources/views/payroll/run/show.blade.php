@extends('layouts.app')
@section('title', $run->title)
@section('breadcrumb', 'Payroll / Generate Payroll / ' . $run->title)
@section('content')
@php
    $u = auth()->user();
    $label = ['draft' => 'draft', 'pending_approval' => 'menunggu', 'approved' => 'disetujui', 'rejected' => 'ditolak'];
    $bisaEdit = $run->isDraft() && ! $run->sedangDiproses() && $u->bisa('pay-run', 'update');
@endphp
@if ($run->sedangDiproses())<meta http-equiv="refresh" content="5">@endif
<x-page-header :judul="$run->title" :sub="'Periode ' . tgl($run->period_start) . ' s/d ' . tgl($run->period_end) . ($run->masterVersion ? ' · ' . $run->masterVersion->master->name . ' v' . $run->masterVersion->version_no : '')" :status="$label[$run->status]">
    <a href="{{ route('pay.run.index') }}" class="btn btn-light">Kembali</a>
    @if ($run->isDraft() && ! $run->sedangDiproses() && $u->bisa('pay-run', 'delete'))
        <form method="post" action="{{ route('pay.run.destroy', $run->id) }}" data-confirm="Hapus payroll draft ini?">@csrf @method('delete')<button class="btn btn-light">Hapus</button></form>
    @endif
    @if ($bisaEdit && $total->n > 0)
        <form method="post" action="{{ route('pay.run.generate', $run->id) }}" data-confirm="{{ \App\Domain\Payroll\Actions\AlurPayroll::butuhApproval((int) $run->company_id) ? 'Setelah di-generate, data tidak bisa diubah dan payroll dikirim ke penyetuju untuk disetujui. Lanjutkan?' : 'Setelah di-generate, payroll langsung final dan slip gaji terbit (perusahaan tidak memakai persetujuan). Lanjutkan?' }}">@csrf<button class="btn btn-primary" @disabled(collect($kesiapan)->contains(fn ($k) => ! $k[1] && $k[4])) title="{{ collect($kesiapan)->contains(fn ($k) => ! $k[1] && $k[4]) ? 'Lengkapi persiapan yang wajib dulu' : '' }}">Generate Payroll</button></form>
    @endif
</x-page-header>

@if ($errors->any())<div class="alert alert-danger">{{ implode(' ', $errors->all()) }}</div>@endif
@if ($run->sedangDiproses())<div class="alert alert-info">Sedang diproses ({{ $run->job_type === 'generate' ? 'generate' : 'preview' }})… halaman memuat ulang otomatis.</div>@endif
@if ($run->job_status === 'queued' && $run->updated_at?->lt(now()->subSeconds(30)))
    <div class="alert alert-warning">Proses ini masih <b>antre</b> sejak {{ $run->updated_at->diffForHumans() }}. Biasanya karena worker antrean belum jalan: jalankan <code>composer run dev</code> (server + antrean) atau <code>php artisan queue:work</code> di terminal, lalu tunggu halaman memuat ulang.</div>
@endif
@if ($run->macet() && $run->isDraft())
    <div class="alert alert-danger d-flex flex-wrap gap-2 align-items-center"><span class="me-auto">Proses {{ $run->job_type === 'generate' ? 'generate' : 'preview' }} sudah lebih dari {{ \App\Domain\Payroll\Models\PayrollRun::BATAS_MACET_MENIT }} menit tanpa hasil, kemungkinan macet (worker mati atau error).</span>
        @if (auth()->user()->bisa('pay-run', 'update'))<form method="post" action="{{ route('pay.run.batalkan', $run->id) }}" data-confirm="Batalkan proses yang macet? Payroll kembali ke draft dan perlu diproses ulang.">@csrf<button class="btn btn-sm btn-light">Batalkan proses</button></form>@endif</div>
@endif
@if ($run->job_status === 'failed')<div class="alert alert-danger">Proses gagal: {{ $run->job_error }}</div>@endif
@if ($run->status === 'pending_approval')
    <div class="alert alert-warning d-flex flex-wrap gap-2 align-items-center"><span class="me-auto">Menunggu persetujuan, data tidak bisa diubah. Ada yang salah? Tarik kembali ke draft, perbaiki, lalu generate ulang.</span>
        @if ($u->bisa('pay-run', 'update'))
            <form method="post" action="{{ route('pay.run.tarik', $run->id) }}" class="d-flex gap-2" data-confirm="Tarik kembali payroll ini ke draft? Penyetuju baru bisa menyetujuinya setelah di-generate ulang.">@csrf
                <input name="alasan" class="form-control form-control-sm" style="width:220px" maxlength="500" placeholder="Alasan (opsional)">
                <button class="btn btn-sm btn-light"><i data-lucide="undo-2"></i> Tarik kembali</button></form>
        @endif
    </div>
@endif
@if ($run->status === 'approved')
    <div class="mb-3"><x-catatan tipe="info">Payroll ini sudah final dan tidak bisa diubah. Kalau ada yang salah, koreksi lewat <b>Tambah penyesuaian</b> di payroll periode berikutnya (mis. "Koreksi lembur {{ tgl($run->period_end, 'F Y') }}" sebesar selisihnya), supaya slip dan riwayat pajak periode ini tetap utuh.</x-catatan></div>
@endif
@if ($run->status === 'rejected')<div class="alert alert-danger">Ditolak dengan alasan: {{ $run->reject_reason }}. Buat payroll baru untuk periode ini.</div>@endif
@if ($kedaluwarsa->isNotEmpty())
    <div class="alert alert-warning"><div class="fw-semibold">Kontrak berakhir sebelum akhir periode. Gaji dari kontrak dibayar prorata sampai tanggal akhir kontrak; perbarui kontraknya bila karyawan masih bekerja:</div>
        {{ $kedaluwarsa->map(fn ($k) => $k->employee->nik . ' ' . $k->employee->nama . ' (' . tgl($k->end_date) . ')')->join(', ') }}</div>
@endif

@if ($kesiapan !== [])
    @php $siapGenerate = collect($kesiapan)->every(fn ($k) => $k[1] || ! $k[4]); @endphp
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex align-items-center mb-2"><div class="fw-semibold me-auto">Persiapan sebelum generate</div>
            <span class="badge badge-soft-{{ $siapGenerate ? 'success' : 'danger' }}">{{ $siapGenerate ? 'Siap di-generate' : 'Belum siap' }}</span></div>
        <div class="kesiapan">
            @foreach ($kesiapan as [$judul, $ok, $ket, $tautan, $wajib])
                <div class="item-kesiapan">
                    <i data-lucide="{{ $ok ? 'circle-check' : ($wajib ? 'circle-x' : 'circle-alert') }}" class="{{ $ok ? 'text-success' : ($wajib ? 'text-danger' : 'text-warning') }}"></i>
                    <div class="flex-grow-1"><div class="fw-semibold">{{ $judul }}@unless ($wajib)<span class="small text-muted fw-normal"> · opsional</span>@endunless</div><div class="small text-muted">{{ $ket }}</div></div>
                    @if ($tautan)<a href="{{ $tautan }}" class="btn btn-light btn-sm text-nowrap">Buka</a>@endif
                </div>
            @endforeach
        </div>
        @if ($u->bisa('pay-run', 'update'))
            <form method="post" action="{{ route('pay.run.pratinjau', $run->id) }}" class="form-preview mt-3 pt-3 border-top">@csrf
                <input type="hidden" name="scope" value="all">
                <div class="me-auto">
                    <div class="fw-semibold">Master payroll</div>
                    <div class="small text-muted">Rumus yang dipakai untuk menghitung semua karyawan aktif di periode ini.</div>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <select name="master_id" class="form-select" aria-label="Master payroll" required>
                        @foreach ($master as $m)<option value="{{ $m->id }}" @selected($run->masterVersion?->master_id === $m->id)>{{ $m->name }}</option>@endforeach</select>
                    <button class="btn btn-primary text-nowrap" @disabled($run->sedangDiproses())><i data-lucide="refresh-cw"></i> {{ $run->employees()->exists() ? 'Hitung ulang' : 'Lihat preview' }}</button>
                </div>
            </form>
        @endif
    </div></div>
@endif

@if ($total->n > 0)
<div class="ringkasan-payroll">
    <div class="card ringkas-kartu utama"><div class="card-body"><div class="label">Gaji bersih</div><div class="angka">{{ rupiah($total->thp) }}</div><div class="ket">{{ $total->n }} karyawan</div></div></div>
    @foreach ($totalKomponen as $t)
        <div class="card ringkas-kartu"><div class="card-body"><div class="label" title="{{ $t->name }}">{{ $t->name }}</div><div class="angka">{{ $t->type === 'deduction' && (float) $t->jumlah > 0 ? '− ' : '' }}{{ rupiah($t->jumlah) }}</div><div class="ket">{{ $t->type === 'deduction' ? 'Potongan' : 'Pendapatan' }}</div></div></div>
    @endforeach
</div>

<div class="card mb-3"><div class="table-responsive"><table class="table table-hover mb-0 tabel-departemen">
    <thead><tr><th>Kode</th><th>Departemen / manager</th><th class="text-end">Karyawan</th><th class="text-end">Pendapatan</th><th class="text-end">Potongan</th><th class="text-end">Gaji bersih</th><th class="text-center">Aksi</th></tr></thead>
    <tbody>
    @foreach ($perDepartemen as $d)
        @php
            $info = $infoDepartemen[$d->department_name] ?? null;
        @endphp
        <tr class="{{ in_array($d->department_name, $fDepartemen, true) ? 'terpilih' : '' }}"><td class="kode">{{ $info?->kode ?? '-' }}</td>
            <td><div class="fw-semibold">{{ $d->department_name ?? 'Tanpa departemen' }}</div>@if ($info?->manager)<div class="small text-muted">{{ $info->manager->nama }}</div>@endif</td>
            <td class="text-end">{{ $d->n }}</td>
            <td class="text-end text-nowrap">{{ rupiah($d->pendapatan) }}</td>
            <td class="text-end text-nowrap">− {{ rupiah($d->potongan) }}</td>
            <td class="text-end text-uang fw-semibold text-nowrap">{{ rupiah($d->thp) }}</td>
            <td class="text-center"><a href="{{ route('pay.run.show', ['id' => $run->id, 'departemen' => [$d->department_name]]) }}#rincian" class="btn btn-light btn-icon" title="Lihat karyawan {{ $d->department_name }}"><i data-lucide="eye"></i></a></td></tr>
    @endforeach
    </tbody></table></div></div>
@endif

<div class="card" id="rincian">
    @if ($total->n > 0)
        @php $semuaRun = $run->employees()->orderBy('full_name')->get(['employee_id', 'full_name', 'employee_no', 'department_name']); @endphp
        <form class="card-search" method="get" data-auto-submit>
            <div class="filter-bar filter-bar-2">
                <div><label class="form-label">Departemen</label>
                    <select name="departemen[]" multiple class="form-select" data-placeholder="Semua departemen" data-satuan="departemen">@foreach ($perDepartemen->pluck('department_name')->filter() as $nama)<option value="{{ $nama }}" @selected(in_array($nama, $fDepartemen, true))>{{ $nama }}</option>@endforeach</select></div>
                <div><label class="form-label">Karyawan</label>
                    <select name="karyawan_id[]" multiple class="form-select" data-placeholder="Semua karyawan" data-satuan="karyawan">
                        @foreach ($semuaRun->groupBy(fn ($k) => $k->department_name ?? 'Tanpa departemen')->sortKeys() as $namaDept => $isi)
                            <optgroup label="{{ $namaDept }}">@foreach ($isi as $k)<option value="{{ $k->employee_id }}" @selected(in_array($k->employee_id, $fKaryawan))>{{ $k->full_name }} · {{ $k->employee_no }}</option>@endforeach</optgroup>
                        @endforeach</select></div>
            </div>
            <div class="filter-aktif"><span class="ringkas"><b class="text-body">{{ $karyawan->total() }}</b> dari {{ $total->n }} karyawan</span>
                @if ($fDepartemen || $fKaryawan)<a href="{{ route('pay.run.show', $run->id) }}#rincian" class="reset">Reset filter</a>@endif</div>
        </form>
    @endif
    @include('payroll.run._tabel')
</div>

@include('payroll.run._penyesuaian', ['bisaEdit' => $bisaEdit && $total->n > 0])

@if ($bisaEdit && $total->n > 0)
    <x-panel judul="Tambah penyesuaian" sub="Gaji karyawan langsung dihitung ulang" :action="route('pay.run.penyesuaian.store', $run->id)" :buka="request()->has('baru') || $errors->hasAny(['employee_id', 'component_id', 'amount', 'note'])">
        <div class="mb-3"><label class="form-label">Karyawan</label><select name="employee_id" class="form-select" required>
            @foreach ($karyawanRun as $k)<option value="{{ $k->employee_id }}" @selected(old('employee_id') == $k->employee_id)>{{ $k->employee_no }} · {{ $k->full_name }}</option>@endforeach</select>
            @error('employee_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror</div>
        <div class="mb-3"><label class="form-label">Jenis (dari Katalog Komponen)</label><select name="component_id" class="form-select" required>
            @foreach ($komponen as $c)<option value="{{ $c->id }}" data-ket="{{ $c->type === 'earning' ? 'Pendapatan' : 'Potongan' }}{{ $c->is_taxable ? ' · kena pajak' : ' · tidak kena pajak' }}" @selected(old('component_id') == $c->id)>{{ $c->name }}</option>@endforeach</select>
            @error('component_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            <div class="form-text">Jenis belum ada? Tambahkan dulu di Katalog Komponen (mis. Bonus kena pajak, Reimburse tidak kena pajak).</div></div>
        <div class="mb-3"><label class="form-label">Nominal</label><input name="amount" value="{{ old('amount') }}" class="form-control" data-rupiah placeholder="mis. 1.500.000" required>
            @error('amount')<div class="text-danger small mt-1">{{ $message }}</div>@enderror</div>
        <div class="mb-3"><label class="form-label">Keterangan</label><input name="note" value="{{ old('note') }}" class="form-control" maxlength="255" placeholder="mis. Bonus kinerja Q3" required>
            @error('note')<div class="text-danger small mt-1">{{ $message }}</div>@enderror</div>
        <div class="tombol"><a href="{{ route('pay.run.show', $run->id) }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
    </x-panel>
@endif

@if (! $run->isDraft())
    <div class="card mt-3"><div class="card-body"><div class="fw-semibold mb-3">Riwayat persetujuan</div>
        <div class="stepper">
            <div class="step selesai"><span class="bulat"><i data-lucide="send"></i></span><div><div class="judul">Di-generate</div><div class="ket">{{ $run->creator?->namaTampil() ?? '-' }} · {{ tgl($run->generated_at ?? $run->created_at, 'j M Y') }}</div></div></div>
            @if ($run->status === 'approved')<div class="step selesai"><span class="bulat"><i data-lucide="check"></i></span><div><div class="judul">Disetujui</div><div class="ket">{{ $run->approver?->namaTampil() ?? 'Otomatis (tanpa persetujuan)' }} · {{ tgl($run->approved_at, 'j M Y') }}</div></div></div>
            @elseif ($run->status === 'rejected')<div class="step ditolak"><span class="bulat"><i data-lucide="x"></i></span><div><div class="judul">Ditolak</div><div class="ket">{{ $run->approver?->namaTampil() ?? '-' }} · {{ tgl($run->rejected_at, 'j M Y') }}</div><div class="ket fst-italic">“{{ $run->reject_reason }}”</div></div></div>
            @else<div class="step aktif"><span class="bulat"><i data-lucide="clock"></i></span><div><div class="judul">Menunggu persetujuan</div><div class="ket">Penyetuju payroll</div></div></div>@endif
        </div></div></div>
@endif

@if (! empty($run->skipped_employees))
    <div class="card mt-3"><details class="card-body"><summary class="fw-semibold">Karyawan dilewati ({{ count($run->skipped_employees) }}) <span class="small text-muted fw-normal">· klik untuk melihat alasannya</span></summary>
        <ul class="small mb-0 mt-2">@foreach ($run->skipped_employees as $s)<li>{{ $s['nik'] }} {{ $s['nama'] }}: {{ $s['alasan'] }}</li>@endforeach</ul></details></div>
@endif
@endsection
