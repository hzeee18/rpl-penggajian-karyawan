<div class="chips">
    <a href="{{ request()->fullUrlWithQuery(['jenis' => null]) }}" class="chip {{ request('jenis') ? '' : 'active' }}">Semua</a>
    @foreach (\App\Models\Leave::JENIS as $v => $l)<a href="{{ request()->fullUrlWithQuery(['jenis' => $v]) }}" class="chip {{ request('jenis') === $v ? 'active' : '' }}">{{ $l }}</a>@endforeach
</div>
