@props(['tipe' => ''])
<div class="notice {{ $tipe ? 'notice-' . $tipe : '' }}"><i data-lucide="{{ $tipe === 'info' ? 'info' : 'circle-alert' }}"></i><span>{{ $slot }}</span></div>
