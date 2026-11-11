<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Engine;

/**
 * Parser rumus -> AST (array). Tidak ada eval / eksekusi PHP dari database.
 *
 * Sintaks:
 *   angka 1500000 atau 0.25 · teks 'cuti' · TRUE FALSE
 *   variabel: huruf kecil/angka/_ (hadir, uang_makan); field baris sumber: row.late_hours
 *   operator: + - * / ( )  = != <> < <= > >=  AND OR NOT
 *   fungsi (huruf bebas): IF MIN MAX ROUND FLOOR CEIL ABS WORKDAYS DAYS MONTHS_BETWEEN LOOKUP LOOKUP_TEXT CONTRACT
 *
 * Node: ['angka', '1.5'] ['teks', 'x'] ['bool', true] ['var', 'nama'] ['fungsi', 'IF', [args]]
 *       ['un', '-'|'NOT', node] ['bin', op, kiri, kanan]
 */
final class Parser
{
    public const FUNGSI = [
        'IF' => [3, 3], 'MIN' => [1, 50], 'MAX' => [1, 50], 'ROUND' => [1, 2], 'FLOOR' => [1, 1], 'CEIL' => [1, 1], 'ABS' => [1, 1],
        'WORKDAYS' => [2, 2], 'DAYS' => [2, 2], 'MONTHS_BETWEEN' => [2, 2],
        'LOOKUP' => [3, 3], 'LOOKUP_TEXT' => [2, 3], 'CONTRACT' => [1, 1],
    ];

    public const MAKS_PANJANG = 2000;

    /** @var list<array{0: string, 1: string, 2: int}> [jenis, isi, posisi] */
    private array $tok = [];

    private int $i = 0;

    /** @var array<string, array<mixed>> cache AST per teks rumus */
    private static array $cache = [];

    /** @return array<mixed> */
    public static function parse(string $rumus): array
    {
        if (isset(self::$cache[$rumus])) {
            return self::$cache[$rumus];
        }
        if (trim($rumus) === '') {
            throw new RumusException('Rumus kosong');
        }
        if (strlen($rumus) > self::MAKS_PANJANG) {
            throw new RumusException('Rumus terlalu panjang (maks. '.self::MAKS_PANJANG.' karakter)');
        }

        $p = new self;
        $p->tok = self::token($rumus);
        $ast = $p->atau();
        if ($p->i < count($p->tok)) {
            throw new RumusException('Bagian rumus tidak dikenali: "'.$p->tok[$p->i][1].'" (posisi '.($p->tok[$p->i][2] + 1).')');
        }

        return self::$cache[$rumus] = $ast;
    }

    /**
     * Semua rujukan di rumus: variabel, kode komponen kontrak, kode tabel referensi.
     *
     * @return array{var: list<string>, kontrak: list<string>, tabel: list<string>}
     */
    public static function rujukan(string $rumus): array
    {
        $hasil = ['var' => [], 'kontrak' => [], 'tabel' => []];
        $jalan = function (array $n) use (&$jalan, &$hasil): void {
            switch ($n[0]) {
                case 'var':
                    $hasil['var'][] = $n[1];
                    break;
                case 'fungsi':
                    if ($n[1] === 'CONTRACT' && $n[2][0][0] === 'teks') {
                        $hasil['kontrak'][] = $n[2][0][1];
                    }
                    if (in_array($n[1], ['LOOKUP', 'LOOKUP_TEXT'], true) && $n[2][0][0] === 'teks') {
                        $hasil['tabel'][] = $n[2][0][1];
                    }
                    foreach ($n[2] as $a) {
                        $jalan($a);
                    }
                    break;
                case 'un':
                    $jalan($n[2]);
                    break;
                case 'bin':
                    $jalan($n[2]);
                    $jalan($n[3]);
                    break;
            }
        };
        $jalan(self::parse($rumus));

        return array_map(fn ($l) => array_values(array_unique($l)), $hasil);
    }

    /** @return list<array{0: string, 1: string, 2: int}> */
    private static function token(string $s): array
    {
        $pola = '/\G\s*(?:(?<angka>\d+(?:\.\d+)?)|(?<teks>\'(?:[^\']|\'\')*\')|(?<nama>[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?)|(?<op><=|>=|!=|<>|[-+*\/(),=<>]))/A';
        $hasil = [];
        $pos = 0;
        $panjang = strlen($s);
        while ($pos < $panjang) {
            if (trim(substr($s, $pos)) === '') {
                break;
            }
            if (! preg_match($pola, $s, $m, 0, $pos)) {
                $sisa = ltrim(substr($s, $pos));
                throw new RumusException('Karakter tidak dikenali: "'.mb_substr($sisa, 0, 1).'" (posisi '.($panjang - strlen($sisa) + 1).')');
            }
            $mulai = $pos + strlen($m[0]) - strlen(ltrim($m[0]));
            if (($m['angka'] ?? '') !== '') {
                $hasil[] = ['angka', $m['angka'], $mulai];
            } elseif (($m['teks'] ?? '') !== '') {
                $hasil[] = ['teks', str_replace("''", "'", substr($m['teks'], 1, -1)), $mulai];
            } elseif (($m['nama'] ?? '') !== '') {
                $hasil[] = ['nama', $m['nama'], $mulai];
            } else {
                $hasil[] = ['op', $m['op'] === '<>' ? '!=' : $m['op'], $mulai];
            }
            $pos += strlen($m[0]);
        }

        return $hasil;
    }

    private function lihat(): ?array
    {
        return $this->tok[$this->i] ?? null;
    }

    private function kata(string $k): bool
    {
        $t = $this->lihat();

        return $t !== null && $t[0] === 'nama' && strtoupper($t[1]) === $k;
    }

    private function op(string ...$ops): ?string
    {
        $t = $this->lihat();
        if ($t !== null && $t[0] === 'op' && in_array($t[1], $ops, true)) {
            $this->i++;

            return $t[1];
        }

        return null;
    }

    private function harus(string $op): void
    {
        if ($this->op($op) === null) {
            $t = $this->lihat();
            throw new RumusException("Diharapkan \"{$op}\"".($t ? ' tetapi ada "'.$t[1].'" (posisi '.($t[2] + 1).')' : ' di akhir rumus'));
        }
    }

    private function atau(): array
    {
        $kiri = $this->dan();
        while ($this->kata('OR')) {
            $this->i++;
            $kiri = ['bin', 'OR', $kiri, $this->dan()];
        }

        return $kiri;
    }

    private function dan(): array
    {
        $kiri = $this->bukan();
        while ($this->kata('AND')) {
            $this->i++;
            $kiri = ['bin', 'AND', $kiri, $this->bukan()];
        }

        return $kiri;
    }

    private function bukan(): array
    {
        if ($this->kata('NOT')) {
            $this->i++;

            return ['un', 'NOT', $this->bukan()];
        }

        return $this->banding();
    }

    private function banding(): array
    {
        $kiri = $this->tambah();
        if (($op = $this->op('=', '!=', '<', '<=', '>', '>=')) !== null) {
            return ['bin', $op, $kiri, $this->tambah()];
        }

        return $kiri;
    }

    private function tambah(): array
    {
        $kiri = $this->kali();
        while (($op = $this->op('+', '-')) !== null) {
            $kiri = ['bin', $op, $kiri, $this->kali()];
        }

        return $kiri;
    }

    private function kali(): array
    {
        $kiri = $this->unari();
        while (($op = $this->op('*', '/')) !== null) {
            $kiri = ['bin', $op, $kiri, $this->unari()];
        }

        return $kiri;
    }

    private function unari(): array
    {
        if ($this->op('-') !== null) {
            return ['un', '-', $this->unari()];
        }
        if ($this->op('+') !== null) {
            return $this->unari();
        }

        return $this->dasar();
    }

    private function dasar(): array
    {
        $t = $this->lihat();
        if ($t === null) {
            throw new RumusException('Rumus berakhir terlalu cepat');
        }
        if ($this->op('(') !== null) {
            $isi = $this->atau();
            $this->harus(')');

            return $isi;
        }
        $this->i++;
        if ($t[0] === 'angka') {
            return ['angka', Desimal::normal($t[1])];
        }
        if ($t[0] === 'teks') {
            return ['teks', $t[1]];
        }
        if ($t[0] === 'nama') {
            $besar = strtoupper($t[1]);
            if ($besar === 'TRUE' || $besar === 'FALSE') {
                return ['bool', $besar === 'TRUE'];
            }
            if (in_array($besar, ['AND', 'OR', 'NOT'], true)) {
                throw new RumusException("\"{$t[1]}\" tidak boleh di posisi ini (posisi ".($t[2] + 1).')');
            }
            if ($this->op('(') !== null) {
                if (! isset(self::FUNGSI[$besar])) {
                    throw new RumusException("Fungsi tidak dikenal: {$t[1]}");
                }
                $args = [];
                if ($this->op(')') === null) {
                    do {
                        $args[] = $this->atau();
                    } while ($this->op(',') !== null);
                    $this->harus(')');
                }
                [$min, $max] = self::FUNGSI[$besar];
                if (count($args) < $min || count($args) > $max) {
                    throw new RumusException("Jumlah argumen {$besar}() harus ".($min === $max ? $min : "{$min}-{$max}").', ada '.count($args));
                }

                return ['fungsi', $besar, $args];
            }

            return ['var', strtolower($t[1])];
        }

        throw new RumusException('Tidak diharapkan: "'.$t[1].'" (posisi '.($t[2] + 1).')');
    }
}
