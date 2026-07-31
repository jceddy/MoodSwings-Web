#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MoodSwings\Database\Connection;
use MoodSwings\Database\MigrationRunner;

$applied = MigrationRunner::applyPending(
    Connection::get(),
    null,
    fn (string $name) => print("Applied {$name}\n")
);

echo $applied === [] ? "Already up to date.\n" : 'Applied ' . count($applied) . " migration(s).\n";
