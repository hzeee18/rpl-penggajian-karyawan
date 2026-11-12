@extends('layouts.app')
@section('title', 'Approver Payroll')
@section('breadcrumb', 'Payroll Baru / Approver Payroll')
@section('content')
<x-page-header judul="Approver Payroll" sub="Manager yang menyetujui payroll buatan masing-masing user HR." />
@if ($errors->any())<div class="alert alert-danger">{{ implode(' ', $errors->all()) }}</div>@endif
<div class="mb-3"><x-catatan>Alur: HR membuat dan generate payroll → payroll masuk ke menu <b>Approval</b> milik approver di bawah → setelah disetujui, slip gaji terbit. HR yang belum punya approver tidak bisa generate payroll.</x-catatan></div>
@if ($manager->isEmpty())
    <div class="alert alert-warning">Belum ada user <b>Manager</b> aktif. Buat dulu di Manajemen User (role Manager), lalu pilih di sini sebagai approver.</div>
@endif
<div class="card"><div class="table-responsive"><table class="table">
    <thead><tr><th>User HR (pembuat payroll)</th><th>Approver saat ini</th><th>Ganti approver</th></tr></thead>
    <tbody>
    @forelse ($user as $usr)
        @php $sekarang = $manager->firstWhere('id', $usr->approver_user_id); @endphp
        <tr><td>{!! orang($usr->namaTampil(), $usr->username) !!}</td>
            <td>@if ($sekarang){!! orang($sekarang->namaTampil(), $sekarang->username) !!}@else<span class="badge badge-soft-warning">Belum diatur</span>@endif</td>
            <td><form method="post" action="{{ route('pay.approver.update', $usr->id) }}" class="d-flex gap-2">@csrf @method('put')
                <select name="approver_user_id" class="form-select" style="max-width:320px"><option value="">— Belum diatur —</option>
                    @foreach ($manager as $m)<option value="{{ $m->id }}" @selected($usr->approver_user_id === $m->id)>{{ $m->namaTampil() }} ({{ $m->username }})</option>@endforeach</select>
                @if (auth()->user()->bisa('pay-approver', 'update'))<button class="btn btn-light">Simpan</button>@endif
            </form></td></tr>
    @empty
        <tr><td colspan="3"><x-empty ikon="user-check" judul="Belum ada user HR" pesan="Buat user dengan role HR di Manajemen User; HR itulah yang membuat payroll dan perlu approver." /></td></tr>
    @endforelse
    </tbody></table></div></div>
@endsection
