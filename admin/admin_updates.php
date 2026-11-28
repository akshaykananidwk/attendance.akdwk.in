<?php
session_start();
require_once '../db.php';
require_once __DIR__ . '/../includes/updater/UpdateManager.php';
check_access(['admin']);

$um = new UpdateManager($conn);
$settings = $um->settings();
$version = app_version_info();
$csrf = generate_csrf_token();
$lastCheck = $settings['last_check_result'] ? json_decode((string) $settings['last_check_result'], true) : null;
$defaultProtected = implode(', ', FileDeployer::DEFAULT_PROTECTED);
function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Update | <?php echo htmlspecialchars(app_brand()); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/saas_theme.css?v=<?php echo app_asset_version(); ?>">
    <style>
        .upd-log { background:#0f172a; color:#e2e8f0; font:12px/1.5 ui-monospace,Menlo,Consolas,monospace; border-radius:8px; padding:12px; height:320px; overflow:auto; white-space:pre-wrap; }
        .file-list { max-height:260px; overflow:auto; font:12px ui-monospace,Menlo,Consolas,monospace; }
        .badge-st-success{background:#16a34a}.badge-st-rolled_back{background:#d97706}.badge-st-failed,.badge-st-rollback_failed{background:#dc2626}.badge-st-running{background:#2563eb}
        .commit-msg { white-space:pre-wrap; }
    </style>
</head>
<body>
<div class="saas-sidebar-backdrop" id="saasSidebarBackdrop" aria-hidden="true"></div>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php require_once __DIR__ . '/../includes/saas_sidebar_admin.php'; ?>
        <div class="col-md-10 main-content p-3 p-md-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <div>
                    <h3 class="mb-0"><i class="bi bi-cloud-arrow-down-fill text-primary"></i> System Update</h3>
                    <div class="text-muted small">Automatic updates from GitHub, with backup, migration, health check and automatic rollback.</div>
                </div>
                <div class="text-end">
                    <div class="small text-muted">Installed version</div>
                    <div class="fw-bold fs-5">v<?php echo h($version['version']); ?>
                        <span class="badge bg-secondary"><?php echo h($version['commit'] ? substr($version['commit'], 0, 7) : 'unknown commit'); ?></span></div>
                    <?php if ($version['updated_at']): ?><div class="small text-muted">updated <?php echo h(date('d M Y H:i', strtotime($version['updated_at']))); ?></div><?php endif; ?>
                </div>
            </div>

            <div id="alertBox"></div>
            <div id="interruptedBox" class="alert alert-warning d-none">
                <i class="bi bi-exclamation-triangle-fill"></i> A previous update was interrupted (timeout or server restart).
                <button class="btn btn-sm btn-warning ms-2" id="btnRecover">Roll back interrupted update</button>
            </div>

            <div class="row g-3">
                <!-- Settings -->
                <div class="col-lg-5">
                    <div class="card shadow-sm h-100">
                        <div class="card-header fw-semibold"><i class="bi bi-github"></i> GitHub connection</div>
                        <div class="card-body">
                            <form id="settingsForm" autocomplete="off">
                                <input type="hidden" name="action" value="save_settings">
                                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Repository (owner/name or URL)</label>
                                    <input class="form-control" name="repo" required placeholder="akshaykananidwk/attendance.akdwk.in"
                                           value="<?php echo h($settings['repo_owner'] ? $settings['repo_owner'] . '/' . $settings['repo_name'] : ''); ?>">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Branch</label>
                                    <input class="form-control" name="branch" required value="<?php echo h($settings['branch'] ?: 'main'); ?>">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Personal access token</label>
                                    <input class="form-control" type="password" name="token" autocomplete="new-password"
                                           placeholder="<?php echo $settings['has_token'] ? 'Saved (' . h($settings['token_hint']) . ') — leave empty to keep' : 'github_pat_… (read-only Contents permission)'; ?>">
                                    <div class="form-text">Stored encrypted (storage/app.key). A fine-grained token with only <b>Contents: Read-only</b> on this repository is enough. Leave empty for a public repository.</div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Site URL (for health check)</label>
                                    <input class="form-control" name="app_url" value="<?php echo h($settings['app_url']); ?>">
                                </div>
                                <details class="mb-2">
                                    <summary class="small fw-semibold">Advanced</summary>
                                    <label class="form-label small mt-2">Extra protected paths (one per line, folders end with /)</label>
                                    <textarea class="form-control font-monospace small" rows="3" name="protected_paths" placeholder="e.g. api/v1/config.local.php"><?php echo h($settings['protected_paths'] ?? ''); ?></textarea>
                                    <div class="form-text">Always protected: <code><?php echo h($defaultProtected); ?></code></div>
                                    <div class="row g-2 mt-1">
                                        <div class="col-6">
                                            <label class="form-label small">Backups to keep</label>
                                            <input class="form-control" type="number" min="1" max="50" name="keep_backups" value="<?php echo (int) ($settings['keep_backups'] ?? 5); ?>">
                                        </div>
                                        <div class="col-6 d-flex align-items-end">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="strict_health" value="1" id="strictHealth" <?php echo !isset($settings['strict_health']) || (int) $settings['strict_health'] === 1 ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="strictHealth">Strict HTTP health check</label>
                                            </div>
                                        </div>
                                    </div>
                                </details>
                                <button class="btn btn-dark w-100" type="submit" id="btnSave"><i class="bi bi-save"></i> Save &amp; test connection</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Check / Update -->
                <div class="col-lg-7">
                    <div class="card shadow-sm h-100">
                        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-arrow-repeat"></i> Updates</span>
                            <span class="small text-muted" id="lastChecked"><?php echo $settings['last_check_at'] ? 'Last checked ' . h($settings['last_check_at']) : ''; ?></span>
                        </div>
                        <div class="card-body">
                            <div class="d-flex gap-2 mb-3">
                                <button class="btn btn-outline-primary" id="btnCheck" <?php echo $settings['repo_owner'] ? '' : 'disabled'; ?>><i class="bi bi-search"></i> Check for update</button>
                                <button class="btn btn-success d-none" id="btnUpdate"><i class="bi bi-cloud-arrow-down"></i> Update now</button>
                            </div>
                            <div id="checkResult" class="small text-muted"><?php echo $settings['repo_owner'] ? 'Click “Check for update”.' : 'Save the GitHub connection first.'; ?></div>

                            <div id="progressWrap" class="mt-3 d-none">
                                <div class="d-flex justify-content-between small mb-1"><span id="progStep">Starting…</span><span id="progPct">0%</span></div>
                                <div class="progress mb-2" style="height:10px"><div class="progress-bar progress-bar-striped progress-bar-animated" id="progBar" style="width:0%"></div></div>
                                <div class="upd-log" id="liveLog"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- History -->
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-header fw-semibold"><i class="bi bi-clock-history"></i> Update history &amp; logs</div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0 align-middle small">
                                    <thead class="table-light"><tr>
                                        <th>#</th><th>Date</th><th>Action</th><th>Status</th><th>From → To</th><th>Commit</th><th>Files</th><th>Migrations</th><th>By</th><th>Time</th><th></th>
                                    </tr></thead>
                                    <tbody id="historyBody"><tr><td colspan="11" class="text-center text-muted p-3">Loading…</td></tr></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="logModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h6 class="modal-title">Update log</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="upd-log" style="height:auto;min-height:300px" id="logModalBody"></div></div>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const API = 'admin_updates_api.php';
const CSRF = <?php echo json_encode($csrf); ?>;
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let polling = null;

function alertMsg(type, html) {
    $('alertBox').innerHTML = `<div class="alert alert-${type} alert-dismissible fade show">${html}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
}
async function post(data) {
    const fd = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData)) { for (const k in data) fd.append(k, data[k]); }
    if (!fd.has('csrf_token')) fd.append('csrf_token', CSRF);
    const r = await fetch(API, {method: 'POST', body: fd, credentials: 'same-origin'});
    let j; try { j = await r.json(); } catch (e) { j = {ok: false, error: 'Server returned an invalid response (HTTP ' + r.status + ')'}; }
    return j;
}
async function get(params) {
    const r = await fetch(API + '?' + new URLSearchParams(params), {credentials: 'same-origin', cache: 'no-store'});
    return r.json();
}
function fmtDate(d) { if (!d) return ''; const x = new Date(d); return isNaN(x) ? d : x.toLocaleString(); }

$('settingsForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const b = $('btnSave'); b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing…';
    const j = await post(new FormData(e.target));
    b.disabled = false; b.innerHTML = '<i class="bi bi-save"></i> Save &amp; test connection';
    if (j.ok) { alertMsg('success', esc(j.message)); $('btnCheck').disabled = false; e.target.token.value = '';
        e.target.token.placeholder = 'Saved (' + (j.settings.token_hint || 'none') + ') — leave empty to keep'; }
    else alertMsg('danger', '<b>Could not save:</b> ' + esc(j.error));
});

function renderCheck(r) {
    const cur = r.current, lat = r.latest;
    let h = `<table class="table table-sm mb-2"><tr><th class="w-25">Installed</th><td>v${esc(cur.version)} <code>${esc((cur.commit || 'unknown').slice(0, 7))}</code></td></tr>
      <tr><th>Latest on <code>${esc(r.branch)}</code></th><td>v${esc(lat.version)} <code>${esc(lat.short)}</code> — ${esc(lat.author)}, ${esc(fmtDate(lat.date))}</td></tr>
      <tr><th>Commit message</th><td class="commit-msg">${esc(lat.message)}</td></tr></table>`;
    if (!r.update_available) {
        h = `<div class="alert alert-success py-2"><i class="bi bi-check-circle-fill"></i> You are on the latest version.</div>` + h;
        $('btnUpdate').classList.add('d-none');
    } else {
        h = `<div class="alert alert-info py-2"><i class="bi bi-stars"></i> <b>Update available</b> — ${r.commits.length} commit(s), ${r.files.length} file(s) changed${r.new_migrations.length ? ', ' + r.new_migrations.length + ' new DB migration(s)' : ''}.</div>` + h;
        $('btnUpdate').classList.remove('d-none');
    }
    if (r.compare_error) h += `<div class="alert alert-warning py-1 small">${esc(r.compare_error)}</div>`;
    if (r.commits.length) {
        h += '<div class="fw-semibold mt-2">Commits</div><ul class="list-group list-group-flush file-list mb-2">' + r.commits.map(c =>
            `<li class="list-group-item px-0 py-1"><code>${esc(c.short)}</code> ${esc(c.title)} <span class="text-muted">— ${esc(c.author)}, ${esc(fmtDate(c.date))}</span></li>`).join('') + '</ul>';
    }
    if (r.files.length) {
        const icon = {added: 'text-success bi-plus-circle', removed: 'text-danger bi-dash-circle', modified: 'text-primary bi-pencil', renamed: 'text-warning bi-arrow-left-right'};
        h += '<div class="fw-semibold">Changed files</div><div class="file-list border rounded p-2">' + r.files.map(f =>
            `<div><i class="bi ${icon[f.status] || 'bi-dot'}"></i> ${esc(f.filename)} <span class="text-success">+${f.additions}</span> <span class="text-danger">-${f.deletions}</span></div>`).join('') + '</div>';
    }
    if (r.new_migrations.length) h += '<div class="small mt-2"><b>New migrations:</b> ' + r.new_migrations.map(esc).join(', ') + '</div>';
    $('checkResult').innerHTML = h;
    $('lastChecked').textContent = 'Last checked ' + r.checked_at;
}

$('btnCheck').addEventListener('click', async () => {
    const b = $('btnCheck'); b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Checking…';
    const j = await post({action: 'check'});
    b.disabled = false; b.innerHTML = '<i class="bi bi-search"></i> Check for update';
    if (j.ok) renderCheck(j.result); else alertMsg('danger', '<b>Check failed:</b> ' + esc(j.error));
});

function showProgress(st) {
    $('progressWrap').classList.remove('d-none');
    const p = st.percent || 0;
    $('progBar').style.width = p + '%'; $('progPct').textContent = p + '%'; $('progStep').textContent = st.step || '';
    const bar = $('progBar');
    bar.classList.toggle('bg-success', st.result === 'success');
    bar.classList.toggle('bg-warning', st.result === 'rolled_back');
    bar.classList.toggle('bg-danger', st.result === 'failed' || st.result === 'rollback_failed');
    const log = $('liveLog'); log.textContent = (st.lines || []).join('\n'); log.scrollTop = log.scrollHeight;
}
async function pollStatus() {
    try { const st = await get({action: 'status'}); showProgress(st);
        if (!st.running && polling) { clearInterval(polling); polling = null; loadHistory(); }
    } catch (e) { /* server busy swapping files — keep polling */ }
}

$('btnUpdate').addEventListener('click', async () => {
    if (!confirm('Start the update now?\n\nA full files + database backup is taken first, and the site rolls back automatically if anything fails.')) return;
    $('btnUpdate').disabled = true; $('btnCheck').disabled = true;
    $('liveLog').textContent = ''; showProgress({percent: 1, step: 'Starting…'});
    polling = setInterval(pollStatus, 1000);
    const j = await post({action: 'update'});
    await pollStatus(); if (polling) { clearInterval(polling); polling = null; }
    $('btnUpdate').disabled = false; $('btnCheck').disabled = false;
    if (j.ok) { alertMsg('success', '<b>Update completed</b> — now on version ' + esc(j.version) + '. Reloading…'); setTimeout(() => location.reload(), 2500); }
    else if (j.status === 'rolled_back') alertMsg('warning', '<b>Update failed and was rolled back automatically.</b> Your site is on the previous working version.<br>' + esc(j.error));
    else alertMsg('danger', '<b>Update failed:</b> ' + esc(j.error));
    loadHistory();
});

$('btnRecover').addEventListener('click', async () => {
    if (!confirm('Restore files and database from the backup taken before the interrupted update?')) return;
    const j = await post({action: 'recover'});
    alertMsg(j.ok ? 'success' : 'danger', j.ok ? 'Interrupted update rolled back.' : esc(j.error || 'Recovery failed'));
    $('interruptedBox').classList.add('d-none'); loadHistory();
});

async function loadHistory() {
    const j = await get({action: 'history'});
    if (!j.ok) return;
    const st = await get({action: 'status'});
    $('interruptedBox').classList.toggle('d-none', !st.interrupted);
    if (st.running && !polling) { showProgress(st); polling = setInterval(pollStatus, 1000); }
    $('historyBody').innerHTML = j.history.length ? j.history.map(r => `<tr>
        <td>${r.id}</td><td class="text-nowrap">${esc(r.started_at)}</td><td>${esc(r.action)}</td>
        <td><span class="badge badge-st-${esc(r.status)}">${esc(r.status.replace('_', ' '))}</span></td>
        <td class="text-nowrap">${esc(r.from_version)} → ${esc(r.to_version)}</td>
        <td><code>${esc((r.to_commit || '').slice(0, 7))}</code> <span class="text-muted">${esc((r.commit_message || '').split('\n')[0].slice(0, 60))}</span>${r.error_message ? '<div class="text-danger">' + esc(r.error_message.slice(0, 160)) + '</div>' : ''}</td>
        <td>${r.files_changed}${r.files_deleted > 0 ? ' / −' + r.files_deleted : ''}</td>
        <td>${esc(r.migrations_run || '—')}</td><td>${esc(r.started_by_name)}</td><td>${r.duration_sec}s</td>
        <td class="text-nowrap"><button class="btn btn-sm btn-outline-secondary" onclick="showLog(${r.id})"><i class="bi bi-file-text"></i></button>
        ${r.backups_available && r.action === 'update' && r.status === 'success' ? `<button class="btn btn-sm btn-outline-warning" title="Restore the version from before this update" onclick="restoreBackup(${r.id})"><i class="bi bi-arrow-counterclockwise"></i></button>` : ''}</td></tr>`).join('')
        : '<tr><td colspan="11" class="text-center text-muted p-3">No updates yet.</td></tr>';
}
async function showLog(id) {
    const j = await get({action: 'log', id});
    $('logModalBody').textContent = j.log || '(empty)';
    new bootstrap.Modal($('logModal')).show();
}
async function restoreBackup(id) {
    if (!confirm('Restore files AND database to the state before update #' + id + '?\nData entered after that update will be lost. A safety backup of the current state is taken first.')) return;
    $('liveLog').textContent = ''; showProgress({percent: 5, step: 'Restoring…'});
    polling = setInterval(pollStatus, 1000);
    const j = await post({action: 'restore', id});
    alertMsg(j.ok ? 'success' : 'danger', j.ok ? 'Previous version restored. Reloading…' : '<b>Restore failed:</b> ' + esc(j.error));
    if (j.ok) setTimeout(() => location.reload(), 2500);
    loadHistory();
}
<?php if ($lastCheck): ?>renderCheck(<?php echo json_encode($lastCheck, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>);<?php endif; ?>
loadHistory();
</script>
<?php require_once __DIR__ . '/../includes/saas_sidebar_toggle.php'; ?>
</body>
</html>
