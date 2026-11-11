<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Engine;

/**
 * Aritmetika uang/angka dengan bcmath (string desimal), tanpa float.
 * Skala perhitungan antara SKALA digit; pembulatan half-up (menjauhi nol pada .5).
 */
final class Desimal
{
    public const SKALA = 20;

    /** Normalisasi string angka: "007.50" -> "7.5", "-0" -> "0". */
    public static function normal(string $angka): string
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', $angka)) {
            throw new RumusException("Bukan angka: {$angka}");
        }
        $neg = str_starts_with($angka, '-');
        $angka = ltrim($angka, '-');
        [$bulat, $pecahan] = array_pad(explode('.', $angka, 2), 2, '');
        $bulat = ltrim($bulat, '0') ?: '0';
        $pecahan = rtrim($pecahan, '0');
        $hasil = $pecahan === '' ? $bulat : "{$bulat}.{$pecahan}";

        return $neg && $hasil !== '0' ? "-{$hasil}" : $hasil;
    }

    public static function tambah(string $a, string $b): string
    {
        return self::normal(bcadd($a, $b, self::SKALA));
    }

    public static function kurang(string $a, string $b): string
    {
        return self::normal(bcsub($a, $b, self::SKALA));
    }

    public static function kali(string $a, string $b): string
    {
        return self::normal(bcmul($a, $b, self::SKALA));
    }

    public static function bagi(string $a, string $b): string
    {
        if (bccomp($b, '0', self::SKALA) === 0) {
            throw new RumusException('Pembagian dengan nol');
        }

        return self::normal(bcdiv($a, $b, self::SKALA));
    }

    public static function banding(string $a, string $b): int
    {
        return bccomp($a, $b, self::SKALA);
    }

    /** Bulatkan half-up ke $digit desimal: 2.5 -> 3, -2.5 -> -3, 1.005 (2 digit) -> 1.01. */
    public static function bulat(string $a, int $digit = 0): string
    {
        $a = self::normal($a);
        $setengah = '0.'.str_repeat('0', $digit).'5';
        $hasil = str_starts_with($a, '-') ? bcsub($a, $setengah, $digit) : bcadd($a, $setengah, $digit);

        return self::normal($hasil);
    }

    public static function bawah(string $a): string
    {
        $bulat = bcadd($a, '0', 0); // terpotong ke arah nol

        return self::normal(str_starts_with($a, '-') && bccomp($a, $bulat, self::SKALA) !== 0 ? bcsub($bulat, '1', 0) : $bulat);
    }

    public static function atas(string $a): string
    {
        $bulat = bcadd($a, '0', 0);

        return self::normal(! str_starts_with($a, '-') && bccomp($a, $bulat, self::SKALA) !== 0 ? bcadd($bulat, '1', 0) : $bulat);
    }

    /** Format uang 2 desimal untuk disimpan ke kolom DECIMAL(15,2). */
    public static function uang(string $a): string
    {
        return bcadd(self::normal($a), '0', 2);
    }
}
