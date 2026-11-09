<div class="chips">
    <a href="{{ request()->fullUrlWithQuery(['status' => null]) }}" class="chip {{ request('status') ? '' : 'active' }}">Semua</a>
    @foreach (\App\Models\Attendance::STATUS as $v => $l)<a href="{{ request()->fullUrlWithQuery(['status' => $v]) }}" class="chip {{ request('status') === $v ? 'active' : '' }}">{{ $l }}</a>@endforeach
</div>
