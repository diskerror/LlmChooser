<?php

declare(strict_types=1);

namespace LLMchooser\Engine;

use Diskerror\Typed\TypedMinMax;

/**
 * An inclusive range of TCP ports, min <= max, clamped to the usable
 * (non-privileged) port space.
 *
 * Out-of-order input is swapped by TypedMinMax::checkRelatedProperties(),
 * which runs at the end of assign()/__construct()/merge(). Clamping is
 * applied there too, so ['min' => 80, 'max' => 99999] becomes 1024-65535
 * instead of silently producing a range we cannot bind.
 */
class PortRange extends TypedMinMax
{
	public const MIN_PORT = 1024;
	public const MAX_PORT = 65535;

	protected string $_type = 'int';

	public function checkRelatedProperties(): void
	{
		// Clamp BEFORE ordering: clamping can itself reorder the pair
		// (e.g. min 70000 -> 65535 would then exceed a clamped max).
		$this->min = max(self::MIN_PORT, min(self::MAX_PORT, $this->min));
		$this->max = max(self::MIN_PORT, min(self::MAX_PORT, $this->max));

		parent::checkRelatedProperties();
	}

	/**
	 * Every port in the range, low to high.
	 *
	 * @return list<int>
	 */
	public function ports(): array
	{
		return range($this->min, $this->max);
	}

	/**
	 * NOT named count(): TypedClass::count() is final (Countable over the
	 * two members, so count($range) === 2). Do not try to override it.
	 */
	public function portCount(): int
	{
		return $this->max - $this->min + 1;
	}
}
