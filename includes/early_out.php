<?php
/**
 * Shift end time + early punch-out approval.
 */

function early_out_ready(mysqli $conn): bool
{
    static $ok = null;

    return $ok ??= (function_exists('db_column_exists') && db_column_exists($conn, 'users', 'shift_end_time') && salary_table_exists($conn, 'early_out_requests'));
}

/** Shift end as a timestamp for a work date (overnight shifts end the next day). Null when no end time is set. */
function shift_end_ts(array $u, string $date): ?int
{
    $end = (string) ($u['shift_end_time'] ?? '');
    if ($end === '' || $end === '00:00:00') {
        return null;
    }
    $start = (string) ($u['shift_start_time'] ?? '00:00:00');
    if ($end === $start) {
        return null; // same start and end = no limit
    }
    $ts = strtotime("$date $end");
    if ($end < $start) {
        $ts += 86400;
    }

    return $ts;
}

function shift_label(array $u): string
{
    $s = !empty($u['shift_start_time']) ? date('h:i A', strtotime((string) $u['shift_start_time'])) : '—';
    $e = !empty($u['shift_end_time']) ? date('h:i A', strtotime((string) $u['shift_end_time'])) : '';

    return $e !== '' ? "$s – $e" : "from $s";
}

/** Worked minutes for a day closed at $outTime (same rule as the punch-out button). */
function attendance_minutes_with_out(array $record, string $outTime): int
{
    if (!empty($record['lunch_out']) && !empty($record['lunch_in'])) {
        return attendance_worked_minutes_from_row(array_merge($record, ['punch_out' => $outTime]));
    }
    $in = strtotime((string) ($record['punch_in'] ?? ''));
    if (!$in) {
        return 0;
    }
    $out = strtotime($outTime);
    if ($out < $in) {
        $out += 86400;
    }

    return max(0, (int) round(($out - $in) / 60));
}

function early_out_pending(mysqli $conn, int $userId, string $date): ?array
{
    if (!early_out_ready($conn)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM early_out_requests WHERE user_id = ? AND log_date = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('is', $userId, $date);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $r;
}

function early_out_create(mysqli $conn, array $u, array $record, string $time, string $reason): int
{
    $uid = (int) $u['id'];
    $cid = (int) ($u['company_id'] ?? 0);
    $aid = (int) $record['id'];
    $date = (string) $record['log_date'];
    $end = $u['shift_end_time'] ?? null;
    $reason = mb_substr(trim($reason), 0, 250);
    $stmt = $conn->prepare('INSERT INTO early_out_requests (user_id, company_id, attendance_id, log_date, requested_time, shift_end_time, reason) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('iiissss', $uid, $cid, $aid, $date, $time, $end, $reason);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return $id;
}

/** Approve → punch-out is recorded at the requested time. Reject → staff keeps working (can punch out at shift end). */
function early_out_decide(mysqli $conn, int $requestId, int $companyId, bool $approve, int $adminId, string $note = ''): array
{
    $stmt = $conn->prepare("SELECT r.*, u.name, u.phone_number FROM early_out_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ? AND r.company_id = ? AND r.status = 'pending'");
    $stmt->bind_param('ii', $requestId, $companyId);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$req) {
        return ['ok' => false, 'msg' => 'Request not found or already decided.'];
    }
    $status = $approve ? 'approved' : 'rejected';
    $note = mb_substr(trim($note), 0, 250);
    if ($approve) {
        $stmt = $conn->prepare('SELECT * FROM attendance_logs WHERE id = ?');
        $stmt->bind_param('i', $req['attendance_id']);
        $stmt->execute();
        $rec = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($rec && empty($rec['punch_out'])) {
            $mins = attendance_minutes_with_out($rec, (string) $req['requested_time']);
            $stmt = $conn->prepare('UPDATE attendance_logs SET punch_out = ?, total_worked_minutes = ? WHERE id = ?');
            $stmt->bind_param('sii', $req['requested_time'], $mins, $rec['id']);
            $stmt->execute();
            $stmt->close();
            if (function_exists('attendance_mark_invalid_if_open_tasks')) {
                attendance_mark_invalid_if_open_tasks($conn, (int) $req['user_id'], (string) $req['log_date']);
            }
        }
    }
    $stmt = $conn->prepare('UPDATE early_out_requests SET status = ?, decided_by = ?, decided_at = NOW(), admin_note = ? WHERE id = ?');
    $stmt->bind_param('sisi', $status, $adminId, $note, $requestId);
    $stmt->execute();
    $stmt->close();
    $t = date('h:i A', strtotime((string) $req['requested_time']));
    write_audit_log($conn, 'attendance.early_out_' . $status, "staff={$req['name']} date={$req['log_date']} out=$t", 'early_out_requests', (string) $requestId);
    if (!empty($req['phone_number'])) {
        sendWhatsAppDirectMessage((string) $req['phone_number'], $approve
            ? "✅ Hello {$req['name']}, your early punch-out at $t on " . date('d M', strtotime((string) $req['log_date'])) . ' is approved.'
            : "❌ Hello {$req['name']}, your early punch-out request at $t was not approved. Please punch out at your shift end time." . ($note !== '' ? " Note: $note" : ''));
    }

    return ['ok' => true, 'msg' => $approve ? "Approved — {$req['name']} punched out at $t." : "Rejected — {$req['name']} must punch out at shift end."];
}
