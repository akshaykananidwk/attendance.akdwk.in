<?php
/**
 * Database migrations.
 *
 * Files live in database/migrations and run once each, in filename order:
 *   0005_add_something.sql   — plain SQL (multiple statements allowed)
 *   0006_backfill_data.php   — PHP file that returns: function (mysqli $conn, MigrationHelper $m): void { ... }
 *
 * Applied migrations are recorded in `schema_migrations`.
 * Write migrations idempotently (IF NOT EXISTS / $m->addColumn) — they may run against databases
 * that were changed by hand in the past.
 */
final class MigrationHelper
{
    public function __construct(public mysqli $conn)
    {
    }

    public function tableExists(string $table): bool
    {
        $t = $this->conn->real_escape_string($table);
        $r = $this->conn->query("SHOW TABLES LIKE '$t'");

        return $r && $r->num_rows > 0;
    }

    public function columnExists(string $table, string $column): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }
        $c = $this->conn->real_escape_string($column);
        $r = $this->conn->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . "` LIKE '$c'");

        return $r && $r->num_rows > 0;
    }

    public function indexExists(string $table, string $index): bool
    {
        $i = $this->conn->real_escape_string($index);
        $r = $this->conn->query('SHOW INDEX FROM `' . str_replace('`', '``', $table) . "` WHERE Key_name = '$i'");

        return $r && $r->num_rows > 0;
    }

    /** Add a column only if it is missing. Returns true when it was added. */
    public function addColumn(string $table, string $column, string $definition): bool
    {
        if (!$this->tableExists($table) || $this->columnExists($table, $column)) {
            return false;
        }
        $this->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD `' . str_replace('`', '``', $column) . "` $definition");

        return true;
    }

    public function addIndex(string $table, string $index, string $columns): bool
    {
        if (!$this->tableExists($table) || $this->indexExists($table, $index)) {
            return false;
        }
        $this->exec('CREATE INDEX `' . $index . '` ON `' . $table . "` ($columns)");

        return true;
    }

    public function exec(string $sql): void
    {
        if (!$this->conn->query($sql)) {
            throw new RuntimeException('SQL error ' . $this->conn->errno . ': ' . $this->conn->error . ' in: ' . mb_substr($sql, 0, 200));
        }
    }

    public function runSql(string $sql): int
    {
        return SqlSplitter::run($this->conn, $sql);
    }
}

final class MigrationRunner
{
    private string $dir;

    public function __construct(private mysqli $conn, ?string $dir = null)
    {
        $this->dir = $dir ?? APP_ROOT . '/database/migrations';
    }

    public function ensureTable(): void
    {
        $ok = $this->conn->query('CREATE TABLE IF NOT EXISTS schema_migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(191) NOT NULL,
            batch INT NOT NULL DEFAULT 1,
            checksum CHAR(40) NOT NULL DEFAULT \'\',
            duration_ms INT NOT NULL DEFAULT 0,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_migration (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if (!$ok) {
            throw new RuntimeException('Cannot create schema_migrations: ' . $this->conn->error);
        }
    }

    /** @return string[] migration file names available on disk, sorted */
    public function available(?string $dir = null): array
    {
        $files = glob(($dir ?? $this->dir) . '/*.{sql,php}', GLOB_BRACE) ?: [];
        $names = array_map('basename', $files);
        $names = array_values(array_filter($names, static fn ($n) => preg_match('/^\d{4}_[A-Za-z0-9_\-]+\.(sql|php)$/', $n)));
        sort($names, SORT_STRING);

        return $names;
    }

    /** @return string[] */
    public function applied(): array
    {
        $this->ensureTable();
        $out = [];
        $r = $this->conn->query('SELECT migration FROM schema_migrations ORDER BY migration');
        while ($r && ($row = $r->fetch_row())) {
            $out[] = $row[0];
        }

        return $out;
    }

    /** @return string[] */
    public function pending(?string $dir = null): array
    {
        return array_values(array_diff($this->available($dir), $this->applied()));
    }

    /**
     * Run all pending migrations. $log receives progress lines.
     * @return string[] migrations that were applied
     */
    public function migrate(?callable $log = null): array
    {
        $log ??= static function (string $m): void {
        };
        $this->ensureTable();
        $pending = $this->pending();
        if (!$pending) {
            $log('No pending migrations.');

            return [];
        }
        $batch = (int) ($this->conn->query('SELECT COALESCE(MAX(batch),0)+1 FROM schema_migrations')->fetch_row()[0]);
        $helper = new MigrationHelper($this->conn);
        $done = [];
        foreach ($pending as $name) {
            $path = $this->dir . '/' . $name;
            $t0 = microtime(true);
            $log("Migrating: $name");
            if (str_ends_with($name, '.sql')) {
                $n = SqlSplitter::run($this->conn, (string) file_get_contents($path));
                $log("  … $n statement(s)");
            } else {
                $fn = (static function (string $__file) {
                    return require $__file;
                })($path);
                if (!is_callable($fn)) {
                    throw new RuntimeException("Migration $name must return a callable.");
                }
                $fn($this->conn, $helper);
            }
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $sum = sha1_file($path) ?: '';
            $stmt = $this->conn->prepare('INSERT INTO schema_migrations (migration, batch, checksum, duration_ms) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('sisi', $name, $batch, $sum, $ms);
            if (!$stmt->execute()) {
                throw new RuntimeException("Cannot record migration $name: " . $stmt->error);
            }
            $stmt->close();
            $log("Migrated:  $name ({$ms} ms)");
            $done[] = $name;
        }

        return $done;
    }
}
