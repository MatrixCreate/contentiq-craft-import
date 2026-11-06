<?php

/**
 * Release check: ContentIQImporter::VERSION must equal the newest CHANGELOG.md
 * entry. VERSION drifted once (still 1.38.0 at the 1.39.0 tag); run this
 * before tagging.
 *
 *   php tests/run-release-check.php
 *
 * Zero dependencies, no Craft. Exits non-zero on mismatch.
 */

$root      = dirname(__DIR__);
$classFile = file_get_contents($root . '/src/ContentIQImporter.php');
$changelog = file_get_contents($root . '/CHANGELOG.md');

if (!preg_match("/const VERSION = '([^']+)';/", (string)$classFile, $v)) {
    echo "FAIL  could not find ContentIQImporter::VERSION\n";
    exit(1);
}

if (!preg_match('/^## (\d+\.\d+\.\d+)\b/m', (string)$changelog, $c)) {
    echo "FAIL  could not find a '## x.y.z' heading in CHANGELOG.md\n";
    exit(1);
}

if ($v[1] !== $c[1]) {
    echo "FAIL  ContentIQImporter::VERSION is {$v[1]} but the top CHANGELOG entry is {$c[1]}\n";
    exit(1);
}

echo "OK: VERSION {$v[1]} matches the top CHANGELOG entry\n";
