<?php
/**
 * Baseline: every core table of the attendance system (CREATE TABLE IF NOT EXISTS — safe on existing databases).
 */
return function (mysqli $conn, MigrationHelper $m): void {
    $m->runSql((string) file_get_contents(APP_ROOT . '/database/schema/baseline.sql'));
};
