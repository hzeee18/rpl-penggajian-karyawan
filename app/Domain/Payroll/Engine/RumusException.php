<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Engine;

use RuntimeException;

/** Kesalahan rumus (sintaks, rujukan tidak dikenal, tipe, pembagian nol). Pesan berbahasa Indonesia untuk HR. */
final class RumusException extends RuntimeException {}
