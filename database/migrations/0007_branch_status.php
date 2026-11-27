<?php
/** Branch active / inactive status (shown on Branch Management). */
return function (mysqli $conn, MigrationHelper $m): void {
    $m->addColumn('branches', 'status', "VARCHAR(10) NOT NULL DEFAULT 'active'");
};
