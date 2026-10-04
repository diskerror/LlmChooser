<?php

declare(strict_types=1);

namespace LLMchooser\Process;

use Diskerror\Typed\TypedArray;

class InstanceCollection extends TypedArray
{
    protected string $_type = Instance::class;
}
