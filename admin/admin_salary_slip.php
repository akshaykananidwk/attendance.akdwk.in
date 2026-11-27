<?php
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager', 'hr', 'auditor'], true)) {
    header("Location: ../login.php");
    exit();
}

require_once '../db.php';
$company_id = (int)get_company_id();

$selected_user = isset($_GET['user_id']) ? $_GET['user_id'] : '';
$selected_month = isset($_GET['month']) ? str_pad($_GET['month'], 2, '0', STR_PAD_LEFT) : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$month_year_format = "$selected_year-$selected_month";

// Fetch Staff List for Dropdown
$include_archived = !empty($_GET['include_archived']);
$staffListSql = "SELECT id, name, account_status FROM users WHERE role='staff' AND company_id = $company_id";
if (!$include_archived && employee_lifecycle_ready($conn)) {
    $staffListSql .= staff_sql_active_filter('');
}
$staffListSql .= ' ORDER BY name ASC';
$staff_list = $conn->query($staffListSql);

$slip_data = null;
$slip_blocked = '';
if ($selected_user != '') {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND company_id = ? AND role = 'staff'");
    $stmt->bind_param('ii', $selected_user, $company_id);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($staff && employee_lifecycle_ready($conn) && !$include_archived
        && ($staff['account_status'] ?? 'active') !== STAFF_STATUS_ACTIVE) {
        $slip_blocked = 'This employee is inactive/disabled. Check "Include archived staff" to view past salary slips.';
        $staff = null;
    }

    if (!$staff) {
        // skip slip generation
    } else {
    // Calculate Final Rate for Current Month
    $stmt = $conn->prepare("SELECT custom_hourly_rate FROM staff_monthly_rates WHERE user_id = ? AND month_year = ?");
    $stmt->bind_param("is", $selected_user, $month_year_format);
    $stmt->execute();
    $custom_rate_q = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $custom_rate = $custom_rate_q ? $custom_rate_q['custom_hourly_rate'] : null;

    $base_rate = floatval($staff['salary_amount']);
    $final_rate = $base_rate;
    $rate_badge = "";

    if (!empty($custom_rate)) {
        $final_rate = floatval($custom_rate);
        $rate_badge = "(Custom)";
    } else {
        $start_date = (!empty($staff['join_date'])) ? $staff['join_date'] : '2026-01-01';
        $j_date = strtotime($start_date);
        $m_diff = (($selected_year - date('Y', $j_date)) * 12) + ($selected_month - date('m', $j_date));
        if ($m_diff > 0) {
            $final_rate += $m_diff;
            $rate_badge = "(Auto Inc. +$m_diff)";
        }
    }

    // Fetch Monthly Attendance Stats (minutes only on present rows; days = distinct dates)
    $stmt = $conn->prepare("SELECT 
        SUM(CASE WHEN punch_in IS NOT NULL AND is_leave = 0 THEN COALESCE(total_worked_minutes, 0) ELSE 0 END) as total_mins, 
        COUNT(DISTINCT CASE WHEN punch_in IS NOT NULL AND is_leave = 0 THEN log_date END) as days_present,
        COUNT(CASE WHEN is_leave = 1 THEN 1 END) as leave_days
        FROM attendance_logs 
        WHERE user_id = ? AND MONTH(log_date) = ? AND YEAR(log_date) = ?");
    $stmt->bind_param("iss", $selected_user, $selected_month, $selected_year);
    $stmt->execute();
    $att_res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Current Month Upad & Jama
    $stmt_upad = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM advance_payments WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ? AND transaction_type = 'Upad'");
    $stmt_upad->bind_param("iss", $selected_user, $selected_month, $selected_year);
    $stmt_upad->execute();
    $month_upad = $stmt_upad->get_result()->fetch_assoc()['total'];
    $stmt_upad->close();

    $stmt_jama = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM advance_payments WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ? AND transaction_type = 'Jama'");
    $stmt_jama->bind_param("iss", $selected_user, $selected_month, $selected_year);
    $stmt_jama->execute();
    $month_jama = $stmt_jama->get_result()->fetch_assoc()['total'];
    $stmt_jama->close();

    $total_mins = (int) ($att_res['total_mins'] ?? 0);
    $days_present = (int) ($att_res['days_present'] ?? 0);
    $avg_hours_per_present_day = $days_present > 0 ? round(($total_mins / 60) / $days_present, 2) : 0.0;

    // Subsequent Upad & Jama (After the selected month)
    $end_of_selected_month = date('Y-m-t', strtotime("$selected_year-$selected_month-01"));
    $stmt_sub_upad = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM advance_payments WHERE user_id = ? AND date > ? AND transaction_type = 'Upad'");
    $stmt_sub_upad->bind_param("is", $selected_user, $end_of_selected_month);
    $stmt_sub_upad->execute();
    $subsequent_upad = $stmt_sub_upad->get_result()->fetch_assoc()['total'];
    $stmt_sub_upad->close();

    $stmt_sub_jama = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM advance_payments WHERE user_id = ? AND date > ? AND transaction_type = 'Jama'");
    $stmt_sub_jama->bind_param("is", $selected_user, $end_of_selected_month);
    $stmt_sub_jama->execute();
    $subsequent_jama = $stmt_sub_jama->get_result()->fetch_assoc()['total'];
    $stmt_sub_jama->close();

    // Opening Balance
    $opening_balance = calculateOpeningBalance($conn, $selected_user, $selected_month, $selected_year);

    // Salary Calculation
    $gross_salary = ($staff['salary_type'] == 'hourly') ? ($total_mins / 60) * $final_rate : ($final_rate / 30) * $days_present;
    
    // Penalty Calculation
    $penalty_days = salary_penalty_day_deductions($conn, $selected_user, $selected_month, $selected_year);
    $penalty_amount = 0.0;
    $resignation_withhold = salary_resignation_withhold($conn, $selected_user, $selected_month, $selected_year);
    
    if ($resignation_withhold) {
        $penalty_amount = $gross_salary;
    } elseif ($penalty_days > 0) {
        $daily = $staff['salary_type'] === 'hourly' ? ($final_rate * 8) : ($final_rate / 30);
        $penalty_amount = $penalty_days * $daily;
        if ($penalty_amount > $gross_salary) {
            $penalty_amount = $gross_salary;
        }
    }

    $net_payable = $opening_balance + ($gross_salary - $penalty_amount) + $month_jama - $month_upad + $subsequent_jama - $subsequent_upad;

    $slip_data = [
        'staff' => $staff,
        'final_rate' => $final_rate,
        'rate_badge' => $rate_badge,
        'hours' => round($total_mins / 60, 2),
        'days' => $days_present,
        'avg_hours_per_day' => $avg_hours_per_present_day,
        'leaves' => $att_res['leave_days'],
        'gross' => $gross_salary,
        'penalty_days' => $penalty_days,
        'penalty_amount' => $penalty_amount,
        'resignation_withhold' => $resignation_withhold,
        'opening_balance' => $opening_balance,
        'month_upad' => $month_upad,
        'month_jama' => $month_jama,
        'subsequent_upad' => $subsequent_upad,
        'subsequent_jama' => $subsequent_jama,
        'net' => $net_payable
    ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Salary Slip | Smart SaaS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/saas_theme.css">
</head>
<body>
<div class="saas-sidebar-backdrop" id="saasSidebarBackdrop" aria-hidden="true"></div>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php require_once __DIR__ . '/../includes/saas_sidebar_admin.php'; ?>

        <div class="col-md-10 main-content">
            
            <div class="dashboard-header d-print-none">
                <div>
                    <button class="mobile-toggle me-2" onclick="toggleSidebar()"><i class="bi bi-list fs-4"></i></button>
                    <span class="fs-4 fw-bold text-dark"><i class="bi bi-file-earmark-text text-primary"></i> Salary Slips</span>
                </div>
            </div>

            <?php if ($slip_blocked): ?>
            <div class="alert alert-warning"><?php echo htmlspecialchars($slip_blocked); ?></div>
            <?php endif; ?>

            <div class="card p-4 mb-4 d-print-none">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="include_archived" id="include_archived" value="1" <?php echo $include_archived ? 'checked' : ''; ?>>
                            <label class="form-check-label small" for="include_archived">Include inactive/disabled staff (past salary slips only)</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold"><i class="bi bi-person-circle"></i> Select Staff</label>
                        <select name="user_id" class="form-select" required>
                            <option value="">-- Choose Member --</option>
                            <?php while($s = $staff_list->fetch_assoc()): ?>
                                <option value="<?=$s['id']?>" <?=($selected_user == $s['id'])?'selected':''?>><?=$s['name']?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold"><i class="bi bi-calendar-month"></i> Month</label>
                        <select name="month" class="form-select">
                            <?php for($m=1;$m<=12;$m++) echo "<option value='".sprintf('%02d',$m)."' ".($selected_month==sprintf('%02d',$m)?'selected':'').">".date('F', mktime(0,0,0,$m,1))."</option>"; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Year</label>
                        <select name="year" class="form-select">
                            <?php for ($y=2025; $y<=2027; $y++) echo "<option value='$y' ".($y == $selected_year ? 'selected' : '').">$y</option>"; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary fw-bold w-100"><i class="bi bi-search"></i> Generate</button>
                        <?php if($slip_data): ?>
                            <button type="button" onclick="window.print()" class="btn btn-dark fw-bold w-100"><i class="bi bi-printer"></i> Print</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <?php if($slip_data): ?>
            <div class="slip-container shadow">
                <div class="slip-header d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="fw-bold mb-0 text-success">AK COMPUTER DWARKA</h2>
                        <p class="text-muted small mb-0">Smart Attendance & Payroll System</p>
                    </div>
                    <div class="text-end">
                        <h4 class="mb-0 fw-bold border border-dark px-3 py-1 d-inline-block">PAYSLIP</h4>
                        <p class="mb-0 mt-2 fw-bold text-muted"><?=date('F Y', mktime(0,0,0,$selected_month, 1, $selected_year))?></p>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-6">
                        <p class="mb-1"><span class="text-muted">Employee Name:</span> <strong class="fs-5"><?=$slip_data['staff']['name']?></strong></p>
                        <p class="mb-1"><span class="text-muted">Phone Number:</span> <strong><?=$slip_data['staff']['phone_number']?></strong></p>
                    </div>
                    <div class="col-6 text-end">
                        <p class="mb-1"><span class="text-muted">Salary Type:</span> <strong class="badge bg-secondary"><?=ucfirst($slip_data['staff']['salary_type'])?></strong></p>
                        <p class="mb-1"><span class="text-muted">Active Rate:</span> <strong>₹<?=number_format($slip_data['final_rate'], 2)?></strong> <small class="text-primary"><?=$slip_data['rate_badge']?></small></p>
                    </div>
                </div>

                <table class="table table-bordered slip-table mb-4">
                    <thead>
                        <tr>
                            <th style="width: 50%;">Earnings / Details</th>
                            <th class="text-end" style="width: 20%;">Amount</th>
                            <th style="width: 30%;">Deductions</th>
                            <th class="text-end" style="width: 20%;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <strong>Basic Earned (This Month)</strong><br>
                                <small class="text-muted">
                                <?php if ($slip_data['staff']['salary_type'] === 'hourly'): ?>
                                    <?= (int) $slip_data['days'] ?> day(s) · <strong><?= $slip_data['hours'] ?> hrs</strong> × ₹<?= number_format($slip_data['final_rate'], 2) ?>/hr
                                <?php else: ?>
                                    Pay basis: <strong><?= (int) $slip_data['days'] ?> calendar day(s) present</strong> × (monthly ÷ 30).
                                    <br><span class="text-secondary">On those days: <strong><?= $slip_data['hours'] ?> hrs</strong> logged total<?= $slip_data['days'] > 0 ? ' (avg ' . $slip_data['avg_hours_per_day'] . ' hrs/day)' : '' ?> — monthly pay uses day count only, not these hours.</span>
                                <?php endif; ?>
                                </small>
                            </td>
                            <td class="text-end fw-bold text-primary">₹<?=number_format($slip_data['gross'], 2)?></td>
                            
                            <td>Upad (Advance Taken)</td>
                            <td class="text-end text-danger fw-bold">- ₹<?=number_format($slip_data['month_upad'], 2)?></td>
                        </tr>
                        <tr>
                            <td>
                                Previous Balance (Old)<br>
                                <small class="text-muted">(Outstanding amount before this month)</small>
                            </td>
                            <td class="text-end fw-bold <?=($slip_data['opening_balance'] < 0) ? 'text-danger' : 'text-success'?>">
                                <?=($slip_data['opening_balance'] >= 0 ? '+' : '')?>₹<?=number_format($slip_data['opening_balance'], 2)?>
                            </td>
                            
                            <td>Current Jama (Added)</td>
                            <td class="text-end text-success fw-bold">+ ₹<?=number_format($slip_data['month_jama'], 2)?></td>
                        </tr>
                        <?php if ($slip_data['penalty_amount'] > 0): ?>
                        <tr>
                            <td colspan="2" class="border-0 bg-transparent"></td>
                            <td>
                                Penalty Deductions<br>
                                <?php if ($slip_data['resignation_withhold']): ?>
                                <small class="text-danger">Resignation Rule 13</small>
                                <?php else: ?>
                                <small class="text-danger"><?= (float) $slip_data['penalty_days'] ?> day(s)</small>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-danger fw-bold">- ₹<?=number_format($slip_data['penalty_amount'], 2)?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($slip_data['subsequent_upad'] > 0 || $slip_data['subsequent_jama'] > 0): ?>
                        <tr>
                            <td>
                                Jama added after this month<br>
                                <small class="text-muted">(Added after <?=date('F Y', mktime(0,0,0,$selected_month, 1, $selected_year))?>)</small>
                            </td>
                            <td class="text-end text-success fw-bold">+ ₹<?=number_format($slip_data['subsequent_jama'], 2)?></td>
                            
                            <td>Upad taken after this month<br>
                                <small class="text-muted">(Taken after <?=date('F Y', mktime(0,0,0,$selected_month, 1, $selected_year))?>)</small>
                            </td>
                            <td class="text-end text-danger fw-bold">- ₹<?=number_format($slip_data['subsequent_upad'], 2)?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="bg-light p-3 border border-2 border-dark d-flex justify-content-between align-items-center mt-2">
                    <h4 class="mb-0 fw-bold">FINAL NET PAYABLE:</h4>
                    <h2 class="mb-0 fw-bold <?=($slip_data['net'] < 0) ? 'text-danger' : 'text-success'?>">
                        ₹<?=number_format($slip_data['net'], 2)?>
                    </h2>
                </div>

                <div class="row mt-5 pt-5">
                    <div class="col-6 text-center">
                        <div style="border-top: 1px solid #000; width: 180px; margin: auto;"></div>
                        <p class="small mt-2 fw-bold text-muted">Employee Signature</p>
                    </div>
                    <div class="col-6 text-center">
                        <div style="border-top: 1px solid #000; width: 180px; margin: auto;"></div>
                        <p class="small mt-2 fw-bold text-muted">Authorized Signatory</p>
                    </div>
                </div>
            </div>
            <div class="text-center d-print-none mt-3">
                <small class="text-muted">Press <strong>Ctrl + P</strong> (Windows) or <strong>Cmd + P</strong> (Mac) to print.</small>
            </div>
            <?php else: ?>
                <div class="alert alert-info text-center fw-bold mt-4 border-0 shadow-sm py-4">
                    <i class="bi bi-info-circle fs-4 d-block mb-2"></i>
                    Please select a staff member and month to view the Salary Slip.
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>