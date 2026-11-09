<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $kode }} · {{ $judul }}</title>
<link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="{{ asset('css/app.css') }}?v=3" rel="stylesheet">
<style>
    .err-wrap { min-height: 100vh; display: flex; flex-direction: column; background: var(--bg-subtle); }
    .err-brand { display: flex; gap: 12px; align-items: center; padding: 32px 48px; }
    .err-brand img { width: 40px; height: 40px; object-fit: contain; }
    .err-isi { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 24px; }
    .err-ilus { width: 168px; height: 168px; border-radius: 50%; background: #e8f3fa; display: flex; align-items: center; justify-content: center; margin-bottom: 28px; position: relative; }
    .err-ilus svg.lucide { width: 72px; height: 72px; color: #1b6fa3; stroke-width: 1.5; }
    .err-ilus::before, .err-ilus::after { content: ''; position: absolute; border-radius: 50%; background: #d5ecf8; }
    .err-ilus::before { width: 14px; height: 14px; left: -10px; top: 24px; }
    .err-ilus::after { width: 16px; height: 16px; right: -12px; bottom: 18px; }
    .err-isi h1 { font-size: 30px; font-weight: 700; margin: 14px 0 10px; }
    .err-isi p { color: var(--text-secondary); max-width: 480px; font-size: 15px; }
</style></head>
<body>
<div class="err-wrap">
    <div class="err-brand">
        <img src="{{ asset('img/logo-unindra.png') }}" alt="Logo Unindra">
        <div><div class="fw-semibold">Universitas Indraprasta PGRI</div><div class="small text-secondary">Aplikasi Penggajian Karyawan</div></div>
    </div>
    <div class="err-isi">
        <div class="err-ilus"><i data-lucide="{{ $ikon }}"></i></div>
        <span class="badge badge-soft-primary">{{ is_numeric($kode) ? 'Error ' . $kode : $kode }}</span>
        <h1>{{ $judul }}</h1>
        <p>{{ $pesan }}</p>
        <div class="d-flex gap-2 mt-3">
            @if ($kode == 419)
                <a href="{{ url('/login') }}" class="btn btn-primary">Masuk lagi</a>
            @else
                <a href="{{ url('/dashboard') }}" class="btn btn-primary">Kembali ke Dashboard</a>
                <a href="javascript:history.back()" class="btn btn-light">Halaman sebelumnya</a>
            @endif
        </div>
    </div>
    <div class="footer border-0">Powered by Kelompok 6 RPL · Kelas X7N · Universitas Indraprasta PGRI · {{ now()->year }}</div>
</div>
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script><script>lucide.createIcons();</script>
</body></html>
