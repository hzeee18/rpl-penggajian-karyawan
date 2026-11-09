{{-- Drawer detail kontrak: tab Detail + Riwayat semua kontrak karyawan ini. Param: $lihat, $riwayat --}}
@php
    $kembaliKe = route('kontrak.index', array_filter(['q' => request('q'), 'status' => request('status'), 'page' => request('page')]));
    $statusK = function ($k) {
        if ($k->is_active) return ['Aktif', 'badge-soft-success'];
        return match ($k->status_approval) {
            'disetujui' => $k->tgl_mulai->isFuture() ? ['Disetujui · belum mulai', 'badge-soft-info'] : ['Berakhir', 'badge-soft-neutral'],
            'menunggu' => ['Menunggu approval', 'badge-soft-warning'],
            'ditolak' => ['Ditolak', 'badge-soft-danger'],
            default => ['Draft', 'badge-soft-neutral'],
        };
    };
    [$lbl, $cls] = $statusK($lihat);
@endphp
<x-panel :judul="$lihat->kode" :sub="$lihat->karyawan->nama . ' · ' . ($lihat->karyawan->departemen?->nama ?? '-')">
    <ul class="nav tab-nav mb-3" role="tablist">
        <li><a href="#tabDetail" class="active" data-bs-toggle="tab" role="tab">Detail</a></li>
        <li><a href="#tabRiwayat" data-bs-toggle="tab" role="tab">Riwayat <span class="badge badge-soft-neutral ms-1">{{ $riwayat->count() }}</span></a></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="tabDetail" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="badge {{ $cls }}">{{ $lbl }}</span>
                @if ($lihat->is_active && ($sisa = $lihat->sisaHari()) !== null)<span class="small {{ $sisa <= 90 ? 'text-warning-emphasis fw-semibold' : 'text-muted' }}">{{ $sisa >= 0 ? 'Berakhir dalam ' . $sisa . ' hari' : 'Sudah lewat' }}</span>@endif
            </div>
            @if ($lihat->sebelumnya)<div class="small text-muted mb-2">Perpanjangan dari <a href="{{ route('kontrak.index', ['lihat' => $lihat->sebelumnya->id]) }}" class="fw-semibold">{{ $lihat->sebelumnya->kode }}</a></div>@endif
            @include('kontrak._detail', ['kontrak' => $lihat])
            <div class="fw-semibold mt-4 mb-2">Approval</div>
            <x-riwayat-approval :approvals="$lihat->approvals" />
        </div>

        <div class="tab-pane fade" id="tabRiwayat" role="tabpanel">
            @foreach ($riwayat as $r)
                @php [$rl, $rc] = $statusK($r); $appr = $r->approvals->first(); @endphp
                <div class="versi {{ $r->id === $lihat->id ? 'aktif' : '' }} {{ $loop->last ? 'akhir' : '' }}">
                    <div class="garis"><span class="titik"></span></div>
                    <div class="kartu">
                        <div class="d-flex align-items-start gap-2">
                            <div class="flex-grow-1 min-w-0">
                                <div class="judul">{{ $r->kode }}</div>
                                <div class="rentang">{{ $r->jenis }} · {{ tgl($r->tgl_mulai, 'j M Y') }} – {{ $r->tgl_akhir ? tgl($r->tgl_akhir, 'j M Y') : 'tetap' }}</div>
                                <div class="rentang">Gaji pokok {{ rupiah($r->gaji_pokok) }}@if ($appr) · {{ $appr->status === 'menunggu' ? 'menunggu' : $appr->status }} oleh {{ $appr->approver?->namaTampil() ?? '-' }}@endif</div>
                            </div>
                            <span class="badge {{ $rc }}">{{ $rl }}</span>
                        </div>
                        @if ($r->id !== $lihat->id)
                            <a href="{{ route('kontrak.index', ['lihat' => $r->id]) }}" class="lihat mt-2">Lihat <i data-lucide="chevron-right"></i></a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="tombol">
        <a href="{{ $kembaliKe }}" class="btn btn-light">Tutup</a>
        @if ($lihat->status_approval === 'disetujui')<a href="{{ route('kontrak.pdf', $lihat) }}" class="btn btn-light" data-turbo="false"><i data-lucide="file-down"></i> PDF</a>@endif
        @if ($lihat->bisaDiperbarui())<a href="{{ route('kontrak.index', ['perbarui' => $lihat->id]) }}" class="btn btn-primary"><i data-lucide="refresh-cw"></i> Perbarui kontrak</a>
        @elseif ($lihat->bisaDiubah())<a href="{{ route('kontrak.index', ['ubah' => $lihat->id]) }}" class="btn btn-primary"><i data-lucide="pencil"></i> Revisi</a>@endif
    </div>
</x-panel>
