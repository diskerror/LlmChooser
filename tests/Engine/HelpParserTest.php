<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Engine;

use LlmChooser\Engine\HelpParser;
use PHPUnit\Framework\TestCase;

class HelpParserTest extends TestCase
{
	private const SAMPLE = <<<'TXT'
----- common params -----

-h,    --help, --usage                  print usage and exit
-c,    --ctx-size N                     size of the prompt context (default: 0, 0 = loaded from model)
                                        (env: LLAMA_ARG_CTX_SIZE)
--spec-draft-model, -md, --model-draft FNAME
                                        draft model for speculative decoding (default: unused)
--mmap, --no-mmap                       whether to memory-map model.
                                        (default: enabled)

----- sampling params -----

--temp N                                temperature (default: 0.8)
TXT;

	public function test_parses_specs_descriptions_and_skips_section_headings(): void
	{
		$e = HelpParser::parse(self::SAMPLE);
		$this->assertSame(['--help', '--ctx-size', '--spec-draft-model', '--mmap', '--temp'], array_keys($e));
		$this->assertSame('-c,    --ctx-size N', $e['--ctx-size']['flags']);
		$this->assertSame('size of the prompt context (default: 0, 0 = loaded from model) (env: LLAMA_ARG_CTX_SIZE)', $e['--ctx-size']['text']);
	}

	public function test_overlong_spec_puts_description_on_following_lines(): void
	{
		$e = HelpParser::parse(self::SAMPLE);
		$this->assertSame('--spec-draft-model, -md, --model-draft FNAME', $e['--spec-draft-model']['flags']);
		$this->assertSame('draft model for speculative decoding (default: unused)', $e['--spec-draft-model']['text']);
	}

	public function test_multi_line_description_joined_and_blank_line_ends_entry(): void
	{
		$e = HelpParser::parse(self::SAMPLE);
		$this->assertSame('whether to memory-map model. (default: enabled)', $e['--mmap']['text']);
	}

	public function test_alias_lookup_finds_entry_listed_under_a_newer_name(): void
	{
		$e = HelpParser::parse(self::SAMPLE);
		$i = HelpParser::aliasIndex($e);
		$this->assertSame('draft model for speculative decoding (default: unused)', HelpParser::match($e, $i, '-md, --model-draft')['text']);
		$this->assertSame('whether to memory-map model. (default: enabled)', HelpParser::match($e, $i, '--no-mmap')['text']);
		$this->assertNull(HelpParser::match($e, $i, '(none)'));
		$this->assertNull(HelpParser::match($e, $i, '--does-not-exist'));
	}

	public function test_hash_ignores_whitespace_but_not_wording(): void
	{
		$this->assertSame(HelpParser::hash("a  b\n c"), HelpParser::hash('a b c'));
		$this->assertNotSame(HelpParser::hash('a b c'), HelpParser::hash('a b d'));
	}

	public function test_bundled_snapshot_parses_every_option_line(): void
	{
		$text = (string)file_get_contents(__DIR__ . '/../../docs/llama-server-help.txt');
		preg_match_all('/^-(?!-{2,})\S/m', $text, $m);
		$this->assertCount(count($m[0]), HelpParser::parse($text));
	}
}
