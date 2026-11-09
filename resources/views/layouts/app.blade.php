<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') · Penggajian Karyawan</title>
    <link rel="icon" href="{{ asset('img/logo-unindra.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" rel="stylesheet">
    <link href="{{ asset('css/app.css') }}?v=24" rel="stylesheet">
    <meta name="turbo-cache-control" content="no-preview">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/id.js"></script>
    <script src="{{ asset('js/form.js') }}?v=9"></script>
    <script src="{{ asset('js/app.js') }}?v=6"></script>
    <script src="https://cdn.jsdelivr.net/npm/@hotwired/turbo@8.0.12/dist/turbo.es2017-umd.js"></script>
    @stack('head')
</head>
<body>
@php
    $u = auth()->user();
    $subUser = $u->karyawan?->jabatan ?? ($u->isRole('Admin') ? 'Administrator' : $u->role->nama);
@endphp
<div class="d-flex">
    {{-- ===== Sidebar ===== --}}
    <aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar">
        <div class="sidebar-brand">
            <img src="{{ asset('img/logo-unindra.png') }}" alt="Logo Unindra">
            <div>
                <div class="nama">Penggajian Karyawan</div>
                <div class="kampus">Universitas Indraprasta PGRI</div>
            </div>
        </div>

        <div class="nav-label">Menu {{ $labelRole }}</div>
        @foreach ($sidebarUtama as $menu)
            <a href="{{ url($menu->url) }}" class="nav-item-link {{ $menuAktif === $menu->url ? 'active' : '' }}">
                <i data-lucide="{{ $menu->icon ?? 'circle' }}"></i> {{ $menu->nama }}
            </a>
        @endforeach
        @foreach ($sidebarGrup as $grup)
            <div class="nav-label">{{ $grup->nama }}</div>
            @foreach ($grup->children as $child)
                <a href="{{ url($child->url) }}" class="nav-item-link {{ $menuAktif === $child->url ? 'active' : '' }}">
                    <i data-lucide="{{ $child->icon ?? 'circle' }}"></i> {{ $child->nama }}
                </a>
            @endforeach
        @endforeach

        <div class="sidebar-user mt-auto">
            <span class="avatar">{{ inisial($u->namaTampil()) }}</span>
            <div class="text-truncate">
                <div class="nama text-truncate">{{ $u->namaTampil() }}</div>
                <div class="peran text-truncate">{{ $subUser }}</div>
            </div>
        </div>
        <form method="post" action="{{ route('logout') }}">@csrf
            <button class="nav-item-link"><i data-lucide="log-out"></i> Keluar</button>
        </form>
    </aside>

    <div class="main flex-grow-1">
        {{-- ===== Topbar ===== --}}
        <header class="topbar">
            <button class="btn-topbar d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Menu"><i data-lucide="menu"></i></button>
            <div class="me-auto">
                <div class="crumb">@yield('breadcrumb', 'Beranda')</div>
                <div class="judul">@yield('title', 'Dashboard')</div>
            </div>

            <form class="topbar-search d-none d-md-block" action="{{ $u->bisa('karyawan') ? route('karyawan.index') : ($u->bisa('slip') ? route('slip.index') : route('dashboard')) }}" data-cari="{{ route('cari') }}" role="search">
                <i data-lucide="search"></i>
                <input name="q" class="form-control" placeholder="{{ $u->bisa('karyawan') ? 'Cari karyawan, slip gaji, atau menu...' : ($u->bisa('slip') ? 'Cari slip gaji atau menu...' : 'Cari menu...') }}" value="{{ request('q') }}" autocomplete="off" aria-autocomplete="list">
                <div class="cari-hasil" hidden></div>
            </form>

            <div class="dropdown">
                <button class="btn-topbar" data-bs-toggle="dropdown" aria-label="Notifikasi">
                    <i data-lucide="bell"></i>
                    @if ($jumlahNotif)<span class="titik"></span>@endif
                </button>
                <div class="dropdown-menu dropdown-menu-end p-0 notif-menu">
                    <div class="d-flex justify-content-between align-items-center px-3 py-3 border-bottom">
                        <strong>Notifikasi @if ($jumlahNotif)<span class="badge badge-soft-danger ms-1">{{ $jumlahNotif }} baru</span>@endif</strong>
                        <a href="{{ route('notifikasi.index') }}" class="small fw-semibold">Lihat semua</a>
                    </div>
                    @forelse ($notifTerbaru as $n)
                        <a href="{{ route('notifikasi.buka', $n) }}" class="notif-item text-reset {{ $n->is_read ? '' : 'unread' }}">
                            <span class="ikon"><i data-lucide="bell"></i></span>
                            <span>
                                <span class="d-block fw-semibold small">{{ $n->judul }}</span>
                                <span class="d-block small text-secondary">{{ \Illuminate\Support\Str::limit($n->pesan, 90) }}</span>
                                <span class="d-block small text-muted">{{ $n->created_at?->diffForHumans() }}</span>
                            </span>
                        </a>
                    @empty
                        <x-empty ikon="bell-off" judul="Belum ada notifikasi" pesan="Kabar soal approval, lembur, dan slip gaji akan muncul di sini." />
                    @endforelse
                </div>
            </div>

            <div class="dropdown">
                <button class="btn-avatar" data-bs-toggle="dropdown" aria-label="Menu profil"><span class="avatar">{{ inisial($u->namaTampil()) }}</span></button>
                <div class="dropdown-menu dropdown-menu-end" style="width:240px">
                    <div class="px-3 py-2 border-bottom mb-1">
                        <div class="fw-semibold">{{ $u->namaTampil() }}</div>
                        <div class="small text-muted">{{ $u->username }} · {{ $u->role->nama }}{{ $u->isManagerHr() ? ' HR' : '' }}</div>
                    </div>
                    @if ($u->karyawan_id && $u->bisa('profil'))
                        <a class="dropdown-item d-flex gap-2" href="{{ route('profil.index') }}"><i data-lucide="user"></i> Profil saya</a>
                    @endif
                    <button class="dropdown-item d-flex gap-2" data-bs-toggle="modal" data-bs-target="#modalPassword"><i data-lucide="key-round"></i> Ganti kata sandi</button>
                    <div class="dropdown-divider"></div>
                    <form method="post" action="{{ route('logout') }}">@csrf
                        <button class="dropdown-item d-flex gap-2 text-danger"><i data-lucide="log-out"></i> Keluar</button>
                    </form>
                </div>
            </div>
        </header>

        <main class="content">
            @yield('content')
        </main>

        <footer class="footer">Powered by <b>Kelompok 6 RPL</b> · Kelas X7N · Universitas Indraprasta PGRI · {{ now()->year }}</footer>
    </div>
</div>

<div class="drawer-backdrop" data-tutup-drawer></div>
@include('partials.flash')

{{-- Modal ganti kata sandi --}}
<div class="modal fade" id="modalPassword" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="{{ route('password.ganti') }}">
            @csrf @method('put')
            <div class="modal-header"><h5 class="modal-title fs-6 fw-semibold">Ganti kata sandi</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Kata sandi lama</label><input type="password" name="password_lama" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Kata sandi baru</label><input type="password" name="password" class="form-control" minlength="8" required><div class="form-text">Minimal 8 karakter.</div></div>
                <div class="mb-1"><label class="form-label">Ulangi kata sandi baru</label><input type="password" name="password_confirmation" class="form-control" required></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan</button></div>
        </form>
    </div>
</div>

{{-- Modal konfirmasi (dipakai form dengan data-confirm) --}}
<div class="modal fade" id="modalKonfirmasi" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:400px">
        <div class="modal-content p-2">
            <div class="modal-body">
                <h5 class="fw-bold fs-5 mb-2" id="konfJudul">Lanjutkan?</h5>
                <p class="text-secondary mb-0" id="konfPesan"></p>
            </div>
            <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button type="button" id="konfOk" class="btn btn-primary">Ya</button></div>
        </div>
    </div>
</div>

<script>window.__errors = @json($errors->getMessages()); window.__bukaModalPassword = {{ ($errors->has('password_lama') || $errors->has('password')) ? 'true' : 'false' }};</script>
@stack('scripts')
</body>
</html>
