<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll;

use App\Domain\Payroll\Engine\Desimal;
use App\Domain\Payroll\Engine\Evaluator;
use App\Domain\Payroll\Engine\KonteksRumus;
use App\Domain\Payroll\Engine\Nilai;
use App\Domain\Payroll\Engine\Parser;
use App\Domain\Payroll\Engine\RumusException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Fase 5: parser dan evaluator rumus (tanpa database). */
final class MesinRumusTest extends TestCase
{
    private function evaluator(array $vars = [], array $kontrak = [], array $libur = []): Evaluator
    {
        $konteks = new class($vars, $kontrak, $libur) implements KonteksRumus
        {
            public function __construct(private array $vars, private array $kontrak, private array $libur) {}

            public function nilai(string $nama): ?Nilai
            {
                return array_key_exists($nama, $this->vars) ? Nilai::dari($this->vars[$nama]) : null;
            }

            public function hariKerja(string $mulai, string $selesai): int
            {
                $n = 0;
                for ($d = new \DateTimeImmutable($mulai); $d <= new \DateTimeImmutable($selesai); $d = $d->modify('+1 day')) {
                    $n += (int) $d->format('N') <= 5 && ! in_array($d->format('Y-m-d'), $this->libur, true) ? 1 : 0;
                }

                return $n;
            }

            public function lookup(string $tabel, string $kunci, ?string $nilai, bool $teks): ?string
            {
                // tabel uji fiktif (bukan angka TER resmi)
                $baris = ['uji_tarif' => [['A', '0', '1000', '0.01', null], ['A', '1000.01', null, '0.02', null]], 'uji_kategori' => [['X/0', null, null, null, 'A']]];
                foreach ($baris[$tabel] ?? [] as [$k, $dari, $sampai, $angka, $hasilTeks]) {
                    if ($k !== $kunci) {
                        continue;
                    }
                    if ($nilai !== null && (($dari !== null && bccomp($nilai, $dari, 10) < 0) || ($sampai !== null && bccomp($nilai, $sampai, 10) > 0))) {
                        continue;
                    }

                    return $teks ? $hasilTeks : $angka;
                }

                return null;
            }

            public function kontrak(string $kodeKomponen): string
            {
                return $this->kontrak[$kodeKomponen] ?? '0';
            }
        };

        return new Evaluator($konteks);
    }

    /** @return array<string, array{string, string}> */
    public static function kasusAngka(): array
    {
        return [
            'presedensi' => ['2 + 3 * 4', '14'],
            'kurung' => ['(2 + 3) * 4', '20'],
            'desimal tepat' => ['0.1 + 0.2', '0.3'],
            'bagi' => ['10 / 4', '2.5'],
            'unari' => ['-3 + 5', '2'],
            'unari ganda' => ['--3', '3'],
            'kiri ke kanan' => ['100 - 10 - 5', '85'],
            'bagi berantai' => ['ROUND(600000 / 22 * 20, 4)', '545454.5455'],
            'uang makan dibulatkan' => ['ROUND(600000 / 22 * 20)', '545455'],
            'round half up' => ['ROUND(2.5)', '3'],
            'round negatif' => ['ROUND(-2.5)', '-3'],
            'round 1.4' => ['ROUND(1.4)', '1'],
            'round digit' => ['ROUND(1.005, 2)', '1.01'],
            'floor negatif' => ['FLOOR(-1.5)', '-2'],
            'ceil' => ['CEIL(1.01)', '2'],
            'abs' => ['ABS(-7.25)', '7.25'],
            'min max' => ['MIN(5, 2, 9) + MAX(1, 3)', '5'],
            'if benar' => ['IF(1 > 0, 10, 20)', '10'],
            'if malas' => ['IF(TRUE, 1, 1 / 0)', '1'],
            'and or not' => ['IF(NOT (1 = 2) AND (FALSE OR TRUE), 1, 0)', '1'],
            'bool jadi angka' => ['(3 > 2) + (2 > 3)', '1'],
            'perbandingan teks' => ["IF('cuti' = 'cuti', 1, 0)", '1'],
            'tidak sama' => ["IF('a' <> 'b', 1, 0)", '1'],
            'hari kerja minus libur' => ["WORKDAYS('2026-11-02', '2026-11-08')", '4'],
            'hari kalender' => ["DAYS('2026-02-01', '2026-02-28')", '28'],
            'bulan' => ["MONTHS_BETWEEN('2025-11-15', '2026-11-14')", '11'],
            'lookup batas bawah' => ["LOOKUP('uji_tarif', 'A', 1000)", '0.01'],
            'lookup di atas batas' => ["LOOKUP('uji_tarif', 'A', 1000.01)", '0.02'],
            'kontrak ada' => ["ROUND(CONTRACT('uang_makan') / 22, 2)", '27272.73'],
            'kontrak tidak ada = 0' => ["CONTRACT('tunjangan_x') * 5", '0'],
            'variabel' => ['hadir + cuti', '21'],
            'nama fungsi bebas huruf' => ['round(2.5) + Max(1, 2)', '5'],
        ];
    }

    #[DataProvider('kasusAngka')]
    public function test_hasil_angka(string $rumus, string $harapan): void
    {
        $e = $this->evaluator(['hadir' => '19', 'cuti' => 2], ['uang_makan' => '600000'], ['2026-11-04']);
        $hasil = $e->angka($rumus);
        $this->assertSame($harapan, $hasil, $rumus);
    }

    public function test_lookup_text_dan_teks_dengan_kutip(): void
    {
        $e = $this->evaluator(['ptkp' => 'X/0']);
        $this->assertSame('A', $e->hitung("LOOKUP_TEXT('uji_kategori', ptkp)")->sebagaiTeks());
        $this->assertSame("Jum'at", $e->hitung("'Jum''at'")->sebagaiTeks());
    }

    /** @return array<string, array{string, string}> */
    public static function kasusGagal(): array
    {
        return [
            'variabel tak dikenal' => ['gaji_rahasia * 2', 'Variabel tidak dikenal: gaji_rahasia'],
            'fungsi tak dikenal' => ['SYSTEM(1)', 'Fungsi tidak dikenal'],
            'injeksi php' => ['exec(\'rm -rf\')', 'Fungsi tidak dikenal'],
            'tanda dolar' => ['$x + 1', 'Karakter tidak dikenali'],
            'titik koma' => ['1; 2', 'Karakter tidak dikenali'],
            'kurung tidak tutup' => ['(1 + 2', 'Diharapkan ")"'],
            'operator menggantung' => ['1 +', 'Rumus berakhir terlalu cepat'],
            'argumen salah' => ['IF(1, 2)', 'Jumlah argumen IF() harus 3'],
            'bagi nol' => ['5 / (2 - 2)', 'Pembagian dengan nol'],
            'teks jadi angka' => ["'abc' + 1", 'Nilai teks dipakai sebagai angka'],
            'lookup tidak cocok' => ["LOOKUP('uji_tarif', 'Z', 5)", 'tidak ada baris cocok'],
            'tanggal salah' => ["WORKDAYS('kemarin', '2026-11-08')", 'harus tanggal'],
            'kosong' => ['   ', 'Rumus kosong'],
            'sisa token' => ['1 2', 'Bagian rumus tidak dikenali'],
        ];
    }

    #[DataProvider('kasusGagal')]
    public function test_rumus_salah_ditolak_dengan_pesan_jelas(string $rumus, string $pesan): void
    {
        $this->expectException(RumusException::class);
        $this->expectExceptionMessage($pesan);
        $this->evaluator()->hitung($rumus);
    }

    public function test_rujukan_untuk_validasi_urutan(): void
    {
        $r = Parser::rujukan("ROUND(CONTRACT('uang_makan') / hari_kerja * (hadir + cuti)) + LOOKUP('ter', kategori, bruto) + row.late_hours");

        $this->assertSame(['hari_kerja', 'hadir', 'cuti', 'kategori', 'bruto', 'row.late_hours'], $r['var']);
        $this->assertSame(['uang_makan'], $r['kontrak']);
        $this->assertSame(['ter'], $r['tabel']);
    }

    public function test_desimal_tanpa_float(): void
    {
        $this->assertSame('99999999999999.99', Desimal::uang('99999999999999.99'));
        $this->assertSame('0.30', Desimal::uang(Desimal::tambah('0.1', '0.2')));
        $this->assertSame('3', Desimal::normal('003.000'));
        $this->assertSame('0', Desimal::normal('-0.00'));
    }
}
