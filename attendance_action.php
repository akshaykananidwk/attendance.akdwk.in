<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header('Location: login.php');
    exit();
}

staff_require_can_mutate();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $user_id = (int) $_SESSION['user_id'];
    $action = $_POST['action'];

    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        die('CSRF Token validation failed.');
    }

    date_default_timezone_set('Asia/Kolkata');

    $current_datetime = date('Y-m-d H:i:s');
    $punch_time = date('H:i:s');
    $today_date = date('Y-m-d');
    $ip_address = get_request_ip() ?: '0.0.0.0';
    $tenant = attendance_user_tenant($conn, $user_id);
    $company_id = $tenant['company_id'];
    $branch_id = $tenant['branch_id'];

    $stmt = $conn->prepare('SELECT * FROM attendance_logs WHERE user_id = ? AND log_date = ?');
    $stmt->bind_param('is', $user_id, $today_date);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $staff_name = $_SESSION['name'];
    $time_now = date('h:i A');

    if ($action === 'punch_in' && !$record) {
        $stmt = $conn->prepare(
            'INSERT INTO attendance_logs (user_id, company_id, branch_id, log_date, punch_in, ip_address, is_leave)
             VALUES (?, ?, ?, ?, ?, ?, 0)'
        );
        $stmt->bind_param('iiisss', $user_id, $company_id, $branch_id, $today_date, $punch_time, $ip_address);
        if (!$stmt->execute()) {
            die('Database Error (Punch In).');
        }
        $stmt->close();
        sendWhatsAppGroupNotification("*$staff_name* • Punch IN at $time_now 🟢");

        // Late Penalty Check
        $stmt_u = $conn->prepare("SELECT shift_start_time, phone_number, name FROM users WHERE id = ?");
        $stmt_u->bind_param("i", $user_id);
        $stmt_u->execute();
        $u_info = $stmt_u->get_result()->fetch_assoc();
        $stmt_u->close();
        if ($u_info) {
            $shift_time = $u_info['shift_start_time'] ?? '09:30:00';
            if (strtotime($punch_time) > strtotime($shift_time) + 3600) {
                $stmt_pchk = $conn->prepare("SELECT id FROM staff_penalties WHERE user_id=? AND penalty_date=? AND penalty_type='late_punch'");
                $stmt_pchk->bind_param("is", $user_id, $today_date);
                $stmt_pchk->execute();
                $penalized = $stmt_pchk->get_result()->num_rows > 0;
                $stmt_pchk->close();
                if (!$penalized) {
                    $ptype = 'late_punch';
                    $preason = 'Late Punch - Arrived after 1 hour of shift time (' . date('h:i A', strtotime($shift_time)) . ')';
                    $pdays = 0.5;
                    $stmt_ins = $conn->prepare("INSERT INTO staff_penalties (user_id, company_id, penalty_date, penalty_type, deduction_days, reason, created_by) VALUES (?, ?, ?, ?, ?, ?, 0)");
                    $stmt_ins->bind_param("iissss", $user_id, $company_id, $today_date, $ptype, $pdays, $preason);
                    if ($stmt_ins->execute() && !empty($u_info['phone_number'])) {
                        $msg = "⚠️ *Penalty Notice* ⚠️\n\nHello {$u_info['name']},\nતમે તમારા નિર્ધારિત સમય (" . date('h:i A', strtotime($shift_time)) . ") ના 1 કલાક પછી પંચિંગ કર્યું છે.\nતેથી તમને *Half Day* ની પેનલ્ટી લગાવવામાં આવી છે.\n\nજો કોઈ કારણ હોય તો એડમિનનો સંપર્ક કરો.";
                        sendWhatsAppDirectMessage($u_info['phone_number'], $msg);
                    }
                    $stmt_ins->close();
                }
            }
        }
    } elseif ($record && empty($record['punch_out'])) {
        if ($action === 'lunch_out' && empty($record['lunch_out'])) {
            $stmt = $conn->prepare('UPDATE attendance_logs SET lunch_out = ? WHERE id = ?');
            $stmt->bind_param('si', $punch_time, $record['id']);
            $stmt->execute();
            $stmt->close();
            sendWhatsAppGroupNotification("*$staff_name* • Lunch OUT at $time_now 🍽️");
        } elseif ($action === 'lunch_in' && !empty($record['lunch_out']) && empty($record['lunch_in'])) {
            $stmt = $conn->prepare('UPDATE attendance_logs SET lunch_in = ? WHERE id = ?');
            $stmt->bind_param('si', $punch_time, $record['id']);
            $stmt->execute();
            $stmt->close();
            sendWhatsAppGroupNotification("*$staff_name* • Lunch IN at $time_now ✅");
        } elseif ($action === 'punch_out') {
            $total_minutes = attendance_worked_minutes_from_row(array_merge($record, [
                'punch_out' => $punch_time,
            ]));
            if (empty($record['lunch_out']) || empty($record['lunch_in'])) {
                $punch_in = strtotime($record['punch_in'] ?? '');
                $punch_out = strtotime($punch_time);
                $total_minutes = $punch_in ? (int) round(($punch_out - $punch_in) / 60) : 0;
            }
            $stmt = $conn->prepare('UPDATE attendance_logs SET punch_out = ?, total_worked_minutes = ? WHERE id = ?');
            $stmt->bind_param('sii', $punch_time, $total_minutes, $record['id']);
            $stmt->execute();
            $stmt->close();
            attendance_mark_invalid_if_open_tasks($conn, $user_id, $today_date);
            sendWhatsAppGroupNotification("*$staff_name* • Punch OUT at $time_now 🔴");
        }
    }
}

header('Location: staff_dashboard.php');
exit;
