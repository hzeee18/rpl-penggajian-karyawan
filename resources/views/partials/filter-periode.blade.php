{{-- Filter periode + karyawan untuk absensi/cuti/lembur. --}}
<form class="d-flex flex-wrap gap-2 align-items-center">
    <input type="month" name="periode" value="{{ $periode }}" class="form-control" style="width:180px" onchange="this.form.submit()">
    @if ($karyawan->count() > 1)
        <select name="karyawan_id" class="form-select" style="width:240px" onchange="this.form.submit()">
            <option value="">Semua karyawan</option>
            @foreach ($karyawan as $k)<option value="{{ $k->id }}" @selected(request('karyawan_id') == $k->id)>{{ $k->nama }}</option>@endforeach
        </select>
    @endif
    @isset($filterTambahan) @include($filterTambahan) @endisset
    <span class="small text-muted ms-auto">{{ tgl($awal, 'j M') }} – {{ tgl($akhir, 'j M Y') }}</span>
</form>
