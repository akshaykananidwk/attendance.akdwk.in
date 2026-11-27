<?php
/**
 * Central configuration loader.
 *
 * Config sources (later wins):
 *   1. defaults
 *   2. environment variables (AKDWK_DB_HOST, AKDWK_DB_USER, AKDWK_DB_PASS, AKDWK_DB_NAME, AKDWK_DB_PORT)
 *   3. config.local.php (written by /install, never overwritten by the updater)
 *
 * No secrets are stored in this file or anywhere in the repository.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('APP_STORAGE')) {
    define('APP_STORAGE', APP_ROOT . '/storage');
}

function app_config(bool $reload = false): array
{
    static $cfg = null;
    if ($cfg !== null && !$reload) {
        return $cfg;
    }
    $cfg = [
        'db_host' => getenv('AKDWK_DB_HOST') ?: 'localhost',
        'db_port' => (int) (getenv('AKDWK_DB_PORT') ?: 3306),
        'db_user' => getenv('AKDWK_DB_USER') ?: '',
        'db_pass' => getenv('AKDWK_DB_PASS') ?: '',
        'db_name' => getenv('AKDWK_DB_NAME') ?: '',
        'app_url' => '',
        'backup_token' => '',
    ];
    $local = APP_ROOT . '/config.local.php';
    if (is_readable($local)) {
        $data = include $local;
        if (is_array($data)) {
            $cfg = array_merge($cfg, $data);
        }
    }
    $cfg['db_port'] = (int) ($cfg['db_port'] ?: 3306);

    return $cfg;
}

/** True when DB credentials exist (user + database name). Password may legitimately be empty on local setups. */
function app_is_configured(): bool
{
    $c = app_config();

    return ($c['db_user'] ?? '') !== '' && ($c['db_name'] ?? '') !== '';
}

function app_is_installed(): bool
{
    return is_file(APP_STORAGE . '/installed.lock');
}

/** Ensure storage folders exist and are protected from web access. */
function app_ensure_storage(): void
{
    foreach (['', '/backups', '/logs', '/updates', '/cache'] as $sub) {
        $dir = APP_STORAGE . $sub;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
    $ht = APP_STORAGE . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nOptions -Indexes\n");
    }
    $idx = APP_STORAGE . '/index.html';
    if (!is_file($idx)) {
        @file_put_contents($idx, '');
    }
}

/**
 * 32-byte application secret, used to encrypt the GitHub token and sign health checks.
 * Kept in storage/app.key (protected path) — generated on first use.
 */
function app_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $file = APP_STORAGE . '/app.key';
    if (is_readable($file)) {
        $raw = base64_decode(trim((string) file_get_contents($file)), true);
        if ($raw !== false && strlen($raw) === 32) {
            return $key = $raw;
        }
    }
    app_ensure_storage();
    $raw = random_bytes(32);
    if (@file_put_contents($file, base64_encode($raw), LOCK_EX) === false) {
        throw new RuntimeException('Cannot write storage/app.key — make storage/ writable.');
    }
    @chmod($file, 0600);

    return $key = $raw;
}

function app_encrypt(string $plain): string
{
    $key = app_key();
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

    return 'o1:' . base64_encode($iv . $tag . $ct);
}

function app_decrypt(string $blob): ?string
{
    if ($blob === '') {
        return null;
    }
    $key = app_key();
    if (strncmp($blob, 's1:', 3) === 0 && function_exists('sodium_crypto_secretbox_open')) {
        $raw = base64_decode(substr($blob, 3), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $out = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);

        return $out === false ? null : $out;
    }
    if (strncmp($blob, 'o1:', 3) === 0) {
        $raw = base64_decode(substr($blob, 3), true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $out === false ? null : $out;
    }

    return null;
}

/** Token that authorises the detailed /health.php report (used by the updater's loopback check). */
function app_health_token(): string
{
    return hash_hmac('sha256', 'health-check', app_key());
}

/** Installed version info written by the installer / updater (storage/version.json), falling back to repo version.json. */
function app_version_info(): array
{
    $info = ['version' => '0.0.0', 'commit' => '', 'branch' => '', 'updated_at' => ''];
    $repo = APP_ROOT . '/version.json';
    if (is_readable($repo)) {
        $j = json_decode((string) file_get_contents($repo), true);
        if (is_array($j) && isset($j['version'])) {
            $info['version'] = (string) $j['version'];
        }
    }
    $state = APP_STORAGE . '/version.json';
    if (is_readable($state)) {
        $j = json_decode((string) file_get_contents($state), true);
        if (is_array($j)) {
            $info = array_merge($info, array_intersect_key($j, $info));
        }
    }

    return $info;
}

/** Maintenance flag handling (set by the updater while files are being swapped). */
function app_maintenance_active(): bool
{
    $f = APP_STORAGE . '/maintenance.flag';
    if (!is_file($f)) {
        return false;
    }
    $j = json_decode((string) @file_get_contents($f), true);
    $until = (int) ($j['until'] ?? 0);
    if ($until > 0 && $until < time()) {
        @unlink($f); // stale flag (crashed update) — never lock the site out forever

        return false;
    }

    return true;
}

/** Relative URL prefix from the current script to the application root (e.g. "../" for admin pages). */
function app_relative_root(): string
{
    $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
    $root = realpath(APP_ROOT) ?: APP_ROOT;
    if ($script === '' || strpos($script, $root) !== 0) {
        return '/';
    }
    $rel = trim(str_replace('\\', '/', substr(dirname($script), strlen($root))), '/');

    return $rel === '' ? './' : str_repeat('../', substr_count($rel, '/') + 1);
}

/** Cache-busting version for CSS/JS links (changes whenever the file changes, e.g. after an update). */
function app_asset_version(string $rel = 'assets/saas_theme.css'): string
{
    $f = APP_ROOT . '/' . ltrim($rel, '/');

    return is_file($f) ? substr(md5((string) filemtime($f) . filesize($f)), 0, 10) : '1';
}
