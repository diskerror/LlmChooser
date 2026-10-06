<?php

declare(strict_types=1);

namespace LlmChooser\Http;

final class Html
{
	public static function e(mixed $v): string
	{
		return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * Full page. $body is already-escaped HTML. $msg is plain text (escaped here).
	 * $head is extra already-escaped markup inserted before </head> (e.g. a
	 * meta-refresh, see the dashboard's loading state).
	 */
	public static function page(string $title, string $body, string $msg = '', string $head = ''): string
	{
		$flash = $msg !== '' ? '<p class="flash">' . self::e($msg) . '</p>' : '';
		return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . self::e($title) . ' · LlmChooser</title>'
			. '<link rel="stylesheet" href="/assets/style.css">' . $head . '</head><body>'
			. '<header><a href="/" class="brand">LlmChooser</a> '
			. '<nav><a href="/">Dashboard</a> <a href="/presets">Presets</a> <a href="/models">Models</a></nav></header>'
			. '<main><h1>' . self::e($title) . '</h1>' . $flash . $body . '</main></body></html>';
	}

	/** A one-button POST form. */
	public static function postButton(string $action, string $label, string $class = '', string $confirm = ''): string
	{
		$onsubmit = $confirm !== '' ? ' onsubmit="return confirm(' . self::e(json_encode($confirm)) . ')"' : '';
		return '<form method="post" action="' . self::e($action) . '" class="inline"' . $onsubmit . '>'
			. '<button type="submit" class="' . self::e($class) . '">' . self::e($label) . '</button></form>';
	}
}
