<?php
$saas_cur = basename($_SERVER['PHP_SELF'] ?? '');
?>
<div class="col-md-2 sidebar" id="sidebar">
    <div class="saas-sidebar-brand">
        <i class="bi bi-person-badge"></i> Staff
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

        <a href="logout.php" class="mt-4 text-danger fw-bold"><i class="bi bi-box-arrow-left"></i> Logout</a>
    </div>
</div>
