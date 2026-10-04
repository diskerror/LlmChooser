<?php

declare(strict_types=1);

namespace LLMchooser\Model;

use Diskerror\Typed\TypedClass;
use RuntimeException;

class ModelRootsConfig extends TypedClass
{
	protected StringList $roots;
	protected string $cache_file = 'storage/model_cache.json';

	public static function load(string $file): self
	{
		if (!is_file($file)) {
			throw new RuntimeException("Model roots config not found: {$file}");
		}
		return new self(json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
	}
}
