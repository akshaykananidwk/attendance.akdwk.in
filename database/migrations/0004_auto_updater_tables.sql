-- Auto-update system: settings (single row) + full history.
CREATE TABLE IF NOT EXISTS update_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    repo_owner VARCHAR(100) NOT NULL DEFAULT '',
    repo_name VARCHAR(100) NOT NULL DEFAULT '',
    branch VARCHAR(150) NOT NULL DEFAULT 'main',
    token_enc TEXT NULL,
    app_url VARCHAR(255) NOT NULL DEFAULT '',
    protected_paths TEXT NULL,
    keep_backups INT NOT NULL DEFAULT 5,
    strict_health TINYINT(1) NOT NULL DEFAULT 1,
    last_check_at DATETIME NULL,
    last_check_result MEDIUMTEXT NULL,
    updated_by INT NULL,
    updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS update_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(20) NOT NULL DEFAULT 'update',
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    from_version VARCHAR(50) NOT NULL DEFAULT '',
    to_version VARCHAR(50) NOT NULL DEFAULT '',
    from_commit VARCHAR(40) NOT NULL DEFAULT '',
    to_commit VARCHAR(40) NOT NULL DEFAULT '',
    branch VARCHAR(150) NOT NULL DEFAULT '',
    commit_message TEXT NULL,
    files_changed INT NOT NULL DEFAULT 0,
    files_deleted INT NOT NULL DEFAULT 0,
    migrations_run TEXT NULL,
    backup_files VARCHAR(255) NOT NULL DEFAULT '',
    backup_db VARCHAR(255) NOT NULL DEFAULT '',
    error_message TEXT NULL,
    log MEDIUMTEXT NULL,
    started_by INT NULL,
    started_by_name VARCHAR(100) NOT NULL DEFAULT '',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    duration_sec INT NOT NULL DEFAULT 0,
    KEY idx_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
