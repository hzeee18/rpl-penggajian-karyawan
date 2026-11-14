{{-- Dropdown daftar versi master (tidak menumpuk). Param: $versiList (koleksi MasterVersion), $aktifId (versi yang sedang dibuka atau null), $label (opsional).
     Badge mengikuti komponen Badge Figma: terbit hijau, aktif biru, draft abu-abu, terkunci merah. Draft yang boleh dihapus punya ikon hapus. --}}
@php $bisaHapus = auth()->user()->bisa('pay-master', 'delete') && $versiList->count() > 1; @endphp
<div class="dropdown pilih-versi">
    <button type="button" class="btn btn-light {{ isset($label) ? '' : 'btn-icon' }}" data-bs-toggle="dropdown" data-bs-auto-close="outside" data-bs-popper-config='{"strategy":"fixed"}' title="Semua versi ({{ $versiList->count() }})" aria-label="Semua versi">
        @isset($label){{ $label }} <i data-lucide="chevron-down"></i>@else<i data-lucide="history"></i>@endisset</button>
    <div class="dropdown-menu dropdown-menu-end">
        <div class="dropdown-header">Semua versi ({{ $versiList->count() }})</div>
        @foreach ($versiList->sortByDesc('version_no') as $v)
            @php
                [$lbl, $cls] = $v->locked_at ? ['Terkunci', 'danger'] : ($v->status === 'published' ? ['Terbit', 'success'] : ['Draft', 'neutral']);
                $aktif = $v->master?->current_version_id === $v->id;
                $hapus = $bisaHapus && $v->bisaDiubah() && ! $aktif;
            @endphp
            <div class="item-versi {{ $aktifId === $v->id ? 'terpilih' : '' }}">
                <a href="{{ route('pay.master.versi', $v->id) }}" class="dropdown-item">v{{ $v->version_no }}
                    <span class="ms-auto d-inline-flex gap-1">@if ($aktif)<span class="badge badge-soft-primary">Aktif</span>@endif<span class="badge badge-soft-{{ $cls }}">{{ $lbl }}</span></span></a>
                @if ($hapus)
                    <form method="post" action="{{ route('pay.master.versi.destroy', $v->id) }}" data-confirm="Hapus draft v{{ $v->version_no }}? Semua isinya ikut terhapus.">@csrf @method('delete')
                        <button class="btn btn-icon btn-hapus-versi" title="Hapus draft v{{ $v->version_no }}" aria-label="Hapus draft v{{ $v->version_no }}"><i data-lucide="trash-2"></i></button></form>
                @else
                    <span class="btn-hapus-versi" aria-hidden="true"></span>
                @endif
            </div>
        @endforeach
    </div>
</div>
