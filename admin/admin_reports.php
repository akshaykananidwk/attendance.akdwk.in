<?php
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager', 'hr', 'auditor'], true)) {
    header("Location: ../login.php");
    exit();
}

require_once '../db.php';
$company_id = get_company_id();

// ૧. ફિલ્ટર સેટિંગ્સ
$selected_month = isset($_GET['month']) ? str_pad($_GET['month'], 2, '0', STR_PAD_LEFT) : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$month_year_format = "$selected_year-$selected_month";

// Opening balance: includes/salary_engine.php (via db.php)

// ૨. રિપોર્ટ ડેટા મેળવો (સ્ટાફ માટે)
$end_of_selected_month = date('Y-m-t', strtotime("$selected_year-$selected_month-01"));

$sql = "SELECT 
            u.id, u.name, u.phone_number, u.salary_type, u.salary_amount, u.join_date,
            smr.custom_hourly_rate,
            COUNT(DISTINCT CASE WHEN a.punch_in IS NOT NULL AND a.is_leave = 0 THEN a.log_date END) as days_present,
            SUM(CASE WHEN a.punch_in IS NOT NULL AND a.is_leave = 0 THEN COALESCE(a.total_worked_minutes, 0) ELSE 0 END) as total_minutes,
            (SELECT COALESCE(SUM(amount), 0) FROM advance_payments WHERE user_id = u.id AND MONTH(date) = ? AND YEAR(date) = ? AND transaction_type = 'Upad') as month_upad,
            (SELECT COALESCE(SUM(amount), 0) FROM advance_payments WHERE user_id = u.id AND MONTH(date) = ? AND YEAR(date) = ? AND transaction_type = 'Jama') as month_jama,
            (SELECT COALESCE(SUM(amount), 0) FROM advance_payments WHERE user_id = u.id AND date > ? AND transaction_type = 'Upad') as subsequent_upad,
            (SELECT COALESCE(SUM(amount), 0) FROM advance_payments WHERE user_id = u.id AND date > ? AND transaction_type = 'Jama') as subsequent_jama
        FROM users u
        LEFT JOIN attendance_logs a ON u.id = a.user_id 
            AND MONTH(a.log_date) = ? 
            AND YEAR(a.log_date) = ?
        LEFT JOIN staff_monthly_rates smr ON u.id = smr.user_id 
            AND smr.month_year = ?
        WHERE u.role = 'staff' AND u.name != 'Office' AND u.company_id = $company_id"
        . staff_sql_active_filter('u') . '
        GROUP BY u.id';

$stmt = $conn->prepare($sql);
$stmt->bind_param("sssssssss", $selected_month, $selected_year, $selected_month, $selected_year, $end_of_selected_month, $end_of_selected_month, $selected_month, $selected_year, $month_year_format);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

// ૩. ઓફિસ ભાડું (Office Rent) - Automatic Daily Calculation
$monthly_office_rent = 0;
$rent_query = $conn->query("SELECT monthly_office_rent FROM settings LIMIT 1");

if ($rent_query && $rent_query->num_rows > 0) {
    $rent_data = $rent_query->fetch_assoc();
    $full_rent = floatval($rent_data['monthly_office_rent']);
    
    $current_year_month = date('Y-m'); 
    $selected_year_month = "$selected_year-$selected_month";

    if ($selected_year_month < $current_year_month) {
        $monthly_office_rent = $full_rent;
    } elseif ($selected_year_month == $current_year_month) {
        $today_day = (int)date('d'); 
        $total_days = (int)date('t'); 
        
        $daily_rent = $full_rent / $total_days;
        $monthly_office_rent = round($daily_rent * $today_day, 2);
    }
}

// ૪. ઑફિસના અન્ય ખર્ચા (જે admin_advance માંથી નાખ્યા હોય)
$stmt_off = $conn->prepare("SELECT id FROM users WHERE name = 'Office' LIMIT 1");
$stmt_off->execute();
$office_id_q = $stmt_off->get_result();
$other_office_expense = 0;
if ($office_id_q->num_rows > 0) {
    $office_id = $office_id_q->fetch_assoc()['id'];
    
    // ઑફિસના નામે મારેલો કુલ Upad
    $stmt_upad = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM advance_payments WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ? AND transaction_type = 'Upad'");
    $stmt_upad->bind_param("iss", $office_id, $selected_month, $selected_year);
    $stmt_upad->execute();
    $other_office_expense = floatval($stmt_upad->get_result()->fetch_assoc()['total']);
    $stmt_upad->close();
}
$stmt_off->close();

// કુલ ઑફિસ ખર્ચ = (રોજિંદુ ભાડું + અન્ય મેન્યુઅલ ખર્ચા)
$total_office_expense = $monthly_office_rent + $other_office_expense;

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Reports | Smart Attendance</title>
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
            
            <div class="dashboard-header">
                <div>
                    <button class="mobile-toggle me-2" onclick="toggleSidebar()"><i class="bi bi-list fs-4"></i></button>
                    <span class="fs-4 fw-bold text-dark">Salary & Expense Reports</span>
                </div>
            </div>

            <div class="card p-4 mb-4">
                <form method="GET" class="row align-items-end g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold"><i class="bi bi-calendar-month text-primary"></i> Select Month</label>
                        <select name="month" class="form-select">
                            <?php for ($m=1; $m<=12; $m++) {
                                $mv = str_pad($m, 2, '0', STR_PAD_LEFT);
                                echo "<option value='$mv' ".($mv == $selected_month ? 'selected' : '').">".date("F", mktime(0,0,0,$m,1))."</option>";
                            } ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Year</label>
                        <select name="year" class="form-select">
                            <?php for ($y=2025; $y<=2027; $y++) {
                                echo "<option value='$y' ".($y == $selected_year ? 'selected' : '').">$y</option>";
                            } ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100 fw-bold">
                            <i class="bi bi-search"></i> Generate
                        </button>
                    </div>
                </form>
            </div>

            <div class="card p-4">
                <h5 class="fw-bold mb-4 text-center">
                    <i class="bi bi-file-earmark-spreadsheet-fill text-success"></i> 
                    Financial Report for <?=date("F Y", mktime(0,0,0,$selected_month, 1, $selected_year))?>
                </h5>
                <div class="table-responsive">
                    <table class="table table-hover table-bordered align-middle text-center border">
                        <thead class="table-dark">
                            <tr>
                                <th>Staff Member</th>
                                <th>Rate (₹)</th>
                                <th>Days / Hrs</th>
                                <th>Gross Earned</th>
                                <th>Prev. Bal (Old)</th>
                                <th>Cur. Upad/Jama</th>
                                <th class="bg-success text-white">Net Payable</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $grand_total = 0;
                            if ($result && $result->num_rows > 0): 
                                while($row = $result->fetch_assoc()):
                                    $P = payroll_slip($conn, (int) $row['id'], (int) $selected_year, (int) $selected_month);
                                    $final_rate = $P['rate'];
                                    $gross = $P['earned_display'];
                                    $open_bal = $P['opening_balance'];
                                    $net = $P['net'];
                                    $row['days_present'] = $P['paid_days'];
                                    $row['total_minutes'] = $P['minutes'];
                                    $grand_total += $net;

                                    $hrs = floor($row['total_minutes'] / 60);
                                    $mins = $row['total_minutes'] % 60;
                            ?>
                            <tr>
                                <td class="text-start ps-3 fw-bold">
                                    <i class="bi bi-person-circle text-secondary"></i> <?=$row['name']?>
                                </td>
                                <td>₹<?=$final_rate?></td>
                                <td><span class="badge bg-primary"><?=$row['days_present']?> Days</span><br><small class="text-muted"><?=$hrs?>h <?=$mins?>m</small></td>
                                <td class="fw-bold text-primary">₹<?=number_format($gross, 2)?></td>
                                <td class="fw-bold <?=($open_bal < 0) ? 'text-danger' : 'text-success'?>">
                                    <?=($open_bal >= 0 ? '+' : '')?>₹<?=number_format($open_bal, 2)?>
                                </td>
                                <td>
                                    <?php if($row['month_jama'] > 0) echo "<div class='text-success small fw-bold'>+₹".$row['month_jama']." (Jama)</div>"; ?>
                                    <?php if($row['month_upad'] > 0) echo "<div class='text-danger small fw-bold'>-₹".$row['month_upad']." (Upad)</div>"; ?>
                                    <?php if($row['subsequent_jama'] > 0) echo "<div class='text-success small fw-bold'>+₹".$row['subsequent_jama']." (Later Jama)</div>"; ?>
                                    <?php if($row['subsequent_upad'] > 0) echo "<div class='text-danger small fw-bold'>-₹".$row['subsequent_upad']." (Later Upad)</div>"; ?>
                                </td>
                                <td class="fw-bold fs-5 <?=($net < 0)?'text-danger':'text-success'?>">₹<?=number_format($net, 2)?></td>
                            </tr>
                            <?php endwhile; ?>
                            
                            <tr class="table-light">
                                <td colspan="6" class="text-end fw-bold fs-5">Staff Salary Total:</td>
                                <td class="bg-dark text-white fw-bold fs-4">₹<?=number_format($grand_total, 2)?></td>
                            </tr>
                            
                            <tr class="table-warning">
                                <td colspan="6" class="text-end fw-bold fs-5 text-danger">
                                    Total Office Expense (<?=$selected_month?>/<?=$selected_year?>):<br>
                                    <small class="text-muted">(Rent: ₹<?=number_format($monthly_office_rent, 2)?> + Extra Expenses: ₹<?=number_format($other_office_expense, 2)?>)</small>
                                </td>
                                <td class="text-danger fw-bold fs-4">₹<?=number_format($total_office_expense, 2)?></td>
                            </tr>

                            <tr class="table-danger">
                                <td colspan="6" class="text-end fw-bold fs-4">Grand Total Expense (Salary + Office):</td>
                                <td class="bg-danger text-white fw-bold fs-3">₹<?=number_format($grand_total + $total_office_expense, 2)?></td>
                            </tr>

                            <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-muted py-4">No data available for the selected month.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>