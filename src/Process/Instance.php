<?php

declare(strict_types=1);

namespace LlmChooser\Process;

use Diskerror\Typed\TypedClass;

class Instance extends TypedClass
{
	public const LOADING = 'loading';
	public const READY   = 'ready';
	public const ERROR   = 'error';
	public const STOPPED = 'stopped';

	// protected, NOT public: these round-trip through
	// storage/runtime_state.json, which is outside data — Typed coerces and
	// sanitizes on write. See the Phase 2A visibility rule.
	protected string $preset_id   = '';
	protected int    $pid         = 0;
	protected string $host        = '127.0.0.1';
	protected int    $port        = 0;
	protected int    $started_at  = 0;     // unix time
	protected string $status      = self::LOADING;
	protected string $message     = '';    // error text, if any
	protected string $log_file    = '';
	protected string $command     = '';    // Command::toString(), for display
	protected array  $allocation  = [];    // Allocation::toArray()
	protected int    $last_used   = 0;     // unix time; updated by the proxy (Phase 7)
}
