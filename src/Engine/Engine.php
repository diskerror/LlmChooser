<?php

namespace LLMchooser\Engine;

use Diskerror\Typed\TypedClass;

class Engine extends TypedClass
{
	public string $id                  = '';
	public string $name                = '';
	public string $binary_path_default = '';
	public string $host                = '127.0.0.1';
	public int    $port_range_start    = 5800;
	public int    $port_range_end      = 5900;
}
