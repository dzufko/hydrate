<?php

declare(strict_types=1);

namespace Hydrate\Pdf;

class TempStore
{
    private const TTL = 1800; // 30 minutes

    public function __construct(private readonly string $storageDir)
    {
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
    }

    public function save(string $pdfBytes, string $filename): string
    {
        $this->purgeExpired();

        $token   = bin2hex(random_bytes(24));
        $expires = time() + self::TTL;

        file_put_contents($this->pdfPath($token), $pdfBytes);
        file_put_contents($this->metaPath($token), json_encode([
            'filename'   => $filename,
            'expires_at' => $expires,
        ]));

        return $token;
    }

    public function get(string $token): array
    {
        $meta = $this->readMeta($token);

        if ($meta === null) {
            throw new \RuntimeException('File not found or token invalid.');
        }

        if (time() > $meta['expires_at']) {
            $this->delete($token);
            throw new \RuntimeException('Link has expired.');
        }

        return [
            'bytes'      => file_get_contents($this->pdfPath($token)),
            'filename'   => $meta['filename'],
            'expires_at' => $meta['expires_at'],
        ];
    }

    public function exists(string $token): bool
    {
        $meta = $this->readMeta($token);
        return $meta !== null && time() <= $meta['expires_at'];
    }

    private function delete(string $token): void
    {
        @unlink($this->pdfPath($token));
        @unlink($this->metaPath($token));
    }

    private function purgeExpired(): void
    {
        foreach (glob($this->storageDir . '/*.json') as $metaFile) {
            $meta = json_decode(file_get_contents($metaFile), true);

            if (!$meta || time() > $meta['expires_at']) {
                $token = basename($metaFile, '.json');
                $this->delete($token);
            }
        }
    }

    private function readMeta(string $token): ?array
    {
        $token = preg_replace('/[^a-f0-9]/', '', $token);
        $path  = $this->metaPath($token);

        if (!file_exists($path)) {
            return null;
        }

        return json_decode(file_get_contents($path), true);
    }

    private function pdfPath(string $token): string
    {
        return $this->storageDir . '/' . $token . '.pdf';
    }

    private function metaPath(string $token): string
    {
        return $this->storageDir . '/' . $token . '.json';
    }
}
