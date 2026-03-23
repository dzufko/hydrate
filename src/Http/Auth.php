<?php

declare(strict_types=1);

namespace Hydrate\Http;

class Auth
{
    public static function check(): void
    {
        $secret = getenv('X_AUTH') ?: 'very-strong-secret';
        $header = $_SERVER['HTTP_X_AUTH'] ?? '';

        if ($header !== $secret) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
    }
}
