<?php

declare(strict_types=1);

namespace LlmChooser\Process;

/**
 * What a running llama-server is doing right now, from its own /slots and
 * /metrics endpoints (/metrics needs --metrics, which CommandBuilder always
 * passes). Short timeouts: a busy or wedged server must not stall the
 * dashboard, it just shows as unreachable.
 *
 * Phases:
 * - idle        no slot is processing
 * - prompt      a slot is processing and has generated nothing yet
 *               (llama-swap calls this "prompt processing")
 * - generating  a slot has produced tokens. /slots cannot tell reasoning
 *               from the answer, so that distinction is not made here.
 */
class ActivityProbe
{
	public const IDLE        = 'idle';
	public const PROMPT      = 'prompt';
	public const GENERATING  = 'generating';
	public const UNREACHABLE = 'unreachable';

	public function __construct(private readonly float $timeout = 0.6) {}

	/**
	 * @return array{phase:string,busy:int,slots:int,decoded:int,slot_ctx:int,peak_ctx:int,gen_tps:float,prompt_tps:float}
	 */
	public function probe(string $host, int $port): array
	{
		$out = ['phase' => self::UNREACHABLE, 'busy' => 0, 'slots' => 0, 'decoded' => 0,
			'slot_ctx' => 0, 'peak_ctx' => 0, 'gen_tps' => 0.0, 'prompt_tps' => 0.0];

		$slots = $this->get("http://{$host}:{$port}/slots");
		if ($slots === null) {
			return $out;
		}
		$list = json_decode($slots, true);
		if (!is_array($list)) {
			return $out;
		}
		$out['phase'] = self::IDLE;
		foreach ($list as $s) {
			if (!is_array($s)) {
				continue;
			}
			$out['slots']++;
			$out['slot_ctx'] = max($out['slot_ctx'], (int)($s['n_ctx'] ?? 0));
			if (!empty($s['is_processing'])) {
				$out['busy']++;
				$out['decoded'] = max($out['decoded'], (int)($s['next_token']['n_decoded'] ?? 0));
			}
		}
		if ($out['busy'] > 0) {
			$out['phase'] = $out['decoded'] > 0 ? self::GENERATING : self::PROMPT;
		}

		$m = $this->get("http://{$host}:{$port}/metrics");
		if ($m !== null) {
			$g = self::gauges($m);
			$out['peak_ctx']   = (int)($g['llamacpp:n_tokens_max'] ?? 0);
			$out['gen_tps']    = (float)($g['llamacpp:predicted_tokens_seconds'] ?? 0.0);
			$out['prompt_tps'] = (float)($g['llamacpp:prompt_tokens_seconds'] ?? 0.0);
		}
		return $out;
	}

	/** @return array<string,float> Prometheus text format, unlabelled samples only. */
	public static function gauges(string $text): array
	{
		$out = [];
		foreach (explode("\n", $text) as $line) {
			if ($line === '' || $line[0] === '#') {
				continue;
			}
			if (preg_match('/^([A-Za-z_:][A-Za-z0-9_:]*)\s+(-?[0-9.eE+-]+)\s*$/', $line, $m)) {
				$out[$m[1]] = (float)$m[2];
			}
		}
		return $out;
	}

	private function get(string $url): ?string
	{
		$ctx  = stream_context_create(['http' => ['timeout' => $this->timeout, 'ignore_errors' => true]]);
		$body = @file_get_contents($url, false, $ctx);
		if ($body === false || !isset($http_response_header[0])) {
			return null;
		}
		return ((int)(explode(' ', $http_response_header[0])[1] ?? 0)) === 200 ? $body : null;
	}
}
