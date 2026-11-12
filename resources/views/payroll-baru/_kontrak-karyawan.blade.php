{{-- Riwayat kontrak modul payroll baru untuk satu karyawan. Parameter: $kontrak (koleksi Contract + components.component), $lihatGaji (bool) --}}
<div class="table-responsive">
    <table class="table mb-0">
        <thead><tr><th>Kontrak</th><th>Periode</th><th>Komponen</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($kontrak as $k)
            <tr class="{{ $k->is_active ? 'table-success' : '' }}">
                <td>{{ $k->contract_name }}</td>
                <td class="small text-nowrap">{{ tgl($k->start_date, 'j M Y') }} – {{ $k->end_date ? tgl($k->end_date, 'j M Y') : 'tidak terbatas' }}</td>
                <td class="small">
                    @foreach ($k->components as $c)
                        <div>{{ $c->component?->name }}@if ($lihatGaji): <span class="text-uang">{{ rupiah($c->amount) }}</span>@endif</div>
                    @endforeach
                    @if ($k->components->isEmpty()) - @endif
                </td>
                <td><span class="badge {{ $k->is_active ? 'badge-soft-success' : 'badge-soft-neutral' }}">{{ $k->is_active ? 'Aktif' : ($k->start_date->isFuture() ? 'Akan datang' : 'Selesai') }}</span></td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty"><i data-lucide="file-x"></i>Belum ada kontrak</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
