{{-- Timeline approval (design system): bulatan + garis penghubung, pola sama dengan stepper di dashboard.
     Param: $approvals (koleksi Approval). Baris "dilewati" tidak jadi langkah; yang masih menunggu digabung satu langkah. --}}
@props(['approvals'])
@php
    $urut = collect($approvals)->sortBy('id')->values();
    $langkah = collect();
    $diajukan = function ($a) use ($langkah) {
        $langkah->push(['selesai', 'send', 'Diajukan', ($a->pengaju?->namaTampil() ?? 'Sistem') . ' · ' . tgl($a->created_at, 'j M Y'), null]);
    };
    foreach ($urut->whereIn('status', ['disetujui', 'ditolak']) as $a) {
        $diajukan($a);
        $langkah->push($a->status === 'disetujui'
            ? ['selesai', 'check', 'Disetujui', ($a->approver?->namaTampil() ?? '-') . ' · ' . tgl($a->tgl_approval, 'j M Y'), $a->catatan]
            : ['ditolak', 'x', 'Ditolak', ($a->approver?->namaTampil() ?? '-') . ' · ' . tgl($a->tgl_approval, 'j M Y'), $a->catatan]);
    }
    $menunggu = $urut->where('status', 'menunggu');
    if ($menunggu->isNotEmpty()) {
        $diajukan($menunggu->first());
        $nama = $menunggu->map(fn ($a) => $a->approver?->namaTampil())->filter()->unique()->join(' atau ');
        $langkah->push(['aktif', 'clock', 'Menunggu persetujuan', $nama, null]);
    }
@endphp
@if ($langkah->isEmpty())
    <div class="small text-muted">Belum diajukan.</div>
@else
    <div class="stepper">
        @foreach ($langkah as [$kelas, $ikon, $judul, $ket, $catatan])
            <div class="step {{ $kelas }}">
                <span class="bulat"><i data-lucide="{{ $ikon }}"></i></span>
                <div><div class="judul">{{ $judul }}</div><div class="ket">{{ $ket }}</div>@if ($catatan)<div class="ket fst-italic">“{{ $catatan }}”</div>@endif</div>
            </div>
        @endforeach
    </div>
@endif
