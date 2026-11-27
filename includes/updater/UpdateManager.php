<?php
require_once __DIR__ . '/SqlSplitter.php';
require_once __DIR__ . '/DbBackup.php';
require_once __DIR__ . '/MigrationRunner.php';
require_once __DIR__ . '/GitHubClient.php';
require_once __DIR__ . '/FileDeployer.php';
require_once __DIR__ . '/HealthCheck.php';

/**
 * Orchestrates: check → download → backup (files + DB) → maintenance → apply → migrate
 * → cache clear → health check → finish, with automatic rollback on any failure.
 */
final class UpdateManager
{
    public const APP_ID = 'akdwk-attendance';

    private string $dir;
    /** @var resource|null */
    private $lockHandle = null;
    private array $lines = [];
    private ?string $logFile = null;
    private int $historyId = 0;
    private array $history = [];

    public function __construct(private mysqli $conn)
    {
        app_ensure_storage();
        $this->dir = APP_STORAGE . '/updates';
        $this->ensureTables();
    }

    /* ------------------------------------------------------------------ settings */

    private function ensureTables(): void
    {
        $runner = new MigrationRunner($this->conn);
        $runner->ensureTable();
        $m = new MigrationHelper($this->conn);
        if (!$m->tableExists('update_settings') || !$m->tableExists('update_history')) {
            $file = APP_ROOT . '/database/migrations/0004_auto_updater_tables.sql';
            SqlSplitter::run($this->conn, (string) file_get_contents($file));
        }
    }

    public function settings(): array
    {
        $r = $this->conn->query('SELECT * FROM update_settings WHERE id = 1');
        $s = $r ? $r->fetch_assoc() : null;
        $s = $s ?: ['repo_owner' => '', 'repo_name' => '', 'branch' => 'main', 'token_enc' => '', 'app_url' => '', 'protected_paths' => '', 'keep_backups' => 5, 'strict_health' => 1, 'last_check_at' => null, 'last_check_result' => null];
        $s['has_token'] = !empty($s['token_enc']) && app_decrypt((string) $s['token_enc']) !== null;
        $tok = $s['has_token'] ? (string) app_decrypt((string) $s['token_enc']) : '';
        $s['token_hint'] = $tok !== '' ? substr($tok, 0, 4) . '…' . substr($tok, -4) : '';
        unset($s['token_enc']);
        if (($s['app_url'] ?? '') === '') {
            $s['app_url'] = (string) (app_config()['app_url'] ?? '') ?: self::detectAppUrl();
        }

        return $s;
    }

    /** Save settings. $token === null keeps the stored token. Validates access against GitHub before saving. */
    public function saveSettings(string $owner, string $repo, string $branch, ?string $token, string $appUrl, string $protected, int $keep, bool $strict, int $userId): array
    {
        $owner = trim($owner);
        $repo = trim(preg_replace('/\.git$/', '', trim($repo)));
        $branch = trim($branch) ?: 'main';
        if (!GitHubClient::validName($owner) || !GitHubClient::validName($repo)) {
            throw new InvalidArgumentException('Repository must look like owner/name.');
        }
        if (!GitHubClient::validBranch($branch)) {
            throw new InvalidArgumentException('Invalid branch name.');
        }
        $appUrl = rtrim(trim($appUrl), '/');
        if ($appUrl !== '' && !preg_match('#^https?://[^\s/]+(/[^\s]*)?$#i', $appUrl)) {
            throw new InvalidArgumentException('App URL must start with http:// or https://');
        }
        $keep = max(1, min(50, $keep));
        $lines = array_filter(array_map('trim', preg_split('/\R/', $protected)));
        foreach ($lines as $l) {
            if (str_contains($l, '..')) {
                throw new InvalidArgumentException('Protected paths may not contain "..".');
            }
        }
        $protected = implode("\n", $lines);

        $r = $this->conn->query('SELECT token_enc FROM update_settings WHERE id = 1');
        $old = $r ? $r->fetch_assoc() : null;
        $enc = $old['token_enc'] ?? '';
        if ($token !== null && trim($token) !== '') {
            $enc = app_encrypt(trim($token));
        }
        $plain = $enc ? (string) app_decrypt($enc) : '';

        // Verify the repository + branch are reachable with this token.
        $gh = new GitHubClient($owner, $repo, $plain, app_config()['github_api'] ?? null);
        $info = $gh->repository();
        $latest = $gh->latestCommit($branch);

        $stmt = $this->conn->prepare('INSERT INTO update_settings (id, repo_owner, repo_name, branch, token_enc, app_url, protected_paths, keep_backups, strict_health, updated_by, updated_at)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE repo_owner=VALUES(repo_owner), repo_name=VALUES(repo_name), branch=VALUES(branch), token_enc=VALUES(token_enc),
              app_url=VALUES(app_url), protected_paths=VALUES(protected_paths), keep_backups=VALUES(keep_backups), strict_health=VALUES(strict_health),
              updated_by=VALUES(updated_by), updated_at=NOW()');
        $s = $strict ? 1 : 0;
        $stmt->bind_param('ssssssiii', $owner, $repo, $branch, $enc, $appUrl, $protected, $keep, $s, $userId);
        $stmt->execute();
        $stmt->close();

        return ['repo' => $info['full_name'] ?? "$owner/$repo", 'private' => !empty($info['private']), 'latest' => $latest];
    }

    private function client(): array
    {
        $r = $this->conn->query('SELECT * FROM update_settings WHERE id = 1');
        $s = $r ? $r->fetch_assoc() : null;
        if (!$s || $s['repo_owner'] === '' || $s['repo_name'] === '') {
            throw new RuntimeException('Save GitHub repository settings first.');
        }
        $token = $s['token_enc'] ? (string) app_decrypt((string) $s['token_enc']) : '';

        return [new GitHubClient($s['repo_owner'], $s['repo_name'], $token, app_config()['github_api'] ?? null), $s];
    }

    public function deployer(?array $s = null): FileDeployer
    {
        if ($s === null) {
            $r = $this->conn->query('SELECT protected_paths FROM update_settings WHERE id = 1');
            $s = $r ? ($r->fetch_assoc() ?: []) : [];
        }
        $extra = array_filter(array_map('trim', preg_split('/\R/', (string) ($s['protected_paths'] ?? ''))));
        foreach ($extra as $i => $p) {
            if (!str_ends_with($p, '/') && is_dir(APP_ROOT . '/' . ltrim($p, '/'))) {
                $extra[$i] = $p . '/';
            }
        }

        return new FileDeployer(APP_ROOT, $extra);
    }

    public static function detectAppUrl(): string
    {
        if (empty($_SERVER['HTTP_HOST'])) {
            return '';
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
        $file = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
        $root = realpath(APP_ROOT) ?: APP_ROOT;
        $relFile = ltrim(str_replace('\\', '/', substr($file, strlen($root))), '/');
        $base = $relFile !== '' && str_ends_with($script, $relFile) ? substr($script, 0, -strlen($relFile)) : '/';

        return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim($base, '/');
    }

    /* ------------------------------------------------------------------ check */

    public function check(): array
    {
        [$gh, $s] = $this->client();
        $current = app_version_info();
        $latest = $gh->latestCommit($s['branch']);
        $remoteVersionRaw = $gh->fileContents('version.json', $latest['sha']);
        $remoteVersion = $remoteVersionRaw ? (json_decode($remoteVersionRaw, true)['version'] ?? '?') : '?';
        $result = [
            'checked_at' => date('Y-m-d H:i:s'),
            'branch' => $s['branch'],
            'repo' => $s['repo_owner'] . '/' . $s['repo_name'],
            'current' => $current,
            'latest' => $latest + ['version' => $remoteVersion],
            'update_available' => $current['commit'] !== $latest['sha'],
            'commits' => [],
            'files' => [],
            'new_migrations' => [],
            'compare_status' => '',
        ];
        if ($current['commit'] !== '' && $current['commit'] !== $latest['sha']) {
            try {
                $cmp = $gh->compare($current['commit'], $latest['sha']);
                $result['commits'] = $cmp['commits'];
                $result['files'] = $cmp['files'];
                $result['compare_status'] = $cmp['status'];
            } catch (Throwable $e) {
                $result['compare_error'] = $e->getMessage();
                $result['commits'] = $gh->recentCommits($s['branch'], 10);
            }
        } elseif ($current['commit'] === '') {
            $result['commits'] = $gh->recentCommits($s['branch'], 10);
            $result['compare_error'] = 'Installed commit is unknown (first update) — showing the latest commits. The update will deploy the full latest version.';
        }
        foreach ($result['files'] as $f) {
            if (str_starts_with($f['filename'], 'database/migrations/') && $f['status'] === 'added') {
                $result['new_migrations'][] = basename($f['filename']);
            }
        }
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->conn->prepare('UPDATE update_settings SET last_check_at = NOW(), last_check_result = ? WHERE id = 1');
        $stmt->bind_param('s', $json);
        $stmt->execute();

        return $result;
    }

    /* ------------------------------------------------------------------ status / lock */

    public function statusFile(): string
    {
        return $this->dir . '/status.json';
    }

    public function status(): array
    {
        $j = json_decode((string) @file_get_contents($this->statusFile()), true);
        $j = is_array($j) ? $j : ['running' => false, 'lines' => []];
        $j['running'] = $this->isRunning();
        $j['interrupted'] = !$j['running'] && is_file($this->dir . '/journal.json');

        return $j;
    }

    public function isRunning(): bool
    {
        $f = @fopen($this->dir . '/update.lock', 'c');
        if (!$f) {
            return false;
        }
        $free = flock($f, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($f, LOCK_UN);
        }
        fclose($f);

        return !$free;
    }

    private function acquireLock(): void
    {
        $this->lockHandle = fopen($this->dir . '/update.lock', 'c');
        if (!$this->lockHandle || !flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another update is already running.');
        }
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    private function progress(string $step, int $percent): void
    {
        $this->writeStatus(['step' => $step, 'percent' => $percent]);
    }

    private function writeStatus(array $extra = []): void
    {
        static $state = [];
        $state = array_merge($state, $extra);
        $state['lines'] = array_slice($this->lines, -400);
        $state['history_id'] = $this->historyId;
        $state['updated'] = time();
        @file_put_contents($this->statusFile(), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function log(string $line): void
    {
        $l = '[' . date('H:i:s') . '] ' . $line;
        $this->lines[] = $l;
        if ($this->logFile) {
            @file_put_contents($this->logFile, $l . "\n", FILE_APPEND | LOCK_EX);
        }
        $this->writeStatus();
    }

    /* ------------------------------------------------------------------ history */

    private function historyStart(string $action, int $userId, string $userName, string $branch): void
    {
        $v = app_version_info();
        $this->history = [
            'action' => $action, 'status' => 'running', 'from_version' => $v['version'], 'to_version' => '',
            'from_commit' => $v['commit'], 'to_commit' => '', 'branch' => $branch, 'commit_message' => '',
            'files_changed' => 0, 'files_deleted' => 0, 'migrations_run' => '', 'backup_files' => '', 'backup_db' => '',
            'error_message' => null, 'started_by' => $userId, 'started_by_name' => $userName,
            'started_at' => date('Y-m-d H:i:s'), 'finished_at' => null, 'duration_sec' => 0,
        ];
        $this->historyId = 0;
        $this->historySave();
    }

    /** Upsert (survives a DB rollback because the full row is re-written from memory). */
    private function historySave(): void
    {
        $h = $this->history;
        $h['log'] = implode("\n", $this->lines);
        $cols = array_keys($h);
        if ($this->historyId > 0) {
            $h = ['id' => $this->historyId] + $h;
            $cols = array_keys($h);
        }
        $place = implode(',', array_fill(0, count($cols), '?'));
        $upd = implode(',', array_map(static fn ($c) => "`$c`=VALUES(`$c`)", array_diff($cols, ['id'])));
        $sql = 'INSERT INTO update_history (`' . implode('`,`', $cols) . "`) VALUES ($place) ON DUPLICATE KEY UPDATE $upd";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return;
        }
        $vals = array_values($h);
        $stmt->bind_param(str_repeat('s', count($vals)), ...$vals);
        @$stmt->execute();
        if ($this->historyId === 0) {
            $this->historyId = (int) $stmt->insert_id;
        }
        $stmt->close();
    }

    private function historyFinish(string $status, ?string $error = null): void
    {
        $this->history['status'] = $status;
        $this->history['error_message'] = $error;
        $this->history['finished_at'] = date('Y-m-d H:i:s');
        $this->history['duration_sec'] = time() - strtotime($this->history['started_at']);
        $this->historySave();
    }

    public function history(int $limit = 50): array
    {
        $out = [];
        $r = $this->conn->query('SELECT id, action, status, from_version, to_version, from_commit, to_commit, branch, commit_message, files_changed, files_deleted, migrations_run, backup_files, backup_db, error_message, started_by_name, started_at, finished_at, duration_sec FROM update_history ORDER BY id DESC LIMIT ' . (int) $limit);
        while ($r && ($row = $r->fetch_assoc())) {
            $row['backups_available'] = $row['backup_files'] !== '' && is_file(APP_STORAGE . '/backups/' . $row['backup_files']) && is_file(APP_STORAGE . '/backups/' . $row['backup_db']);
            $out[] = $row;
        }

        return $out;
    }

    public function historyLog(int $id): string
    {
        $r = $this->conn->query('SELECT log FROM update_history WHERE id = ' . (int) $id);
        $row = $r ? $r->fetch_assoc() : null;

        return (string) ($row['log'] ?? '');
    }

    /* ------------------------------------------------------------------ update */

    /** Run a full update to the latest commit of the configured branch. */
    public function update(int $userId, string $userName): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        @ini_set('memory_limit', '512M');
        $this->acquireLock();
        $this->lines = [];
        $this->logFile = APP_STORAGE . '/logs/update-' . date('Ymd-His') . '.log';
        @unlink($this->statusFile());
        $this->writeStatus(['running' => true, 'result' => null, 'started' => time()]);

        $journal = null;
        $staging = $pkg = '';
        try {
            [$gh, $s] = $this->client();
            $this->historyStart('update', $userId, $userName, $s['branch']);
            $this->log("Update started by $userName — repository {$s['repo_owner']}/{$s['repo_name']} @ {$s['branch']}");
            $deployer = $this->deployer($s);

            // 1. Pre-checks
            $this->progress('Pre-flight checks', 3);
            foreach (['zip' => 'ZipArchive', 'curl' => 'curl_init'] as $ext => $sym) {
                if (!class_exists($sym) && !function_exists($sym)) {
                    throw new RuntimeException("PHP extension '$ext' is required.");
                }
            }
            if (!is_writable(APP_STORAGE . '/backups') || !is_writable($this->dir)) {
                throw new RuntimeException('storage/ is not writable.');
            }
            $free = @disk_free_space(APP_STORAGE);
            if ($free !== false && $free < 50 * 1024 * 1024) {
                throw new RuntimeException('Less than 50 MB free disk space — update aborted.');
            }
            $this->recoverIfInterrupted();

            // 2. Latest commit + download
            $this->progress('Fetching latest version from GitHub', 8);
            $latest = $gh->latestCommit($s['branch']);
            $this->history['to_commit'] = $latest['sha'];
            $this->history['commit_message'] = $latest['message'];
            $this->log("Latest commit {$latest['short']} — {$latest['title']} ({$latest['date']})");
            $pkg = $this->dir . '/package-' . $latest['short'] . '.zip';
            $staging = $this->dir . '/staging-' . $latest['short'];
            FileDeployer::rrmdir($staging);
            $this->progress('Downloading package', 12);
            $gh->downloadZip($latest['sha'], $pkg);
            $this->log('Downloaded ' . round(filesize($pkg) / 1024) . ' KB');

            // 3. Extract + validate
            $this->progress('Extracting & validating package', 20);
            $files = $deployer->extract($pkg, $staging);
            $vj = json_decode((string) @file_get_contents($staging . '/version.json'), true);
            if (!is_array($vj) || ($vj['app'] ?? '') !== self::APP_ID) {
                throw new RuntimeException('Package is not a valid ' . self::APP_ID . ' release (version.json missing/mismatch). Nothing was changed.');
            }
            foreach (['db.php', 'includes/updater/UpdateManager.php', 'health.php'] as $must) {
                if (!is_file($staging . '/' . $must)) {
                    throw new RuntimeException("Package is missing $must — refusing to deploy. Nothing was changed.");
                }
            }
            $this->history['to_version'] = (string) $vj['version'];
            $this->log('Package version ' . $vj['version'] . ', ' . count($files) . ' files');
            $this->progress('PHP syntax check', 24);
            $this->lint($staging, $files);

            $manifest = json_decode((string) @file_get_contents(APP_STORAGE . '/manifest.json'), true);
            $plan = $deployer->plan($staging, $files, is_array($manifest) ? $manifest : []);
            $this->history['files_changed'] = count($plan['write']) + count($plan['create']);
            $this->history['files_deleted'] = count($plan['delete']);
            $this->log(sprintf('Plan: %d new, %d changed, %d deleted, %d protected (skipped)', count($plan['create']), count($plan['write']), count($plan['delete']), count($plan['skip'])));
            foreach ($plan['skip'] as $sk) {
                $this->log("  protected, not touched: $sk");
            }
            $deployer->preflight($plan);
            $this->log('Write permissions OK');

            // 4. Backups
            $stamp = date('Ymd-His');
            $this->progress('Backing up files', 30);
            $fb = "files-$stamp-" . substr($this->history['from_commit'] ?: 'unknown', 0, 7) . '.zip';
            $nFiles = $deployer->backup(APP_STORAGE . '/backups/' . $fb);
            $this->history['backup_files'] = $fb;
            $this->log("Files backup: $fb ($nFiles files, " . round(filesize(APP_STORAGE . '/backups/' . $fb) / 1024) . ' KB)');
            $this->progress('Backing up database', 40);
            $db = "db-$stamp.sql.gz";
            $this->historySave();
            $info = (new DbBackup($this->conn))->dump(APP_STORAGE . '/backups/' . $db);
            if (!DbBackup::verify(APP_STORAGE . '/backups/' . $db)) {
                throw new RuntimeException('Database backup verification failed — update aborted, nothing changed.');
            }
            $this->history['backup_db'] = $db;
            $this->log("Database backup: $db ({$info['tables']} tables, {$info['rows']} rows, " . round($info['bytes'] / 1024) . ' KB) ✔ verified');
            $this->historySave();

            // 5. Apply (from here on, any failure triggers rollback)
            $journal = [
                'history' => $this->history, 'history_id' => $this->historyId,
                'files_backup' => $fb, 'db_backup' => $db, 'created' => $plan['create'],
                'prev_version' => @file_get_contents(APP_STORAGE . '/version.json') ?: null,
                'prev_manifest' => @file_get_contents(APP_STORAGE . '/manifest.json') ?: null,
                'started' => time(),
            ];
            file_put_contents($this->dir . '/journal.json', json_encode($journal), LOCK_EX);
            $this->maintenance(true);
            $this->log('Maintenance mode ON');
            $this->progress('Installing new files', 55);
            $deployer->apply($staging, $plan, fn ($l) => $this->log($l));
            $this->log('Files installed');
            $this->writeVersion((string) $vj['version'], $latest['sha'], $s['branch']);
            $releaseManifest = array_values(array_filter($files, static fn ($f) => !$deployer->isProtected($f)));
            file_put_contents(APP_STORAGE . '/manifest.json', json_encode($releaseManifest), LOCK_EX);
            $this->syncHtaccess();

            // 6. Migrations
            $this->progress('Running database migrations', 70);
            $done = (new MigrationRunner($this->conn))->migrate(fn ($l) => $this->log($l));
            $this->history['migrations_run'] = implode(', ', $done);

            // 7. Cache
            $this->progress('Clearing caches', 80);
            $this->clearCaches();
            $this->maintenance(false);
            $this->log('Maintenance mode OFF');

            // 8. Health
            $this->progress('Health check', 88);
            $this->healthOrFail((int) $s['strict_health'] === 1, $s['app_url'] ?: self::detectAppUrl(), $latest['sha']);

            // 9. Done
            @unlink($this->dir . '/journal.json');
            $journal = null;
            $this->cleanup($staging, $pkg);
            $this->pruneBackups((int) $s['keep_backups']);
            $this->log('✅ Update completed successfully → version ' . $vj['version'] . ' (' . $latest['short'] . ')');
            $this->historyFinish('success');
            $this->conn->query('UPDATE update_settings SET last_check_result = NULL WHERE id = 1');
            $this->progress('Completed', 100);
            $this->writeStatus(['running' => false, 'result' => 'success']);

            return ['ok' => true, 'history_id' => $this->historyId, 'version' => $vj['version']];
        } catch (Throwable $e) {
            $this->log('❌ ERROR: ' . $e->getMessage());
            $status = 'failed';
            if ($journal !== null) {
                $status = $this->rollback($journal) ? 'rolled_back' : 'rollback_failed';
            } else {
                $this->log('No changes had been made to the site.');
            }
            if ($staging !== '' || $pkg !== '') {
                $this->cleanup($staging, $pkg);
            }
            $this->historyFinish($status, $e->getMessage());
            $this->writeStatus(['running' => false, 'result' => $status, 'percent' => 100, 'step' => $status === 'rolled_back' ? 'Rolled back to previous version' : 'Failed']);

            return ['ok' => false, 'status' => $status, 'error' => $e->getMessage(), 'history_id' => $this->historyId];
        } finally {
            $this->maintenance(false);
            $this->releaseLock();
        }
    }

    private function healthOrFail(bool $strict, string $appUrl, string $sha): void
    {
        $internal = HealthCheck::internal($this->conn);
        foreach ($internal['checks'] as $name => $c) {
            $this->log(sprintf('  %s %s: %s', $c['ok'] ? '✔' : '✘', $name, $c['info']));
        }
        if ($internal['status'] !== 'ok') {
            throw new RuntimeException('Health check failed (internal checks).');
        }
        $http = HealthCheck::http($appUrl, $sha);
        foreach ($http['lines'] as $l) {
            $this->log($l);
        }
        if (!$http['ok']) {
            if (!$http['fatal'] && !$strict) {
                $this->log('⚠ HTTP health check could not run (loopback blocked?) — continuing because strict mode is off.');

                return;
            }
            throw new RuntimeException('Health check failed — new version does not respond correctly.');
        }
        $this->log('Health check passed ✔');
    }

    /** Restore files + database from journal. */
    private function rollback(array $j): bool
    {
        $this->log('↩ Starting automatic rollback…');
        $this->progress('Rolling back', 92);
        $ok = true;
        $this->maintenance(true);
        try {
            $n = $this->deployer()->restore(APP_STORAGE . '/backups/' . $j['files_backup'], $j['created'] ?? [], fn ($l) => $this->log($l));
            $this->log("Files restored ($n files)");
        } catch (Throwable $e) {
            $ok = false;
            $this->log('✘ File restore failed: ' . $e->getMessage());
        }
        try {
            $this->conn->query('SELECT 1') || $this->reconnect();
            $keep = $this->snapshotTables(['update_history', 'update_settings']);
            (new DbBackup($this->conn))->restore(APP_STORAGE . '/backups/' . $j['db_backup']);
            $this->restoreSnapshot($keep);
            $this->log('Database restored from ' . $j['db_backup']);
        } catch (Throwable $e) {
            $ok = false;
            $this->log('✘ Database restore failed: ' . $e->getMessage());
        }
        if ($j['prev_version'] !== null) {
            @file_put_contents(APP_STORAGE . '/version.json', $j['prev_version']);
        } else {
            @unlink(APP_STORAGE . '/version.json');
        }
        if ($j['prev_manifest'] !== null) {
            @file_put_contents(APP_STORAGE . '/manifest.json', $j['prev_manifest']);
        } else {
            @unlink(APP_STORAGE . '/manifest.json');
        }
        $this->clearCaches();
        $this->maintenance(false);
        if ($this->historyId === 0 && !empty($j['history_id'])) {
            $this->historyId = (int) $j['history_id'];
            $this->history = $j['history'];
        }
        if ($ok) {
            @unlink($this->dir . '/journal.json');
            $this->log('✅ Rollback complete — previous working version restored.');
        } else {
            $this->log('⚠ Rollback incomplete — restore manually from storage/backups (see above).');
        }

        return $ok;
    }

    /** Rows of updater bookkeeping tables survive a DB restore (history must never be lost). */
    private function snapshotTables(array $tables): array
    {
        $snap = [];
        foreach ($tables as $t) {
            $r = @$this->conn->query("SELECT * FROM `$t`");
            if ($r) {
                $snap[$t] = $r->fetch_all(MYSQLI_ASSOC);
            }
        }

        return $snap;
    }

    private function restoreSnapshot(array $snap): void
    {
        foreach ($snap as $t => $rows) {
            if (!(new MigrationHelper($this->conn))->tableExists($t)) {
                SqlSplitter::run($this->conn, (string) file_get_contents(APP_ROOT . '/database/migrations/0004_auto_updater_tables.sql'));
            }
            $this->conn->query("DELETE FROM `$t`");
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $stmt = $this->conn->prepare("INSERT INTO `$t` (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
                if (!$stmt) {
                    continue;
                }
                $vals = array_values($row);
                $stmt->bind_param(str_repeat('s', count($vals)), ...$vals);
                @$stmt->execute();
                $stmt->close();
            }
        }
    }

    private function reconnect(): void
    {
        $c = app_config();
        $this->conn = new mysqli($c['db_host'], $c['db_user'], $c['db_pass'], $c['db_name'], (int) $c['db_port']);
        $this->conn->set_charset('utf8mb4');
    }

    /** An update that died mid-way (timeout / server restart) leaves a journal → roll it back first. */
    public function recoverIfInterrupted(): bool
    {
        $f = $this->dir . '/journal.json';
        if (!is_file($f)) {
            return false;
        }
        $j = json_decode((string) file_get_contents($f), true);
        if (!is_array($j)) {
            @unlink($f);

            return false;
        }
        $this->log('Interrupted previous update detected — rolling it back first.');
        $savedId = $this->historyId;
        $savedHist = $this->history;
        $this->historyId = (int) ($j['history_id'] ?? 0);
        $this->history = $j['history'] ?? [];
        $ok = $this->rollback($j);
        foreach (glob($this->dir . '/staging-*', GLOB_ONLYDIR) ?: [] as $d) {
            FileDeployer::rrmdir($d);
        }
        foreach (glob($this->dir . '/package-*.zip') ?: [] as $f) {
            @unlink($f);
        }
        if ($this->historyId > 0 && $this->history) {
            $this->historyFinish($ok ? 'rolled_back' : 'rollback_failed', 'Update was interrupted (timeout or server restart).');
        }
        $this->historyId = $savedId;
        $this->history = $savedHist;
        if ($savedId > 0) {
            $this->historySave();
        }

        return $ok;
    }

    /** Manual action from the UI: recover an interrupted update. */
    public function recover(int $userId, string $userName): array
    {
        $this->acquireLock();
        try {
            $this->lines = [];
            $ok = $this->recoverIfInterrupted();
            $this->writeStatus(['running' => false, 'result' => $ok ? 'rolled_back' : 'nothing']);

            return ['ok' => $ok];
        } finally {
            $this->releaseLock();
        }
    }

    /** Manual restore of the backups taken before a past update ("restore previous version"). */
    public function restoreBackup(int $historyId, int $userId, string $userName): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $r = $this->conn->query('SELECT * FROM update_history WHERE id = ' . (int) $historyId);
        $row = $r ? $r->fetch_assoc() : null;
        if (!$row || $row['backup_files'] === '' || $row['backup_db'] === '') {
            throw new RuntimeException('No backups recorded for that update.');
        }
        foreach ([$row['backup_files'], $row['backup_db']] as $b) {
            if (basename($b) !== $b || !is_file(APP_STORAGE . '/backups/' . $b)) {
                throw new RuntimeException('Backup file no longer exists: ' . $b);
            }
        }
        $this->acquireLock();
        $this->lines = [];
        $this->logFile = APP_STORAGE . '/logs/restore-' . date('Ymd-His') . '.log';
        try {
            $this->historyStart('restore', $userId, $userName, (string) $row['branch']);
            $this->history['to_version'] = $row['from_version'];
            $this->history['to_commit'] = $row['from_commit'];
            $this->history['commit_message'] = 'Manual restore of backup taken before update #' . $historyId;
            $this->log('Manual restore of backups from update #' . $historyId);
            // safety backup of the current state first
            $stamp = date('Ymd-His');
            $fb = "files-$stamp-prerestore.zip";
            $db = "db-$stamp-prerestore.sql.gz";
            $this->deployer()->backup(APP_STORAGE . '/backups/' . $fb);
            (new DbBackup($this->conn))->dump(APP_STORAGE . '/backups/' . $db);
            $this->history['backup_files'] = $fb;
            $this->history['backup_db'] = $db;
            $this->log("Safety backup of current state: $fb, $db");
            $prevManifest = json_decode((string) @file_get_contents(APP_STORAGE . '/manifest.json'), true) ?: [];
            $zip = new ZipArchive();
            $inBackup = [];
            if ($zip->open(APP_STORAGE . '/backups/' . $row['backup_files']) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $inBackup[] = $zip->getNameIndex($i);
                }
                $zip->close();
            }
            $created = array_values(array_diff($prevManifest, $inBackup));
            $ok = $this->rollback([
                'files_backup' => $row['backup_files'], 'db_backup' => $row['backup_db'], 'created' => $created,
                'prev_version' => json_encode(['version' => $row['from_version'], 'commit' => $row['from_commit'], 'branch' => $row['branch'], 'updated_at' => date('c')]),
                'prev_manifest' => json_encode(array_values(array_filter($inBackup, static fn ($f) => $f !== '.backup-manifest.json' && !str_ends_with((string) $f, '/')))),
                'history_id' => 0, 'history' => [],
            ]);
            $this->historyFinish($ok ? 'success' : 'failed', $ok ? null : 'Restore incomplete');

            return ['ok' => $ok, 'history_id' => $this->historyId];
        } finally {
            $this->releaseLock();
        }
    }

    /* ------------------------------------------------------------------ helpers */

    private function lint(string $staging, array $files): void
    {
        $bin = self::phpCli();
        if ($bin === null) {
            $this->log('PHP syntax check skipped (exec() not available on this host)');

            return;
        }
        $bad = [];
        foreach ($files as $rel) {
            if (!str_ends_with($rel, '.php')) {
                continue;
            }
            $out = [];
            $code = 0;
            @exec(escapeshellarg($bin) . ' -l ' . escapeshellarg($staging . '/' . $rel) . ' 2>&1', $out, $code);
            if ($code !== 0) {
                $bad[] = $rel . ': ' . trim(implode(' ', $out));
            }
        }
        if ($bad) {
            throw new RuntimeException("PHP syntax errors in new version — update aborted, nothing changed:\n" . implode("\n", array_slice($bad, 0, 5)));
        }
        $this->log('PHP syntax check passed ✔');
    }

    private static function phpCli(): ?string
    {
        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return null;
        }
        foreach (array_unique([PHP_BINARY, PHP_BINDIR . '/php', PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, '/usr/bin/php', '/usr/local/bin/php']) as $c) {
            if ($c && preg_match('#(^|/)php[\d.]*(-cli)?(\.exe)?$#', $c) && @is_executable($c)) {
                $out = [];
                @exec(escapeshellarg($c) . ' -r "echo PHP_SAPI;" 2>&1', $out, $code);
                if ($code === 0 && trim(implode('', $out)) === 'cli') {
                    return $c;
                }
            }
        }

        return null;
    }

    private function writeVersion(string $version, string $sha, string $branch): void
    {
        file_put_contents(APP_STORAGE . '/version.json', json_encode(['version' => $version, 'commit' => $sha, 'branch' => $branch, 'updated_at' => date('c')], JSON_PRETTY_PRINT), LOCK_EX);
    }

    public function maintenance(bool $on): void
    {
        $f = APP_STORAGE . '/maintenance.flag';
        if ($on) {
            @file_put_contents($f, json_encode(['since' => time(), 'until' => time() + 1800]), LOCK_EX);
        } elseif (is_file($f)) {
            @unlink($f);
        }
    }

    public function clearCaches(): void
    {
        clearstatcache(true);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
        foreach (glob(APP_STORAGE . '/cache/*') ?: [] as $f) {
            is_dir($f) ? FileDeployer::rrmdir($f) : @unlink($f);
        }
        $this->log('Caches cleared (OPcache, APCu, storage/cache)');
    }

    /** Keep our managed block inside the root .htaccess up to date (hosting-panel lines are preserved). */
    public function syncHtaccess(): void
    {
        $tpl = APP_ROOT . '/install/htaccess.block';
        if (!is_file($tpl)) {
            return;
        }
        $r = self::writeHtaccessBlock(APP_ROOT . '/.htaccess', (string) file_get_contents($tpl));
        if ($r) {
            $this->log('Root .htaccess security block refreshed (other lines untouched)');
        }
    }

    public static function writeHtaccessBlock(string $file, string $block): bool
    {
        $begin = '# BEGIN AKDWK';
        $end = '# END AKDWK';
        $block = trim($block);
        if (!str_starts_with($block, $begin)) {
            $block = "$begin\n$block\n$end";
        }
        $cur = is_file($file) ? (string) file_get_contents($file) : '';
        $pattern = '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\R?/s';
        $new = preg_match($pattern, $cur) ? preg_replace($pattern, $block . "\n", $cur, 1) : rtrim($block . "\n\n" . ltrim($cur)) . "\n";
        if ($new === $cur) {
            return false;
        }

        return @file_put_contents($file, $new, LOCK_EX) !== false;
    }

    private function cleanup(string $staging, string $pkg): void
    {
        if ($staging !== '') {
            FileDeployer::rrmdir($staging);
        }
        if ($pkg !== '' && is_file($pkg)) {
            @unlink($pkg);
        }
    }

    public function pruneBackups(int $keep): void
    {
        foreach (['files-*.zip', 'db-*.sql.gz'] as $pattern) {
            $list = glob(APP_STORAGE . '/backups/' . $pattern) ?: [];
            rsort($list, SORT_STRING);
            foreach (array_slice($list, $keep) as $old) {
                @unlink($old);
            }
        }
        $logs = glob(APP_STORAGE . '/logs/update-*.log') ?: [];
        rsort($logs, SORT_STRING);
        foreach (array_slice($logs, 50) as $old) {
            @unlink($old);
        }
    }
}
