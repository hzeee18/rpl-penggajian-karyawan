{{-- Tab Absensi / Cuti / Lembur (di Figma jadi satu layar "Absensi, Cuti & Lembur") --}}
@php $u = auth()->user(); @endphp
<div class="tab-nav">
    @foreach (['absensi' => 'Absensi', 'cuti' => 'Cuti, Izin & Sakit', 'lembur' => 'Lembur'] as $url => $label)
        @if ($u->bisa($url) && ! ($u->isManagerTim() && $url === 'lembur'))
            <a href="{{ url($url) }}{{ request('periode') ? '?periode=' . request('periode') : '' }}" class="{{ request()->segment(1) === $url ? 'active' : '' }}">{{ $label }}</a>
        @endif
    @endforeach
</div>
