{{-- Tabel karyawan x komponen. Param: $run, $karyawan (paginator RunEmployee dengan items), $kolom (code,name,type), $bisaEdit --}}
<div class="table-responsive"><table class="table table-sm table-hover">
    <thead><tr><th>Karyawan</th>
        @foreach ($kolom as $k)<th class="text-end text-nowrap">{{ $k->name }}<div class="small text-muted fw-normal">{{ $k->type === 'earning' ? 'penambah' : 'pengurang' }}</div></th>@endforeach
        <th class="text-end">THP</th></tr></thead>
    <tbody>
    @forelse ($karyawan as $re)
        @php $items = $re->items->keyBy('code'); @endphp
        <tr><td class="text-nowrap">{!! orang($re->full_name, $re->employee_no . ' · ' . $re->department_name) !!}</td>
            @foreach ($kolom as $k)
                @php $it = $items[$k->code] ?? null; @endphp
                <td class="text-end text-nowrap {{ $it?->is_overridden ? 'table-warning' : '' }}" title="{{ $it?->is_overridden ? 'Diubah manual. Hasil rumus: ' . rupiah($it->calculated_amount) : '' }}">
                    @if ($it && $bisaEdit)
                        <form method="post" action="{{ route('pay.run.sel', [$run->id, $re->id]) }}" class="d-inline-flex gap-1 align-items-center">@csrf @method('put')
                            <input type="hidden" name="code" value="{{ $k->code }}">
                            <input name="nilai" value="{{ number_format((float) $it->final_amount, 0, ',', '.') }}" class="form-control form-control-sm text-end" style="width:120px" aria-label="{{ $k->name }} {{ $re->full_name }}">
                            @if ($it->is_overridden)<button name="kembalikan" value="1" class="btn btn-light btn-sm" title="Kembalikan ke hasil rumus"><i data-lucide="rotate-ccw"></i></button>@endif
                        </form>
                    @else
                        {{ $it ? rupiah($it->final_amount) : '-' }}
                    @endif
                </td>
            @endforeach
            <td class="text-end fw-semibold text-nowrap">{{ rupiah($re->take_home_pay) }}</td></tr>
    @empty
        <tr><td colspan="{{ count($kolom) + 2 }}"><x-empty ikon="users" judul="Belum ada data preview" /></td></tr>
    @endforelse
    </tbody></table></div>
@if ($karyawan->lastPage() > 1) @include('partials.halaman', ['data' => $karyawan, 'label' => 'karyawan']) @endif
