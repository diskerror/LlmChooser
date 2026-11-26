<?php

declare(strict_types=1);

// Usage: php84 bin/estimate.php <preset-id>
// Prints the detected topology, the memory estimate, and the admission
// verdict for starting that preset alongside nothing.
//
// Topology: Linux (/proc, /sys) or macOS (sysctl, vm_stat, ioreg).
require __DIR__ . '/../vendor/autoload.php';

use LlmChooser\App;

if (($argv[1] ?? '') === '') {
	fwrite(STDERR, "usage: php84 bin/estimate.php <preset-id>\n");
	exit(2);
}

$root   = dirname(__DIR__);
$app    = new App($root);
$preset = $app->presets->load($argv[1]);
$svc    = $app->memory;

echo json_encode([
	'topology'   => $svc->topology()->toArray(),
	'allocation' => $svc->estimate($preset)->toArray(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

$result = $svc->admit($preset, []);
echo $result->message(), "\n";
exit($result->fits ? 0 : 1);
