<?php
// Usage: php84 bin/scan-models.php [root ...]
// With no roots, uses config/model_roots.json.

require __DIR__ . '/../vendor/autoload.php';

use LLMchooser\Model\ModelRootsConfig;
use LLMchooser\Model\ModelScanner;

$projectRoot = dirname(__DIR__);
$roots       = array_slice($argv, 1);

$scanner = $roots
	? new ModelScanner($roots)
	: ModelScanner::fromConfig(ModelRootsConfig::load($projectRoot . '/config/model_roots.json'), $projectRoot);

foreach ($scanner->scan() as $e) {
	printf(
		"%-70s %8.1f GB  %-10s ctx=%-7s %s%s\n",
		$e->displayName,
		$e->sizeBytes / 1024 ** 3,
		$e->info?->architecture() ?? '-',
		$e->info?->contextLength() ?? '-',
		$e->isSharded() ? '[' . count($e->shardPaths) . ' shards] ' : '',
		$e->error ? "ERROR: {$e->error}" : ''
	);
}
