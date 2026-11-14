{{-- Tab Kalender Payroll (AUDIT.md §53). Param: $aktif = periode|libur --}}
<div class="tab-nav" data-tab-ajax>
    <a data-turbo="false" href="{{ route('pay.libur.index') }}" class="{{ $aktif === 'periode' ? 'active' : '' }}"><i data-lucide="calendar-range"></i> Periode</a>
    <a data-turbo="false" href="{{ route('pay.libur.index', ['tab' => 'libur']) }}" class="{{ $aktif === 'libur' ? 'active' : '' }}"><i data-lucide="calendar-days"></i> Hari Libur</a>
    <a data-turbo="false" href="{{ route('pay.libur.index', ['tab' => 'pola']) }}" class="{{ $aktif === 'pola' ? 'active' : '' }}"><i data-lucide="calendar-clock"></i> Pola Hari Kerja</a>
</div>
