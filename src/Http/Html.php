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
	 * meta-refresh, see the dashboard's loading state). $path is the current
	 * request path, used to mark the active tab (GUI-DESIGN.md §2).
	 * $summary is the plain-text header status line (GUI-DESIGN.md §2:
	 * "host · 3 running · RAM 61/124 GB"), escaped here.
	 */
	public static function page(string $title, string $body, string $msg = '', string $head = '', string $path = '', string $summary = ''): string
	{
		$flash = $msg !== '' ? '<p class="flash">' . self::e($msg) . '</p>' : '';
		$sum   = $summary !== '' ? '<span class="summary">' . self::e($summary) . '</span>' : '';
		return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . self::e($title) . ' · LlmChooser</title>'
			. '<link rel="stylesheet" href="/assets/style.css">'
			// No JS: help panels are permanently open (GUI-DESIGN.md §4).
			. '<noscript><style>.help{display:block!important}</style></noscript>'
			. $head . '</head><body>'
			. '<header class="topbar"><a href="/" class="brand">LlmChooser</a>'
			. '<nav class="tabs">' . self::tabLinks($path) . '</nav>' . $sum . '</header>'
			. '<main><h1>' . self::e($title) . '</h1>' . $flash . $body . '</main>'
			. '<script src="/assets/app.js" defer></script>'
			. '</body></html>';
	}

	/** The four tabs; active rule per GUI-DESIGN.md §2. */
	private static function tabLinks(string $path): string
	{
		$out = '';
		foreach (['/' => 'Dashboard', '/presets' => 'Presets', '/models' => 'Models', '/engines' => 'Engines'] as $href => $label) {
			$active = match ($href) {
				'/'        => $path === '/' || $path === '',
				'/presets' => $path === '/presets' || str_starts_with($path, '/presets/'),
				'/models'  => $path === '/models' || str_starts_with($path, '/models/'),
				default    => $path === '/engines' || str_starts_with($path, '/engines/'),
			};
			$out .= '<a href="' . self::e($href) . '"' . ($active ? ' class="active"' : '') . '>'
				. self::e($label) . '</a> ';
		}
		return rtrim($out);
	}

	/** A one-button POST form. */
	public static function postButton(string $action, string $label, string $class = '', string $confirm = '', string $formClass = '', array $formAttrs = []): string
	{
		$onsubmit = $confirm !== '' ? ' onsubmit="return confirm(' . self::e(json_encode($confirm)) . ')"' : '';
		$attrs    = '';
		foreach ($formAttrs as $k => $v) {
			$attrs .= ' ' . self::e($k) . '="' . self::e($v) . '"';
		}
		$cls = trim('inline ' . $formClass);
		return '<form method="post" action="' . self::e($action) . '" class="' . self::e($cls) . '"' . $onsubmit . $attrs . '>'
			. '<button type="submit" class="' . self::e($class) . '">' . self::e($label) . '</button></form>';
	}
}
