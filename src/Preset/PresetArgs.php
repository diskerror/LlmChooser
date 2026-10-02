<?php

namespace LLMchooser\Preset;

use Diskerror\Typed\TypedClass;

class PresetArgs extends TypedClass
{
	public int    $ctx_size          = 0;
	public int    $n_predict         = -1;
	public int    $batch_size        = 2048;
	public int    $ubatch_size       = 512;
	public string $flash_attn        = 'auto';
	public string $cache_type_k      = 'f16';
	public string $cache_type_v      = 'f16';
	public string $n_gpu_layers      = 'auto';
	public string $split_mode        = 'layer';
	public string $tensor_split      = '';
	public int    $main_gpu          = 0;
	public int    $parallel          = -1;
	public bool   $no_context_shift  = true;
	public float  $temp              = 0.8;
	public int    $top_k             = 20;
	public float  $top_p             = 0.95;
	public float  $min_p             = 0.05;
	public float  $repeat_penalty    = 1.0;
	public float  $presence_penalty  = 0.0;
	public float  $frequency_penalty = 0.0;
	public int    $seed              = -1;
	public string $spec_type         = 'none';
	public int    $spec_draft_n_max  = 16;
	public string $alias             = '';
	public bool   $jinja             = true;
	public string $reasoning_effort  = 'default';
	public int    $threads           = -1;
	public string $extra_flags       = '';
}
