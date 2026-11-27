<?php

declare(strict_types=1);

/** Account statuses for staff (never delete users — change status only). */
const STAFF_STATUS_ACTIVE = 'active';
const STAFF_STATUS_INACTIVE = 'inactive';
const STAFF_STATUS_DISABLED = 'disabled';

function employee_lifecycle_install_schema(mysqli $conn): void
{
    $userCols = [
        'account_status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
        'login_allowed' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'archived_at' => 'DATETIME NULL',
        'status_changed_at' => 'DATETIME NULL',
        'status_changed_by' => 'INT NULL',
        'status_note' => 'VARCHAR(255) NULL',
        'last_login_at' => 'DATETIME NULL',
        'last_activity_at' => 'DATETIME NULL',
    ];
    foreach ($userCols as $col => $def) {
        if (!db_column_exists($conn, 'users', $col)) {
            @$conn->query("ALTER TABLE `users` ADD `$col` $def");
        }
    }
    staff_ensure_activity_table($conn);
}

function employee_lifecycle_ready(mysqli $conn): bool
{
    static $ready = null;
    static $installTried = false;

    if ($ready === true) {
        return true;
    }

    if (db_column_exists($conn, 'users', 'account_status')) {
        return $ready = true;
    }

    if (!$installTried) {
        $installTried = true;
        employee_lifecycle_install_schema($conn);
        if (db_column_exists($conn, 'users', 'account_status')) {
            return $ready = true;
        }
    }

    return $ready = false;
}

/** SQL fragment: only staff who count toward live attendance / automation. */
function staff_sql_active_filter(string $alias = 'u'): string
{
    global $conn;
    if (!db_column_exists($conn, 'users', 'account_status')) {
        return '';
    }

    if ($alias === '') {
        return " AND account_status = '" . STAFF_STATUS_ACTIVE . "' ";
    }

    $a = preg_replace('/[^a-z_]/i', '', $alias) ?: 'u';

    return " AND {$a}.account_status = '" . STAFF_STATUS_ACTIVE . "' ";
}

function staff_status_label(string $status): string
{
    return match ($status) {
        STAFF_STATUS_INACTIVE => 'Inactive',
        STAFF_STATUS_DISABLED => 'Disabled',
        default => 'Active',
    };
}

function staff_status_badge_class(string $status): string
{
    return match ($status) {
        STAFF_STATUS_INACTIVE => 'bg-warning text-dark',
        STAFF_STATUS_DISABLED => 'bg-secondary',
        default => 'bg-success',
    };
}

/**
 * @return array{account_status:string,login_allowed:int,last_login_at:?string,last_activity_at:?string}
 */
function staff_fetch_lifecycle(mysqli $conn, int $userId): array
{
    if (!employee_lifecycle_ready($conn)) {
        return [
            'account_status' => STAFF_STATUS_ACTIVE,
            'login_allowed' => 1,
            'last_login_at' => null,
            'last_activity_at' => null,
        ];
    }
    $stmt = $conn->prepare(
        'SELECT account_status, login_allowed, last_login_at, last_activity_at FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return [
        'account_status' => (string) ($row['account_status'] ?? STAFF_STATUS_ACTIVE),
        'login_allowed' => (int) ($row['login_allowed'] ?? 1),
        'last_login_at' => $row['last_login_at'] ?? null,
        'last_activity_at' => $row['last_activity_at'] ?? null,
    ];
}

function staff_is_active_for_operations(mysqli $conn, int $userId): bool
{
    if (!employee_lifecycle_ready($conn)) {
        return true;
    }
    $lc = staff_fetch_lifecycle($conn, $userId);

    return $lc['account_status'] === STAFF_STATUS_ACTIVE;
}

/** Admin may change salary/profile only for active employees. */
function staff_require_active_staff(mysqli $conn, int $staffId, int $companyId): bool
{
    if (!employee_lifecycle_ready($conn)) {
        return true;
    }
    $stmt = $conn->prepare(
        "SELECT id FROM users WHERE id = ? AND company_id = ? AND role = 'staff' AND account_status = 'active' LIMIT 1"
    );
    $stmt->bind_param('ii', $staffId, $companyId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $ok;
}

function staff_can_login(mysqli $conn, array $userRow): bool
{
    if (!employee_lifecycle_ready($conn)) {
        return true;
    }

    if ((int) ($userRow['login_allowed'] ?? 1) !== 1) {
        return false;
    }

    $status = (string) ($userRow['account_status'] ?? STAFF_STATUS_ACTIVE);
    if ($status === STAFF_STATUS_DISABLED) {
        return false;
    }

    return true;
}

/** Re-check DB on every staff request; end session if login no longer allowed. */
function staff_enforce_portal_access(mysqli $conn): void
{
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
        return;
    }
    if (!empty($_SESSION['impersonator_id'])) {
        return;
    }

    if (!employee_lifecycle_ready($conn)) {
        return;
    }

    $lc = staff_fetch_lifecycle($conn, (int) $_SESSION['user_id']);
    $row = [
        'account_status' => $lc['account_status'],
        'login_allowed' => $lc['login_allowed'],
    ];

    if (!staff_can_login($conn, $row)) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        header('Location: login.php?blocked=1');
        exit();
    }

    staff_apply_session_lifecycle($row);
}

/** Read-only portal: view history, no punch / leave / tasks mutations. */
function staff_is_readonly_session(): bool
{
    if (!empty($_SESSION['impersonator_id'])) {
        return false;
    }

    return !empty($_SESSION['staff_readonly']);
}

function staff_apply_session_lifecycle(array $userRow): void
{
    $status = (string) ($userRow['account_status'] ?? STAFF_STATUS_ACTIVE);
    $_SESSION['account_status'] = $status;
    $_SESSION['login_allowed'] = (int) ($userRow['login_allowed'] ?? 1);
    $_SESSION['staff_readonly'] = ($status !== STAFF_STATUS_ACTIVE);
}

function staff_require_can_mutate(): void
{
    if (staff_is_readonly_session()) {
        header('Location: staff_dashboard.php?readonly=1');
        exit();
    }
}

function staff_ensure_activity_table(mysqli $conn): void
{
    $conn->query(
        'CREATE TABLE IF NOT EXISTS staff_activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL DEFAULT 0,
            user_id INT NOT NULL,
            user_name VARCHAR(120) NULL,
            account_status VARCHAR(20) NULL,
            action VARCHAR(80) NOT NULL,
            page_url VARCHAR(255) NULL,
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_user_time (user_id, created_at),
            KEY idx_company_time (company_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function staff_log_activity(
    mysqli $conn,
    int $userId,
    string $action,
    ?string $details = null,
    ?string $pageUrl = null,
    ?string $userName = null,
    ?int $companyId = null
): void {
    staff_ensure_activity_table($conn);

    if (employee_lifecycle_ready($conn)) {
        $stmt = $conn->prepare('SELECT name, company_id, account_status FROM users WHERE id = ? LIMIT 1');
    } else {
        $stmt = $conn->prepare('SELECT name, company_id FROM users WHERE id = ? LIMIT 1');
    }
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $userName = $userName ?? (string) ($u['name'] ?? '');
    $companyId = $companyId ?? (int) ($u['company_id'] ?? 0);
    $acctStatus = (string) ($u['account_status'] ?? ($_SESSION['account_status'] ?? STAFF_STATUS_ACTIVE));

    $now = date('Y-m-d H:i:s');
    $ip = get_request_ip();
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
    $page = $pageUrl ?? (basename($_SERVER['PHP_SELF'] ?? ''));

    $stmt = $conn->prepare(
        'INSERT INTO staff_activity_log (company_id, user_id, user_name, account_status, action, page_url, details, ip_address, user_agent, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt) {
        $stmt->bind_param(
            'iissssssss',
            $companyId,
            $userId,
            $userName,
            $acctStatus,
            $action,
            $page,
            $details,
            $ip,
            $ua,
            $now
        );
        @$stmt->execute();
        $stmt->close();
    }

    if (employee_lifecycle_ready($conn)) {
        $stmt = $conn->prepare('UPDATE users SET last_activity_at = ? WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('si', $now, $userId);
            @$stmt->execute();
            $stmt->close();
        }
    }
}

function staff_record_login(mysqli $conn, int $userId, string $userName, int $companyId, string $accountStatus): void
{
    $now = date('Y-m-d H:i:s');
    if (employee_lifecycle_ready($conn)) {
        $stmt = $conn->prepare('UPDATE users SET last_login_at = ?, last_activity_at = ? WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('ssi', $now, $now, $userId);
            @$stmt->execute();
            $stmt->close();
        }
    }
    staff_log_activity(
        $conn,
        $userId,
        'staff.login',
        'status=' . $accountStatus,
        'login.php',
        $userName,
        $companyId
    );
}

/** Throttled page view log (once per page per session). */
function staff_track_page_view(mysqli $conn, string $page): void
{
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
        return;
    }
    $key = 'staff_pv_' . md5($page);
    if (!empty($_SESSION[$key])) {
        return;
    }
    $_SESSION[$key] = time();
    $uid = (int) $_SESSION['user_id'];
    $status = (string) ($_SESSION['account_status'] ?? STAFF_STATUS_ACTIVE);
    $detail = 'status=' . $status;
    if ($status !== STAFF_STATUS_ACTIVE) {
        $detail .= ' (archived read-only)';
    }
    staff_log_activity($conn, $uid, 'staff.page_view', $detail, $page);
}

/**
 * @return array{ok:bool,message:string}
 */
function staff_set_account_status(
    mysqli $conn,
    int $staffId,
    int $companyId,
    string $newStatus,
    int $loginAllowed,
    ?string $note,
    int $adminId
): array {
    if (!employee_lifecycle_ready($conn)) {
        return [
            'ok' => false,
            'message' => 'Could not add employee status columns. Run db_update_v7_employee_lifecycle.php as admin or check database permissions.',
        ];
    }

    $allowed = [STAFF_STATUS_ACTIVE, STAFF_STATUS_INACTIVE, STAFF_STATUS_DISABLED];
    if (!in_array($newStatus, $allowed, true)) {
        return ['ok' => false, 'message' => 'Invalid status.'];
    }

    if ($newStatus === STAFF_STATUS_DISABLED) {
        $loginAllowed = 0;
    } elseif ($newStatus === STAFF_STATUS_ACTIVE) {
        $loginAllowed = 1;
    } else {
        $loginAllowed = $loginAllowed ? 1 : 0;
    }

    $note = $note ?? '';
    $now = date('Y-m-d H:i:s');

    if ($newStatus === STAFF_STATUS_ACTIVE) {
        $stmt = $conn->prepare(
            "UPDATE users SET account_status = ?, login_allowed = ?, status_note = ?, status_changed_at = ?, status_changed_by = ?, archived_at = NULL
             WHERE id = ? AND company_id = ? AND role = 'staff'"
        );
        $stmt->bind_param('sissiii', $newStatus, $loginAllowed, $note, $now, $adminId, $staffId, $companyId);
    } else {
        $stmt = $conn->prepare(
            "UPDATE users SET account_status = ?, login_allowed = ?, status_note = ?, status_changed_at = ?, status_changed_by = ?, archived_at = ?
             WHERE id = ? AND company_id = ? AND role = 'staff'"
        );
        $stmt->bind_param('sissisii', $newStatus, $loginAllowed, $note, $now, $adminId, $now, $staffId, $companyId);
    }
    $ok = $stmt && $stmt->execute();
    $err = $stmt ? $stmt->error : $conn->error;
    $stmt?->close();

    if (!$ok) {
        return ['ok' => false, 'message' => 'Update failed: ' . $err];
    }

    write_audit_log(
        $conn,
        'staff.status_change',
        "staff_id={$staffId} status={$newStatus} login_allowed={$loginAllowed} note=" . ($note ?? ''),
        'user',
        (string) $staffId
    );

    return ['ok' => true, 'message' => 'Employee status updated. Historical data is preserved.'];
}
