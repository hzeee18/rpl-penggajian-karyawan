@props(['periode', 'name' => 'periode', 'label' => 'Periode'])
{{-- Kotak "Periode: Oktober 2026" dengan ikon cari (Figma); diklik membuka pemilih bulan. --}}
<form class="card-search" method="get">
    @foreach (request()->except([$name, 'page', 'lihat', 'ubah']) as $k => $v)@if (! is_array($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
    <label class="input-search d-block position-relative mb-0">
        <span class="form-control d-flex align-items-center text-secondary">{{ $label }}: {{ nama_periode($periode) }}</span>
        <input type="month" name="{{ $name }}" value="{{ $periode }}" onchange="this.form.submit()" onclick="try{this.showPicker()}catch(e){}" style="position:absolute;inset:0;opacity:0;cursor:pointer">
        <i data-lucide="search"></i>
    </label>
</form>
