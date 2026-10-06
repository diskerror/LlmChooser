<?php

declare(strict_types=1);

namespace LlmChooser\Process;

use Diskerror\Typed\TypedArray;

class InstanceCollection extends TypedArray
{
    protected string $_type = Instance::class;
}
