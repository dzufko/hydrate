<?php

declare(strict_types=1);

namespace Hydrate\Http;

use Hydrate\Hydrator\Hydrator;
use Hydrate\Pdf\PdfGenerator;
use Hydrate\Pdf\TempStore;
use Hydrate\Template\TemplateRepository;

/**
 * MCP (Model Context Protocol) endpoint — JSON-RPC 2.0 over HTTP.
 * Spec: https://modelcontextprotocol.io/specification/2024-11-05
 */
class McpController
{
    public function __construct(
        private readonly TemplateRepository $repo,
        private readonly Hydrator           $hydrator,
        private readonly PdfGenerator       $pdf,
        private readonly TempStore          $tempStore,
    ) {}

    public function handle(): void
    {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true);

        $this->logRequest($raw, is_array($body) ? $body : null);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
            $this->error(null, -32700, 'Parse error');
            return;
        }

        // Notifications have no "id" — acknowledge silently
        if (!array_key_exists('id', $body)) {
            http_response_code(202);
            $this->log('info', 'Notification received without id; 202 accepted');
            return;
        }

        $id     = $body['id'];
        $method = $body['method'] ?? '';
        $params = $body['params'] ?? [];

        match ($method) {
            'initialize'  => $this->initialize($id, $params),
            'ping'        => $this->success($id, new \stdClass()),
            'tools/list'  => $this->toolsList($id),
            'tools/call'  => $this->toolsCall($id, $params),
            default       => $this->error($id, -32601, 'Method not found'),
        };
    }

    // -------------------------------------------------------------------------
    // Protocol handlers
    // -------------------------------------------------------------------------

    private function initialize(mixed $id, array $params): void
    {
        $this->success($id, [
            'protocolVersion' => '2024-11-05',
            'capabilities'    => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name'    => 'hydrate',
                'version' => '1.0.0',
            ],
        ]);
    }

    private function toolsList(mixed $id): void
    {
        $this->success($id, ['tools' => $this->toolDefinitions()]);
    }

    private function toolsCall(mixed $id, array $params): void
    {
        $name = $params['name']      ?? '';
        $args = $params['arguments'] ?? [];

        try {
            $result = match ($name) {
                'list_templates'  => $this->toolListTemplates(),
                'get_template'    => $this->toolGetTemplate($args),
                'create_template' => $this->toolCreateTemplate($args),
                'update_template' => $this->toolUpdateTemplate($args),
                'delete_template' => $this->toolDeleteTemplate($args),
                'list_versions'   => $this->toolListVersions($args),
                'delete_version'  => $this->toolDeleteVersion($args),
                'render_template' => $this->toolRenderTemplate($args),
                'html_to_pdf'     => $this->toolHtmlToPdf($args),
                default           => throw new \InvalidArgumentException("Unknown tool: {$name}"),
            };

            $this->success($id, [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)]],
                'isError' => false,
            ]);
        } catch (\Throwable $e) {
            $this->success($id, [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Tools
    // -------------------------------------------------------------------------

    private function toolListTemplates(): array
    {
        return $this->repo->list();
    }

    private function toolGetTemplate(array $args): array
    {
        $name    = $this->require($args, 'name');
        $version = isset($args['version']) ? (int) $args['version'] : null;

        return $this->repo->get($name, $version)->toArray();
    }

    private function toolCreateTemplate(array $args): array
    {
        $name        = $this->require($args, 'name');
        $html        = $this->require($args, 'html');
        $description = $args['description'] ?? null;

        return $this->repo->save($name, $html, $description)->toArray();
    }

    private function toolUpdateTemplate(array $args): array
    {
        $name        = $this->require($args, 'name');
        $html        = $this->require($args, 'html');
        $description = $args['description'] ?? null;

        // Verify exists
        $this->repo->get($name);

        return $this->repo->save($name, $html, $description)->toArray();
    }

    private function toolDeleteTemplate(array $args): array
    {
        $name = $this->require($args, 'name');
        $this->repo->delete($name);

        return ['message' => "Template '{$name}' deleted."];
    }

    private function toolListVersions(array $args): array
    {
        $name = $this->require($args, 'name');

        return $this->repo->versions($name);
    }

    private function toolDeleteVersion(array $args): array
    {
        $name    = $this->require($args, 'name');
        $version = (int) $this->require($args, 'version');

        $this->repo->deleteVersion($name, $version);

        return ['message' => "Template '{$name}' version {$version} deleted."];
    }

    private function toolRenderTemplate(array $args): array
    {
        $name    = $this->require($args, 'name');
        $data    = $args['data']    ?? [];
        $format  = $args['format']  ?? 'html';
        $version = isset($args['version']) ? (int) $args['version'] : null;

        $template = $this->repo->get($name, $version);
        $missing  = $this->hydrator->missingKeys($template, $data);

        if (!empty($missing)) {
            throw new \RuntimeException('Missing placeholder values: ' . implode(', ', $missing));
        }

        $html = $this->hydrator->hydrate($template, $data);

        if ($format === 'pdf') {
            $pdfBytes = $this->pdf->generate($html, [
                'paper'       => $args['paper']       ?? 'A4',
                'orientation' => $args['orientation']  ?? 'portrait',
            ]);

            $filename = $name . '_v' . $template->version . '.pdf';
            $token    = $this->tempStore->save($pdfBytes, $filename);

            return [
                'format'     => 'pdf',
                'url'        => $this->baseUrl() . '/renders/' . $token,
                'expires_at' => date('c', time() + 1800),
            ];
        }

        return [
            'format' => 'html',
            'html'   => $html,
        ];
    }

    private function toolHtmlToPdf(array $args): array
    {
        $html        = $this->require($args, 'html');
        $paper       = $args['paper']       ?? 'A4';
        $orientation = $args['orientation'] ?? 'portrait';

        $pdfBytes = $this->pdf->generate($html, [
            'paper'       => $paper,
            'orientation' => $orientation,
        ]);

        $filename = 'document_' . time() . '.pdf';
        $token    = $this->tempStore->save($pdfBytes, $filename);
        $expiresAt = time() + 1800;

        return [
            'success'    => true,
            'message'    => "Your PDF has been generated successfully! The download link is valid for 30 minutes.",
            'url'        => $this->baseUrl() . '/renders/' . $token,
            'expires_at' => date('c', $expiresAt),
            'filename'   => $filename,
        ];
    }


    private function toolDefinitions(): array
    {
        return [
            [
                'name'        => 'list_templates',
                'description' => 'List all templates with their versions.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name'        => 'get_template',
                'description' => 'Get a template by name. Returns HTML, version, and placeholder list.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => ['name'],
                    'properties' => [
                        'name'    => ['type' => 'string', 'description' => 'Template name'],
                        'version' => ['type' => 'integer', 'description' => 'Version number; omit for latest'],
                    ],
                ],
            ],
            [
                'name'        => 'create_template',
                'description' => 'Create a new template. Use {{placeholder}} syntax in HTML.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => ['name', 'html'],
                    'properties' => [
                        'name'        => ['type' => 'string'],
                        'html'        => ['type' => 'string', 'description' => 'HTML with {{placeholder}} tokens'],
                        'description' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'name'        => 'update_template',
                'description' => 'Save a new version of an existing template.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => ['name', 'html'],
                    'properties' => [
                        'name'        => ['type' => 'string'],
                        'html'        => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'name'        => 'delete_template',
                'description' => 'Delete a template and all its versions.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'name'        => 'list_versions',
                'description' => 'List all versions of a template with metadata.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'name'        => 'delete_version',
                'description' => 'Delete a specific version of a template.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => ['name', 'version'],
                    'properties' => [
                        'name'    => ['type' => 'string'],
                        'version' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name'        => 'render_template',
                'description' => 'Hydrate a template with data and render to HTML or PDF. PDF is saved to a temp URL valid for 30 minutes. Use this if you want to render a template an existing template but using different data and not changing structure of template.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => ['name'],
                    'properties' => [
                        'name'        => ['type' => 'string'],
                        'data'        => [
                            'type'                 => 'object',
                            'description'          => 'Key/value map for {{placeholder}} substitution',
                            'additionalProperties' => ['type' => 'string'],
                        ],
                        'version'     => ['type' => 'integer', 'description' => 'Version to render; omit for latest'],
                        'format'      => ['type' => 'string', 'enum' => ['html', 'pdf'], 'default' => 'html'],
                        'paper'       => ['type' => 'string', 'enum' => ['A4', 'Letter', 'Legal', 'A3'], 'default' => 'A4'],
                        'orientation' => ['type' => 'string', 'enum' => ['portrait', 'landscape'], 'default' => 'portrait'],
                    ],
                ],
            ],
            [
                'name'        => 'html_to_pdf',
                'description' => 'Convert raw HTML directly to PDF without needing a template. Useful for quick one-off PDF generation from dynamic HTML. Returns a download link valid for 30 minutes.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => ['html'],
                    'properties' => [
                        'html'        => [
                            'type'        => 'string',
                            'description' => 'Raw HTML content to convert to PDF',
                        ],
                        'paper'       => [
                            'type'        => 'string',
                            'enum'        => ['A4', 'Letter', 'Legal', 'A3'],
                            'default'     => 'A4',
                            'description' => 'Page size',
                        ],
                        'orientation' => [
                            'type'        => 'string',
                            'enum'        => ['portrait', 'landscape'],
                            'default'     => 'portrait',
                            'description' => 'Page orientation',
                        ],
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host;
    }

    private function log(string $level, string $message): void
    {
        $mask = substr($message, 0, 16384);
        error_log(sprintf('[MCP] %s: %s', strtoupper($level), $mask));
    }

    private function logRequest(string $raw, ?array $decoded): void
    {
        $payload = $decoded !== null ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : 'invalid';
        $this->log('request', sprintf('raw=%s decoded=%s', substr($raw, 0, 8192), $payload));
    }

    private function logResponse(mixed $id, array $response): void
    {
        $this->log('response', sprintf('id=%s response=%s', json_encode($id, JSON_UNESCAPED_UNICODE), json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)));
    }

    private function require(array $args, string $key): mixed
    {
        if (!isset($args[$key]) || $args[$key] === '') {
            throw new \InvalidArgumentException("Missing required argument: {$key}");
        }

        return $args[$key];
    }

    private function success(mixed $id, mixed $result): void
    {
        $payload = ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        $this->logResponse($id, $payload);

        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function error(mixed $id, int $code, string $message): void
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ];

        $this->logResponse($id, $payload);

        http_response_code(200); // JSON-RPC errors still return 200
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
