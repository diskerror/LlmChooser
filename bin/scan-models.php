<?php

declare(strict_types=1);

// Usage: php84 bin/scan-models.php [root ...]
// With no roots, scans every configured engine's model_dir.

require __DIR__ . '/../vendor/autoload.php';

use LlmChooser\App;
use LlmChooser\Model\ModelScanner;

$project_root = dirname(__DIR__);
$roots       = array_slice($argv, 1);

$scanner = $roots
	? new ModelScanner($roots)
	: (new App($project_root))->modelScanner();

foreach ($scanner->scan() as $e) {
	printf(
		"%-70s %8.1f GB  %-10s ctx=%-7s %s%s\n",
		$e->display_name,
		$e->size_bytes / 1024 ** 3,
		$e->info?->architecture() ?? '-',
		$e->info?->contextLength() ?? '-',
		$e->isSharded() ? '[' . count($e->shard_paths) . ' shards] ' : '',
		$e->error ? "ERROR: {$e->error}" : ''
	);
}
