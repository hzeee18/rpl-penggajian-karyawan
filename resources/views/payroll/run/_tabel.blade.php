{{-- Tabel karyawan x komponen (baca saja; perubahan lewat "Tambah penyesuaian"). Param: $run, $karyawan (paginator RunEmployee dengan items), $kolom (code,name,type) --}}
@if ($karyawan->isEmpty())
    <x-empty ikon="calculator" judul="Belum ada data preview" pesan="Pilih master payroll di atas lalu klik Lihat preview untuk menghitung gaji semua karyawan." />
@else
<div class="table-responsive"><table class="table table-hover tabel-payroll mb-0">
    <thead><tr><th>Karyawan</th>
        @foreach ($kolom as $k)<th class="text-end text-nowrap">{{ $k->name }}</th>@endforeach
        <th class="text-end text-nowrap kol-thp">Gaji bersih</th></tr></thead>
    <tbody>
    @forelse ($karyawan as $re)
        @php $items = $re->items->keyBy('code'); @endphp
        <tr><td class="text-nowrap"><div class="fw-semibold">{{ $re->full_name }}</div><div class="small text-muted">{{ $re->employee_no }} · {{ $re->department_name ?? '-' }}</div></td>
            @foreach ($kolom as $k)
                @php $it = $items[$k->code] ?? null; @endphp
                <td class="text-end text-nowrap {{ $it?->is_overridden ? 'table-warning' : '' }}" title="{{ $it?->is_overridden ? 'Diubah manual. Hasil rumus: ' . rupiah($it->calculated_amount) : '' }}">
                    {{ $it ? ($k->type === 'deduction' && (float) $it->final_amount > 0 ? '−' : '') . rupiah($it->final_amount) : '-' }}
                </td>
            @endforeach
            <td class="text-end text-nowrap kol-thp">{{ rupiah($re->take_home_pay) }}</td></tr>
    @empty
        <tr><td colspan="{{ count($kolom) + 2 }}"><x-empty ikon="calculator" judul="Belum ada data preview" pesan="Pilih master payroll di atas lalu klik Lihat preview untuk menghitung gaji semua karyawan." /></td></tr>
    @endforelse
    </tbody></table></div>
@endif
@if ($karyawan->lastPage() > 1) @include('partials.halaman', ['data' => $karyawan, 'label' => 'karyawan']) @endif
