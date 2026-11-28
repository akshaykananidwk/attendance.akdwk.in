<?php
$saas_cur = basename($_SERVER['PHP_SELF'] ?? '');
?>
<div class="col-md-2 sidebar" id="sidebar">
    <div class="saas-sidebar-brand">
        <svg class="saas-brand-logo" viewBox="0 0 40 40" aria-hidden="true"><defs><linearGradient id="sbg2" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#60a5fa"/><stop offset="1" stop-color="#1d6cf2"/></linearGradient></defs><path d="M20 3 36 11.5 20 20 4 11.5z" fill="url(#sbg2)"/><path d="M4 19.5 20 28l16-8.5" fill="none" stroke="#93c5fd" stroke-width="3" stroke-linejoin="round"/><path d="M4 27 20 35.5 36 27" fill="none" stroke="#3b82f6" stroke-width="3" stroke-linejoin="round"/></svg>
        <div><div class="saas-brand-name"><?php echo ui_e(app_brand()); ?></div><div class="saas-brand-sub">Staff portal</div></div>
    </div>
    <div class="saas-sidebar-scroll">
        <div class="saas-nav-section-label">Daily</div>
        <a href="staff_dashboard.php" class="<?php echo $saas_cur === 'staff_dashboard.php' ? 'active' : ''; ?>"><i class="bi bi-house-door-fill"></i> Dashboard</a>
        <a href="staff_tasks.php" class="<?php echo $saas_cur === 'staff_tasks.php' ? 'active' : ''; ?>"><i class="bi bi-list-check"></i> My Tasks</a>
        <a href="staff_leave.php" class="<?php echo $saas_cur === 'staff_leave.php' ? 'active' : ''; ?>"><i class="bi bi-calendar-event"></i> Request Leave</a>
        <a href="staff_tasks_history.php" class="<?php echo $saas_cur === 'staff_tasks_history.php' ? 'active' : ''; ?>"><i class="bi bi-clock-history"></i> Work History</a>
        <details class="saas-more" <?php echo $saas_cur === 'staff_resignation.php' ? 'open' : ''; ?>>
            <summary class="saas-nav-section-label">More <i class="bi bi-chevron-down"></i></summary>
            <a href="staff_resignation.php" class="<?php echo $saas_cur === 'staff_resignation.php' ? 'active' : ''; ?>"><i class="bi bi-door-open"></i> Resignation</a>
        </details>
        <a href="logout.php" class="text-danger fw-bold"><i class="bi bi-box-arrow-left"></i> Logout</a>
    </div>
    <svg class="saas-sidebar-wave" viewBox="0 0 248 70" preserveAspectRatio="none" aria-hidden="true"><path d="M0 40 Q62 10 124 34 T248 26 V70 H0z" fill="#1d4fd8" fill-opacity=".35"/><path d="M0 52 Q70 30 140 50 T248 44 V70 H0z" fill="#2563eb" fill-opacity=".45"/></svg>
</div>
<?php $saas_sname = (string) ($_SESSION['name'] ?? 'Staff'); ?>
<nav class="ui-bottom-nav d-print-none" aria-label="Quick navigation">
    <?php foreach ([['staff_dashboard.php', 'bi-house-door', 'Home'], ['staff_tasks.php', 'bi-list-check', 'Tasks'], ['staff_leave.php', 'bi-calendar-event', 'Leave'], ['staff_tasks_history.php', 'bi-clock-history', 'History']] as [$h, $i, $l]): ?>
        <a href="<?php echo $h; ?>" class="<?php echo $saas_cur === $h ? 'on' : ''; ?>"><i class="bi <?php echo $i; ?>"></i><span><?php echo $l; ?></span></a>
    <?php endforeach; ?>
    <a href="#" onclick="toggleSidebar();return false;"><i class="bi bi-grid"></i><span>More</span></a>
</nav>
<template id="uiTopbarTpl">
    <div class="ui-topbar d-print-none">
        <button class="ui-icon-btn ui-menu-toggle" type="button" onclick="toggleSidebar()" aria-label="Menu"><i class="bi bi-list"></i></button>
        <div class="fw-bold ui-hide-sm" style="color:var(--ui-muted)"><i class="bi bi-calendar3 me-1"></i><?php echo date('l, d M Y'); ?></div>
        <div class="ui-top-actions">
            <a class="ui-icon-btn" href="staff_tasks.php" title="My tasks"><i class="bi bi-list-check"></i></a>
            <button class="ui-icon-btn" type="button" id="uiThemeBtn" title="Light / dark mode"><i class="bi bi-sun"></i></button>
            <div class="dropdown">
                <a class="ui-profile" data-bs-toggle="dropdown" href="#" role="button">
                    <?php echo ui_avatar($saas_sname); ?>
                    <span class="ui-profile-text"><div class="ui-profile-name"><?php echo ui_e($saas_sname); ?></div><div class="ui-profile-role">Staff</div></span>
                    <i class="bi bi-chevron-down small ui-hide-sm"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li><a class="dropdown-item" href="staff_password.php"><i class="bi bi-key me-2"></i>Change password</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-left me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</template>
<script>
(function () {
    var KEY = 'akdwk_theme';
    function apply(t) { document.documentElement.setAttribute('data-bs-theme', t); var b = document.getElementById('uiThemeBtn'); if (b) b.innerHTML = t === 'dark' ? '<i class="bi bi-moon-stars"></i>' : '<i class="bi bi-sun"></i>'; }
    var saved = 'light'; try { saved = localStorage.getItem(KEY) || 'light'; } catch (e) {} apply(saved);
    function mount() {
        var main = document.querySelector('.main-content'), tpl = document.getElementById('uiTopbarTpl');
        if (!main || !tpl || document.querySelector('.ui-topbar')) return;
        main.insertBefore(tpl.content.cloneNode(true), main.firstChild);
        main.querySelectorAll('.dashboard-header .mobile-toggle').forEach(function (b) { b.remove(); });
        apply(document.documentElement.getAttribute('data-bs-theme') || 'light');
        document.getElementById('uiThemeBtn').addEventListener('click', function () { var t = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'; try { localStorage.setItem(KEY, t); } catch (e) {} apply(t); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
</script>
