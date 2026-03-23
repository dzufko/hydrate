<?php

declare(strict_types=1);

namespace Hydrate\Hydrator;

use Hydrate\Template\Template;

class Hydrator
{
    public function hydrate(Template $template, array $data): string
    {
        $html = $template->html;

        foreach ($data as $key => $value) {
            $html = str_replace('{{' . $key . '}}', htmlspecialchars((string) $value, ENT_QUOTES), $html);
        }

        // Optionally strip any remaining unresolved placeholders
        $html = preg_replace('/\{\{\w+\}\}/', '', $html);

        return $html;
    }

    public function missingKeys(Template $template, array $data): array
    {
        return array_values(
            array_diff($template->placeholders(), array_keys($data))
        );
    }
}
