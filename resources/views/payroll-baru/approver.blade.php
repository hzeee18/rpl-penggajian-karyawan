@extends('layouts.app')
@section('title', 'Approver Payroll')
@section('breadcrumb', 'Payroll Baru / Approver Payroll')
@section('content')
<x-page-header judul="Approver Payroll" sub="Manager yang menyetujui payroll buatan masing-masing user HR." />
@if ($errors->any())<div class="alert alert-danger">{{ implode(' ', $errors->all()) }}</div>@endif
<div class="card"><div class="table-responsive"><table class="table">
    <thead><tr><th>User HR</th><th>Approver</th></tr></thead>
    <tbody>
    @forelse ($user as $usr)
        <tr><td>{!! orang($usr->namaTampil(), $usr->username) !!}</td>
            <td><form method="post" action="{{ route('pay.approver.update', $usr->id) }}" class="d-flex gap-2">@csrf @method('put')
                <select name="approver_user_id" class="form-select" style="max-width:320px"><option value="">— Belum diatur —</option>
                    @foreach ($manager as $m)<option value="{{ $m->id }}" @selected($usr->approver_user_id === $m->id)>{{ $m->namaTampil() }} ({{ $m->username }})</option>@endforeach</select>
                @if (auth()->user()->bisa('pay-approver', 'update'))<button class="btn btn-light">Simpan</button>@endif
            </form></td></tr>
    @empty
        <tr><td colspan="2"><x-empty ikon="user-check" judul="Belum ada user HR" /></td></tr>
    @endforelse
    </tbody></table></div></div>
@endsection
