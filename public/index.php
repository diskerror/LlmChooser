<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use LlmChooser\App;
use LlmChooser\Http\Request;
use LlmChooser\Http\WebApp;

// Under `php -S` serve existing static files directly.
if (PHP_SAPI === 'cli-server') {
	$file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
	if (is_file($file) && !str_ends_with($file, '.php')) {
		return false;
	}
}

$app = new App(dirname(__DIR__));
$app->processes->reconcile();

// Request::fromGlobals() must be called only once — it reads php://input.
$request = Request::fromGlobals();
if (str_starts_with($request->path, '/v1/')) {
	set_time_limit(0);
	(new \LlmChooser\Proxy\ChatProxy($app))->handle($request, new \LlmChooser\Proxy\Emitter());
	return;
}
(new WebApp($app))->handle($request)->send();
