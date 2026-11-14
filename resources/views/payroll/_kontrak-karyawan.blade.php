{{-- Riwayat kontrak modul payroll baru untuk satu karyawan (tampilan Figma "Profil & Kontrak").
     Parameter: $kontrak (koleksi Contract + components.component), $lihatGaji (bool) --}}
@php $bisaBuka = auth()->user()->bisa('pay-kontrak'); @endphp
<div class="table-responsive">
    <table class="table mb-0">
        <thead><tr><th>No. kontrak</th><th>Periode</th><th>Gaji pokok</th><th>Komponen gaji</th><th>Status</th><th class="text-center">Aksi</th></tr></thead>
        <tbody>
        @forelse ($kontrak as $k)
            @php $gp = $k->components->first(fn ($c) => $c->component?->code === 'gaji_pokok')?->amount; @endphp
            <tr>
                <td class="kode">{{ $k->contract_name }}</td>
                <td class="text-secondary text-nowrap">{{ tgl($k->start_date, 'j M Y') }} – {{ $k->end_date ? tgl($k->end_date, 'j M Y') : 'tidak terbatas' }}</td>
                <td class="text-nowrap">{{ ! $lihatGaji ? 'Rahasia' : ($gp !== null ? rupiah($gp) : '-') }}</td>
                <td class="small text-secondary">{{ $k->components->map(fn ($c) => $c->component?->name)->filter()->join(', ') ?: '-' }}</td>
                <td><span class="badge {{ $k->is_active ? 'badge-soft-success' : 'badge-soft-neutral' }}">{{ $k->is_active ? 'Aktif' : ($k->start_date->isFuture() ? 'Akan datang' : 'Selesai') }}</span></td>
                <td class="text-nowrap text-center">
                    @if ($bisaBuka)
                        <a href="{{ route('pay.kontrak.index', ['lihat' => $k->id]) }}" class="btn btn-light btn-icon" title="Lihat detail kontrak"><i data-lucide="eye"></i></a>
                    @elseif ($lihatGaji)
                        <button type="button" class="btn btn-light btn-icon" data-bs-toggle="collapse" data-bs-target="#rincian-kontrak-{{ $k->id }}" aria-expanded="false" title="Lihat rincian"><i data-lucide="eye"></i></button>
                    @endif
                </td>
            </tr>
            @if (! $bisaBuka && $lihatGaji)
                <tr class="collapse" id="rincian-kontrak-{{ $k->id }}"><td colspan="6" class="bg-light">
                    <div class="d-flex flex-wrap gap-4 small">@foreach ($k->components as $c)<div><span class="text-muted">{{ $c->component?->name }}</span> <span class="fw-semibold text-uang">{{ rupiah($c->amount) }}</span></div>@endforeach</div>
                </td></tr>
            @endif
        @empty
            <tr><td colspan="6"><x-empty ikon="file-x" judul="Belum ada kontrak" /></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
