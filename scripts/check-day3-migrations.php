#!/usr/bin/env php
<?php

$files = [
    'apps/api/Modules/Documents/Infrastructure/Persistence/Migrations/W18CreateDocumentGovernanceTables.php',
    'apps/api/Modules/Notifications/Infrastructure/Persistence/Migrations/W18CreateNotificationDeliveryTables.php',
    'apps/api/Modules/Search/Infrastructure/Persistence/Migrations/CreateSearchProjectionTables.php',
    'apps/api/Modules/Reporting/Infrastructure/Persistence/Migrations/CreateReportingProjectionTables.php',
];
$errors = [];
foreach ($files as $file) {
    $source = file_get_contents(__DIR__.'/../'.$file);
    if ($source === false) {
        $errors[] = "missing {$file}";
        continue;
    }
    preg_match_all("/['\"]([A-Za-z0-9_]+)['\"]\s*\);/", $source, $matches);
    foreach ($matches[1] as $identifier) {
        if (strlen($identifier) > 64 && preg_match('/_(?:idx|index|uq|unique|foreign)$/', $identifier) === 1) {
            $errors[] = "{$file}: identifier exceeds 64 characters: {$identifier}";
        }
    }
    preg_match_all("/Schema::create\(['\"]([^'\"]+)['\"].*?\$table->(?:uuid|string|id)\(['\"]([^'\"]+)['\"]\)->unique\(\)/s", $source, $automatic, PREG_SET_ORDER);
    foreach ($automatic as $match) {
        $identifier = $match[1].'_'.$match[2].'_unique';
        if (strlen($identifier) > 64) {
            $errors[] = "{$file}: automatic identifier exceeds 64 characters: {$identifier}";
        }
    }
}
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "day3 migration identifiers are within MySQL's 64-character limit\n");
