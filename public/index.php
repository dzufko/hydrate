<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../vendor/autoload.php';

use Hydrate\Http\Auth;
use Hydrate\Http\McpController;
use Hydrate\Http\RenderController;
use Hydrate\Http\Request;
use Hydrate\Http\Router;
use Hydrate\Http\SwaggerController;
use Hydrate\Http\TemplateController;
use Hydrate\Hydrator\Hydrator;
use Hydrate\Pdf\PdfGenerator;
use Hydrate\Pdf\TempStore;
use Hydrate\Template\TemplateRepository;

$uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

$publicRoutes = ['#^/renders/[a-f0-9]+$#', '#^/swagger$#', '#^/openapi\.json$#'];
$isPublic = (bool) array_filter($publicRoutes, fn($p) => preg_match($p, $uri));

if (!$isPublic) {
    Auth::check();
}

// --- Bootstrap ---

$storageDir = __DIR__ . '/../storage/templates';

$repo      = new TemplateRepository($storageDir);
$hydrator  = new Hydrator();
$pdf       = new PdfGenerator();
$tempStore = new TempStore(__DIR__ . '/../storage/renders');

$templates = new TemplateController($repo);
$render    = new RenderController($repo, $hydrator, $pdf, $tempStore);
$swagger   = new SwaggerController();
$mcp       = new McpController($repo, $hydrator, $pdf, $tempStore);

// --- Routes ---

$router = new Router();

$router->add('GET',    '/templates',                  fn()       => $templates->index());
$router->add('POST',   '/templates',                  fn()       => $templates->store());
$router->add('GET',    '/templates/{name}',            fn($p)     => $templates->show($p));
$router->add('PUT',    '/templates/{name}',            fn($p)     => $templates->update($p));
$router->add('DELETE', '/templates/{name}',            fn($p)     => $templates->destroy($p));
$router->add('GET',    '/templates/{name}/versions',              fn($p) => $templates->versions($p));
$router->add('DELETE', '/templates/{name}/versions/{version}',   fn($p) => $templates->destroyVersion($p));
$router->add('POST',   '/templates/{name}/render',     fn($p) => $render->render($p));
$router->add('GET',    '/renders/{token}',             fn($p) => $render->serve($p));
$router->add('GET',    '/swagger',                     fn()   => $swagger->ui());
$router->add('POST',   '/mcp',                         fn()   => $mcp->handle());

// --- Dispatch ---

$router->dispatch(Request::method(), Request::uri());
