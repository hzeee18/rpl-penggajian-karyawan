<?php

namespace App\Support;

use RuntimeException;

/**
 * Penghitung rumus komponen gaji yang aman (tanpa eval).
 *
 * Contoh: "GP * 15% / HK * HADIR", "MIN(MASA_KERJA, 12) / 12 * GP", "ROUND(GP / 173 * JAM_LEMBUR, 0)".
 * - Angka: titik ribuan (1.500.000) atau titik desimal (1.5). Koma hanya pemisah isian fungsi.
 * - Operator: + - * / dan kurung. "15%" artinya 0,15.
 * - Fungsi: SUM, MIN, MAX, ROUND(x[, digit]), FLOOR, CEIL, ABS, IF(kondisi, ya, tidak) dengan kondisi > < >= <= = !=
 * - Nama variabel / kode komponen: huruf besar, angka, garis bawah (GP, UM, JAM_LEMBUR).
 */
class Rumus
{
    public const FUNGSI = ['SUM', 'MIN', 'MAX', 'ROUND', 'FLOOR', 'CEIL', 'ABS', 'IF'];

    /** Variabel bawaan yang selalu tersedia untuk setiap karyawan. */
    public const VARIABEL = [
        'GP' => 'Gaji pokok di kontrak',
        'HK' => 'Hari kerja periode',
        'HADIR' => 'Hari hadir (termasuk terlambat)',
        'TERLAMBAT' => 'Hari terlambat',
        'SAKIT' => 'Hari sakit',
        'IZIN' => 'Hari izin',
        'CUTI' => 'Hari cuti',
        'ALFA' => 'Hari alfa',
        'JAM_LEMBUR' => 'Total jam lembur',
        'JAM_LEMBUR_1' => 'Jam lembur pertama tiap hari',
        'JAM_LEMBUR_LANJUT' => 'Jam lembur setelah jam pertama',
        'HARI_LEMBUR' => 'Hari lembur',
        'MASA_KERJA' => 'Masa kerja (bulan)',
        'NILAI' => 'Nilai khusus di kontrak karyawan (kalau diisi)',
    ];

    private array $tok = [];
    private int $i = 0;

    /** Hitung rumus dengan nilai variabel. */
    public static function hitung(string $rumus, array $vars): float
    {
        $r = new self();
        $r->tok = self::token($rumus);
        $r->i = 0;
        $v = $r->ekspresi($vars);
        if ($r->i < count($r->tok)) {
            throw new RuntimeException('Ada bagian rumus yang tidak dikenali: "' . $r->tok[$r->i][1] . '".');
        }

        return is_finite($v) ? $v : 0.0;
    }

    /** Kode/variabel yang dipakai di rumus (untuk cek urutan & referensi melingkar). */
    public static function referensi(string $rumus): array
    {
        $tok = self::token($rumus);
        $hasil = [];
        foreach ($tok as $k => [$jenis, $nilai]) {
            if ($jenis === 'id' && ($tok[$k + 1][1] ?? null) !== '(') {
                $hasil[] = $nilai;
            }
        }

        return array_values(array_unique($hasil));
    }

    /** Cek rumus valid. Return pesan error atau null. $dikenal = daftar nama yang boleh dipakai. */
    public static function cek(string $rumus, array $dikenal): ?string
    {
        if (trim($rumus) === '') {
            return 'Rumus tidak boleh kosong.';
        }
        try {
            $asing = array_diff(self::referensi($rumus), $dikenal);
            if ($asing) {
                return 'Kode/variabel tidak dikenal: ' . implode(', ', $asing) . '.';
            }
            self::hitung($rumus, array_fill_keys($dikenal, 1));
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Urutkan komponen supaya yang dirujuk dihitung lebih dulu.
     * $graf = [kode => [kode lain yang dirujuk]]. Melempar error kalau ada rujukan melingkar.
     */
    public static function urutan(array $graf): array
    {
        $hasil = [];
        $status = [];
        $kunjungi = function ($kode, $jejak) use (&$kunjungi, &$hasil, &$status, $graf) {
            if (($status[$kode] ?? null) === 'selesai') return;
            if (($status[$kode] ?? null) === 'proses') {
                throw new RuntimeException('Rumus saling merujuk (melingkar): ' . implode(' → ', [...$jejak, $kode]) . '.');
            }
            $status[$kode] = 'proses';
            foreach ($graf[$kode] ?? [] as $dep) {
                if (isset($graf[$dep])) $kunjungi($dep, [...$jejak, $kode]);
            }
            $status[$kode] = 'selesai';
            $hasil[] = $kode;
        };
        foreach (array_keys($graf) as $kode) {
            $kunjungi($kode, []);
        }

        return $hasil;
    }

    // ---------------- parser ----------------

    private static function token(string $s): array
    {
        $tok = [];
        $n = strlen($s);
        for ($i = 0; $i < $n;) {
            $c = $s[$i];
            if (ctype_space($c)) { $i++; continue; }
            if (ctype_digit($c) || ($c === '.' && ctype_digit($s[$i + 1] ?? ''))) {
                preg_match('/\G[0-9]+(?:\.[0-9]+)*|\G\.[0-9]+/', $s, $m, 0, $i);
                $tok[] = ['num', self::angka($m[0])];
                $i += strlen($m[0]);
                continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $s, $m, 0, $i);
                $tok[] = ['id', strtoupper($m[0])];
                $i += strlen($m[0]);
                continue;
            }
            $dua = substr($s, $i, 2);
            if (in_array($dua, ['>=', '<=', '!=', '=='], true)) { $tok[] = ['op', $dua === '==' ? '=' : $dua]; $i += 2; continue; }
            $ch = mb_substr(substr($s, $i), 0, 1);
            if (in_array($ch, ['+', '-', '*', '/', '(', ')', '%', ',', '<', '>', '=', '×', '÷'], true)) {
                $tok[] = ['op', ['×' => '*', '÷' => '/'][$ch] ?? $ch];
                $i += strlen($ch);
                continue;
            }
            throw new RuntimeException('Karakter "' . mb_substr(substr($s, $i), 0, 1) . '" tidak boleh dipakai di rumus.');
        }

        return $tok;
    }

    /** "1.500.000" / "500.000" => ribuan, "1.5" / "0.15" => desimal. Koma hanya pemisah isian fungsi. */
    private static function angka(string $t): float
    {
        if (substr_count($t, '.') > 1 || preg_match('/^\d{1,3}(\.\d{3})+$/', $t)) {
            return (float) str_replace('.', '', $t);
        }

        return (float) $t;
    }

    private function lihat(): ?string { return $this->tok[$this->i][1] ?? null; }

    private function ambil(?string $harap = null): array
    {
        $t = $this->tok[$this->i] ?? null;
        if (! $t) throw new RuntimeException('Rumus belum selesai' . ($harap ? ', kurang "' . $harap . '"' : '') . '.');
        if ($harap !== null && $t[1] !== $harap) throw new RuntimeException('Harusnya "' . $harap . '" tapi ketemu "' . $t[1] . '".');
        $this->i++;

        return $t;
    }

    private function ekspresi(array $v): float
    {
        $kiri = $this->suku($v);
        while (in_array($this->lihat(), ['+', '-'], true)) {
            $op = $this->ambil()[1];
            $kanan = $this->suku($v);
            $kiri = $op === '+' ? $kiri + $kanan : $kiri - $kanan;
        }

        return $kiri;
    }

    private function suku(array $v): float
    {
        $kiri = $this->faktor($v);
        while (in_array($this->lihat(), ['*', '/'], true)) {
            $op = $this->ambil()[1];
            $kanan = $this->faktor($v);
            $kiri = $op === '*' ? $kiri * $kanan : ($kanan == 0 ? 0.0 : $kiri / $kanan);
        }

        return $kiri;
    }

    private function faktor(array $v): float
    {
        if ($this->lihat() === '-') { $this->ambil(); return -$this->faktor($v); }
        if ($this->lihat() === '+') { $this->ambil(); return $this->faktor($v); }
        $x = $this->dasar($v);
        while ($this->lihat() === '%') { $this->ambil(); $x /= 100; }

        return $x;
    }

    private function dasar(array $v): float
    {
        [$jenis, $nilai] = $this->ambil();
        if ($jenis === 'num') return $nilai;
        if ($nilai === '(') { $x = $this->ekspresi($v); $this->ambil(')'); return $x; }
        if ($jenis === 'id') {
            if ($this->lihat() === '(') {
                if (! in_array($nilai, self::FUNGSI, true)) throw new RuntimeException('Fungsi ' . $nilai . ' tidak dikenal. Pakai: ' . implode(', ', self::FUNGSI) . '.');
                $this->ambil('(');
                $arg = [];
                if ($nilai === 'IF') {
                    $arg[] = $this->kondisi($v); $this->ambil(','); $arg[] = $this->ekspresi($v); $this->ambil(','); $arg[] = $this->ekspresi($v);
                } elseif ($this->lihat() !== ')') {
                    $arg[] = $this->ekspresi($v);
                    while ($this->lihat() === ',') { $this->ambil(); $arg[] = $this->ekspresi($v); }
                }
                $this->ambil(')');

                return $this->fungsi($nilai, $arg);
            }
            if (! array_key_exists($nilai, $v)) throw new RuntimeException('Kode/variabel ' . $nilai . ' tidak dikenal.');

            return (float) $v[$nilai];
        }
        throw new RuntimeException('Tanda "' . $nilai . '" tidak di tempat yang benar.');
    }

    private function kondisi(array $v): float
    {
        $a = $this->ekspresi($v);
        $op = $this->lihat();
        if (! in_array($op, ['>', '<', '>=', '<=', '=', '!='], true)) return $a != 0 ? 1.0 : 0.0;
        $this->ambil();
        $b = $this->ekspresi($v);

        return (float) match ($op) { '>' => $a > $b, '<' => $a < $b, '>=' => $a >= $b, '<=' => $a <= $b, '=' => abs($a - $b) < 1e-9, '!=' => abs($a - $b) >= 1e-9 };
    }

    private function fungsi(string $f, array $a): float
    {
        $butuh = ['ROUND' => [1, 2], 'FLOOR' => [1, 1], 'CEIL' => [1, 1], 'ABS' => [1, 1], 'IF' => [3, 3], 'MIN' => [1, 99], 'MAX' => [1, 99], 'SUM' => [1, 99]][$f];
        if (count($a) < $butuh[0] || count($a) > $butuh[1]) throw new RuntimeException('Jumlah isian ' . $f . '() salah.');

        return match ($f) {
            'SUM' => array_sum($a), 'MIN' => min($a), 'MAX' => max($a), 'ROUND' => round($a[0], (int) ($a[1] ?? 0)),
            'FLOOR' => floor($a[0]), 'CEIL' => ceil($a[0]), 'ABS' => abs($a[0]), 'IF' => $a[0] ? $a[1] : $a[2],
        };
    }
}
