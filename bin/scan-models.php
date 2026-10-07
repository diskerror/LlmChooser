<?php

declare(strict_types=1);

// Usage: php84 bin/scan-models.php [root ...]
// With no roots, scans every configured engine's model_dir.

require __DIR__ . '/../vendor/autoload.php';

use LlmChooser\App;
use LlmChooser\Model\ModelScanner;

$projectRoot = dirname(__DIR__);
$roots       = array_slice($argv, 1);

$scanner = $roots
	? new ModelScanner($roots)
	: (new App($projectRoot))->modelScanner();

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
