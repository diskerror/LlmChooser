<?php

declare(strict_types=1);

namespace LlmChooser\Model;

/**
 * Reads only the GGUF header and metadata key/values. Never reads weights.
 */
final class GgufReader
{
	public const T_UINT8   = 0;
	public const T_INT8    = 1;
	public const T_UINT16  = 2;
	public const T_INT16   = 3;
	public const T_UINT32  = 4;
	public const T_INT32   = 5;
	public const T_FLOAT32 = 6;
	public const T_BOOL    = 7;
	public const T_STRING  = 8;
	public const T_ARRAY   = 9;
	public const T_UINT64  = 10;
	public const T_INT64   = 11;
	public const T_FLOAT64 = 12;

	/**
	 * unpack() code and byte width of each fixed-size type (all little-endian).
	 * INT16/INT32 read unsigned and are sign-fixed in scalar(): PHP has no
	 * little-endian signed code for them ('s'/'l' are machine order).
	 */
	private const FORMATS = [
		self::T_UINT8  => ['C', 1], self::T_INT8    => ['c', 1], self::T_BOOL    => ['C', 1],
		self::T_UINT16 => ['v', 2], self::T_INT16   => ['v', 2],
		self::T_UINT32 => ['V', 4], self::T_INT32   => ['V', 4], self::T_FLOAT32 => ['g', 4],
		self::T_UINT64 => ['P', 8], self::T_INT64   => ['P', 8], self::T_FLOAT64 => ['e', 8],
	];

	/** Arrays longer than this are skipped (not stored). */
	public const MAX_STORED_ARRAY = 4096;

	/** Sanity limit for a single string (a corrupt length would otherwise allocate GBs). */
	private const MAX_STRING_BYTES = 16 * 1024 * 1024;

	/** @var resource */
	private $fh;

	private string $path;

	public static function read(string $path): GgufInfo
	{
		$r = new self();
		return $r->readFile($path);
	}

	private function readFile(string $path): GgufInfo
	{
		$this->path = $path;
		$fh         = @fopen($path, 'rb');
		if ($fh === false) {
			throw new GgufException("Cannot open: {$path}");
		}
		$this->fh = $fh;

		try {
			if ($this->bytes(4) !== 'GGUF') {
				throw new GgufException("Not a GGUF file: {$path}");
			}
			$version = $this->u32();
			if ($version !== 2 && $version !== 3) {
				throw new GgufException("Unsupported GGUF version {$version}: {$path}");
			}
			$tensor_count = $this->u64();
			$kv_count     = $this->u64();

			$meta = [];
			for ($i = 0; $i < $kv_count; ++$i) {
				$key  = $this->str();
				$type = $this->u32();
				if ($type === self::T_ARRAY) {
					$value = $this->arrayValue();
					if ($value !== null) {
						$meta[$key] = $value;
					}
				}
				else {
					$meta[$key] = $this->scalar($type);
				}
			}

			// Tensor info section (GGUF spec v2/v3): per tensor — name,
			// n_dims (uint32), dims (n_dims x uint64), ggml type (uint32 —
			// NOT uint16; gguf-py gguf_reader.py _get_tensor_info_field
			// reads np.uint32), offset (uint64). We keep name/dims/type and
			// stop; the data section is never touched.
			$tensors = [];
			for ($i = 0; $i < $tensor_count; ++$i) {
				$name   = $this->str();
				$n_dims  = $this->u32();
				if ($n_dims > 16) {
					throw new GgufException("Implausible tensor dimension count {$n_dims}: {$path}");
				}
				$dims = [];
				for ($d = 0; $d < $n_dims; ++$d) {
					$dims[] = $this->u64();
				}
				$tensors[] = ['name' => $name, 'dims' => $dims, 'type' => $this->u32()];
				$this->u64(); // offset within the data section — not needed
			}

			return new GgufInfo($version, $tensor_count, $meta, $tensors);
		}
		finally {
			fclose($fh);
		}
	}

	private function bytes(int $n): string
	{
		if ($n === 0) {
			return '';
		}
		$out = '';
		while (strlen($out) < $n) {
			$chunk = fread($this->fh, $n - strlen($out));
			if ($chunk === false || $chunk === '') {
				throw new GgufException("Unexpected end of file: {$this->path}");
			}
			$out .= $chunk;
		}
		return $out;
	}

	private function skip(int $n): void
	{
		if ($n > 0 && fseek($this->fh, $n, SEEK_CUR) !== 0) {
			throw new GgufException("Seek failed: {$this->path}");
		}
	}

	private function u32(): int
	{
		return $this->scalar(self::T_UINT32);
	}

	private function u64(): int
	{
		return $this->scalar(self::T_UINT64);
	}

	private function str(): string
	{
		$len = $this->u64();
		if ($len < 0 || $len > self::MAX_STRING_BYTES) {
			throw new GgufException("Bad string length {$len}: {$this->path}");
		}
		return $this->bytes($len);
	}

	private function scalar(int $type): mixed
	{
		if ($type === self::T_STRING) {
			return $this->str();
		}
		if (!isset(self::FORMATS[$type])) {
			throw new GgufException("Unknown value type {$type}: {$this->path}");
		}
		[$code, $size] = self::FORMATS[$type];
		$v = unpack($code, $this->bytes($size))[1];

		// 64-bit values need no fix: PHP ints are signed 64-bit already.
		return match ($type) {
			self::T_BOOL  => $v !== 0,
			self::T_INT16 => $v >= 0x8000 ? $v - 0x10000 : $v,
			self::T_INT32 => $v >= 0x80000000 ? $v - 0x100000000 : $v,
			default       => $v,
		};
	}

	/**
	 * Returns the array, or null if it was skipped (too long, or strings/nested arrays).
	 */
	private function arrayValue(): ?array
	{
		$elem_type = $this->u32();
		$count     = $this->u64();

		$storable = $count <= self::MAX_STORED_ARRAY
			&& $elem_type !== self::T_STRING
			&& $elem_type !== self::T_ARRAY;

		if ($storable) {
			$out = [];
			for ($i = 0; $i < $count; ++$i) {
				$out[] = $this->scalar($elem_type);
			}
			return $out;
		}

		// Skip.
		if (isset(self::FORMATS[$elem_type])) {
			$this->skip($count * self::FORMATS[$elem_type][1]);
			return null;
		}
		for ($i = 0; $i < $count; ++$i) {
			if ($elem_type === self::T_STRING) {
				$this->skip($this->u64());
			}
			elseif ($elem_type === self::T_ARRAY) {
				$this->arrayValue();
			}
			else {
				throw new GgufException("Unknown array element type {$elem_type}: {$this->path}");
			}
		}
		return null;
	}
}
