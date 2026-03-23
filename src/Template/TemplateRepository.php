<?php

declare(strict_types=1);

namespace Hydrate\Template;

class TemplateRepository
{
    public function __construct(private readonly string $storageDir)
    {
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
    }

    public function list(): array
    {
        $templates = [];

        foreach (glob($this->storageDir . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            $templates[] = [
                'name'            => $name,
                'latest_version'  => $this->latestVersion($name),
                'versions'        => $this->versions($name),
            ];
        }

        return $templates;
    }

    public function get(string $name, ?int $version = null): Template
    {
        $version ??= $this->latestVersion($name);

        if ($version === null) {
            throw new \RuntimeException("Template '{$name}' not found.");
        }

        $path = $this->versionPath($name, $version);

        if (!file_exists($path)) {
            throw new \RuntimeException("Template '{$name}' version {$version} not found.");
        }

        return new Template(
            name: $name,
            version: $version,
            html: file_get_contents($path),
            meta: $this->readMeta($name, $version),
        );
    }

    public function save(string $name, string $html, ?string $description = null): Template
    {
        $version = ($this->latestVersion($name) ?? 0) + 1;
        $dir = $this->templateDir($name);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->versionPath($name, $version), $html);

        $meta = [
            'version'     => $version,
            'description' => $description,
            'created_at'  => date('c'),
        ];

        $this->writeMeta($name, $version, $meta);

        return new Template(name: $name, version: $version, html: $html, meta: $meta);
    }

    public function delete(string $name): void
    {
        $dir = $this->templateDir($name);

        if (!is_dir($dir)) {
            throw new \RuntimeException("Template '{$name}' not found.");
        }

        $this->removeDir($dir);
    }

    public function deleteVersion(string $name, int $version): void
    {
        $htmlPath = $this->versionPath($name, $version);
        $metaPath = $this->metaPath($name, $version);

        if (!file_exists($htmlPath)) {
            throw new \RuntimeException("Template '{$name}' version {$version} not found.");
        }

        unlink($htmlPath);

        if (file_exists($metaPath)) {
            unlink($metaPath);
        }

        // Remove the template directory entirely if no versions remain
        if (empty(glob($this->templateDir($name) . '/*.html'))) {
            rmdir($this->templateDir($name));
        }
    }

    public function versions(string $name): array
    {
        $versions = [];

        foreach (glob($this->templateDir($name) . '/*.html') as $file) {
            $v = (int) basename($file, '.html');
            $versions[] = [
                'version' => $v,
                'meta'    => $this->readMeta($name, $v),
            ];
        }

        usort($versions, fn($a, $b) => $a['version'] <=> $b['version']);

        return $versions;
    }

    public function latestVersion(string $name): ?int
    {
        $files = glob($this->templateDir($name) . '/*.html');

        if (empty($files)) {
            return null;
        }

        $versions = array_map(fn($f) => (int) basename($f, '.html'), $files);

        return max($versions);
    }

    private function templateDir(string $name): string
    {
        return $this->storageDir . '/' . $this->sanitizeName($name);
    }

    private function versionPath(string $name, int $version): string
    {
        return $this->templateDir($name) . '/' . $version . '.html';
    }

    private function metaPath(string $name, int $version): string
    {
        return $this->templateDir($name) . '/' . $version . '.json';
    }

    private function readMeta(string $name, int $version): array
    {
        $path = $this->metaPath($name, $version);

        return file_exists($path) ? json_decode(file_get_contents($path), true) : [];
    }

    private function writeMeta(string $name, int $version, array $meta): void
    {
        file_put_contents($this->metaPath($name, $version), json_encode($meta, JSON_PRETTY_PRINT));
    }

    private function sanitizeName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }

        rmdir($dir);
    }
}
