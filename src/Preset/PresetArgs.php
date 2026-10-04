<?php

declare(strict_types=1);

namespace LLMchooser\Preset;

use Diskerror\Typed\TypedClass;

class PresetArgs extends TypedClass
{
	protected int    $ctx_size          = 0;
	protected int    $n_predict         = -1;
	protected int    $batch_size        = 2048;
	protected int    $ubatch_size       = 512;
	protected string $flash_attn        = 'auto';
	protected string $cache_type_k      = 'f16';
	protected string $cache_type_v      = 'f16';
	protected string $n_gpu_layers      = 'auto';
	protected string $split_mode        = 'layer';
	protected string $tensor_split      = '';
	protected int    $main_gpu          = 0;
	protected int    $parallel          = -1;
	protected bool   $no_context_shift  = true;
	protected float  $temp              = 0.8;
	protected int    $top_k             = 20;
	protected float  $top_p             = 0.95;
	protected float  $min_p             = 0.05;
	protected float  $repeat_penalty    = 1.0;
	protected float  $presence_penalty  = 0.0;
	protected float  $frequency_penalty = 0.0;
	protected int    $seed              = -1;
	protected string $spec_type         = 'none';
	protected int    $spec_draft_n_max  = 16;
	protected string $alias             = '';
	protected bool   $jinja             = true;
	protected string $reasoning_effort  = 'default';
	protected int    $threads           = -1;
	protected string $extra_flags       = '';
}
