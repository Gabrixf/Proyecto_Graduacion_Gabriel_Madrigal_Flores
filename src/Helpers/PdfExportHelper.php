<?php

declare(strict_types=1);

namespace App\Helpers;

use Dompdf\Dompdf;
use Dompdf\Options;

final class PdfExportHelper
{
    public function fromHtml(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
