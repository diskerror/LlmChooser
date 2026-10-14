<?php

declare(strict_types=1);

namespace LlmChooser\Engine;

use Diskerror\Typed\TypedClass;

class Engine extends TypedClass
{
	protected string    $id                  = '';
	protected string    $name                = '';
	protected string    $binary_path_default = '';
	protected string    $model_dir           = '';
	protected string    $host                = '127.0.0.1';
	// Flags appended to EVERY preset's command line on this engine, before
	// the preset's own extra_flags (so a preset can still override them).
	// --metrics is not here: CommandBuilder always passes it.
	protected string    $extra_flags         = '';

	// protected, NOT public: a nested TypedMinMax only gets its coercion when
	// it is non-public — see TypedClass::_setByName(). Access stays public via
	// the class's magic __get/__set, so `$engine->port_range->min` works.
	protected PortRange $port_range;

	public function __construct(mixed $in = [])
	{
		$this->port_range = new PortRange(['min' => 5800, 'max' => 5900]);
		parent::__construct($in);
	}
}
