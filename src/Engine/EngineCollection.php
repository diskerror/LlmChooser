<?php

declare(strict_types=1);

namespace LLMchooser\Engine;

use Diskerror\Typed\TypedArray;

class EngineCollection extends TypedArray
{
    protected string $_type = Engine::class;
}
