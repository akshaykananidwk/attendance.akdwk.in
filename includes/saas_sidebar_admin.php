<?php
require_once __DIR__ . '/ui.php';
$saas_cur = basename($_SERVER['PHP_SELF'] ?? '');
$saas_is_auditor = function_exists('is_auditor') && is_auditor();
$saas_role = (string) ($_SESSION['role'] ?? '');
$saas_name = (string) ($_SESSION['name'] ?? 'Admin');
$saas_cid = (int) ($_SESSION['company_id'] ?? 0);

$saas_link = static function (string $href, string $icon, string $label) use ($saas_cur): string {
    return '<a href="' . $href . '" class="' . ($saas_cur === $href ? 'active' : '') . '"><i class="bi ' . $icon . '"></i> ' . $label . '</a>';
};

if ($saas_is_auditor) {
    $saas_menu = [
        'Reports & data' => [
            ['admin_reports.php', 'bi-bar-chart-line-fill', 'Salary Reports'],
            ['admin_salary_slip.php', 'bi-file-earmark-text-fill', 'Salary Slips'],
            ['admin_clients.php', 'bi-person-lines-fill', 'Client History'],
            ['admin_exports.php', 'bi-file-earmark-spreadsheet', 'Data Reports'],
            ['admin_live_tracking.php', 'bi-geo-alt-fill', 'Live Tracking'],
            ['admin_audit_log.php', 'bi-journal-text', 'Audit Log'],
        ],
    ];
} else {
    $saas_menu = [
        'Overview' => [
            ['admin_dashboard.php', 'bi-house-door-fill', 'Dashboard'],
            ['admin_live_tracking.php', 'bi-geo-alt-fill', 'Live Tracking'],
        ],
        'Organization' => [
            ['admin_branches.php', 'bi-building', 'Branches'],
            ['admin_staff.php', 'bi-people-fill', 'Staff'],
            ['admin_employee_activity.php', 'bi-person-badge', 'Staff Activity'],
            ['admin_clients.php', 'bi-person-vcard', 'Client History'],
        ],
        'Operations' => [
            ['admin_tasks.php', 'bi-list-check', 'Tasks'],
            ['admin_announcements.php', 'bi-megaphone', 'Announcements'],
            ['admin_performance.php', 'bi-star-fill', 'Performance'],
        ],
        'Time & Payroll' => [
            ['admin_leaves.php', 'bi-calendar2-check', 'Leave Approvals'],
            ['admin_manual_time.php', 'bi-clock-history', 'Manual Time'],
            ['admin_advance.php', 'bi-cash-stack', 'Upad &amp; Jama'],
            ['admin_penalties.php', 'bi-exclamation-octagon', 'Penalties'],
            ['admin_resignations.php', 'bi-door-open', 'Resignations'],
        ],
        'Salary & Reports' => [
            ['admin_reports.php', 'bi-bar-chart-line-fill', 'Salary Reports'],
            ['admin_salary_slip.php', 'bi-file-earmark-text-fill', 'Salary Slips'],
            ['admin_payments.php', 'bi-currency-rupee', 'Salary Payments'],
            ['admin_payroll_rules.php', 'bi-calculator', 'Payroll Rules &amp; Check'],
        ],
        'Data & System' => [
            ['admin_exports.php', 'bi-file-earmark-spreadsheet', 'Data Reports'],
            ['admin_audit_log.php', 'bi-journal-text', 'Audit Log'],
            ['admin_settings.php', 'bi-gear-fill', 'Settings'],
            ['admin_backup.php', 'bi-cloud-download-fill', 'Backup &amp; Restore'],
            ['admin_updates.php', 'bi-cloud-arrow-down-fill', 'System Update'],
        ],
    ];
}

// Notifications (pending leave requests + open tasks) — cheap counts, ignore errors
$saas_notes = [];
if (isset($conn) && $conn instanceof mysqli && !$saas_is_auditor) {
    $r = @$conn->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending' AND company_id = $saas_cid");
    $n = $r ? (int) $r->fetch_row()[0] : 0;
    if ($n > 0) {
        $saas_notes[] = ['admin_leaves.php', 'bi-calendar2-check text-warning', "$n leave request(s) waiting for approval"];
    }
    $r = @$conn->query("SELECT COUNT(*) FROM resignation_requests WHERE status = 'pending' AND company_id = $saas_cid");
    $n = $r ? (int) $r->fetch_row()[0] : 0;
    if ($n > 0) {
        $saas_notes[] = ['admin_resignations.php', 'bi-door-open text-danger', "$n resignation request(s) pending"];
    }
    $r = @$conn->query("SELECT COUNT(*) FROM tasks WHERE status <> 'Completed' AND company_id = $saas_cid");
    $n = $r ? (int) $r->fetch_row()[0] : 0;
    if ($n > 0) {
        $saas_notes[] = ['admin_tasks.php', 'bi-list-check text-primary', "$n task(s) not completed"];
    }
}

// Search index: menu pages + staff + branches
$saas_index = [];
foreach ($saas_menu as $items) {
    foreach ($items as [$h, $i, $l]) {
        $saas_index[] = ['t' => html_entity_decode($l), 'u' => $h, 'i' => $i, 'k' => 'Page'];
    }
}
if (isset($conn) && $conn instanceof mysqli) {
    $r = @$conn->query("SELECT name, phone_number FROM users WHERE role = 'staff' AND company_id = $saas_cid ORDER BY name LIMIT 300");
    while ($r && ($row = $r->fetch_assoc())) {
        $saas_index[] = ['t' => $row['name'] . ' · ' . $row['phone_number'], 'u' => 'admin_staff.php?filter=all&q=' . rawurlencode($row['name']), 'i' => 'bi-person', 'k' => 'Staff'];
    }
    $r = @$conn->query("SELECT name FROM branches WHERE company_id = $saas_cid LIMIT 100");
    while ($r && ($row = $r->fetch_assoc())) {
        $saas_index[] = ['t' => $row['name'], 'u' => 'admin_branches.php?q=' . rawurlencode($row['name']), 'i' => 'bi-building', 'k' => 'Branch'];
    }
}
$saas_role_label = ['admin' => 'Super Admin', 'manager' => 'Manager', 'hr' => 'HR', 'auditor' => 'Auditor'][$saas_role] ?? ucfirst($saas_role);
?>
<div class="col-md-2 sidebar" id="sidebar">
    <div class="saas-sidebar-brand">
        <svg class="saas-brand-logo" viewBox="0 0 40 40" aria-hidden="true"><defs><linearGradient id="sbg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#60a5fa"/><stop offset="1" stop-color="#1d6cf2"/></linearGradient></defs><path d="M20 3 36 11.5 20 20 4 11.5z" fill="url(#sbg)"/><path d="M4 19.5 20 28l16-8.5" fill="none" stroke="#93c5fd" stroke-width="3" stroke-linejoin="round"/><path d="M4 27 20 35.5 36 27" fill="none" stroke="#3b82f6" stroke-width="3" stroke-linejoin="round"/></svg>
        <div><div class="saas-brand-name"><?php echo ui_e(app_brand()); ?></div><div class="saas-brand-sub">HR • Operations • Payroll<?php echo $saas_is_auditor ? ' · Auditor' : ''; ?></div></div>
    </div>
    <div class="saas-sidebar-scroll">
        <?php foreach ($saas_menu as $section => $items): ?>
            <div class="saas-nav-section-label"><?php echo ui_e($section); ?></div>
            <?php foreach ($items as [$h, $i, $l]) {
                echo $saas_link($h, $i, $l);
            } ?>
        <?php endforeach; ?>
        <div class="saas-nav-section-label">Account</div>
        <?php echo $saas_link('admin_password.php', 'bi-key-fill', 'Change Password'); ?>
        <a href="../logout.php" class="text-danger fw-bold"><i class="bi bi-box-arrow-left"></i> Logout</a>
    </div>
    <svg class="saas-sidebar-wave" viewBox="0 0 248 70" preserveAspectRatio="none" aria-hidden="true"><path d="M0 40 Q62 10 124 34 T248 26 V70 H0z" fill="#1d4fd8" fill-opacity=".35"/><path d="M0 52 Q70 30 140 50 T248 44 V70 H0z" fill="#2563eb" fill-opacity=".45"/></svg>
</div>

<template id="uiTopbarTpl">
    <div class="ui-topbar d-print-none">
        <button class="ui-icon-btn ui-menu-toggle" type="button" onclick="toggleSidebar()" aria-label="Menu"><i class="bi bi-list"></i></button>
        <div class="ui-search">
            <i class="bi bi-search"></i>
            <input type="search" id="uiSearch" placeholder="Search staff, pages, branches…" autocomplete="off" aria-label="Search">
            <kbd>Ctrl + K</kbd>
            <div class="ui-search-results" id="uiSearchResults"></div>
        </div>
        <div class="ui-top-actions">
            <div class="dropdown">
                <button class="ui-icon-btn" data-bs-toggle="dropdown" aria-label="Notifications" type="button"><i class="bi bi-bell"></i><?php if ($saas_notes): ?><span class="ui-dot"></span><?php endif; ?></button>
                <div class="dropdown-menu dropdown-menu-end shadow p-0" style="min-width:300px">
                    <div class="px-3 py-2 fw-bold border-bottom">Notifications</div>
                    <?php if (!$saas_notes): ?><div class="px-3 py-3 text-muted small">You're all caught up 🎉</div><?php endif; ?>
                    <?php foreach ($saas_notes as [$h, $i, $t]): ?>
                        <a class="dropdown-item py-2 small" href="<?php echo ui_e($h); ?>"><i class="bi <?php echo ui_e($i); ?> me-2"></i><?php echo ui_e($t); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <a class="ui-icon-btn ui-hide-sm" href="admin_payroll_rules.php" title="Help: how salary is calculated"><i class="bi bi-question-circle"></i></a>
            <button class="ui-icon-btn" type="button" id="uiThemeBtn" title="Light / dark mode"><i class="bi bi-sun"></i></button>
            <div class="dropdown">
                <a class="ui-profile" data-bs-toggle="dropdown" href="#" role="button">
                    <?php echo ui_avatar($saas_name); ?>
                    <span class="ui-profile-text"><div class="ui-profile-name"><?php echo ui_e($saas_name); ?></div><div class="ui-profile-role"><?php echo ui_e($saas_role_label); ?></div></span>
                    <i class="bi bi-chevron-down small ui-hide-sm"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li><a class="dropdown-item" href="admin_password.php"><i class="bi bi-key me-2"></i>Change password</a></li>
                    <?php if (!$saas_is_auditor): ?><li><a class="dropdown-item" href="admin_settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li><?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-left me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</template>
<script>
(function () {
    var THEME_KEY = 'akdwk_theme';
    function applyTheme(t) {
        document.documentElement.setAttribute('data-bs-theme', t);
        var b = document.getElementById('uiThemeBtn');
        if (b) b.innerHTML = t === 'dark' ? '<i class="bi bi-moon-stars"></i>' : '<i class="bi bi-sun"></i>';
    }
    var saved = 'light';
    try { saved = localStorage.getItem(THEME_KEY) || 'light'; } catch (e) {}
    applyTheme(saved);

    var index = <?php echo json_encode($saas_index, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    function mount() {
        var main = document.querySelector('.main-content');
        var tpl = document.getElementById('uiTopbarTpl');
        if (!main || !tpl || document.querySelector('.ui-topbar')) return;
        main.insertBefore(tpl.content.cloneNode(true), main.firstChild);
        // old in-page mobile buttons are replaced by the topbar menu button
        main.querySelectorAll('.dashboard-header .mobile-toggle').forEach(function (b) { b.remove(); });
        applyTheme(document.documentElement.getAttribute('data-bs-theme') || 'light');
        document.getElementById('uiThemeBtn').addEventListener('click', function () {
            var t = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            try { localStorage.setItem(THEME_KEY, t); } catch (e) {}
            applyTheme(t);
        });
        var input = document.getElementById('uiSearch'), box = document.getElementById('uiSearchResults'), sel = 0, hits = [];
        function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
        function render() {
            var q = input.value.trim().toLowerCase();
            if (!q) { box.style.display = 'none'; return; }
            hits = index.filter(function (x) { return x.t.toLowerCase().indexOf(q) !== -1; }).slice(0, 12);
            sel = 0;
            box.innerHTML = hits.length ? hits.map(function (x, i) {
                return '<a href="' + esc(x.u) + '" class="' + (i === 0 ? 'sel' : '') + '"><i class="bi ' + esc(x.i) + '"></i><span class="flex-grow-1">' + esc(x.t) + '</span><small class="text-muted">' + esc(x.k) + '</small></a>';
            }).join('') : '<div class="px-3 py-2 small text-muted">No results</div>';
            box.style.display = 'block';
        }
        input.addEventListener('input', render);
        input.addEventListener('keydown', function (e) {
            var links = box.querySelectorAll('a');
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!links.length) return;
                links[sel].classList.remove('sel');
                sel = (sel + (e.key === 'ArrowDown' ? 1 : links.length - 1)) % links.length;
                links[sel].classList.add('sel');
            } else if (e.key === 'Enter' && links[sel]) { location.href = links[sel].getAttribute('href'); }
            else if (e.key === 'Escape') { box.style.display = 'none'; input.blur(); }
        });
        document.addEventListener('click', function (e) { if (!e.target.closest('.ui-search')) box.style.display = 'none'; });
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); input.focus(); input.select(); }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
</script>
