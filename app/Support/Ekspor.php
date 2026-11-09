<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Ekspor tabel sederhana ke Excel (tombol "Export" di Figma). */
class Ekspor
{
    public static function excel(string $namaFile, string $judul, array $header, iterable $baris)
    {
        $sheet = ($book = new Spreadsheet())->getActiveSheet();
        $sheet->setTitle(mb_substr(preg_replace('/[^A-Za-z0-9 ]/', '', $judul), 0, 30) ?: 'Data');
        $sheet->fromArray([[$judul], [], $header]);
        $n = 4;
        foreach ($baris as $b) {
            $sheet->fromArray(array_values($b), null, 'A' . $n++, true);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('3:3')->getFont()->setBold(true);
        foreach (range(1, count($header)) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), $namaFile . '.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
