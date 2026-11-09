@props(['placeholder' => 'Cari...', 'name' => 'q'])
{{-- Kotak pencarian di atas tabel (Figma). Slot = filter tambahan di kanan. --}}
<form class="card-search d-flex gap-2 flex-wrap" method="get">
    @foreach (request()->except([$name, 'page', 'ubah', 'lihat']) as $k => $v)
        @if (! is_array($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
    @endforeach
    <div class="input-search flex-grow-1"><input name="{{ $name }}" value="{{ request($name) }}" class="form-control" placeholder="{{ $placeholder }}"><i data-lucide="search"></i></div>
    {{ $slot }}
</form>
