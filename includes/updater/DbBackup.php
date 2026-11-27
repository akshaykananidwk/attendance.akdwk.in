<?php
/**
 * Pure-PHP MySQL/MariaDB backup + restore (no exec()/mysqldump needed — works on shared hosting).
 * Output: gzip-compressed SQL, one statement per line block, restorable by phpMyAdmin too.
 */
final class DbBackup
{
    private const BATCH = 500;

    public function __construct(private mysqli $conn)
    {
    }

    /** @return string[] base tables in the current database */
    public function tables(): array
    {
        $t = [];
        $r = $this->conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while ($r && ($row = $r->fetch_row())) {
            $t[] = $row[0];
        }

        return $t;
    }

    /** Dump whole database to $file (.sql.gz). Returns ['tables'=>n,'rows'=>n,'bytes'=>n]. */
    public function dump(string $file): array
    {
        $gz = gzopen($file, 'wb6');
        if (!$gz) {
            throw new RuntimeException("Cannot write DB backup: $file");
        }
        $db = $this->conn->query('SELECT DATABASE()')->fetch_row()[0];
        $w = static function (string $s) use ($gz): void {
            if (gzwrite($gz, $s) === false) {
                throw new RuntimeException('DB backup write failed (disk full?)');
            }
        };
        $w("-- AKDWK database backup\n-- Database: `$db`\n-- Created: " . date('c') . "\n\n");
        $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n\n");
        $this->conn->query("SET time_zone='+00:00'");
        $rowsTotal = 0;
        $tables = $this->tables();
        foreach ($tables as $table) {
            $q = '`' . str_replace('`', '``', $table) . '`';
            $create = $this->conn->query("SHOW CREATE TABLE $q")->fetch_row()[1];
            $w("DROP TABLE IF EXISTS $q;\n$create;\n\n");
            $res = $this->conn->query("SELECT * FROM $q", MYSQLI_USE_RESULT);
            if (!$res) {
                throw new RuntimeException("Backup read failed for $table: " . $this->conn->error);
            }
            $fields = $res->fetch_fields();
            $binary = [];
            foreach ($fields as $i => $f) {
                $binary[$i] = ($f->flags & MYSQLI_BINARY_FLAG) && in_array($f->type, [MYSQLI_TYPE_BLOB, MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_STRING, MYSQLI_TYPE_VAR_STRING], true) && $f->charsetnr === 63;
            }
            $rows = [];
            while ($row = $res->fetch_row()) {
                $vals = [];
                foreach ($row as $i => $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } elseif ($binary[$i]) {
                        $vals[] = $v === '' ? "''" : '0x' . bin2hex($v);
                    } else {
                        $vals[] = "'" . $this->conn->real_escape_string((string) $v) . "'";
                    }
                }
                $rows[] = '(' . implode(',', $vals) . ')';
                $rowsTotal++;
                if (count($rows) >= self::BATCH) {
                    $w("INSERT INTO $q VALUES\n" . implode(",\n", $rows) . ";\n");
                    $rows = [];
                }
            }
            $res->free();
            if ($rows) {
                $w("INSERT INTO $q VALUES\n" . implode(",\n", $rows) . ";\n");
            }
            $w("\n");
        }
        $w("SET FOREIGN_KEY_CHECKS=1;\n-- END OF BACKUP\n");
        gzclose($gz);
        $this->conn->query("SET time_zone='" . date('P') . "'");

        return ['tables' => count($tables), 'rows' => $rowsTotal, 'bytes' => filesize($file)];
    }

    /** Verify that a backup file is complete (has the end marker). */
    public static function verify(string $file): bool
    {
        $sql = self::read($file);

        return $sql !== '' && str_contains(substr($sql, -200), '-- END OF BACKUP');
    }

    public static function read(string $file): string
    {
        $data = @file_get_contents($file);
        if ($data === false) {
            return '';
        }
        if (substr($data, 0, 2) === "\x1f\x8b") {
            $data = gzdecode($data);
        }

        return $data === false ? '' : $data;
    }

    /**
     * Restore database from backup. When $dropExtra is true, tables that are not in the backup
     * (e.g. created by a failed migration) are dropped so the schema matches the backup exactly.
     */
    public function restore(string $file, bool $dropExtra = true): int
    {
        $sql = self::read($file);
        if ($sql === '') {
            throw new RuntimeException('Backup file is empty or unreadable: ' . basename($file));
        }
        if (!str_contains(substr($sql, -200), '-- END OF BACKUP')) {
            throw new RuntimeException('Backup file is incomplete — refusing to restore: ' . basename($file));
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS=0');
        if ($dropExtra) {
            preg_match_all('/^DROP TABLE IF EXISTS `((?:[^`]|``)+)`;/m', $sql, $m);
            $inBackup = array_map(static fn ($t) => str_replace('``', '`', $t), $m[1]);
            foreach (array_diff($this->tables(), $inBackup) as $extra) {
                $this->conn->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $extra) . '`');
            }
        }
        $n = SqlSplitter::run($this->conn, $sql);
        $this->conn->query('SET FOREIGN_KEY_CHECKS=1');

        return $n;
    }
}
