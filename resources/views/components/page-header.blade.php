@props(['judul', 'sub' => null, 'status' => null])
<div class="page-header">
    <div>
        <h1>{{ $judul }}@if ($status) <span class="status-judul">{!! badge_status($status) !!}</span>@endif</h1>
        @if ($sub)<p>{{ $sub }}</p>@endif
    </div>
    @if (trim($slot))<div class="aksi">{{ $slot }}</div>@endif
</div>
