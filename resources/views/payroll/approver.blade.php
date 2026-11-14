@extends('layouts.app')
@section('title', 'Approver Payroll')
@section('breadcrumb', 'Payroll / Approver Payroll')
@section('content')
<x-page-header judul="Approver Payroll" sub="Atur apakah payroll perlu disetujui, dan siapa penyetujunya." />
@if ($errors->any() && ! $errors->has('penyetuju'))<div class="alert alert-danger">{{ implode(' ', $errors->all()) }}</div>@endif
<div class="card mb-3"><form method="post" action="{{ route('pay.approver.pengaturan') }}" class="card-body d-flex flex-wrap gap-3 align-items-center">@csrf @method('put')
    <div class="me-auto"><div class="fw-semibold">PDF slip gaji pakai password?</div>
        <div class="small text-muted">{{ $slipPakaiPassword ? 'Ya: password = 2 digit terakhir nomor karyawan + 4 digit terakhir nomor rekening.' : 'Tidak: PDF slip bisa langsung dibuka.' }} Berlaku untuk slip yang terbit setelah setelan disimpan.</div></div>
    <select name="slip_pakai_password" class="form-select" style="max-width:260px" @disabled(! auth()->user()->bisa('pay-approver', 'update'))>
        <option value="1" @selected($slipPakaiPassword)>Ya, pakai password</option><option value="0" @selected(! $slipPakaiPassword)>Tidak pakai password</option></select>
    @if (auth()->user()->bisa('pay-approver', 'update'))<button class="btn btn-primary">Simpan</button>@endif
</form></div>
@php $bisaUbah = auth()->user()->bisa('pay-approver', 'update'); $roleDipilih = array_map('intval', old('role_id', $pilihan->pluck('role_id')->filter()->all())); $userDipilih = array_map('intval', old('user_id', $pilihan->pluck('user_id')->filter()->all())); $pakai = (bool) old('payroll_butuh_approval', $butuhApproval); @endphp
<div class="card mb-3"><form method="post" action="{{ route('pay.approver.penyetuju') }}" class="card-body">@csrf @method('put')
    <div class="d-flex flex-wrap gap-3 align-items-center">
        <div class="me-auto"><div class="fw-semibold">Payroll perlu persetujuan?</div>
            <div class="small text-muted">Tidak: setelah generate, payroll langsung final dan slip langsung terbit. Ya: payroll menunggu keputusan salah satu penyetuju.</div></div>
        <select name="payroll_butuh_approval" class="form-select" style="max-width:260px" data-toggle-penyetuju @disabled(! $bisaUbah)>
            <option value="0" @selected(! $pakai)>Tidak, langsung final</option><option value="1" @selected($pakai)>Ya, perlu approval</option></select>
    </div>
    <div id="pilih-penyetuju" class="border-top mt-3 pt-3" @unless ($pakai) hidden @endunless>
        <div class="fw-semibold">Siapa yang menyetujui? <span class="text-danger">*</span></div>
        <div class="small text-muted mb-3">Wajib minimal satu. Pilih role (semua anggotanya jadi penyetuju) dan/atau user tertentu. Penyetuju otomatis bisa membuka menu Approval.</div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Role penyetuju</label>
                <select name="role_id[]" class="form-select" multiple data-mode="chips" data-placeholder="Cari role…" @disabled(! $bisaUbah)>
                    @foreach ($roleKandidat as $r)<option value="{{ $r->id }}" @selected(in_array($r->id, $roleDipilih, true))>{{ $r->nama }}</option>@endforeach</select></div>
            <div class="col-md-6"><label class="form-label">User penyetuju</label>
                <select name="user_id[]" class="form-select" multiple data-mode="chips" data-placeholder="Cari nama atau username…" @disabled(! $bisaUbah)>
                    @foreach ($userKandidat as $usr)<option value="{{ $usr->id }}" @selected(in_array($usr->id, $userDipilih, true))>{{ $usr->namaTampil() }} · {{ $usr->username }} ({{ $usr->role?->nama }})</option>@endforeach</select></div>
        </div>
        @error('penyetuju')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
    </div>
    @if ($bisaUbah)<div class="mt-3 text-end"><button class="btn btn-primary">Simpan</button></div>@endif
</form></div>
<script>
    document.querySelectorAll('[data-toggle-penyetuju]').forEach(el => el.addEventListener('change', () => { document.getElementById('pilih-penyetuju').hidden = el.value !== '1'; }));
</script>
@if ($butuhApproval && $penyetuju->isEmpty())
    <div class="alert alert-warning">Payroll perlu persetujuan tetapi belum ada penyetuju. Generate payroll akan ditolak sampai penyetuju dipilih.</div>
@endif
<div class="card"><div class="card-body pb-0"><div class="fw-semibold">Penyetuju yang berlaku</div>
    <div class="small text-muted">Semua orang di bawah bisa menyetujui atau menolak payroll. Yang lebih dulu memutuskan, itu yang tercatat.</div></div>
<div class="table-responsive"><table class="table">
    <thead><tr><th>User</th><th>Role</th><th>Jabatan</th><th>Dipilih lewat</th></tr></thead>
    <tbody>
    @forelse ($penyetuju as $usr)
        <tr><td>{!! orang($usr->namaTampil(), $usr->username) !!}</td><td>{{ $usr->role?->nama }}</td><td>{{ $usr->karyawan?->jabatan ?: '-' }}</td>
            <td>{{ collect([in_array($usr->id, $userDipilih) ? 'User' : null, in_array($usr->role_id, $roleDipilih) ? 'Role ' . $usr->role?->nama : null])->filter()->join(' dan ') }}</td></tr>
    @empty
        <tr><td colspan="4"><x-empty ikon="user-check" judul="Belum ada penyetuju" :pesan="$butuhApproval ? 'Pilih role atau user penyetuju di atas.' : 'Perusahaan tidak memakai approval.'" /></td></tr>
    @endforelse
    </tbody></table></div></div>
@endsection
