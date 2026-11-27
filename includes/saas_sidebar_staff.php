<?php
$saas_cur = basename($_SERVER['PHP_SELF'] ?? '');
?>
<div class="col-md-2 sidebar" id="sidebar">
    <div class="saas-sidebar-brand">
        <svg class="saas-brand-logo" viewBox="0 0 40 40" aria-hidden="true"><defs><linearGradient id="sbg2" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#60a5fa"/><stop offset="1" stop-color="#1d6cf2"/></linearGradient></defs><path d="M20 3 36 11.5 20 20 4 11.5z" fill="url(#sbg2)"/><path d="M4 19.5 20 28l16-8.5" fill="none" stroke="#93c5fd" stroke-width="3" stroke-linejoin="round"/><path d="M4 27 20 35.5 36 27" fill="none" stroke="#3b82f6" stroke-width="3" stroke-linejoin="round"/></svg>
        <div><div class="saas-brand-name">Smart SaaS</div><div class="saas-brand-sub">Staff portal</div></div>
    </div>
    <div class="saas-sidebar-scroll">
        <div class="saas-nav-section-label">Workspace</div>
        <a href="staff_dashboard.php" class="<?php echo $saas_cur === 'staff_dashboard.php' ? 'active' : ''; ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>

        <div class="saas-nav-section-label">Work</div>
        <a href="staff_tasks.php" class="<?php echo $saas_cur === 'staff_tasks.php' ? 'active' : ''; ?>"><i class="bi bi-list-task"></i> My tasks</a>
        <a href="staff_tasks_history.php" class="<?php echo $saas_cur === 'staff_tasks_history.php' ? 'active' : ''; ?>"><i class="bi bi-clock-history"></i> Work history</a>

        <div class="saas-nav-section-label">Time off</div>
        <a href="staff_leave.php" class="<?php echo $saas_cur === 'staff_leave.php' ? 'active' : ''; ?>"><i class="bi bi-calendar-event"></i> Request leave</a>
        <a href="staff_resignation.php" class="<?php echo $saas_cur === 'staff_resignation.php' ? 'active' : ''; ?>"><i class="bi bi-door-open"></i> Resignation</a>

        <div class="saas-nav-section-label">Account</div>
        <a href="staff_password.php" class="<?php echo $saas_cur === 'staff_password.php' ? 'active' : ''; ?>"><i class="bi bi-key-fill"></i> Change password</a>

        <a href="logout.php" class="text-danger fw-bold"><i class="bi bi-box-arrow-left"></i> Logout</a>
    </div>
    <svg class="saas-sidebar-wave" viewBox="0 0 248 70" preserveAspectRatio="none" aria-hidden="true"><path d="M0 40 Q62 10 124 34 T248 26 V70 H0z" fill="#1d4fd8" fill-opacity=".35"/><path d="M0 52 Q70 30 140 50 T248 44 V70 H0z" fill="#2563eb" fill-opacity=".45"/></svg>
</div>
