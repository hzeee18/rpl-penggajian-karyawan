@extends('layouts.app')
@section('title', 'Generate Payroll')
@section('breadcrumb', 'Payroll / Generate Payroll')
@section('content')
@php $label = ['draft' => 'draft', 'pending_approval' => 'menunggu', 'approved' => 'disetujui', 'rejected' => 'ditolak']; @endphp
<x-page-header judul="Generate Payroll" sub="Buat payroll, cek preview, perbaiki bila perlu, lalu generate untuk disetujui.">
    @if (auth()->user()->bisa('pay-run', 'create') && $periode->isNotEmpty())<a href="{{ route('pay.run.index', ['baru' => 1]) }}" class="btn btn-light"><i data-lucide="plus"></i> Buat Payroll</a>@endif
</x-page-header>

@if (! $butuhApproval)
    <div class="mb-3"><x-catatan>Perusahaan ini <b>tidak memakai persetujuan</b>: payroll yang di-generate langsung final dan slip gaji langsung terbit. Admin bisa mengubahnya di menu Approver.</x-catatan></div>
@endif
@php $u = auth()->user(); @endphp
<div class="card mb-3"><div class="card-body d-flex flex-wrap align-items-center gap-3">
    @if ($berikut)
        <div class="me-auto"><div class="small text-muted">Periode berikutnya</div>
            <div class="fs-5 fw-semibold">{{ nama_periode($berikut->period_month) }}</div>
            <div class="small text-muted">{{ tgl($berikut->start_date) }} – {{ tgl($berikut->end_date) }} · belum diproses</div></div>
        @if ($u->bisa('pay-run', 'create'))<a href="{{ route('pay.run.index', ['baru' => 1, 'periode' => $berikut->period_month]) }}" class="btn btn-primary"><i data-lucide="play"></i> Generate payroll {{ nama_periode($berikut->period_month) }}</a>@endif
    @else
        <div class="me-auto"><div class="fw-semibold">{{ $periode->isEmpty() ? 'Belum ada periode payroll' : 'Semua periode sudah punya payroll' }}</div>
            <div class="small text-muted">Buat periode dulu di Kalender Payroll, lalu kembali ke sini untuk generate.</div></div>
        @if ($u->bisa('pay-libur', 'view'))<a href="{{ route('pay.libur.index') }}" class="btn btn-primary"><i data-lucide="calendar-range"></i> Buka Kalender Payroll</a>@endif
    @endif
</div></div>
<div class="md-grid">
    <div class="card"><div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Judul</th><th>Periode</th><th>Status</th><th>Dibuat</th><th class="text-center">Aksi</th></tr></thead>
        <tbody>
        @forelse ($run as $r)
            <tr><td>{{ $r->title }}</td><td class="text-nowrap">{{ tgl($r->period_start) }} – {{ tgl($r->period_end) }}</td>
                <td>{!! badge_status($label[$r->status]) !!}@if ($r->sedangDiproses()) <span class="badge badge-soft-info">diproses</span>@endif</td>
                <td>{{ $r->creator?->username }}</td>
                <td class="text-center"><a href="{{ route('pay.run.show', $r->id) }}" class="btn btn-light btn-icon" title="Buka"><i data-lucide="eye"></i></a></td></tr>
        @empty
            <tr><td colspan="5"><x-empty ikon="calculator" judul="Belum ada payroll" /></td></tr>
        @endforelse
        </tbody></table></div>
        @if ($run->lastPage() > 1) @include('partials.halaman', ['data' => $run, 'label' => 'payroll']) @endif
    </div>

    @if (request()->has('baru'))
        @php
            $statusLabel = array_map(fn ($s) => strtolower($s[0]), \App\Domain\Payroll\Models\PayPeriod::STATUS);
            $pilihPeriode = old('periode', request('periode', $berikut?->period_month));
        @endphp
        <x-panel judul="Buat Payroll" sub="Pilih periode dari Kalender Payroll" :buka="true" :action="route('pay.run.store')">
            <div class="mb-field"><label class="form-label">Periode</label><select name="periode" id="periodeRun" class="form-select" required>
                @foreach ($periode->sortByDesc('period_month') as $p)<option value="{{ $p->period_month }}" data-ket="{{ tgl($p->start_date) }} – {{ tgl($p->end_date) }} · {{ ucfirst($statusLabel[$p->status_periode]) }}" @selected($pilihPeriode === $p->period_month)>{{ nama_periode($p->period_month) }}</option>@endforeach</select>
                @error('periode')<div class="text-danger small">{{ $message }}</div>@enderror
                <div class="form-text">Periode belum ada? Buat dulu di <a href="{{ route('pay.libur.index') }}">Kalender Payroll</a>.</div></div>
            <div class="mb-field"><label class="form-label">Judul</label><input name="title" id="judulRun" value="{{ old('title', $pilihPeriode ? 'Payroll ' . nama_periode($pilihPeriode) : '') }}" class="form-control" required></div>
            <div class="tombol"><a href="{{ route('pay.run.index') }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Buat</button></div>
        </x-panel>
        <script>
            // judul bawaan ikut bulan periode yang dipilih selama belum diketik manual
            (() => {
                const j = document.getElementById('judulRun'), p = document.getElementById('periodeRun'); if (!j || !p) return;
                const nama = v => { const [y, m] = v.split('-'); return v ? 'Payroll ' + new Date(y, m - 1, 1).toLocaleDateString('id-ID', { month: 'long', year: 'numeric' }) : ''; };
                let otomatis = j.value === nama(p.value);
                j.addEventListener('input', () => { otomatis = false; });
                p.addEventListener('change', () => { if (otomatis && p.value) j.value = nama(p.value); });
            })();
        </script>
    @endif
</div>
@endsection
