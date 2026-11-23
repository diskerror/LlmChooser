<?php

declare(strict_types=1);

namespace LlmChooser\Model;

/**
 * The n-gram (per-layer token embedding) table of a Qwen3.8-Flash-Next
 * (arch "qwen4exp") model. llama.cpp memory-maps it and reads rows on demand
 * (--lazy-mode), so its bytes are on disk but mostly not resident. File sizes
 * therefore overstate RAM use (table inside the model file) or leave the
 * table out (table in a separate file, named by qwen4exp.ngram_file relative
 * to the model's folder). The estimate does not model this yet; the UI only
 * flags it.
 */
final class NgramTable
{
	public const ARCH = 'qwen4exp';
	public const FILE_KEY = 'qwen4exp.ngram_file';

	/** Footnote for sizes marked with SIZE_MARK. */
	public const EMBEDDED_NOTE = '* Includes the n-gram table, which llama.cpp memory-maps and reads on demand (--lazy-mode); '
		. 'RAM in use will be well below this size, and the estimate overstates it.';

	/** Footnote for sizes with "+ … n-grams". */
	public const SEPARATE_NOTE = '+ The n-gram table is a separate file, memory-mapped and read on demand (--lazy-mode); '
		. 'it is not counted in the model size or the estimate.';

	public const SIZE_MARK = '*';

	/**
	 * @param bool    $embedded  The table is inside the model file.
	 * @param ?string $file_path  Resolved path of the separate table, or null.
	 * @param ?int    $file_bytes Size of the separate table, or null if missing/unreadable.
	 */
	private function __construct(
		public readonly bool $embedded,
		public readonly ?string $file_path,
		public readonly ?int $file_bytes,
	) {}

	/** Null when the model has no n-gram table (any other architecture, or no header). */
	public static function of(string $model_path, ?GgufInfo $info): ?self
	{
		if ($info === null || $info->architecture() !== self::ARCH) {
			return null;
		}
		$rel = $info->get(self::FILE_KEY);
		if (!is_string($rel) || $rel === '') {
			// The architecture requires the table, so without a pointer it is embedded.
			return new self(true, null, null);
		}
		// llama.cpp resolves the pointer from the model's folder, not the CWD.
		$path  = str_starts_with($rel, '/') ? $rel : dirname($model_path) . '/' . $rel;
		$bytes = is_file($path) ? @filesize($path) : false;
		return new self(false, $path, $bytes === false ? null : $bytes);
	}

	/** Same as of(), reading the header itself; null if it can't be read. */
	public static function forPath(string $model_path): ?self
	{
		if ($model_path === '') {
			return null;
		}
		try {
			return self::of($model_path, GgufReader::read($model_path));
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Text appended to an estimate: " (+95.4 GiB)" for a separate
	 * table of known size, else "" (an embedded table is already inside
	 * the estimate).
	 *
	 * @param callable(int):string $format
	 */
	public function estimateSuffix(callable $format): string
	{
		return !$this->embedded && $this->file_bytes !== null
			? ' (+' . $format($this->file_bytes) . ')'
			: '';
	}

	/**
	 * Text appended to a model size: "*" (embedded), " + 102.4 GB"
	 * (separate), " + n-grams (missing)" (pointer to a file that isn't there).
	 *
	 * @param callable(int):string $format Byte formatter used for the size next to it.
	 */
	public function sizeSuffix(callable $format): string
	{
		if ($this->embedded) {
			return self::SIZE_MARK;
		}
		return $this->file_bytes !== null
			? ' + ' . $format($this->file_bytes)
			: ' + n-grams (missing)';
	}

	/**
	 * True when llama-server will read this table on demand instead of
	 * loading it: embedded, and lazy_mode not 'off' ('auto' is on for
	 * tensors over 4 GiB, which every n-gram table we know of is). The
	 * estimator still counts it as resident, so the estimate overstates.
	 */
	public function loadsLazily(string $lazy_mode): bool
	{
		return $this->embedded && $lazy_mode !== 'off';
	}

	public function note(): string
	{
		return $this->embedded ? self::EMBEDDED_NOTE : self::SEPARATE_NOTE;
	}
}
