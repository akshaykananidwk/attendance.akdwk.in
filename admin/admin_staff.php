<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
deny_auditor_to_reports();
check_access(['admin', 'manager', 'hr']);

$company_id = (int) get_company_id();
$message = '';
employee_lifecycle_ready($conn);

/** Payroll v2 per-staff options posted from add/edit forms. */
function staff_payroll_options_from_post(string $prefix): array
{
    $mode = (string) ($_POST[$prefix . 'attendance_mode'] ?? 'self');
    if (!in_array($mode, ['self', 'auto', 'manual'], true)) {
        $mode = 'self';
    }
    $off = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST[$prefix . 'weekly_off'] ?? [])), static fn ($d) => $d >= 0 && $d <= 6)));
    sort($off);
    $inc = max(0, round((float) ($_POST[$prefix . 'rate_increment'] ?? 0), 2));

    return [$mode, implode(',', $off), $inc];
}

function staff_save_payroll_options(mysqli $conn, int $userId, int $companyId, string $prefix): void
{
    if (!db_column_exists($conn, 'users', 'attendance_mode')) {
        return;
    }
    [$mode, $off, $inc] = staff_payroll_options_from_post($prefix);
    $stmt = $conn->prepare("UPDATE users SET
            auto_attendance_from = CASE WHEN ? = 'auto' AND (attendance_mode <> 'auto' OR auto_attendance_from IS NULL) THEN CURDATE() ELSE auto_attendance_from END,
            attendance_mode = ?, weekly_off = ?, rate_increment = ?
        WHERE id = ? AND company_id = ?");
    $stmt->bind_param('sssdii', $mode, $mode, $off, $inc, $userId, $companyId);
    $stmt->execute();
    $stmt->close();
    write_audit_log($conn, 'staff.payroll_options', "staff_id={$userId} attendance_mode={$mode} weekly_off={$off} rate_increment={$inc}", 'user', (string) $userId);
}

// 1. ADD STAFF LOGIC
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_staff'])) {
    // CSRF Verification
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        die("CSRF Token validation failed.");
    }

    $company_id = get_company_id();
    $name = $_POST['name'];
    $phone = $_POST['phone_number'];
    $password = $_POST['password'];
    $salary_type = $_POST['salary_type'];
    $salary_amount = $_POST['salary_amount'];
    $join_date = $_POST['join_date'];
    $branch_id = $_POST['branch_id'];
    $shift_start_time = $_POST['shift_start_time'] ?? '09:30:00';

    // // Password Complexity Validation
    // if (!validate_password_complexity($password)) {
    //     $message = "<div class='alert alert-danger fw-bold'>Password must be at least 8 characters long and include both letters and numbers.</div>";
    // } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO users (company_id, branch_id, name, phone_number, password, role, salary_type, salary_amount, msg_active, join_date, shift_start_time) VALUES (?, ?, ?, ?, ?, 'staff', ?, ?, 1, ?, ?)");
        // FIX: Changed "iissssssss" (10 chars) to "iisssssss" (9 chars) to match 9 parameters
        $stmt->bind_param("iisssssss", $company_id, $branch_id, $name, $phone, $hashed_password, $salary_type, $salary_amount, $join_date, $shift_start_time);
        
        if ($stmt->execute()) {
            write_audit_log($conn, 'staff.add', "name={$name} salary_type={$salary_type} amount={$salary_amount}", 'user', (string) $stmt->insert_id);
            $new_staff_id = (int) $stmt->insert_id;
            staff_save_payroll_options($conn, $new_staff_id, (int) $company_id, '');
            // New "auto" staff: present automatically from the joining date (not from today)
            $upd = $conn->prepare("UPDATE users SET auto_attendance_from = LEAST(CURDATE(), join_date) WHERE id = ? AND attendance_mode = 'auto' AND join_date IS NOT NULL");
            $upd->bind_param('i', $new_staff_id);
            $upd->execute();
            $upd->close();
            $message = "<div class='alert alert-success alert-dismissible fade show fw-bold'>New staff added successfully!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } else {
            $message = "<div class='alert alert-danger alert-dismissible fade show fw-bold'>Error: Number already exists or database error.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
        $stmt->close();
    // }
}

// 2. EDIT STAFF LOGIC
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_staff'])) {
    // CSRF Verification
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        die("CSRF Token validation failed.");
    }

    $edit_id = (int) $_POST['edit_id'];
    if (!staff_require_active_staff($conn, $edit_id, $company_id)) {
        $message = "<div class='alert alert-danger fw-bold'>Cannot edit inactive/disabled staff. Reactivate them first.</div>";
    } else {
    $name = $_POST['edit_name'];
    $phone = $_POST['edit_phone'];
    $password = $_POST['edit_password'] ?? '';
    $salary_type = $_POST['edit_salary_type'];
    $salary_amount = $_POST['edit_salary_amount'];
    $join_date = $_POST['edit_join_date'];
    $branch_id = $_POST['edit_branch_id'];
    $shift_start_time = $_POST['edit_shift_start_time'] ?? '09:30:00';

    staff_save_payroll_options($conn, $edit_id, (int) $company_id, 'edit_');
    // If password is being changed, hash it
    if (!empty($password)) {
        // if (!validate_password_complexity($password)) {
        //     $message = "<div class='alert alert-danger fw-bold'>Password must be at least 8 characters long and include both letters and numbers.</div>";
        // } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET name=?, phone_number=?, password=?, salary_type=?, salary_amount=?, join_date=?, branch_id=?, shift_start_time=? WHERE id=? AND company_id=?");
            $stmt->bind_param("ssssssisii", $name, $phone, $hashed_password, $salary_type, $salary_amount, $join_date, $branch_id, $shift_start_time, $edit_id, $company_id);
            if ($stmt->execute()) {
                write_audit_log($conn, 'staff.profile_update', "staff_id={$edit_id} salary_type={$salary_type} salary_amount={$salary_amount} password_changed=1", 'user', (string) $edit_id);
                $message = "<div class='alert alert-success alert-dismissible fade show fw-bold'>Staff details updated!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            }
            $stmt->close();
        // }
    } else {
        // Update without changing password
        $stmt = $conn->prepare("UPDATE users SET name=?, phone_number=?, salary_type=?, salary_amount=?, join_date=?, branch_id=?, shift_start_time=? WHERE id=? AND company_id=?");
        $stmt->bind_param("sssssisii", $name, $phone, $salary_type, $salary_amount, $join_date, $branch_id, $shift_start_time, $edit_id, $company_id);
        if ($stmt->execute()) {
            write_audit_log($conn, 'staff.salary_update', "staff_id={$edit_id} salary_type={$salary_type} salary_amount={$salary_amount}", 'user', (string) $edit_id);
            $message = "<div class='alert alert-success alert-dismissible fade show fw-bold'>Staff details updated!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
        $stmt->close();
    }
    }
}

// 3. SET CUSTOM MONTHLY RATE LOGIC
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['set_custom_rate'])) {
    // CSRF Verification
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        die("CSRF Token validation failed.");
    }

    $user_id = (int) $_POST['custom_user_id'];
    $month_year = $_POST['month_year'];
    $custom_rate = $_POST['custom_rate'];

    if (!staff_require_active_staff($conn, $user_id, $company_id)) {
        $message = "<div class='alert alert-danger fw-bold'>Custom rate applies to active staff only.</div>";
    } else {
    // Check if rate already exists for this month using prepared statement
    $stmt = $conn->prepare("SELECT id FROM staff_monthly_rates WHERE user_id=? AND month_year=?");
    $stmt->bind_param("is", $user_id, $month_year);
    $stmt->execute();
    $check = $stmt->get_result();
    
    if ($check->num_rows > 0) {
        $upd_stmt = $conn->prepare("UPDATE staff_monthly_rates SET custom_hourly_rate=? WHERE user_id=? AND month_year=?");
        $upd_stmt->bind_param("dis", $custom_rate, $user_id, $month_year);
        $upd_stmt->execute();
        $upd_stmt->close();
    } else {
        $ins_stmt = $conn->prepare("INSERT INTO staff_monthly_rates (user_id, month_year, custom_hourly_rate) VALUES (?, ?, ?)");
        $ins_stmt->bind_param("isd", $user_id, $month_year, $custom_rate);
        $ins_stmt->execute();
        $ins_stmt->close();
    }
    $stmt->close();
    write_audit_log($conn, 'staff.custom_rate', "user_id={$user_id} month={$month_year} rate={$custom_rate}", 'staff_monthly_rates', "{$user_id}-{$month_year}");
    $message = "<div class='alert alert-success alert-dismissible fade show fw-bold'>Custom monthly rate saved successfully!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

// 4. EMPLOYEE STATUS (soft lifecycle — never delete records)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_staff_status'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        die('CSRF Token validation failed.');
    }
    $staffId = (int) ($_POST['status_staff_id'] ?? 0);
    $newStatus = (string) ($_POST['account_status'] ?? STAFF_STATUS_ACTIVE);
    $loginAllowed = !empty($_POST['login_allowed']) ? 1 : 0;
    $note = trim((string) ($_POST['status_note'] ?? ''));
    $result = staff_set_account_status(
        $conn,
        $staffId,
        $company_id,
        $newStatus,
        $loginAllowed,
        $note !== '' ? $note : null,
        (int) $_SESSION['user_id']
    );
    $message = $result['ok']
        ? "<div class='alert alert-success fw-bold'>" . htmlspecialchars($result['message']) . '</div>'
        : "<div class='alert alert-danger fw-bold'>" . htmlspecialchars($result['message']) . '</div>';
}

// 5. TOGGLE MESSAGE STATUS (On/Off)
if (isset($_GET['toggle_id']) && isset($_GET['status'])) {
    $id = $_GET['toggle_id'];
    $new_status = ($_GET['status'] == 1) ? 0 : 1;
    $stmt = $conn->prepare("UPDATE users SET msg_active = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_status, $id);
    $stmt->execute();
    write_audit_log($conn, 'staff.toggle_whatsapp', "user_id={$id} msg_active={$new_status}", 'user', (string) $id);
    header("Location: admin_staff.php");
    exit();
}

$list_filter = $_GET['filter'] ?? 'active';
$statusWhere = '';
if (employee_lifecycle_ready($conn)) {
    switch ($list_filter) {
        case 'inactive':
            $statusWhere = " AND u.account_status = 'inactive' ";
            break;
        case 'disabled':
            $statusWhere = " AND u.account_status = 'disabled' ";
            break;
        case 'all':
            $statusWhere = '';
            break;
        case 'active':
        default:
            $statusWhere = " AND u.account_status = 'active' ";
            $list_filter = 'active';
            break;
    }
}
$current_month = date('Y-m');
$staff_result = $conn->query(
    "SELECT u.*, b.name as branch_name, smr.custom_hourly_rate FROM users u
     LEFT JOIN branches b ON u.branch_id = b.id
     LEFT JOIN staff_monthly_rates smr ON u.id = smr.user_id AND smr.month_year = '$current_month'
     WHERE u.role='staff' AND u.company_id = $company_id $statusWhere
     ORDER BY u.id DESC"
);
$branches_list = $conn->query("SELECT id, name FROM branches WHERE company_id = $company_id");
$branches_data = [];
while($b = $branches_list->fetch_assoc()) $branches_data[] = $b;

$csrf_token = generate_csrf_token();
$counts = ['active' => 0, 'inactive' => 0, 'disabled' => 0, 'all' => 0, 'wa' => 0];
$cr = $conn->query("SELECT COALESCE(account_status,'active') st, COUNT(*) n, SUM(msg_active = 1) wa FROM users WHERE role = 'staff' AND company_id = $company_id GROUP BY st");
while ($cr && ($c = $cr->fetch_assoc())) {
    $k = in_array($c['st'], ['active', 'inactive', 'disabled'], true) ? $c['st'] : 'active';
    $counts[$k] += (int) $c['n'];
    $counts['all'] += (int) $c['n'];
    if ($k === 'active') {
        $counts['wa'] += (int) $c['wa'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff | Smart Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/saas_theme.css?v=<?php echo app_asset_version(); ?>">
</head>
<body>
<div class="saas-sidebar-backdrop" id="saasSidebarBackdrop" aria-hidden="true"></div>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php require_once __DIR__ . '/../includes/saas_sidebar_admin.php'; ?>

        <div class="col-md-10 main-content">
            <?php echo ui_hero('Staff Management', 'Manage your organization staff, salaries, and access in one place.', 'bi-people-fill', 'clipboard',
                '<button class="btn btn-primary fw-bold px-4 py-2" data-bs-toggle="modal" data-bs-target="#addStaffModal"><i class="bi bi-person-plus-fill"></i> Add New Staff</button>'); ?>

            <?php if ($message != '') {
                echo $message;
            } ?>
            <?php if (!employee_lifecycle_ready($conn)): ?>
            <div class="alert alert-warning fw-bold mb-3">Employee status columns are missing — run System update (database migrations).</div>
            <?php endif; ?>

            <div class="ui-stats">
                <?php
                echo ui_stat($counts['all'], 'Total Staff', 'bi-people-fill', 'blue', '?filter=all');
                echo ui_stat($counts['active'], 'Active', 'bi-person-check-fill', 'green', '?filter=active');
                echo ui_stat($counts['inactive'], 'Inactive', 'bi-clock-history', 'orange', '?filter=inactive');
                echo ui_stat($counts['disabled'], 'Disabled', 'bi-person-x-fill', 'red', '?filter=disabled');
                echo ui_stat($counts['wa'], 'WhatsApp ON', 'bi-whatsapp', 'teal');
                ?>
            </div>

            <div class="alert alert-info small mb-3 py-2 alert-dismissible fade show">
                <i class="bi bi-info-circle"></i> Employees are <strong>never permanently deleted</strong>. Use status to deactivate; history is preserved.
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
            </div>

            <div class="ui-panel">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div class="ui-tabs">
                        <a href="?filter=all" class="<?php echo $list_filter === 'all' ? 'on' : ''; ?>">All Staff<span class="cnt"><?php echo $counts['all']; ?></span></a>
                        <a href="?filter=active" class="<?php echo $list_filter === 'active' ? 'on' : ''; ?>">Active<span class="cnt"><?php echo $counts['active']; ?></span></a>
                        <a href="?filter=inactive" class="<?php echo $list_filter === 'inactive' ? 'on' : ''; ?>">Inactive<span class="cnt"><?php echo $counts['inactive']; ?></span></a>
                        <a href="?filter=disabled" class="<?php echo $list_filter === 'disabled' ? 'on' : ''; ?>">Disabled<span class="cnt"><?php echo $counts['disabled']; ?></span></a>
                    </div>
                    <a class="btn btn-outline-primary fw-semibold" href="admin_employee_activity.php"><i class="bi bi-clock-history"></i> Activity Log</a>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <div class="ui-search" style="flex:1 1 280px"><i class="bi bi-search"></i><input type="search" id="sSearch" placeholder="Search by name, phone or branch…" value="<?php echo ui_e($_GET['q'] ?? ''); ?>" style="padding-right:.8rem"></div>
                    <select class="form-select" id="sBranch" style="width:auto"><option value="">All Branches</option><?php foreach ($branches_data as $b): ?><option><?php echo ui_e($b['name']); ?></option><?php endforeach; ?></select>
                    <select class="form-select" id="sType" style="width:auto"><option value="">All Types</option><option value="hourly">Hourly</option><option value="monthly">Monthly</option></select>
                    <button class="btn btn-outline-primary fw-semibold" id="sExport" type="button"><i class="bi bi-download"></i> Export</button>
                </div>
                <div class="table-responsive">
                    <table class="ui-table text-center" id="staffTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th class="text-start">Name &amp; Phone</th>
                                <th>Status</th>
                                <th>Branch</th>
                                <th>Join Date</th>
                                <th>Base Salary</th>
                                <th>WhatsApp</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rowNo = 0; while($row = $staff_result->fetch_assoc()): $rowNo++;
                                $acctStatus = employee_lifecycle_ready($conn) ? ($row['account_status'] ?? STAFF_STATUS_ACTIVE) : STAFF_STATUS_ACTIVE;
                                $loginOk = (int)($row['login_allowed'] ?? 1);
                            ?>
                            <tr class="s-row" data-branch="<?php echo ui_e($row['branch_name'] ?? ''); ?>" data-type="<?php echo ui_e($row['salary_type']); ?>">
                                <td><?php echo $rowNo; ?></td>
                                <td class="text-start"><div class="d-flex align-items-center gap-2"><?php echo ui_avatar((string) $row['name']); ?>
                                    <div><b><?php echo htmlspecialchars($row['name']); ?></b><div class="small text-muted"><i class="bi bi-whatsapp text-success"></i> <?php echo htmlspecialchars($row['phone_number']); ?></div></div></div>
                                </td>
                                <td>
                                    <?php echo $acctStatus === 'active' ? '<span class="ui-pill green">● Active</span>' : ($acctStatus === 'disabled' ? '<span class="ui-pill red">● ' . staff_status_label($acctStatus) . '</span>' : '<span class="ui-pill amber">● ' . staff_status_label($acctStatus) . '</span>'); ?>
                                    <?php if ($loginOk): ?><br><small class="text-success">Login OK</small><?php else: ?><br><small class="text-danger">Login blocked</small><?php endif; ?>
                                    <?php if (!empty($row['last_login_at'])): ?><br><small class="text-muted">Last login: <?php echo date('d M Y H:i', strtotime($row['last_login_at'])); ?></small><?php endif; ?>
                                </td>
                                <td>
                                    <span class="ui-branch-tag"><i class="bi bi-shop"></i><?php echo htmlspecialchars($row['branch_name'] ?? 'No Branch'); ?></span>
                                </td>
                                <td>
                                    <span class="text-nowrap"><i class="bi bi-calendar3 text-muted me-1"></i><?php echo ($row['join_date']) ? date("d M Y", strtotime($row['join_date'])) : '<span class="text-muted">Not Set</span>'; ?></span>
                                </td>
                                <td>
                                    <span class="ui-pill gray mb-1"><?php echo ucfirst($row['salary_type']); ?></span><br>
                                    <span class="text-muted small">Base:</span> ₹<?php echo $row['salary_amount']; ?>
                                    <?php if ((float) ($row['rate_increment'] ?? 0) > 0): ?> <span class="ui-pill amber">+₹<?php echo (float) $row['rate_increment']; ?>/month</span><?php endif; ?>
                                    <br><span class="ui-pill gray mt-1"><?php echo ['self' => 'Self punch', 'auto' => 'Auto present', 'manual' => 'Manual'][$row['attendance_mode'] ?? 'self'] ?? 'Self punch'; ?></span>
                                    <span class="ui-pill gray mt-1"><?php echo htmlspecialchars(payroll_weekly_off_label($row)); ?></span>
                                    <?php if (!empty($row['custom_hourly_rate'])): ?>
                                        <br><span class="text-success small fw-bold" title="Custom Rate for this month">This Mth: ₹<?php echo $row['custom_hourly_rate']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="?toggle_id=<?php echo $row['id']; ?>&status=<?php echo $row['msg_active']; ?>" 
                                       class="btn btn-sm <?php echo ($row['msg_active'] == 1) ? 'btn-success' : 'btn-outline-danger'; ?> fw-bold">
                                        <?php echo ($row['msg_active'] == 1) ? 'ON' : 'OFF'; ?>
                                    </a>
                                </td>
                                <td class="s-actions">
                                    <button type="button" class="btn btn-sm btn-warning fw-bold mb-1 btn-staff-status"
                                        data-staff-id="<?php echo (int) $row['id']; ?>"
                                        data-staff-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-staff-status="<?php echo htmlspecialchars($acctStatus, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-staff-login="<?php echo $loginOk; ?>"
                                        data-staff-note="<?php echo htmlspecialchars((string) ($row['status_note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="bi bi-person-gear"></i> Status
                                    </button>
                                    <?php if ($acctStatus === STAFF_STATUS_ACTIVE): ?>
                                    <form method="POST" action="staff_access.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="staff_id" value="<?php echo (int) $row['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-success fw-bold mb-1" title="Open staff portal as this user">
                                            <i class="bi bi-box-arrow-in-right"></i> Login as staff
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <a href="../login.php" class="btn btn-sm btn-outline-secondary mb-1" title="Staff uses this phone on login page">
                                        <i class="bi bi-phone"></i> Login page
                                    </a>
                                    <br>
                                    <?php if ($acctStatus === STAFF_STATUS_ACTIVE): ?>
                                    <button type="button" class="btn btn-sm btn-info text-white fw-bold mb-1 btn-custom-rate"
                                        data-staff-id="<?php echo (int) $row['id']; ?>"
                                        data-staff-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="bi bi-currency-rupee"></i> Custom Rate
                                    </button>
                                    <br>
                                    <button type="button" class="btn btn-sm btn-primary btn-edit-staff"
                                        data-staff-id="<?php echo (int) $row['id']; ?>"
                                        data-staff-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-staff-phone="<?php echo htmlspecialchars($row['phone_number'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-staff-type="<?php echo htmlspecialchars($row['salary_type'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-staff-amount="<?php echo htmlspecialchars((string) $row['salary_amount'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-staff-join="<?php echo htmlspecialchars((string) ($row['join_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-staff-shift="<?php echo htmlspecialchars((string) ($row['shift_start_time'] ?? '09:30:00'), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-staff-branch="<?php echo (int) $row['branch_id']; ?>"
                                        data-staff-mode="<?php echo htmlspecialchars((string) ($row['attendance_mode'] ?? 'self'), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-staff-off="<?php echo htmlspecialchars((string) ($row['weekly_off'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-staff-inc="<?php echo htmlspecialchars((string) ($row['rate_increment'] ?? '0'), ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="bi bi-pencil"></i> Edit
                                    </button>
                                    <?php else: ?>
                                    <span class="badge bg-secondary mb-1">Edit locked</span><br>
                                    <?php endif; ?>
                                    <a href="admin_employee_activity.php?user_id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-dark" title="View login & page activity"><i class="bi bi-activity"></i></a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content card">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold">Add New Staff</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <div class="mb-3"><label class="form-label fw-bold">Full Name</label><input type="text" name="name" class="form-control" required></div>
                <div class="mb-3"><label class="form-label fw-bold">WhatsApp Number</label><input type="text" name="phone_number" class="form-control" required></div>
                <div class="mb-3"><label class="form-label fw-bold">Login Password</label><input type="text" name="password" class="form-control" required></div>
                <div class="row">
                    <div class="col-6 mb-3"><label class="form-label fw-bold">Joining Date</label><input type="date" name="join_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="col-6 mb-3"><label class="form-label fw-bold">Shift Start Time</label><input type="time" name="shift_start_time" class="form-control" value="09:30" required></div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Assign Branch</label>
                    <select name="branch_id" class="form-select" required>
                        <?php foreach($branches_data as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo $b['name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row">
                    <div class="col-6 mb-3"><label class="form-label fw-bold">Type</label><select name="salary_type" class="form-select"><option value="hourly">Hourly</option><option value="monthly">Monthly</option></select></div>
                    <div class="col-6 mb-3"><label class="form-label fw-bold">Base Amount (₹)</label><input type="number" step="0.01" name="salary_amount" class="form-control" required></div>
                </div>

                <div class="border rounded p-2 mb-3 bg-light">
                    <div class="fw-bold small mb-1"><i class="bi bi-calendar-week"></i> Attendance &amp; salary rules</div>
                    <label class="form-label small mb-1">Attendance mode</label>
                    <select name="attendance_mode" id="a_mode" class="form-select form-select-sm mb-2">
                        <option value="self">Staff punches (self) — auto absence/late penalty ON</option>
                        <option value="auto">Automatic present every working day (fixed salary)</option>
                        <option value="manual">Manual — admin enters attendance, no auto penalty</option>
                    </select>
                    <label class="form-label small mb-1">Weekly off (paid for monthly staff, never a penalty)</label>
                    <div class="mb-2"><div class="form-check form-check-inline"><input class="form-check-input a_wo" type="checkbox" name="weekly_off[]" value="0" id="a_wo0"><label class="form-check-label small" for="a_wo0">Sun</label></div><div class="form-check form-check-inline"><input class="form-check-input a_wo" type="checkbox" name="weekly_off[]" value="1" id="a_wo1"><label class="form-check-label small" for="a_wo1">Mon</label></div><div class="form-check form-check-inline"><input class="form-check-input a_wo" type="checkbox" name="weekly_off[]" value="2" id="a_wo2"><label class="form-check-label small" for="a_wo2">Tue</label></div><div class="form-check form-check-inline"><input class="form-check-input a_wo" type="checkbox" name="weekly_off[]" value="3" id="a_wo3"><label class="form-check-label small" for="a_wo3">Wed</label></div><div class="form-check form-check-inline"><input class="form-check-input a_wo" type="checkbox" name="weekly_off[]" value="4" id="a_wo4"><label class="form-check-label small" for="a_wo4">Thu</label></div><div class="form-check form-check-inline"><input class="form-check-input a_wo" type="checkbox" name="weekly_off[]" value="5" id="a_wo5"><label class="form-check-label small" for="a_wo5">Fri</label></div><div class="form-check form-check-inline"><input class="form-check-input a_wo" type="checkbox" name="weekly_off[]" value="6" id="a_wo6"><label class="form-check-label small" for="a_wo6">Sat</label></div></div>
                    <label class="form-label small mb-1">Auto increment per month (₹) <span class="text-muted">— 0 = no automatic increase</span></label>
                    <input type="number" step="0.01" min="0" name="rate_increment" id="a_inc" class="form-control form-control-sm" value="0">
                </div>
                <button type="submit" name="add_staff" class="btn btn-primary w-100 fw-bold">Save Staff</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content card">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold">Edit Staff Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="edit_id" id="e_id">
                <div class="mb-3"><label class="form-label fw-bold">Full Name</label><input type="text" name="edit_name" id="e_name" class="form-control" required></div>
                <div class="mb-3"><label class="form-label fw-bold">WhatsApp Number</label><input type="text" name="edit_phone" id="e_phone" class="form-control" required></div>
                <div class="mb-3"><label class="form-label fw-bold">Password (Leave blank if no change)</label><input type="text" name="edit_password" id="e_pass" class="form-control" placeholder="New Password"></div>
                <div class="row">
                    <div class="col-6 mb-3"><label class="form-label fw-bold">Joining Date</label><input type="date" name="edit_join_date" id="e_join" class="form-control" required></div>
                    <div class="col-6 mb-3"><label class="form-label fw-bold">Shift Start Time</label><input type="time" name="edit_shift_start_time" id="e_shift" class="form-control" required></div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Change Branch</label>
                    <select name="edit_branch_id" id="e_branch" class="form-select" required>
                        <?php foreach($branches_data as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo $b['name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row">
                    <div class="col-6 mb-3"><label class="form-label fw-bold">Type</label><select name="edit_salary_type" id="e_type" class="form-select"><option value="hourly">Hourly</option><option value="monthly">Monthly</option></select></div>
                    <div class="col-6 mb-3"><label class="form-label fw-bold">Base Amount (₹)</label><input type="number" step="0.01" name="edit_salary_amount" id="e_amount" class="form-control" required></div>
                </div>

                <div class="border rounded p-2 mb-3 bg-light">
                    <div class="fw-bold small mb-1"><i class="bi bi-calendar-week"></i> Attendance &amp; salary rules</div>
                    <label class="form-label small mb-1">Attendance mode</label>
                    <select name="edit_attendance_mode" id="e_mode" class="form-select form-select-sm mb-2">
                        <option value="self">Staff punches (self) — auto absence/late penalty ON</option>
                        <option value="auto">Automatic present every working day (fixed salary)</option>
                        <option value="manual">Manual — admin enters attendance, no auto penalty</option>
                    </select>
                    <label class="form-label small mb-1">Weekly off (paid for monthly staff, never a penalty)</label>
                    <div class="mb-2"><div class="form-check form-check-inline"><input class="form-check-input e_wo" type="checkbox" name="edit_weekly_off[]" value="0" id="e_wo0"><label class="form-check-label small" for="e_wo0">Sun</label></div><div class="form-check form-check-inline"><input class="form-check-input e_wo" type="checkbox" name="edit_weekly_off[]" value="1" id="e_wo1"><label class="form-check-label small" for="e_wo1">Mon</label></div><div class="form-check form-check-inline"><input class="form-check-input e_wo" type="checkbox" name="edit_weekly_off[]" value="2" id="e_wo2"><label class="form-check-label small" for="e_wo2">Tue</label></div><div class="form-check form-check-inline"><input class="form-check-input e_wo" type="checkbox" name="edit_weekly_off[]" value="3" id="e_wo3"><label class="form-check-label small" for="e_wo3">Wed</label></div><div class="form-check form-check-inline"><input class="form-check-input e_wo" type="checkbox" name="edit_weekly_off[]" value="4" id="e_wo4"><label class="form-check-label small" for="e_wo4">Thu</label></div><div class="form-check form-check-inline"><input class="form-check-input e_wo" type="checkbox" name="edit_weekly_off[]" value="5" id="e_wo5"><label class="form-check-label small" for="e_wo5">Fri</label></div><div class="form-check form-check-inline"><input class="form-check-input e_wo" type="checkbox" name="edit_weekly_off[]" value="6" id="e_wo6"><label class="form-check-label small" for="e_wo6">Sat</label></div></div>
                    <label class="form-label small mb-1">Auto increment per month (₹) <span class="text-muted">— 0 = no automatic increase</span></label>
                    <input type="number" step="0.01" min="0" name="edit_rate_increment" id="e_inc" class="form-control form-control-sm" value="0">
                </div>
                <button type="submit" name="edit_staff" class="btn btn-dark w-100 fw-bold">Update Details</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="customRateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <form method="POST" class="modal-content card">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title fw-bold">Set Custom Month Rate</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="custom_user_id" id="c_id">
                <div class="mb-3">
                    <label class="form-label fw-bold">Staff Name</label>
                    <input type="text" id="c_name" class="form-control bg-light" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Select Month</label>
                    <input type="month" name="month_year" class="form-control" value="<?php echo date('Y-m'); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">New Hourly Rate (₹)</label>
                    <input type="number" step="0.01" name="custom_rate" class="form-control" placeholder="e.g. 35" required>
                </div>
                <button type="submit" name="set_custom_rate" class="btn btn-info text-white w-100 fw-bold">Save Rate</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content card">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold">Employee account status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="status_staff_id" id="st_id">
                <p class="fw-bold mb-3" id="st_name"></p>
                <div class="mb-3">
                    <label class="form-label fw-bold">Status</label>
                    <select name="account_status" id="st_status" class="form-select">
                        <option value="active">Active — full access, live attendance</option>
                        <option value="inactive">Inactive — archive (login optional)</option>
                        <option value="disabled">Disabled — archive (login optional)</option>
                    </select>
                </div>
                <div class="form-check mb-3" id="st_login_wrap">
                    <input class="form-check-input" type="checkbox" name="login_allowed" id="st_login" value="1" checked>
                    <label class="form-check-label" for="st_login">Allow login (inactive only — disabled accounts cannot log in)</label>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Note (optional)</label>
                    <input type="text" name="status_note" id="st_note" class="form-control" placeholder="e.g. Resigned May 2026">
                </div>
                <button type="submit" name="set_staff_status" class="btn btn-warning w-100 fw-bold">Save status</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function openStatusModal(id, name, status, loginAllowed, note) {
        var modalEl = document.getElementById('statusModal');
        if (!modalEl) {
            alert('Status dialog not loaded. Refresh the page.');
            return;
        }
        document.getElementById('st_id').value = id;
        document.getElementById('st_name').textContent = name;
        document.getElementById('st_status').value = status || 'active';
        document.getElementById('st_login').checked = Number(loginAllowed) === 1;
        document.getElementById('st_note').value = note || '';
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
    function syncStatusLoginUi() {
        var st = document.getElementById('st_status').value;
        var loginWrap = document.getElementById('st_login_wrap');
        var loginCb = document.getElementById('st_login');
        if (st === 'disabled') {
            loginCb.checked = false;
            loginCb.disabled = true;
            if (loginWrap) loginWrap.classList.add('opacity-50');
        } else if (st === 'active') {
            loginCb.checked = true;
            loginCb.disabled = false;
            if (loginWrap) loginWrap.classList.remove('opacity-50');
        } else {
            loginCb.disabled = false;
            if (loginWrap) loginWrap.classList.remove('opacity-50');
        }
    }
    document.getElementById('st_status').addEventListener('change', syncStatusLoginUi);

    document.querySelectorAll('.btn-staff-status').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openStatusModal(
                parseInt(this.getAttribute('data-staff-id'), 10),
                this.getAttribute('data-staff-name') || '',
                this.getAttribute('data-staff-status') || 'active',
                parseInt(this.getAttribute('data-staff-login'), 10) || 0,
                this.getAttribute('data-staff-note') || ''
            );
            syncStatusLoginUi();
        });
    });

    document.querySelectorAll('.btn-edit-staff').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('e_id').value = this.getAttribute('data-staff-id');
            document.getElementById('e_name').value = this.getAttribute('data-staff-name') || '';
            document.getElementById('e_phone').value = this.getAttribute('data-staff-phone') || '';
            document.getElementById('e_pass').value = '';
            document.getElementById('e_type').value = this.getAttribute('data-staff-type') || 'hourly';
            document.getElementById('e_amount').value = this.getAttribute('data-staff-amount') || '';
            document.getElementById('e_join').value = this.getAttribute('data-staff-join') || '';
            document.getElementById('e_shift').value = this.getAttribute('data-staff-shift') || '09:30';
            document.getElementById('e_mode').value = this.getAttribute('data-staff-mode') || 'self';
            document.getElementById('e_inc').value = this.getAttribute('data-staff-inc') || '0';
            var offs = (this.getAttribute('data-staff-off') || '').split(',');
            document.querySelectorAll('.e_wo').forEach(function (cb) { cb.checked = offs.indexOf(cb.value) !== -1; });
            document.getElementById('e_branch').value = this.getAttribute('data-staff-branch') || '';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
        });
    });

    document.querySelectorAll('.btn-custom-rate').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('c_id').value = this.getAttribute('data-staff-id');
            document.getElementById('c_name').value = this.getAttribute('data-staff-name') || '';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('customRateModal')).show();
        });
    });
</script>
<script>
(function () {
    var q = document.getElementById('sSearch'), br = document.getElementById('sBranch'), ty = document.getElementById('sType');
    var rows = document.querySelectorAll('#staffTable tr.s-row');
    function f() { var t = q.value.toLowerCase().trim(); rows.forEach(function (r) {
        r.style.display = (r.textContent.toLowerCase().indexOf(t) !== -1 && (!br.value || r.dataset.branch === br.value) && (!ty.value || r.dataset.type === ty.value)) ? '' : 'none'; }); }
    q.addEventListener('input', f); br.addEventListener('change', f); ty.addEventListener('change', f); f();
    document.getElementById('sExport').addEventListener('click', function () {
        var out = [['Name', 'Phone', 'Status', 'Branch', 'Join date', 'Salary']];
        rows.forEach(function (r) { if (r.style.display === 'none') return; var c = r.querySelectorAll('td'); var nm = c[1].querySelector('b');
            out.push([nm ? nm.innerText : '', c[1].innerText.replace(nm ? nm.innerText : '', '').trim(), c[2].innerText.split('\n')[0].trim(), c[3].innerText.trim(), c[4].innerText.trim(), c[5].innerText.replace(/\s+/g, ' ').trim()]); });
        var csv = out.map(function (r) { return r.map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(','); }).join('\n');
        var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + csv], {type: 'text/csv'})); a.download = 'staff.csv'; a.click();
    });
    if (/[?&]add=1/.test(location.search) && window.bootstrap) { bootstrap.Modal.getOrCreateInstance(document.getElementById('addStaffModal')).show(); }
})();
</script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>