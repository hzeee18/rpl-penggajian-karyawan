{{-- Pilihan karyawan di drawer. Param: $karyawan, $nilai, $sisa (tampilkan sisa cuti), $lemburSaja (hanya yang berhak lembur) --}}
<div class="mb-field"><label class="form-label">Karyawan</label>
    <select name="karyawan_id" class="form-select" required>
        <option value="">Pilih karyawan</option>
        @foreach ($karyawan as $k)
            @continue(! empty($lemburSaja) && ! in_array($k->id, $berhakLembur ?? []) && $k->id != $nilai)
            <option value="{{ $k->id }}" @selected(old('karyawan_id', $nilai) == $k->id)>{{ $k->nama }} · {{ $k->nik }}{{ ! empty($sisa) ? ' (sisa cuti ' . $k->sisa_cuti . ')' : '' }}</option>
        @endforeach
    </select>
</div>
