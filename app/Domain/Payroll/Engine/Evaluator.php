<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Engine;

use Carbon\CarbonImmutable;

/** Evaluasi AST rumus dengan KonteksRumus. Semua angka bcmath (string), tanpa float. */
final class Evaluator
{
    public function __construct(private readonly KonteksRumus $konteks) {}

    public function hitung(string $rumus): Nilai
    {
        return $this->eval(Parser::parse($rumus));
    }

    /** Hitung dan kembalikan angka (string desimal). */
    public function angka(string $rumus): string
    {
        return $this->hitung($rumus)->sebagaiAngka();
    }

    /** @param array<mixed> $n */
    private function eval(array $n): Nilai
    {
        return match ($n[0]) {
            'angka' => Nilai::angka($n[1]),
            'teks' => Nilai::teks($n[1]),
            'bool' => Nilai::bool($n[1]),
            'var' => $this->konteks->nilai($n[1]) ?? throw new RumusException("Variabel tidak dikenal: {$n[1]}"),
            'un' => $n[1] === 'NOT'
                ? Nilai::bool(! $this->eval($n[2])->sebagaiBool())
                : Nilai::angka(Desimal::kurang('0', $this->eval($n[2])->sebagaiAngka('-'))),
            'bin' => $this->biner($n[1], $n[2], $n[3]),
            'fungsi' => $this->fungsi($n[1], $n[2]),
        };
    }

    private function biner(string $op, array $kiri, array $kanan): Nilai
    {
        if ($op === 'AND') {
            return Nilai::bool($this->eval($kiri)->sebagaiBool() && $this->eval($kanan)->sebagaiBool());
        }
        if ($op === 'OR') {
            return Nilai::bool($this->eval($kiri)->sebagaiBool() || $this->eval($kanan)->sebagaiBool());
        }

        $a = $this->eval($kiri);
        $b = $this->eval($kanan);

        if (in_array($op, ['=', '!=', '<', '<=', '>', '>='], true)) {
            $c = $a->tipe !== 'teks' && $b->tipe !== 'teks'
                ? Desimal::banding($a->sebagaiAngka(), $b->sebagaiAngka())
                : strcmp($a->sebagaiTeks(), $b->sebagaiTeks()) <=> 0;

            return Nilai::bool(match ($op) {
                '=' => $c === 0, '!=' => $c !== 0, '<' => $c < 0, '<=' => $c <= 0, '>' => $c > 0, '>=' => $c >= 0,
            });
        }

        $x = $a->sebagaiAngka($op);
        $y = $b->sebagaiAngka($op);

        return Nilai::angka(match ($op) {
            '+' => Desimal::tambah($x, $y),
            '-' => Desimal::kurang($x, $y),
            '*' => Desimal::kali($x, $y),
            '/' => Desimal::bagi($x, $y),
        });
    }

    /** @param list<array<mixed>> $args */
    private function fungsi(string $f, array $args): Nilai
    {
        if ($f === 'IF') { // malas: hanya cabang terpilih yang dievaluasi
            return $this->eval($this->eval($args[0])->sebagaiBool() ? $args[1] : $args[2]);
        }

        $v = array_map(fn ($a) => $this->eval($a), $args);
        $angka = fn (int $i) => $v[$i]->sebagaiAngka($f);

        switch ($f) {
            case 'MIN':
            case 'MAX':
                $hasil = $angka(0);
                foreach ($v as $i => $_) {
                    $c = Desimal::banding($angka($i), $hasil);
                    if (($f === 'MIN' && $c < 0) || ($f === 'MAX' && $c > 0)) {
                        $hasil = $angka($i);
                    }
                }

                return Nilai::angka($hasil);
            case 'ROUND':
                $digit = isset($v[1]) ? (int) Desimal::bulat($angka(1)) : 0;
                if ($digit < 0 || $digit > 6) {
                    throw new RumusException('ROUND: jumlah digit 0-6');
                }

                return Nilai::angka(Desimal::bulat($angka(0), $digit));
            case 'FLOOR':
                return Nilai::angka(Desimal::bawah($angka(0)));
            case 'CEIL':
                return Nilai::angka(Desimal::atas($angka(0)));
            case 'ABS':
                return Nilai::angka(ltrim($angka(0), '-'));
            case 'WORKDAYS':
                return Nilai::angka($this->konteks->hariKerja($this->tanggal($v[0], $f), $this->tanggal($v[1], $f)));
            case 'DAYS':
                $a = CarbonImmutable::parse($this->tanggal($v[0], $f));
                $b = CarbonImmutable::parse($this->tanggal($v[1], $f));

                return Nilai::angka($b->lt($a) ? 0 : (int) $a->diffInDays($b) + 1);
            case 'MONTHS_BETWEEN':
                $a = CarbonImmutable::parse($this->tanggal($v[0], $f));
                $b = CarbonImmutable::parse($this->tanggal($v[1], $f));

                return Nilai::angka($b->lt($a) ? 0 : (int) floor($a->diffInMonths($b)));
            case 'LOOKUP':
            case 'LOOKUP_TEXT':
                $teks = $f === 'LOOKUP_TEXT';
                $hasil = $this->konteks->lookup($v[0]->sebagaiTeks(), $v[1]->sebagaiTeks(), isset($v[2]) ? $v[2]->sebagaiAngka($f) : null, $teks);
                if ($hasil === null) {
                    throw new RumusException("{$f}: tidak ada baris cocok di tabel '{$v[0]->sebagaiTeks()}' untuk kunci '{$v[1]->sebagaiTeks()}'".(isset($v[2]) ? " dan nilai {$v[2]->sebagaiTeks()}" : ''));
                }

                return $teks ? Nilai::teks($hasil) : Nilai::angka($hasil);
            case 'CONTRACT':
                return Nilai::angka($this->konteks->kontrak($v[0]->sebagaiTeks()));
        }

        throw new RumusException("Fungsi tidak dikenal: {$f}");
    }

    private function tanggal(Nilai $n, string $f): string
    {
        $t = $n->sebagaiTeks();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) {
            throw new RumusException("{$f}: argumen harus tanggal YYYY-MM-DD, ada \"{$t}\"");
        }

        return $t;
    }
}
