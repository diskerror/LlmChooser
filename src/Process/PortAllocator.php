<?php

declare(strict_types=1);

namespace LlmChooser\Process;

use LlmChooser\Engine\Engine;
use LlmChooser\Preset\Preset;
use LlmChooser\Preset\PresetRepository;
use RuntimeException;

/**
 * Hands out ports for presets and keeps the assignment stable across
 * restarts by saving it on the preset.
 *
 * PresetRepository::loadAll() skips files it cannot parse (Phase 2A), so
 * this sees fewer presets than there are files. That is fine: a preset with
 * an unparseable file has no readable assigned_port, so its port counts as
 * free. We never reserve ports for presets we cannot read.
 */
final class PortAllocator
{
	public function __construct(private readonly PresetRepository $presets) {}

	/** Returns the port to use for $preset and saves it on the preset if newly assigned. */
	public function portFor(Preset $preset, Engine $engine): int
	{
		$range = $engine->port_range;

		$assigned = $preset->assigned_port;
		if ($assigned !== null && $range->contains($assigned) && !$this->claimedByOther($assigned, $preset->id)) {
			return $assigned;
		}

		foreach ($range->ports() as $port) {
			if ($this->claimedByOther($port, $preset->id)) {
				continue;
			}
			if (!self::isFree($engine->host, $port)) {
				continue;
			}
			$preset->assigned_port = $port;
			$this->presets->save($preset);
			return $port;
		}

		throw new RuntimeException(sprintf('No free port in range %d-%d', $range->min, $range->max));
	}

	/** Is $port the assigned_port of a preset other than $exceptId? */
	private function claimedByOther(int $port, string $exceptId): bool
	{
		foreach ($this->presets->loadAll() as $p) {
			if ($p->id !== $exceptId && $p->assigned_port === $port) {
				return true;
			}
		}
		return false;
	}

	public static function isFree(string $host, int $port): bool
	{
		$s = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
		if ($s === false) {
			return false;
		}
		fclose($s);
		return true;
	}
}
