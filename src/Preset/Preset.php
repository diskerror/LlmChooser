<?php

namespace LLMchooser\Preset;

use Diskerror\Typed\TypedClass;

class Preset extends TypedClass
{
	public string  $id               = '';
	public string  $name             = '';
	public string  $engine_id        = '';
	public string  $binary_path      = '';
	public string  $model_path       = '';
	public ?string $mmproj_path      = null;
	public ?string $model_draft_path = null;

	// protected, NOT public: TypedClass only runs its nested-object coercion
	// (deep assign of an array into the PresetArgs instance) for non-public
	// properties — see TypedClass::_setByName(). Access stays public via the
	// class's magic __get/__set, so `$preset->args` works either way.
	protected PresetArgs $args;
	
	public int    $ttl_seconds   = 86400;
	public ?int   $assigned_port = null;
	public string $notes         = '';
}
