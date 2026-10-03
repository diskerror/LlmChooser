<?php

namespace LLMchooser\Model;

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

	/** Fixed byte sizes of the non-string scalar types. */
	private const SIZES = [
		self::T_UINT8 => 1, self::T_INT8 => 1, self::T_UINT16 => 2, self::T_INT16 => 2,
		self::T_UINT32 => 4, self::T_INT32 => 4, self::T_FLOAT32 => 4, self::T_BOOL => 1,
		self::T_UINT64 => 8, self::T_INT64 => 8, self::T_FLOAT64 => 8,
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
			$tensorCount = $this->u64();
			$kvCount     = $this->u64();

			$meta = [];
			for ($i = 0; $i < $kvCount; ++$i) {
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

			return new GgufInfo($version, $tensorCount, $meta);
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
		return unpack('V', $this->bytes(4))[1];
	}

	private function u64(): int
	{
		return unpack('P', $this->bytes(8))[1];
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
		switch ($type) {
			case self::T_UINT8:   return unpack('C', $this->bytes(1))[1];
			case self::T_INT8:    return unpack('c', $this->bytes(1))[1];
			case self::T_UINT16:  return unpack('v', $this->bytes(2))[1];
			case self::T_INT16:
				$v = unpack('v', $this->bytes(2))[1];
				return $v >= 0x8000 ? $v - 0x10000 : $v;
			case self::T_UINT32:  return unpack('V', $this->bytes(4))[1];
			case self::T_INT32:
				$v = unpack('V', $this->bytes(4))[1];
				return $v >= 0x80000000 ? $v - 0x100000000 : $v;
			case self::T_FLOAT32: return unpack('g', $this->bytes(4))[1];
			case self::T_BOOL:    return unpack('C', $this->bytes(1))[1] !== 0;
			case self::T_STRING:  return $this->str();
			case self::T_UINT64:  return unpack('P', $this->bytes(8))[1];
			case self::T_INT64:   return unpack('q', $this->bytes(8))[1];
			case self::T_FLOAT64: return unpack('e', $this->bytes(8))[1];
		}
		throw new GgufException("Unknown value type {$type}: {$this->path}");
	}

	/**
	 * Returns the array, or null if it was skipped (too long, or strings/nested arrays).
	 */
	private function arrayValue(): ?array
	{
		$elemType = $this->u32();
		$count    = $this->u64();

		$storable = $count <= self::MAX_STORED_ARRAY
			&& $elemType !== self::T_STRING
			&& $elemType !== self::T_ARRAY;

		if ($storable) {
			$out = [];
			for ($i = 0; $i < $count; ++$i) {
				$out[] = $this->scalar($elemType);
			}
			return $out;
		}

		// Skip.
		if (isset(self::SIZES[$elemType])) {
			$this->skip($count * self::SIZES[$elemType]);
			return null;
		}
		for ($i = 0; $i < $count; ++$i) {
			if ($elemType === self::T_STRING) {
				$this->skip($this->u64());
			}
			elseif ($elemType === self::T_ARRAY) {
				$this->arrayValue();
			}
			else {
				throw new GgufException("Unknown array element type {$elemType}: {$this->path}");
			}
		}
		return null;
	}
}
