<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Model;

use LlmChooser\Model\GgufReader as R;

final class GgufFixture
{
	/**
	 * @param list<array{0:string,1:int,2:mixed}> $kv  [key, type, value]
	 *        For R::T_ARRAY the value is [elementType, list<values>].
	 * @param int $pad_bytes extra zero bytes appended (to give the file a size)
	 * @param list<array{0:string,1:list<int>,2:int,3?:int}> $tensors
	 *        [name, dims, ggml_type, bytes] written as the tensor info
	 *        section. When any tensor has bytes, offsets are laid out in
	 *        order and that much (zero) tensor data follows the header,
	 *        aligned to 32; $pad_bytes then lands after the data (so it
	 *        counts toward the last tensor). Without bytes all offsets are 0.
	 */
	public static function write(string $path, array $kv, int $tensor_count = 0, int $pad_bytes = 0, int $version = 3, array $tensors = []): void
	{
		$dir = dirname($path);
		if (!is_dir($dir)) {
			mkdir($dir, 0775, true);
		}
		$b = 'GGUF' . pack('V', $version) . pack('P', $tensor_count) . pack('P', count($kv));
		foreach ($kv as [$key, $type, $value]) {
			$b .= self::str($key) . pack('V', $type) . self::value($type, $value);
		}
		$offset = 0;
		$sized  = false;
		foreach ($tensors as $t) {
			[$name, $dims, $type] = $t;
			$b .= self::str($name) . pack('V', count($dims));
			foreach ($dims as $d) {
				$b .= pack('P', $d);
			}
			$b .= pack('V', $type) . pack('P', isset($t[3]) ? $offset : 0); // type (uint32 per spec), offset
			if (isset($t[3])) {
				$offset += $t[3];
				$sized = true;
			}
		}
		if ($sized) {
			$b .= str_repeat("\0", (32 - strlen($b) % 32) % 32) . str_repeat("\0", $offset);
		}
		$b .= str_repeat("\0", $pad_bytes);
		file_put_contents($path, $b);
	}

	/** A minimal plausible llama-style model header. */
	public static function model(string $path, string $arch = 'llama', int $pad_bytes = 1000, array $extra = []): void
	{
		$kv = array_merge([
			['general.architecture', R::T_STRING, $arch],
			['general.name', R::T_STRING, 'Fixture Model'],
			["{$arch}.block_count", R::T_UINT32, 32],
			["{$arch}.context_length", R::T_UINT32, 8192],
			["{$arch}.embedding_length", R::T_UINT32, 4096],
			["{$arch}.attention.head_count", R::T_UINT32, 32],
			["{$arch}.attention.head_count_kv", R::T_UINT32, 8],
		], $extra);
		self::write($path, $kv, 0, $pad_bytes);
	}

	private static function str(string $s): string
	{
		return pack('P', strlen($s)) . $s;
	}

	private static function value(int $type, mixed $v): string
	{
		return match ($type) {
			R::T_UINT8, R::T_BOOL => pack('C', (int)$v),
			R::T_INT8             => pack('c', $v),
			R::T_UINT16, R::T_INT16 => pack('v', $v & 0xFFFF),
			R::T_UINT32, R::T_INT32 => pack('V', $v & 0xFFFFFFFF),
			R::T_FLOAT32          => pack('g', $v),
			R::T_STRING           => self::str($v),
			R::T_UINT64           => pack('P', $v),
			R::T_INT64            => pack('q', $v),
			R::T_FLOAT64          => pack('e', $v),
			R::T_ARRAY            => self::arr($v[0], $v[1]),
		};
	}

	private static function arr(int $elem_type, array $values): string
	{
		$b = pack('V', $elem_type) . pack('P', count($values));
		foreach ($values as $v) {
			$b .= self::value($elem_type, $v);
		}
		return $b;
	}
}
