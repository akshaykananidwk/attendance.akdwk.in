<?php
/**
 * Post-update health checks.
 *  - internal: DB reachable, core tables exist, no pending migrations, storage writable
 *  - HTTP loopback: /health.php answers 200 with JSON status=ok and reports the NEW commit
 *    (proves Apache/PHP serve the new code without fatal errors), and login page does not return 5xx.
 */
final class HealthCheck
{
    public const CORE_TABLES = ['users', 'attendance_logs', 'settings', 'companies', 'schema_migrations'];

    /** Checks that run inside health.php itself. */
    public static function internal(mysqli $conn): array
    {
        $checks = [];
        $checks['php'] = ['ok' => PHP_VERSION_ID >= 80000, 'info' => PHP_VERSION];
        $r = @$conn->query('SELECT 1');
        $checks['database'] = ['ok' => (bool) $r, 'info' => $r ? 'connected' : $conn->error];
        $missing = [];
        foreach (self::CORE_TABLES as $t) {
            $res = @$conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
            if (!$res || $res->num_rows === 0) {
                $missing[] = $t;
            }
        }
        $checks['tables'] = ['ok' => !$missing, 'info' => $missing ? 'missing: ' . implode(', ', $missing) : 'ok'];
        try {
            $pending = (new MigrationRunner($conn))->pending();
            $checks['migrations'] = ['ok' => !$pending, 'info' => $pending ? 'pending: ' . implode(', ', $pending) : 'up to date'];
        } catch (Throwable $e) {
            $checks['migrations'] = ['ok' => false, 'info' => $e->getMessage()];
        }
        $checks['storage'] = ['ok' => is_writable(APP_STORAGE), 'info' => is_writable(APP_STORAGE) ? 'writable' : 'storage/ not writable'];
        $ok = true;
        foreach ($checks as $c) {
            $ok = $ok && $c['ok'];
        }

        return ['status' => $ok ? 'ok' : 'fail', 'checks' => $checks];
    }

    /**
     * Loopback HTTP check run by the updater after files+migrations are applied.
     * @return array{ok:bool,fatal:bool,lines:string[]}
     */
    public static function http(string $appUrl, string $expectCommit): array
    {
        $lines = [];
        $base = rtrim($appUrl, '/');
        if ($base === '') {
            return ['ok' => false, 'fatal' => false, 'lines' => ['App URL unknown — HTTP check skipped']];
        }
        $get = static function (string $url): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HTTPHEADER => ['Cache-Control: no-cache', 'User-Agent: akdwk-updater-health'],
            ]);
            $body = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return [$code, $body === false ? '' : (string) $body, $body === false ? $err : ''];
        };

        [$code, $body, $err] = $get($base . '/health.php?token=' . app_health_token() . '&t=' . time());
        if ($err !== '') {
            return ['ok' => false, 'fatal' => false, 'lines' => ["Loopback request failed: $err"]];
        }
        $j = json_decode($body, true);
        $lines[] = "GET /health.php → HTTP $code";
        $ok = $code === 200 && is_array($j) && ($j['status'] ?? '') === 'ok';
        if (is_array($j)) {
            foreach (($j['checks'] ?? []) as $name => $c) {
                $lines[] = sprintf('  %s %s: %s', !empty($c['ok']) ? '✔' : '✘', $name, $c['info'] ?? '');
            }
            if ($expectCommit !== '' && ($j['commit'] ?? '') !== $expectCommit) {
                $ok = false;
                $lines[] = '  ✘ server reports commit ' . ($j['commit'] ?? '?') . ', expected ' . $expectCommit;
            }
        } else {
            $lines[] = '  ✘ invalid response: ' . mb_substr(strip_tags($body), 0, 200);
        }
        [$code2, , $err2] = $get($base . '/login.php');
        $lines[] = 'GET /login.php → ' . ($err2 !== '' ? $err2 : "HTTP $code2");
        if ($err2 === '' && ($code2 >= 500 || $code2 === 0)) {
            $ok = false;
        }

        return ['ok' => $ok, 'fatal' => true, 'lines' => $lines];
    }
}
