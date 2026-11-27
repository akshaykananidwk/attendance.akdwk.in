<?php
/**
 * Splits a SQL script into individual statements.
 * Handles '…', "…", `…` quoting (with backslash escapes), -- / # line comments,
 * C-style comments (MySQL /*! … *\/ conditional comments are kept) and the DELIMITER command.
 */
final class SqlSplitter
{
    /** @return string[] */
    public static function split(string $sql): array
    {
        $out = [];
        $len = strlen($sql);
        $buf = '';
        $delim = ';';
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];
            $n = $i + 1 < $len ? $sql[$i + 1] : '';

            // DELIMITER command at start of a statement
            if (trim($buf) === '' && ($c === 'D' || $c === 'd') && strncasecmp(substr($sql, $i, 10), 'DELIMITER ', 10) === 0) {
                $eol = strpos($sql, "\n", $i);
                $eol = $eol === false ? $len : $eol;
                $delim = trim(substr($sql, $i + 10, $eol - $i - 10));
                $buf = '';
                $i = $eol + 1;
                continue;
            }
            // Line comments
            if (($c === '-' && $n === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) || $c === '#') {
                $eol = strpos($sql, "\n", $i);
                $i = $eol === false ? $len : $eol + 1;
                $buf .= "\n";
                continue;
            }
            // Block comments (keep MySQL conditional /*! ... */)
            if ($c === '/' && $n === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $len : $end + 2;
                if ($i + 2 < $len && $sql[$i + 2] === '!') {
                    $buf .= substr($sql, $i, $end - $i);
                }
                $i = $end;
                continue;
            }
            // Quoted strings / identifiers
            if ($c === "'" || $c === '"' || $c === '`') {
                $j = $i + 1;
                while ($j < $len) {
                    if ($sql[$j] === '\\' && $c !== '`') {
                        $j += 2;
                        continue;
                    }
                    if ($sql[$j] === $c) {
                        if ($j + 1 < $len && $sql[$j + 1] === $c) { // doubled quote
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $buf .= substr($sql, $i, $j - $i + 1);
                $i = $j + 1;
                continue;
            }
            // Delimiter
            if ($c === $delim[0] && substr($sql, $i, strlen($delim)) === $delim) {
                $stmt = trim($buf);
                if ($stmt !== '') {
                    $out[] = $stmt;
                }
                $buf = '';
                $i += strlen($delim);
                continue;
            }
            $buf .= $c;
            $i++;
        }
        $stmt = trim($buf);
        if ($stmt !== '') {
            $out[] = $stmt;
        }

        return $out;
    }

    /** Execute every statement; throws on the first failure. Returns number of statements run. */
    public static function run(mysqli $conn, string $sql): int
    {
        $count = 0;
        foreach (self::split($sql) as $stmt) {
            if (!$conn->query($stmt)) {
                throw new RuntimeException('SQL error ' . $conn->errno . ': ' . $conn->error . ' in: ' . mb_substr($stmt, 0, 200));
            }
            $count++;
        }

        return $count;
    }
}
