{{-- Tabel ringkasan payroll per karyawan. Param: $ringkasan, $payroll, $link (bool) --}}
<div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th>NIK</th><th>Karyawan</th><th class="text-end">Gaji pokok</th><th class="text-end">Bruto</th><th class="text-end">PPh 21</th><th class="text-end">Potongan</th><th class="text-end">Gaji bersih</th>@if ($link ?? false)<th>Aksi</th>@endif</tr></thead>
        <tbody>
        @foreach ($ringkasan as $r)
            <tr>
                <td class="fw-semibold">{{ $r->karyawan->nik }}</td>
                <td>{!! orang($r->karyawan->nama, $r->karyawan->departemen?->nama) !!}</td>
                <td class="text-uang">{{ rupiah($r->gaji_pokok) }}</td>
                <td class="text-uang">{{ rupiah($r->bruto) }}</td>
                <td class="text-uang">{{ rupiah($r->pph21) }}</td>
                <td class="text-uang">{{ rupiah($r->potongan) }}</td>
                <td class="text-uang fw-semibold">{{ rupiah($r->thp) }}</td>
                @if ($link ?? false)<td><a href="{{ route('payroll.detailKaryawan', [$payroll, $r->karyawan]) }}" class="btn btn-light btn-icon" title="Rincian"><i data-lucide="eye"></i></a></td>@endif
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr class="fw-semibold"><td colspan="2">Total ({{ $ringkasan->count() }} karyawan)</td>
                <td class="text-uang">{{ rupiah($ringkasan->sum('gaji_pokok')) }}</td><td class="text-uang">{{ rupiah($ringkasan->sum('bruto')) }}</td>
                <td class="text-uang">{{ rupiah($ringkasan->sum('pph21')) }}</td><td class="text-uang">{{ rupiah($ringkasan->sum('potongan')) }}</td>
                <td class="text-uang">{{ rupiah($ringkasan->sum('thp')) }}</td>@if ($link ?? false)<td></td>@endif</tr>
        </tfoot>
    </table>
</div>
