<?php

declare(strict_types=1);

/*
 * Loads the classes of ddd-renames without Composer's autoloader (the bin runs without the application's code).
 */

foreach (['RenameEntry', 'RenameHit', 'RenameMap', 'PhpFileContext', 'RenameMatcher', 'PackageRenames', 'RenameRun', 'RenamesCommandLine'] as $renamesClassName) {
    require_once __DIR__ . '/' . $renamesClassName . '.php';
}
