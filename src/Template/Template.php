<?php

declare(strict_types=1);

namespace Hydrate\Template;

class Template
{
    public function __construct(
        public readonly string $name,
        public readonly int    $version,
        public readonly string $html,
        public readonly array  $meta = [],
    ) {}

    public function placeholders(): array
    {
        preg_match_all('/\{\{(\w+)\}\}/', $this->html, $matches);

        return array_unique($matches[1]);
    }

    public function toArray(): array
    {
        return [
            'name'         => $this->name,
            'version'      => $this->version,
            'placeholders' => $this->placeholders(),
            'meta'         => $this->meta,
            'html'         => $this->html,
        ];
    }
}
