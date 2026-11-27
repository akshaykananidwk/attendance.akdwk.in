# Smart Attendance — Install & Auto-Update Guide

Server: Apache 2.4 + PHP 8.0+ + MySQL/MariaDB (cPanel / phpMyAdmin hosting).

## 1. First-time install (one time only)

1. In cPanel → **MySQL Databases** create a database + user (give the user ALL PRIVILEGES on it).
   Use a **new, empty** database (the installer refuses a database that belongs to another app).
2. Upload all files of this repository into the site folder (e.g. `public_html/attendance.akdwk.in/`).
3. Open `https://your-domain/install/` and follow the 3 steps:
   - **Requirements** — PHP extensions and folder permissions are checked automatically.
   - **Database** — host / name / user / password. Connection is tested; nothing is changed if it fails.
   - **Admin & settings** — company, branch, admin mobile + password, site URL, optional GitHub details.
     Optional: upload an old `.sql` / `.sql.gz` backup to restore existing data instead of starting empty.
4. Done. `config.local.php`, `storage/app.key` and the `.htaccess` security rules are created for you,
   all database migrations run, and the installer locks itself (`storage/installed.lock`).

**Existing site?** If the database already has Smart Attendance data, the installer detects it
("Existing installation found"), backs the database up first, and only adds what is missing.
Existing logins keep working; old plain-text passwords are converted to secure hashes.

## 2. Connect GitHub (one time only)

Admin → **System update** → GitHub connection:

- Repository: `owner/name` (or paste the GitHub URL)
- Branch: e.g. `main`
- Token: GitHub → Settings → Developer settings → **Fine-grained token** →
  only this repository → Permissions → **Contents: Read-only**. (Not needed for a public repository.)
  The token is stored encrypted and is never shown again.

## 3. Every future update — no file upload needed

1. Push your changes to the branch on GitHub (and bump `version` in `version.json`).
2. Admin → System update → **Check for update** — shows the latest version, commit message, date,
   commits, changed files and new DB migrations.
3. **Update now** — runs automatically:

| Step | What happens |
|------|--------------|
| Pre-checks | Extensions, disk space, write permissions, lock (only one update at a time) |
| Download | Exact commit zip from GitHub; package validated (`version.json` app id, zip-slip / symlink protection) |
| PHP lint | Every PHP file syntax-checked (when the host allows `exec`) — errors abort with **no change** |
| Backup | Full files backup (zip) + full database backup (gzip, verified) in `storage/backups/` |
| Install | Maintenance page on, files swapped atomically, removed files deleted, protected paths skipped |
| Migrate | New files in `database/migrations/` run once, tracked in `schema_migrations` |
| Cache | OPcache, APCu and `storage/cache` cleared |
| Health check | DB, tables, migrations, storage + real HTTP request to `/health.php` (must report the new commit) and `/login.php` |
| Rollback | Any error after the backup → files + database restored automatically, history marked `rolled_back` |

Protected (never overwritten or deleted): `.env`, `config.php`, `config.local.php`, `.htaccess`,
`.user.ini`, `php.ini`, `uploads/`, `storage/`, `_backups/`, `.well-known/`, `cgi-bin/`, `.git/` —
plus any extra paths you add under *Advanced*.

The root `.htaccess` is protected, so hosting-panel lines (e.g. cPanel PHP version) are kept;
only the block between `# BEGIN AKDWK` and `# END AKDWK` is refreshed by updates.

**History & logs** — every update / rollback / restore is listed with status, versions, commit, files,
migrations, user, duration and the full log. ↺ restores the files + database backup taken before that update.

**Interrupted update** (server restart / timeout) — the page shows *Roll back interrupted update*;
the next update also recovers automatically first.

## 4. Writing database migrations

Add a file to `database/migrations/` with the next number:

- `0006_add_column.sql` — plain SQL, several statements allowed
- `0007_backfill.php` —
  ```php
  <?php
  return function (mysqli $conn, MigrationHelper $m): void {
      $m->addColumn('users', 'nickname', 'VARCHAR(50) NULL');   // only if missing
  };
  ```

Never edit a migration that has already been released; add a new one instead.

## 5. Optional

- `/health.php` can be used by an uptime monitor (returns `{"status":"ok"}` / HTTP 503).
- `db_exporter.php` / `backup_processor.php` (Python backup tool) use `backup_token` from `config.local.php`
  (the installer generates one; the old hardcoded token no longer works).
