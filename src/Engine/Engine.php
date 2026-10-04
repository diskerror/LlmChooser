<?php

declare(strict_types=1);

namespace LLMchooser\Engine;

use Diskerror\Typed\TypedClass;

class Engine extends TypedClass
{
	protected string    $id                  = '';
	protected string    $name                = '';
	protected string    $binary_path_default = '';
	protected string    $host                = '127.0.0.1';

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
