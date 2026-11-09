@extends('layouts.app')
@section('title', 'Role & Privilege')
@section('breadcrumb', 'Pengaturan / Role & Privilege')
@section('content')
<x-page-header judul="Role & Privilege" sub="Atur menu dan aksi yang bisa diakses setiap role.">
    <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#modalRole"><i data-lucide="plus"></i> Tambah Role</button>
    @if ($role)<button form="formPriv" class="btn btn-primary"><i data-lucide="check"></i> Simpan Perubahan</button>@endif
</x-page-header>

<div class="d-grid gap-4" style="grid-template-columns: 300px minmax(0, 1fr)">
    <div>
        @foreach ($roles as $r)
            <a href="{{ route('roles.index', ['role' => $r->id]) }}" class="role-card {{ $role?->id === $r->id ? 'aktif' : '' }}">
                <div class="d-flex justify-content-between"><span class="nama">{{ $r->nama }}</span><span class="jml">{{ $r->users_count }} user</span></div>
                <div class="ket">{{ \App\Http\Controllers\Admin\RoleController::KETERANGAN[$r->nama] ?? 'Role tambahan' }}</div>
            </a>
        @endforeach
    </div>

    @if ($role)
    <form id="formPriv" method="post" action="{{ route('roles.simpanHakAkses', $role) }}" class="card">
        @csrf @method('put')
        <div class="card-header d-flex justify-content-between align-items-start">
            <span>Privilege: {{ $role->nama }}<span class="sub">Centang aksi yang diizinkan untuk setiap menu</span></span>
            @unless (in_array($role->nama, ['Admin', 'HR', 'Manager', 'Karyawan']))
                <button form="hapusRole" class="btn btn-outline-danger btn-sm"><i data-lucide="trash-2"></i> Hapus role</button>
            @endunless
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Menu</th>@foreach (['Lihat', 'Tambah', 'Ubah', 'Hapus', 'Approve'] as $a)<th class="text-center">{{ $a }}</th>@endforeach</tr></thead>
                @php
                    $label = $role->nama === 'Manager' ? ['karyawan' => 'Tim Saya (data tim sendiri)', 'absensi' => 'Absensi Tim', 'cuti' => 'Cuti, izin & sakit tim', 'lembur' => 'Lembur Tim (input tim sendiri)'] : [];
                    $items = $menus->flatMap(fn ($m) => $m->url ? [[$m, null]] : $m->children->map(fn ($c) => [$c, $m]));
                    $punya = fn ($it) => collect(\App\Models\Privilege::AKSI)->contains(fn ($a) => $priv[$it->id]?->{'can_' . $a} ?? false);
                    [$ada, $lain] = $items->partition(fn ($x) => $punya($x[0]));
                @endphp
                <tbody>
                @foreach ($ada as [$item, $induk])
                    @include('admin.roles._baris')
                @endforeach
                </tbody>
                @if ($lain->isNotEmpty())
                    <tbody class="table-group-divider border-0">
                        <tr><td colspan="6" class="py-2"><a href="#" class="small fw-semibold" onclick="document.getElementById('menuLain').classList.toggle('d-none');return false;">{{ $lain->map(fn ($x) => $x[1]?->nama ?? $x[0]->nama)->unique()->join(', ') }} (tidak ada akses) · tampilkan</a></td></tr>
                    </tbody>
                    <tbody id="menuLain" class="d-none">
                    @foreach ($lain as [$item, $induk])
                        @include('admin.roles._baris')
                    @endforeach
                    </tbody>
                @endif
            </table>
        </div>
        <div class="p-3 border-top"><x-catatan tipe="info">Isi menu Approval diatur ApprovalMapping: kontrak masuk ke Manager divisi karyawan, payroll hanya masuk ke Manager HR (Manager departemen HRD).</x-catatan></div>
    </form>
    <form id="hapusRole" method="post" action="{{ route('roles.destroy', $role) }}" data-confirm="Hapus role {{ $role->nama }}?">@csrf @method('delete')</form>
    @endif
</div>

<div class="modal fade" id="modalRole" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="{{ route('roles.store') }}">
            @csrf
            <div class="modal-header"><h5 class="modal-title fs-6 fw-semibold">Tambah Role</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><label class="form-label">Nama role</label><input name="nama" class="form-control" placeholder="mis. Finance" required></div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan</button></div>
        </form>
    </div>
</div>
@endsection
