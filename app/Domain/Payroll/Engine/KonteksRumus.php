<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Engine;

/** Sumber nilai saat rumus dievaluasi (variabel, hari kerja, tabel referensi, kontrak). */
interface KonteksRumus
{
    /** Nilai variabel/parameter/komponen/field baris; null = tidak dikenal. */
    public function nilai(string $nama): ?Nilai;

    /** Jumlah hari Senin-Jumat di [mulai, selesai] dikurangi hari libur perusahaan. */
    public function hariKerja(string $mulai, string $selesai): int;

    /** Hasil tabel referensi: angka (LOOKUP) atau teks (LOOKUP_TEXT). Null = tidak ada baris cocok. */
    public function lookup(string $tabel, string $kunci, ?string $nilai, bool $teks): ?string;

    /** Nilai komponen di kontrak aktif (0 bila komponen tidak ada di kontrak). */
    public function kontrak(string $kodeKomponen): string;
}
