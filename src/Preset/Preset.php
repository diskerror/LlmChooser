<?php

declare(strict_types=1);

namespace LLMchooser\Preset;

use Diskerror\Typed\TypedClass;

class Preset extends TypedClass
{
	protected string  $id               = '';
	protected string  $name             = '';
	protected bool    $enabled          = true;
	protected string  $engine_id        = '';
	protected string  $binary_path      = '';
	protected string  $model_path       = '';
	protected ?string $mmproj_path      = null;
	protected ?string $model_draft_path = null;

	// protected, NOT public: TypedClass only runs its nested-object coercion
	// (deep assign of an array into the PresetArgs instance) for non-public
	// properties — see TypedClass::_setByName(). Access stays public via the
	// class's magic __get/__set, so `$preset->args` works either way.
	protected PresetArgs $args;
	
	protected int    $ttl_seconds   = 86400;
	protected ?int   $assigned_port = null;
	protected string $notes         = '';
}
