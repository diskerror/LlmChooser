<?php

declare(strict_types=1);

// Usage: php84 bin/estimate.php <preset-id>
// Prints the detected topology, the memory estimate, and the admission
// verdict for starting that preset alongside nothing.
//
// Linux only: topology detection reads /proc and /sys. On the Mac this
// throws "Cannot read memory info" — that is expected, run it on beelink.
require __DIR__ . '/../vendor/autoload.php';

use LlmChooser\Memory\LinuxTopologyProbe;
use LlmChooser\Memory\MemoryService;
use LlmChooser\Preset\PresetRepository;

if (($argv[1] ?? '') === '') {
	fwrite(STDERR, "usage: php84 bin/estimate.php <preset-id>\n");
	exit(2);
}

$root   = dirname(__DIR__);
$preset = (new PresetRepository($root . '/storage/presets'))->load($argv[1]);
$svc    = new MemoryService(new LinuxTopologyProbe());

echo json_encode([
	'topology'   => $svc->topology()->toArray(),
	'allocation' => $svc->estimate($preset)->toArray(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

$result = $svc->admit($preset, []);
echo $result->message(), "\n";
exit($result->fits ? 0 : 1);
