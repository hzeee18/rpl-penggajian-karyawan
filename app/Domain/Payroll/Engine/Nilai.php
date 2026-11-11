<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Engine;

/** Nilai di mesin rumus: angka (string desimal), teks (termasuk tanggal YYYY-MM-DD), atau boolean. */
final readonly class Nilai
{
    private function __construct(public string $tipe, public string|bool $isi) {}

    public static function angka(string|int $n): self
    {
        return new self('angka', Desimal::normal((string) $n));
    }

    public static function teks(string $s): self
    {
        return new self('teks', $s);
    }

    public static function bool(bool $b): self
    {
        return new self('bool', $b);
    }

    public static function dari(mixed $v): self
    {
        return match (true) {
            $v instanceof self => $v,
            is_bool($v) => self::bool($v),
            is_int($v), is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v) === 1 => self::angka((string) $v),
            $v instanceof \DateTimeInterface => self::teks($v->format('Y-m-d')),
            $v === null => self::teks(''),
            default => self::teks((string) $v),
        };
    }

    public function sebagaiAngka(string $konteks = ''): string
    {
        if ($this->tipe === 'angka') {
            return (string) $this->isi;
        }
        if ($this->tipe === 'bool') {
            return $this->isi ? '1' : '0';
        }

        throw new RumusException('Nilai teks dipakai sebagai angka'.($konteks === '' ? '' : " ({$konteks})").': "'.$this->isi.'"');
    }

    public function sebagaiBool(): bool
    {
        return match ($this->tipe) {
            'bool' => (bool) $this->isi,
            'angka' => Desimal::banding((string) $this->isi, '0') !== 0,
            default => $this->isi !== '',
        };
    }

    public function sebagaiTeks(): string
    {
        return match ($this->tipe) {
            'bool' => $this->isi ? '1' : '0',
            default => (string) $this->isi,
        };
    }
}
