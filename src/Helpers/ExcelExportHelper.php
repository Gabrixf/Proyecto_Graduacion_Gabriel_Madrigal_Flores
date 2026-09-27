<?php

declare(strict_types=1);

namespace App\Helpers;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ExcelExportHelper
{
    /**
     * @param string[] $encabezados
     * @param array<int, array<int, mixed>> $filas
     */
    public function build(string $titulo, array $encabezados, array $filas): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($titulo, 0, 31));

        $sheet->fromArray($encabezados, null, 'A1');

        if ($filas !== []) {
            $sheet->fromArray($filas, null, 'A2');
        }

        return $spreadsheet;
    }

    /**
     * @param string[] $encabezados
     * @param array<int, array<int, mixed>> $filas
     */
    public function addSheet(Spreadsheet $spreadsheet, string $titulo, array $encabezados, array $filas): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(mb_substr($titulo, 0, 31));

        $sheet->fromArray($encabezados, null, 'A1');

        if ($filas !== []) {
            $sheet->fromArray($filas, null, 'A2');
        }
    }

    public function toBinaryString(Spreadsheet $spreadsheet): string
    {
        $writer = new Xlsx($spreadsheet);

        $stream = fopen('php://temp', 'r+b');
        $writer->save($stream);
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);

        return $bytes === false ? '' : $bytes;
    }
}
