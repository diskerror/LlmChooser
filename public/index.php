<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use LLMchooser\App;
use LLMchooser\Http\Request;
use LLMchooser\Http\WebApp;

// Under `php -S` serve existing static files directly.
if (PHP_SAPI === 'cli-server') {
	$file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
	if (is_file($file) && !str_ends_with($file, '.php')) {
		return false;
	}
}

$app = new App(dirname(__DIR__));
$app->processes->reconcile();
(new WebApp($app))->handle(Request::fromGlobals())->send();
