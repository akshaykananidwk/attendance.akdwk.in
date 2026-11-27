<?php
/**
 * File-level operations for the updater: protected-path rules, release extraction,
 * file backup, atomic copy, deletion of removed files and rollback.
 */
final class FileDeployer
{
    /** Never overwritten or deleted by an update (relative to app root; trailing "/" = whole folder). */
    public const DEFAULT_PROTECTED = [
        '.env',
        '.env.local',
        'config.php',
        'config.local.php',
        '.htaccess',
        '.user.ini',
        'php.ini',
        'error_log',
        'uploads/',
        'storage/',
        '_backups/',
        '.well-known/',
        'cgi-bin/',
        '.git/',
    ];

    /** Paths inside a release that are never deployed to the server. */
    public const RELEASE_EXCLUDE = ['.git/', '.github/', 'tests/', '.gitattributes', '.editorconfig', '.gitignore'];

    /** @var string[] */
    private array $protected;

    public function __construct(private string $root, array $extraProtected = [])
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $p = array_merge(self::DEFAULT_PROTECTED, $extraProtected);
        $p = array_map(static fn ($x) => ltrim(str_replace('\\', '/', trim($x)), '/'), $p);
        $this->protected = array_values(array_unique(array_filter($p, static fn ($x) => $x !== '' && !str_contains($x, '..'))));
    }

    public function protectedList(): array
    {
        return $this->protected;
    }

    public function isProtected(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        foreach ($this->protected as $p) {
            if (str_ends_with($p, '/')) {
                if (str_starts_with($rel . '/', $p) || str_starts_with($rel, $p)) {
                    return true;
                }
            } elseif ($rel === $p) {
                return true;
            } elseif (str_contains($p, '*') && fnmatch($p, $rel)) {
                return true;
            }
        }

        return false;
    }

    public static function isExcludedFromRelease(string $rel): bool
    {
        foreach (self::RELEASE_EXCLUDE as $p) {
            if (str_ends_with($p, '/') ? str_starts_with($rel, $p) : $rel === $p) {
                return true;
            }
        }

        return false;
    }

    /**
     * Safely extract a GitHub zipball into $dest (strips the top "owner-repo-sha/" folder).
     * Rejects path traversal, absolute paths and symlinks. Returns list of relative file paths.
     */
    public function extract(string $zipFile, string $dest, int $maxBytes = 524288000): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('Downloaded package is not a valid ZIP file.');
        }
        $prefix = null;
        $files = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) $st['name']);
            if ($prefix === null) {
                $prefix = strstr($name, '/', true) . '/';
            }
            if (!str_starts_with($name, $prefix)) {
                $zip->close();
                throw new RuntimeException('Unexpected ZIP layout.');
            }
            $rel = substr($name, strlen($prefix));
            if ($rel === '' || str_ends_with($rel, '/')) {
                continue;
            }
            if (str_contains($rel, '../') || str_starts_with($rel, '/') || preg_match('#(^|/)\.\.?(/|$)#', $rel) || preg_match('/^[A-Za-z]:/', $rel) || str_contains($rel, "\0")) {
                $zip->close();
                throw new RuntimeException("Unsafe path in package: $rel");
            }
            // symlink entries (unix mode S_IFLNK) are refused
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                $zip->close();
                throw new RuntimeException("Symlink in package refused: $rel");
            }
            $total += (int) $st['size'];
            if ($total > $maxBytes) {
                $zip->close();
                throw new RuntimeException('Package too large.');
            }
            if (self::isExcludedFromRelease($rel)) {
                continue;
            }
            $target = $dest . '/' . $rel;
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0775, true)) {
                throw new RuntimeException('Cannot create staging folder.');
            }
            $data = $zip->getFromIndex($i);
            if ($data === false || file_put_contents($target, $data) === false) {
                $zip->close();
                throw new RuntimeException("Cannot extract $rel");
            }
            $files[] = $rel;
        }
        $zip->close();
        sort($files);

        return $files;
    }

    /**
     * Compute changes between staging release and live site.
     * @param string[] $releaseFiles
     * @param string[] $previousManifest files deployed by the previous release (for deletions)
     * @return array{write:string[],create:string[],delete:string[],skip:string[]}
     */
    public function plan(string $staging, array $releaseFiles, array $previousManifest): array
    {
        $write = $create = $skip = $delete = [];
        $set = array_flip($releaseFiles);
        foreach ($releaseFiles as $rel) {
            if ($this->isProtected($rel)) {
                $skip[] = $rel;
                continue;
            }
            $live = $this->root . '/' . $rel;
            if (!is_file($live)) {
                $create[] = $rel;
            } elseif (sha1_file($live) !== sha1_file($staging . '/' . $rel)) {
                $write[] = $rel;
            }
        }
        foreach ($previousManifest as $rel) {
            if (!isset($set[$rel]) && !$this->isProtected($rel) && is_file($this->root . '/' . $rel) && !self::isExcludedFromRelease($rel)) {
                $delete[] = $rel;
            }
        }

        return compact('write', 'create', 'delete', 'skip');
    }

    /** Fail early (before touching anything) if a target cannot be written. */
    public function preflight(array $plan): void
    {
        $problems = [];
        foreach (array_merge($plan['write'], $plan['delete']) as $rel) {
            $f = $this->root . '/' . $rel;
            if (!is_writable($f) || !is_writable(dirname($f))) {
                $problems[] = $rel;
            }
        }
        foreach ($plan['create'] as $rel) {
            $d = dirname($this->root . '/' . $rel);
            while (!is_dir($d) && strlen($d) > strlen($this->root)) {
                $d = dirname($d);
            }
            if (!is_writable($d)) {
                $problems[] = $rel;
            }
        }
        if ($problems) {
            throw new RuntimeException('No write permission for: ' . implode(', ', array_slice($problems, 0, 10)) . (count($problems) > 10 ? ' …' : ''));
        }
    }

    /**
     * Zip every non-protected application file (full code snapshot).
     * @return int number of files in the backup
     */
    public function backup(string $zipFile): int
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create files backup.');
        }
        $n = 0;
        foreach ($this->liveFiles() as $rel) {
            if (!$zip->addFile($this->root . '/' . $rel, $rel)) {
                $zip->close();
                throw new RuntimeException("Cannot add $rel to backup.");
            }
            $n++;
        }
        $zip->addFromString('.backup-manifest.json', json_encode(['created' => date('c'), 'files' => $n]));
        if (!$zip->close()) {
            throw new RuntimeException('Files backup could not be written (disk full?).');
        }

        return $n;
    }

    /** @return string[] relative paths of non-protected files currently on the server */
    public function liveFiles(): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $f) {
                    $rel = ltrim(substr(str_replace('\\', '/', $f->getPathname()), strlen($this->root)), '/');
                    if ($f->isLink()) {
                        return false;
                    }

                    return !$this->isProtected($f->isDir() ? $rel . '/' : $rel);
                }
            )
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = ltrim(substr(str_replace('\\', '/', $f->getPathname()), strlen($this->root)), '/');
            }
        }
        sort($out);

        return $out;
    }

    /** Copy files from staging into the live site (write to temp + rename = atomic per file). */
    public function apply(string $staging, array $plan, callable $log): void
    {
        foreach (array_merge($plan['create'], $plan['write']) as $rel) {
            $src = $staging . '/' . $rel;
            $dst = $this->root . '/' . $rel;
            $dir = dirname($dst);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                throw new RuntimeException("Cannot create folder for $rel");
            }
            $tmp = $dst . '.upd-' . bin2hex(random_bytes(4));
            if (!@copy($src, $tmp)) {
                throw new RuntimeException("Cannot write $rel");
            }
            if (is_file($dst)) {
                @chmod($tmp, fileperms($dst) & 0777);
            }
            if (!@rename($tmp, $dst)) {
                @unlink($tmp);
                throw new RuntimeException("Cannot replace $rel");
            }
            if (function_exists('opcache_invalidate') && str_ends_with($rel, '.php')) {
                @opcache_invalidate($dst, true);
            }
        }
        foreach ($plan['delete'] as $rel) {
            $f = $this->root . '/' . $rel;
            if (is_file($f) && !@unlink($f)) {
                throw new RuntimeException("Cannot delete $rel");
            }
            $log("  deleted $rel");
            $this->pruneEmptyDirs(dirname($f));
        }
    }

    /**
     * Rollback: restore every file from the backup zip, and remove files that the failed update created.
     */
    public function restore(string $zipFile, array $createdFiles, callable $log): int
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('Files backup is missing or corrupt: ' . basename($zipFile));
        }
        $n = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $rel = (string) $zip->getNameIndex($i);
            if ($rel === '.backup-manifest.json' || str_ends_with($rel, '/') || str_contains($rel, '..') || $this->isProtected($rel)) {
                continue;
            }
            $dst = $this->root . '/' . $rel;
            if (!is_dir(dirname($dst))) {
                @mkdir(dirname($dst), 0775, true);
            }
            $data = $zip->getFromIndex($i);
            if ($data === false) {
                continue;
            }
            if (is_file($dst) && sha1_file($dst) === sha1($data)) {
                continue;
            }
            $tmp = $dst . '.rb-' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $data) === false || !@rename($tmp, $dst)) {
                @unlink($tmp);
                $log("  ! could not restore $rel");
                continue;
            }
            if (function_exists('opcache_invalidate') && str_ends_with($rel, '.php')) {
                @opcache_invalidate($dst, true);
            }
            $n++;
        }
        $zip->close();
        foreach ($createdFiles as $rel) {
            $f = $this->root . '/' . $rel;
            if (!$this->isProtected($rel) && is_file($f)) {
                @unlink($f);
                $this->pruneEmptyDirs(dirname($f));
            }
        }

        return $n;
    }

    private function pruneEmptyDirs(string $dir): void
    {
        while (strlen($dir) > strlen($this->root) && is_dir($dir) && count(scandir($dir)) === 2) {
            @rmdir($dir);
            $dir = dirname($dir);
        }
    }

    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
