<?php
$saas_cur = basename($_SERVER['PHP_SELF'] ?? '');
$saas_is_auditor = function_exists('is_auditor') && is_auditor();
?>
<div class="col-md-2 sidebar" id="sidebar">
    <div class="saas-sidebar-brand">
        <i class="bi bi-grid-1x2-fill text-white"></i> Smart SaaS
        <?php if ($saas_is_auditor): ?>
            <div class="small text-white-50 mt-1">Auditor (read-only)</div>
        <?php endif; ?>
    </div>
    <div class="saas-sidebar-scroll">
        <?php if ($saas_is_auditor): ?>
            <div class="saas-nav-section-label">Reports &amp; data</div>
            <a href="admin_reports.php" class="<?php echo $saas_cur === 'admin_reports.php' ? 'active' : ''; ?>"><i class="bi bi-bar-chart-line-fill"></i> Salary reports</a>
            <a href="admin_salary_slip.php" class="<?php echo $saas_cur === 'admin_salary_slip.php' ? 'active' : ''; ?>"><i class="bi bi-file-earmark-text-fill"></i> Salary slips</a>
            <a href="admin_clients.php" class="<?php echo $saas_cur === 'admin_clients.php' ? 'active' : ''; ?>"><i class="bi bi-person-lines-fill"></i> Client history</a>
            <a href="admin_exports.php" class="<?php echo $saas_cur === 'admin_exports.php' ? 'active' : ''; ?>"><i class="bi bi-file-earmark-spreadsheet"></i> Data exports</a>
            <a href="admin_live_tracking.php" class="<?php echo $saas_cur === 'admin_live_tracking.php' ? 'active' : ''; ?>"><i class="bi bi-geo-alt-fill"></i> Live tracking</a>
            <a href="admin_audit_log.php" class="<?php echo $saas_cur === 'admin_audit_log.php' ? 'active' : ''; ?>"><i class="bi bi-journal-text"></i> Audit log</a>
            <a href="../logout.php" class="mt-4 text-danger fw-bold"><i class="bi bi-box-arrow-left"></i> Logout</a>
        <?php else: ?>
            <div class="saas-nav-section-label">Overview</div>
            <a href="admin_dashboard.php" class="<?php echo $saas_cur === 'admin_dashboard.php' ? 'active' : ''; ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a href="admin_live_tracking.php" class="<?php echo $saas_cur === 'admin_live_tracking.php' ? 'active' : ''; ?>"><i class="bi bi-geo-alt-fill"></i> Live Tracking</a>

            <div class="saas-nav-section-label">Organization</div>
            <a href="admin_branches.php" class="<?php echo $saas_cur === 'admin_branches.php' ? 'active' : ''; ?>"><i class="bi bi-building"></i> Branches</a>
            <a href="admin_staff.php" class="<?php echo $saas_cur === 'admin_staff.php' ? 'active' : ''; ?>"><i class="bi bi-people-fill"></i> Staff</a>
            <a href="admin_employee_activity.php" class="<?php echo $saas_cur === 'admin_employee_activity.php' ? 'active' : ''; ?>"><i class="bi bi-person-lines-fill"></i> Staff activity</a>
            <a href="admin_clients.php" class="<?php echo $saas_cur === 'admin_clients.php' ? 'active' : ''; ?>"><i class="bi bi-person-lines-fill"></i> Client history</a>

            <div class="saas-nav-section-label">Operations</div>
            <a href="admin_tasks.php" class="<?php echo $saas_cur === 'admin_tasks.php' ? 'active' : ''; ?>"><i class="bi bi-list-task"></i> Tasks</a>
            <a href="admin_announcements.php" class="<?php echo $saas_cur === 'admin_announcements.php' ? 'active' : ''; ?>"><i class="bi bi-megaphone"></i> Announcements</a>
            <a href="admin_performance.php" class="<?php echo $saas_cur === 'admin_performance.php' ? 'active' : ''; ?>"><i class="bi bi-star-fill"></i> Performance</a>

            <div class="saas-nav-section-label">Time &amp; payroll</div>
            <a href="admin_leaves.php" class="<?php echo $saas_cur === 'admin_leaves.php' ? 'active' : ''; ?>"><i class="bi bi-calendar2-check"></i> Leave approvals</a>
            <a href="admin_manual_time.php" class="<?php echo $saas_cur === 'admin_manual_time.php' ? 'active' : ''; ?>"><i class="bi bi-clock-history"></i> Manual time</a>
            <a href="admin_advance.php" class="<?php echo $saas_cur === 'admin_advance.php' ? 'active' : ''; ?>"><i class="bi bi-cash-stack"></i> Upad &amp; Jama</a>
            <a href="admin_penalties.php" class="<?php echo $saas_cur === 'admin_penalties.php' ? 'active' : ''; ?>"><i class="bi bi-exclamation-octagon"></i> Penalties</a>
            <a href="admin_resignations.php" class="<?php echo $saas_cur === 'admin_resignations.php' ? 'active' : ''; ?>"><i class="bi bi-door-open"></i> Resignations</a>
            <a href="admin_reports.php" class="<?php echo $saas_cur === 'admin_reports.php' ? 'active' : ''; ?>"><i class="bi bi-bar-chart-line-fill"></i> Salary reports</a>
            <a href="admin_salary_slip.php" class="<?php echo $saas_cur === 'admin_salary_slip.php' ? 'active' : ''; ?>"><i class="bi bi-file-earmark-text-fill"></i> Salary slips</a>
            <a href="admin_payments.php" class="<?php echo $saas_cur === 'admin_payments.php' ? 'active' : ''; ?>"><i class="bi bi-currency-rupee"></i> Salary payments</a>

            <div class="saas-nav-section-label">Data &amp; system</div>
            <a href="admin_exports.php" class="<?php echo $saas_cur === 'admin_exports.php' ? 'active' : ''; ?>"><i class="bi bi-file-earmark-spreadsheet"></i> Data exports</a>
            <a href="admin_audit_log.php" class="<?php echo $saas_cur === 'admin_audit_log.php' ? 'active' : ''; ?>"><i class="bi bi-journal-text"></i> Audit log</a>
            <a href="admin_settings.php" class="<?php echo $saas_cur === 'admin_settings.php' ? 'active' : ''; ?>"><i class="bi bi-gear-fill"></i> Settings</a>
            <a href="admin_backup.php" class="<?php echo $saas_cur === 'admin_backup.php' ? 'active' : ''; ?>"><i class="bi bi-cloud-download-fill"></i> Backup &amp; restore</a>
            <a href="admin_updates.php" class="<?php echo $saas_cur === 'admin_updates.php' ? 'active' : ''; ?>"><i class="bi bi-cloud-arrow-down-fill"></i> System update</a>

            <a href="../logout.php" class="mt-4 text-danger fw-bold"><i class="bi bi-box-arrow-left"></i> Logout</a>
        <?php endif; ?>
    </div>
</div>
