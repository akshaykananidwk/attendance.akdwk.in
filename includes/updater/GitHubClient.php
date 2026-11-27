<?php
/**
 * Minimal GitHub REST client for the updater (works with private repositories via a fine-grained token
 * that has read-only "Contents" permission).
 */
final class GitHubClient
{
    private string $api;

    public function __construct(
        private string $owner,
        private string $repo,
        private string $token,
        ?string $apiBase = null
    ) {
        if (!self::validName($owner) || !self::validName($repo)) {
            throw new InvalidArgumentException('Invalid GitHub owner/repository name.');
        }
        $this->api = rtrim($apiBase ?: 'https://api.github.com', '/');
    }

    public static function validName(string $s): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $s) && $s !== '.' && $s !== '..';
    }

    public static function validBranch(string $b): bool
    {
        return (bool) preg_match('#^[A-Za-z0-9._/\-]{1,150}$#', $b) && !str_contains($b, '..') && !str_starts_with($b, '/') && !str_ends_with($b, '/');
    }

    private function headers(string $accept = 'application/vnd.github+json'): array
    {
        $h = [
            'Accept: ' . $accept,
            'User-Agent: akdwk-attendance-updater',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($this->token !== '') {
            $h[] = 'Authorization: Bearer ' . $this->token;
        }

        return $h;
    }

    /** @return array{0:int,1:string} [status, body] */
    private function request(string $path, string $accept = 'application/vnd.github+json', ?string $saveTo = null, int $timeout = 30): array
    {
        $ch = curl_init($this->api . $path);
        $fh = null;
        $opts = [
            CURLOPT_HTTPHEADER => $this->headers($accept),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ];
        if ($saveTo !== null) {
            $fh = fopen($saveTo, 'wb');
            if (!$fh) {
                throw new RuntimeException('Cannot write download file.');
            }
            $opts[CURLOPT_FILE] = $fh;
        } else {
            $opts[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($fh) {
            fclose($fh);
        }
        if ($body === false) {
            throw new RuntimeException('Could not reach GitHub: ' . $err);
        }

        return [$code, $saveTo !== null ? '' : (string) $body];
    }

    private function json(string $path): array
    {
        [$code, $body] = $this->request($path);
        $data = json_decode($body, true);
        if ($code >= 400 || !is_array($data)) {
            $msg = is_array($data) && isset($data['message']) ? $data['message'] : "HTTP $code";
            if ($code === 401) {
                $msg = 'GitHub token is invalid or expired (401).';
            } elseif ($code === 404) {
                $msg = 'Repository / branch not found, or the token has no access to it (404).';
            } elseif ($code === 403) {
                $msg = 'GitHub refused the request (403): ' . $msg;
            }
            throw new RuntimeException($msg);
        }

        return $data;
    }

    public function repository(): array
    {
        return $this->json("/repos/{$this->owner}/{$this->repo}");
    }

    /** Latest commit on a branch: sha, message, author, date, url. */
    public function latestCommit(string $branch): array
    {
        $c = $this->json("/repos/{$this->owner}/{$this->repo}/commits/" . rawurlencode($branch));

        return self::commitSummary($c);
    }

    public function recentCommits(string $branch, int $n = 10): array
    {
        $list = $this->json("/repos/{$this->owner}/{$this->repo}/commits?sha=" . rawurlencode($branch) . '&per_page=' . $n);

        return array_map([self::class, 'commitSummary'], $list);
    }

    /** Commits + changed files between two SHAs. */
    public function compare(string $base, string $head): array
    {
        $d = $this->json("/repos/{$this->owner}/{$this->repo}/compare/" . rawurlencode($base) . '...' . rawurlencode($head));
        $files = [];
        foreach (($d['files'] ?? []) as $f) {
            $files[] = [
                'filename' => (string) $f['filename'],
                'status' => (string) $f['status'],
                'additions' => (int) ($f['additions'] ?? 0),
                'deletions' => (int) ($f['deletions'] ?? 0),
                'previous_filename' => $f['previous_filename'] ?? null,
            ];
        }

        return [
            'status' => (string) ($d['status'] ?? ''),
            'ahead_by' => (int) ($d['ahead_by'] ?? 0),
            'behind_by' => (int) ($d['behind_by'] ?? 0),
            'commits' => array_reverse(array_map([self::class, 'commitSummary'], $d['commits'] ?? [])),
            'files' => $files,
        ];
    }

    /** Raw file at a ref (null when missing). */
    public function fileContents(string $path, string $ref): ?string
    {
        [$code, $body] = $this->request("/repos/{$this->owner}/{$this->repo}/contents/" . str_replace('%2F', '/', rawurlencode($path)) . '?ref=' . rawurlencode($ref), 'application/vnd.github.raw');
        if ($code === 404) {
            return null;
        }
        if ($code >= 400) {
            throw new RuntimeException("Cannot read $path from GitHub (HTTP $code).");
        }

        return $body;
    }

    /** Download the source zip of a commit. */
    public function downloadZip(string $sha, string $saveTo): void
    {
        if (!preg_match('/^[0-9a-f]{7,40}$/i', $sha)) {
            throw new InvalidArgumentException('Invalid commit SHA.');
        }
        [$code] = $this->request("/repos/{$this->owner}/{$this->repo}/zipball/$sha", 'application/vnd.github+json', $saveTo, 600);
        if ($code !== 200) {
            @unlink($saveTo);
            throw new RuntimeException("Download failed (HTTP $code).");
        }
    }

    private static function commitSummary(array $c): array
    {
        $msg = (string) ($c['commit']['message'] ?? '');

        return [
            'sha' => (string) ($c['sha'] ?? ''),
            'short' => substr((string) ($c['sha'] ?? ''), 0, 7),
            'message' => $msg,
            'title' => strtok($msg, "\n") ?: '',
            'author' => (string) ($c['commit']['author']['name'] ?? ($c['author']['login'] ?? '')),
            'date' => (string) ($c['commit']['committer']['date'] ?? ($c['commit']['author']['date'] ?? '')),
            'url' => (string) ($c['html_url'] ?? ''),
        ];
    }
}
