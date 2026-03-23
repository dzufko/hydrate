<?php

declare(strict_types=1);

namespace Hydrate\Http;

use Hydrate\Template\TemplateRepository;

class TemplateController
{
    public function __construct(private readonly TemplateRepository $repo) {}

    /** GET /templates */
    public function index(): void
    {
        Response::json($this->repo->list());
    }

    /** POST /templates */
    public function store(): void
    {
        $body = Request::body();

        $name = trim($body['name'] ?? '');
        $html = $body['html'] ?? '';
        $description = $body['description'] ?? null;

        if ($name === '' || $html === '') {
            Response::json(['error' => 'name and html are required'], 422);
            return;
        }

        $template = $this->repo->save($name, $html, $description);
        Response::json($template->toArray(), 201);
    }

    /** GET /templates/{name} */
    public function show(array $params): void
    {
        try {
            $version = isset($_GET['version']) ? (int) $_GET['version'] : null;
            $template = $this->repo->get($params['name'], $version);
            Response::json($template->toArray());
        } catch (\RuntimeException $e) {
            Response::json(['error' => $e->getMessage()], 404);
        }
    }

    /** PUT /templates/{name} */
    public function update(array $params): void
    {
        $body = Request::body();
        $html = $body['html'] ?? '';
        $description = $body['description'] ?? null;

        if ($html === '') {
            Response::json(['error' => 'html is required'], 422);
            return;
        }

        try {
            // Verify template exists
            $this->repo->get($params['name']);
        } catch (\RuntimeException $e) {
            Response::json(['error' => $e->getMessage()], 404);
            return;
        }

        $template = $this->repo->save($params['name'], $html, $description);
        Response::json($template->toArray(), 201);
    }

    /** DELETE /templates/{name} */
    public function destroy(array $params): void
    {
        try {
            $this->repo->delete($params['name']);
            Response::json(['message' => "Template '{$params['name']}' deleted."]);
        } catch (\RuntimeException $e) {
            Response::json(['error' => $e->getMessage()], 404);
        }
    }

    /** GET /templates/{name}/versions */
    public function versions(array $params): void
    {
        Response::json($this->repo->versions($params['name']));
    }

    /** DELETE /templates/{name}/versions/{version} */
    public function destroyVersion(array $params): void
    {
        try {
            $this->repo->deleteVersion($params['name'], (int) $params['version']);
            Response::json(['message' => "Template '{$params['name']}' version {$params['version']} deleted."]);
        } catch (\RuntimeException $e) {
            Response::json(['error' => $e->getMessage()], 404);
        }
    }
}
