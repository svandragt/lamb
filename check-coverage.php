<?php

/**
 * Parses a Clover XML coverage report and exits with code 1 if coverage is below the threshold.
 *
 * Usage: php check-coverage.php <clover-xml-file> [threshold]
 */

$file = $argv[1] ?? 'tests/_output/coverage.xml';
$threshold = (float) ($argv[2] ?? 80);

if (!file_exists($file)) {
    fwrite(STDERR, "Coverage file not found: $file\n");
    exit(1);
}

$xml = simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "Failed to parse coverage file: $file\n");
    exit(1);
}

$metrics = $xml->xpath('//project/metrics');
if (empty($metrics)) {
    fwrite(STDERR, "No metrics found in coverage file.\n");
    exit(1);
}

$total = (int) $metrics[0]['statements'];
$covered = (int) $metrics[0]['coveredstatements'];

if ($total === 0) {
    fwrite(STDERR, "No statements found in coverage report.\n");
    exit(1);
}

$coverage = ($covered / $total) * 100;
$coverageFormatted = number_format($coverage, 2);

echo "Coverage: {$coverageFormatted}% ({$covered}/{$total} statements)\n";

if ($coverage < $threshold) {
    fwrite(STDERR, "Coverage {$coverageFormatted}% is below the required {$threshold}%.\n");
    exit(1);
}

echo "Coverage threshold of {$threshold}% met.\n";
