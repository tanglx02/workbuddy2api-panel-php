<?php
/**
 * WorkBuddy2API Panel - PHP Edition
 * Front controller. Works under Apache (.htaccess) and any PHP-capable host.
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak stack traces to production output

define('WB2A_ROOT', __DIR__);
define('WB2A_VERSION', '1.0.0');

spl_autoload_register(function ($class) {
    if (strpos($class, 'WB2A\\') !== 0) { return; }
    $rel = str_replace('\\', '/', substr($class, 5));
    $file = WB2A_ROOT . '/app/' . $rel . '.php';
    if (is_file($file)) { require $file; }
});

WB2A\Core\Env::bootstrap();

$path = WB2A\Core\Env::requestPath();

// Hard block sensitive directories even if rewrite rules are missing.
foreach (['data/', 'app/', 'tests/', 'docs/', 'install-backup/'] as $blocked) {
    if (strpos($path, $blocked) === 0) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"ok":false,"error":"not found"}';
        exit;
    }
}

// Installer entry (auto-locks itself after successful install).
if ($path === 'install' || $path === 'install/' || strpos($path, 'install/') === 0) {
    require WB2A_ROOT . '/install/index.php';
    exit;
}

if (!WB2A\Core\Env::installed()) {
    header('Content-Type: text/html; charset=utf-8');
    // absolute redirect (relative Location breaks under PATH_INFO routing on IIS)
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    header('Location: ' . $base . '/install/');
    exit;
}

$router = new WB2A\Core\Router();

// ---- OpenAI-compatible gateway ----
$router->add('POST', '#^/v1/chat/completions$#', [WB2A\Api\ChatApi::class, 'chat']);
$router->add('GET', '#^/v1/models$#', [WB2A\Api\ModelsApi::class, 'list']);
$router->add('GET', '#^/status$#', [WB2A\Api\StatusApi::class, 'status']);
$router->add('GET', '#^/healthz$#', [WB2A\Api\StatusApi::class, 'health']);

// ---- Web panel ----
$router->add('GET', '#^/panel/?$#', [WB2A\Api\PanelApi::class, 'indexPage']);
$router->add('GET', '#^/panel/app\.js$#', [WB2A\Api\PanelApi::class, 'appScript']);
$router->add(['GET','POST'], '#^/panel/api/(.*)$#', [WB2A\Api\PanelApi::class, 'dispatch']);

// ---- Cron / scheduler endpoint (token protected) ----
$router->add(['GET','POST'], '#^/cron$#', [WB2A\Api\CronApi::class, 'run']);
$router->add(['GET','POST'], '#^/cron/(.*)$#', [WB2A\Api\CronApi::class, 'run']);

// ---- Static assets that live as real files (panel css etc.) are served by
// the web server directly; anything else falls through to 404.
$router->dispatch($path);
