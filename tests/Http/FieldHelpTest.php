<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Http;

use LlmChooser\Http\FieldHints;
use LlmChooser\Preset\Preset;
use LlmChooser\Preset\PresetArgs;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards resources/field_help.json (the single source of the form's help
 * text): every field has an entry, no orphans, and each entry has the shape
 * the form expects.
 */
class FieldHelpTest extends TestCase
{
	/**
	 * Every Preset/PresetArgs field that appears on the form. 'args' is the
	 * nested PresetArgs object itself, never a form field of its own.
	 * Only properties the two classes declare themselves — TypedClass's
	 * own internals (e.g. _map) are inherited and not fields.
	 *
	 * @return list<string>
	 */
	private static function formFields(): array
	{
		$names = [];
		foreach ([Preset::class, PresetArgs::class] as $class) {
			foreach ((new ReflectionClass($class))->getProperties() as $prop) {
				if ($prop->getDeclaringClass()->getName() !== $class || $prop->getName() === 'args') {
					continue;
				}
				$names[] = $prop->getName();
			}
		}
		return $names;
	}

	public function test_every_preset_field_has_help_entry(): void
	{
		$help = FieldHints::load();
		foreach (self::formFields() as $name) {
			$this->assertArrayHasKey($name, $help, "field '{$name}' has no help entry");
		}
	}

	public function test_no_orphaned_help_entries(): void
	{
		$fields = self::formFields();
		foreach (array_keys(FieldHints::load()) as $name) {
			$this->assertContains($name, $fields, "help entry '{$name}' matches no real field");
		}
	}

	public function test_every_entry_has_required_keys(): void
	{
		foreach (FieldHints::load() as $name => $entry) {
			foreach (['label', 'flag', 'level', 'upstream_default', 'short'] as $key) {
				$this->assertIsString($entry[$key] ?? null, "{$name}.{$key} missing or not a string");
				$this->assertNotSame('', trim($entry[$key]), "{$name}.{$key} is empty");
			}
			// 'full' may legitimately be short, but never absent.
			$this->assertIsString($entry['full'] ?? null, "{$name}.full missing");
		}
	}

	public function test_level_is_basic_or_advanced(): void
	{
		foreach (FieldHints::load() as $name => $entry) {
			$this->assertContains($entry['level'], ['basic', 'advanced'], "{$name}.level = '{$entry['level']}'");
		}
	}
}
