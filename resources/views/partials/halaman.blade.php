{{-- Footer tabel: "Menampilkan 1–7 dari 132 pengguna" + nomor halaman (Figma). Param: $data (paginator), $label --}}
<div class="table-foot border-top">
    <span>Menampilkan {{ $data->firstItem() ?? 0 }}–{{ $data->lastItem() ?? 0 }} dari {{ $data->total() }} {{ $label }}</span>
    @if ($data->lastPage() > 1)
        <ul class="pagination">
            @if (! $data->onFirstPage())<li class="page-item"><a class="page-link" href="{{ $data->previousPageUrl() }}">‹</a></li>@endif
            @foreach ($data->getUrlRange(max(1, $data->currentPage() - 2), min($data->lastPage(), $data->currentPage() + 2)) as $n => $url)
                <li class="page-item {{ $n === $data->currentPage() ? 'active' : '' }}"><a class="page-link" href="{{ $url }}">{{ $n }}</a></li>
            @endforeach
            @if ($data->hasMorePages())<li class="page-item"><a class="page-link" href="{{ $data->nextPageUrl() }}">›</a></li>@endif
        </ul>
    @endif
</div>
