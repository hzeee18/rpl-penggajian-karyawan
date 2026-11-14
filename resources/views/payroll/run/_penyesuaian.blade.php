{{-- Daftar penyesuaian HR (AUDIT.md §52). Param: $run, $penyesuaian (RunAdjustment + component, employee, creator), $bisaEdit --}}
@if ($penyesuaian->isNotEmpty() || $bisaEdit)
<div class="card mt-3"><div class="card-body pb-0 d-flex align-items-center gap-2">
    <div class="me-auto"><div class="fw-semibold">Penyesuaian ({{ $penyesuaian->count() }})</div>
        <div class="small text-muted">Bonus, THR, reimburse, atau koreksi di luar rumus master. Pajak mengikuti jenis komponen di Katalog.</div></div>
    @if ($bisaEdit)<a href="{{ route('pay.run.show', [$run->id, 'baru' => 1]) }}" class="btn btn-primary btn-sm"><i data-lucide="plus"></i> Tambah penyesuaian</a>@endif
</div>
<div class="table-responsive"><table class="table tabel-payroll mb-0">
    <thead><tr><th>Karyawan</th><th>Jenis</th><th class="text-end">Nominal</th><th style="padding-left:32px">Keterangan</th><th>Oleh</th>@if ($bisaEdit)<th class="text-center">Aksi</th>@endif</tr></thead>
    <tbody>
    @forelse ($penyesuaian as $p)
        <tr><td><div class="fw-semibold">{{ $p->employee?->nama }}</div><div class="small text-muted">{{ $p->employee?->nik }}</div></td>
            <td><div>{{ $p->component->name }}</div><div class="small text-muted">{{ $p->component->type === 'earning' ? 'Pendapatan' : 'Potongan' }}{{ $p->component->is_taxable ? ' · kena pajak' : '' }}</div></td>
            <td class="text-end text-nowrap text-uang">{{ rupiah($p->amount) }}</td><td style="padding-left:32px">{{ $p->note }}</td><td class="small">{{ $p->creator?->namaTampil() }}</td>
            @if ($bisaEdit)<td class="text-center"><form method="post" action="{{ route('pay.run.penyesuaian.destroy', [$run->id, $p->id]) }}" data-confirm="Hapus penyesuaian ini?">@csrf @method('delete')<button class="btn btn-outline-danger btn-icon" title="Hapus"><i data-lucide="trash-2"></i></button></form></td>@endif</tr>
    @empty
        <tr><td colspan="6" class="small text-muted">Belum ada penyesuaian.</td></tr>
    @endforelse
    </tbody></table></div></div>
@endif
