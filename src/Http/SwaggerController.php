<?php

declare(strict_types=1);

namespace Hydrate\Http;

class SwaggerController
{
    public function ui(): void
    {
        $defaultSecret = htmlspecialchars(getenv('X_AUTH') ?: 'very-strong-secret', ENT_QUOTES);

        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Hydrate API — Swagger UI</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css" />
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script>
    const ui = SwaggerUIBundle({
      url: '/openapi.json',
      dom_id: '#swagger-ui',
      presets: [SwaggerUIBundle.presets.apis, SwaggerUIBundle.SwaggerUIStandalonePreset],
      layout: 'BaseLayout',
      deepLinking: true,
      onComplete() {
        ui.preauthorizeApiKey('XAuth', '{$defaultSecret}');
      },
    });
  </script>
</body>
</html>
HTML;
    }
}
