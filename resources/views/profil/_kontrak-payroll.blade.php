{{-- Kartu kontrak aktif modul payroll baru (AUDIT.md §29). Karyawan melihat nilai komponen kontraknya sendiri. --}}
@php $kp = $karyawan->kontrakPayroll->firstWhere('is_active', true); @endphp
<div class="col-lg-6">
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between">Kontrak Aktif @if ($kp)<span class="badge badge-soft-success">Aktif</span>@endif</div>
        <div class="card-body">
            @if ($kp)
                @foreach ([['Kontrak', $kp->contract_name], ['Periode', tgl($kp->start_date, 'j M Y') . ' – ' . ($kp->end_date ? tgl($kp->end_date, 'j M Y') : 'tidak terbatas')],
                    ['Jabatan', $karyawan->position?->name ?? $karyawan->jabatan]] as [$k, $v])
                    <div class="d-flex py-2 border-bottom"><span class="text-muted" style="width:130px">{{ $k }}</span><span class="fw-medium">{{ $v }}</span></div>
                @endforeach
                @foreach ($kp->components as $c)
                    <div class="d-flex py-2 border-bottom"><span class="text-muted" style="width:130px">{{ $c->component?->name }}</span><span class="fw-medium">{{ rupiah($c->amount) }}</span></div>
                @endforeach
            @else
                <x-empty ikon="file-x" judul="Belum ada kontrak aktif" />
            @endif
        </div>
    </div>
</div>
