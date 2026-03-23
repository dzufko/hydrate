<?php

declare(strict_types=1);

namespace Hydrate\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

class PdfGenerator
{
    public function generate(string $html, array $options = []): string
    {
        $opts = new Options();
        $opts->set('isHtml5ParserEnabled', true);
        $opts->set('isRemoteEnabled', $options['remote'] ?? false);
        $opts->set('defaultFont', $options['font'] ?? 'DejaVu Sans');

        $dompdf = new Dompdf($opts);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($options['paper'] ?? 'A4', $options['orientation'] ?? 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
