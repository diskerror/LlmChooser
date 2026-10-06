<?php

declare(strict_types=1);

namespace LlmChooser\Preset;

use Diskerror\Typed\TypedArray;

class PresetCollection extends TypedArray
{
    protected string $_type = Preset::class;
}
