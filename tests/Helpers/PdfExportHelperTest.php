<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\PdfExportHelper;
use PHPUnit\Framework\TestCase;

final class PdfExportHelperTest extends TestCase
{
    private function helper(): PdfExportHelper
    {
        return new PdfExportHelper();
    }

    public function testFromHtmlProduceUnArchivoPdfValido(): void
    {
        $bytes = $this->helper()->fromHtml('<h1>Reporte de prueba</h1><p>Contenido</p>');

        self::assertNotEmpty($bytes);
        // Todo PDF empieza con la firma "%PDF-".
        self::assertSame('%PDF-', substr($bytes, 0, 5));
    }

    public function testFromHtmlConTablaProduceUnPdfDeTamanoRazonable(): void
    {
        $html = '<table><tr><th>Empleado</th><th>Monto</th></tr>'
              . '<tr><td>Juan Pérez</td><td>225000</td></tr></table>';

        $bytes = $this->helper()->fromHtml($html);

        // Un PDF con contenido real mide más que un puñado de bytes.
        self::assertGreaterThan(500, strlen($bytes));
    }
}
