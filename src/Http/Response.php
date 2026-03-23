<?php

declare(strict_types=1);

namespace Hydrate\Http;

class Response
{
    public static function json(mixed $data, int $status = 200): void
    {
        self::discardBuffer();
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public static function pdf(string $content, string $filename = 'document.pdf'): void
    {
        self::discardBuffer();
        http_response_code(200);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
    }

    private static function discardBuffer(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
