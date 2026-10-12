<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\ExcelExportHelper;
use PHPUnit\Framework\TestCase;

final class ExcelExportHelperTest extends TestCase
{
    private function helper(): ExcelExportHelper
    {
        return new ExcelExportHelper();
    }

    public function testBuildEscribeEncabezadosEnLaPrimeraFila(): void
    {
        $sheet = $this->helper()
            ->build('Planilla', ['Nombre', 'Salario'], [])
            ->getActiveSheet();

        self::assertSame('Nombre', $sheet->getCell('A1')->getValue());
        self::assertSame('Salario', $sheet->getCell('B1')->getValue());
    }

    public function testBuildEscribeLasFilasDeDatosApartirDeLaSegundaFila(): void
    {
        $sheet = $this->helper()
            ->build('Planilla', ['Nombre', 'Salario'], [
                ['Juan Pérez', 225000.0],
                ['María González', 225000.0],
            ])
            ->getActiveSheet();

        self::assertSame('Juan Pérez', $sheet->getCell('A2')->getValue());
        self::assertEqualsWithDelta(225000.0, $sheet->getCell('B2')->getValue(), 0.001);
        self::assertSame('María González', $sheet->getCell('A3')->getValue());
    }

    public function testBuildUsaElTituloComoNombreDeLaHoja(): void
    {
        $sheet = $this->helper()->build('Costos Patronales', ['Empleado'], [])->getActiveSheet();

        self::assertSame('Costos Patronales', $sheet->getTitle());
    }

    public function testBuildTruncaElNombreDeHojaAlLimiteDeExcel(): void
    {
        $tituloLargo = str_repeat('X', 50);
        $sheet = $this->helper()->build($tituloLargo, ['Empleado'], [])->getActiveSheet();

        self::assertLessThanOrEqual(31, mb_strlen($sheet->getTitle()));
    }

    public function testBuildConFilasVaciasNoLanzaError(): void
    {
        $sheet = $this->helper()->build('Vacío', ['Empleado'], [])->getActiveSheet();

        self::assertSame('Empleado', $sheet->getCell('A1')->getValue());
        self::assertNull($sheet->getCell('A2')->getValue());
    }

    public function testAddSheetAgregaUnaHojaAdicionalConSuPropioContenido(): void
    {
        $helper = $this->helper();
        $spreadsheet = $helper->build('Nóminas', ['Período'], [['2026-05']]);

        $helper->addSheet($spreadsheet, 'Horas Extra', ['Fecha', 'Horas'], [['2026-05-10', 4.5]]);

        self::assertSame(2, $spreadsheet->getSheetCount());
        $hoja2 = $spreadsheet->getSheet(1);
        self::assertSame('Horas Extra', $hoja2->getTitle());
        self::assertSame('Fecha', $hoja2->getCell('A1')->getValue());
        self::assertEqualsWithDelta(4.5, $hoja2->getCell('B2')->getValue(), 0.001);
    }

    public function testToBinaryStringProduceUnArchivoXlsxValido(): void
    {
        $helper = $this->helper();
        $spreadsheet = $helper->build('Planilla', ['Nombre'], [['Juan']]);

        $bytes = $helper->toBinaryString($spreadsheet);

        self::assertNotEmpty($bytes);
        // Un .xlsx es un archivo ZIP: empieza con la firma "PK".
        self::assertSame('PK', substr($bytes, 0, 2));
    }
}
