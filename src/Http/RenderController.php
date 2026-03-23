<?php

declare(strict_types=1);

namespace Hydrate\Http;

use Hydrate\Hydrator\Hydrator;
use Hydrate\Pdf\PdfGenerator;
use Hydrate\Pdf\TempStore;
use Hydrate\Template\TemplateRepository;

class RenderController
{
    public function __construct(
        private readonly TemplateRepository $repo,
        private readonly Hydrator           $hydrator,
        private readonly PdfGenerator       $pdf,
        private readonly TempStore          $tempStore,
    ) {}

    /**
     * POST /templates/{name}/render
     *
     * Body:
     *   data        - key/value map of placeholder values
     *   version     - (optional) template version to render
     *   format      - "pdf" (default) | "html"
     *   paper       - A4 (default), Letter, etc.
     *   orientation - portrait (default) | landscape
     *
     * PDF response:
     *   { url, expires_at }   — temp link valid for 30 minutes
     */
    public function render(array $params): void
    {
        $body = Request::body();

        $data   = $body['data']   ?? [];
        $format = $body['format'] ?? 'pdf';

        try {
            $version  = isset($body['version']) ? (int) $body['version'] : null;
            $template = $this->repo->get($params['name'], $version);
        } catch (\RuntimeException $e) {
            Response::json(['error' => $e->getMessage()], 404);
            return;
        }

        $missing = $this->hydrator->missingKeys($template, $data);

        if (!empty($missing)) {
            Response::json([
                'error'   => 'Missing placeholder values',
                'missing' => $missing,
            ], 422);
            return;
        }

        $html = $this->hydrator->hydrate($template, $data);

        if ($format === 'html') {
            http_response_code(200);
            header('Content-Type: text/html; charset=UTF-8');
            echo $html;
            return;
        }

        $pdfBytes = $this->pdf->generate($html, [
            'paper'       => $body['paper']       ?? 'A4',
            'orientation' => $body['orientation']  ?? 'portrait',
        ]);

        $filename = $params['name'] . '_v' . $template->version . '.pdf';
        $token    = $this->tempStore->save($pdfBytes, $filename);

        Response::json([
            'url'        => $this->baseUrl() . '/renders/' . $token,
            'expires_at' => date('c', time() + 1800),
        ], 201);
    }

    /**
     * GET /renders/{token}
     */
    public function serve(array $params): void
    {
        try {
            $file = $this->tempStore->get($params['token']);
        } catch (\RuntimeException $e) {
            Response::json(['error' => $e->getMessage()], 404);
            return;
        }

        Response::pdf($file['bytes'], $file['filename']);
    }

    private function baseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host;
    }
}
