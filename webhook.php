<?php
// 1. સૌથી અગત્યનું: ઇન્ડિયાનો ટાઈમઝોન સેટ કરો (UTC એરર સોલ્વ કરવા)
date_default_timezone_set('Asia/Kolkata');

// ડેટાબેઝ કનેક્શન (db.php)
require_once 'db.php';

// લોગ લખવા માટેનું ફંક્શન (આમાં પણ હવે ઇન્ડિયાનો ટાઈમ આવશે)
function writeLog($message) {
    $time = date('Y-m-d H:i:s');
    file_put_contents(__DIR__ . '/storage/logs/webhook.log', "[$time] $message" . PHP_EOL, FILE_APPEND);
}

// Node.js માંથી આવતો ડેટા પકડો
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    die("No data received");
}

// સિસ્ટમ સેટિંગ્સ ડેટાબેઝમાંથી લાવો
$set_res = $conn->query("SELECT * FROM settings LIMIT 1");
$settings = $set_res->fetch_assoc();
$expected_group = $settings['group_id'];
$api_key = $settings['api_key'];
$session_id = $settings['session_id'];

$group_id = $data['group_id'] ?? '';
$sender = $data['sender'] ?? ''; // 15-digit LID (વર્ચ્યુઅલ નંબર)
$message_text = strtolower(trim($data['message'] ?? ''));

// 1. Group Validation
if ($group_id !== $expected_group) {
    writeLog("Group Mismatch: Expected $expected_group but got $group_id");
    exit();
}

writeLog("Group Match Success. Sender LID: $sender");

// 2. User/LID Matching (15-digit bypass logic)
$activeW = staff_sql_active_filter('');
$stmt = $conn->prepare("SELECT id, name FROM users WHERE phone_number = ? AND role='staff'{$activeW}");
$stmt->bind_param("s", $sender);
$stmt->execute();
$user_query = $stmt->get_result();

if ($user_query->num_rows === 0) {
    writeLog("Unknown User LID ignored: $sender");
    $stmt->close();
    exit();
}
$user = $user_query->fetch_assoc();
$user_id = $user['id'];
$name = $user['name'];
$stmt->close();

// સમય અને તારીખ હવે 100% IST (ભારત) ના જ આવશે
$today = date('Y-m-d');
$time_now = date('H:i:s');
$reply_msg = "";

// આજના દિવસનો રેકોર્ડ ચેક કરો
$stmt = $conn->prepare("SELECT * FROM attendance_logs WHERE user_id=? AND log_date=?");
$stmt->bind_param("is", $user_id, $today);
$stmt->execute();
$att_query = $stmt->get_result();
$att_row = $att_query->fetch_assoc();
$stmt->close();

// નવું અપડેટ: 'leave' અને 'raja' કમાન્ડ એડ કર્યા છે
$valid_commands = ['in', 'office in', 'lunch', 'lunch out', 'lunch in', 'out', 'office out', 'leave', 'raja'];

if (in_array($message_text, $valid_commands)) {
    
    // -- કમાન્ડ લોજિક --
    if ($message_text == 'in' || $message_text == 'office in') {
        if (!$att_row) {
            $stmt = $conn->prepare("INSERT INTO attendance_logs (user_id, log_date, punch_in) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $user_id, $today, $time_now);
            $stmt->execute();
            $stmt->close();
            $reply_msg = "$name, તમારો IN સમય ($time_now) નોંધાઈ ગયો છે. Have a great day!";
            
            // Late Penalty Check
            $stmt_u = $conn->prepare("SELECT shift_start_time, company_id FROM users WHERE id = ?");
            $stmt_u->bind_param("i", $user_id);
            $stmt_u->execute();
            $u_info = $stmt_u->get_result()->fetch_assoc();
            $stmt_u->close();
            if ($u_info) {
                $shift_time = $u_info['shift_start_time'] ?? '09:30:00';
                $company_id = $u_info['company_id'];
                if (strtotime($time_now) > strtotime($shift_time) + 3600) {
                    $stmt_pchk = $conn->prepare("SELECT id FROM staff_penalties WHERE user_id=? AND penalty_date=? AND penalty_type='late_punch'");
                    $stmt_pchk->bind_param("is", $user_id, $today);
                    $stmt_pchk->execute();
                    $penalized = $stmt_pchk->get_result()->num_rows > 0;
                    $stmt_pchk->close();
                    if (!$penalized) {
                        $ptype = 'late_punch';
                        $preason = 'Late Punch - Arrived after 1 hour of shift time (' . date('h:i A', strtotime($shift_time)) . ')';
                        $pdays = 0.5;
                        $stmt_ins = $conn->prepare("INSERT INTO staff_penalties (user_id, company_id, penalty_date, penalty_type, deduction_days, reason, created_by) VALUES (?, ?, ?, ?, ?, ?, 0)");
                        $stmt_ins->bind_param("iissss", $user_id, $company_id, $today, $ptype, $pdays, $preason);
                        if ($stmt_ins->execute()) {
                            $reply_msg .= "\n\n⚠️ *Penalty Notice* ⚠️\nતમે નિર્ધારિત સમય (" . date('h:i A', strtotime($shift_time)) . ") ના 1 કલાક પછી પંચિંગ કર્યું છે. તેથી તમને *Half Day* ની પેનલ્ટી લગાવવામાં આવી છે.";
                        }
                        $stmt_ins->close();
                    }
                }
            }
        } else {
            $reply_msg = "$name, તમારી આજની IN હાજરી પહેલેથી જ નોંધાયેલી છે.";
        }
    }
    elseif ($message_text == 'lunch' || $message_text == 'lunch out') {
        if ($att_row && empty($att_row['lunch_out'])) {
            $stmt = $conn->prepare("UPDATE attendance_logs SET lunch_out=? WHERE id=?");
            $stmt->bind_param("si", $time_now, $att_row['id']);
            $stmt->execute();
            $stmt->close();
            $reply_msg = "$name, તમારો Lunch Out સમય ($time_now) નોંધાઈ ગયો છે.";
        }
    }
    elseif ($message_text == 'lunch in') {
        if ($att_row && !empty($att_row['lunch_out']) && empty($att_row['lunch_in'])) {
            $stmt = $conn->prepare("UPDATE attendance_logs SET lunch_in=? WHERE id=?");
            $stmt->bind_param("si", $time_now, $att_row['id']);
            $stmt->execute();
            $stmt->close();
            $reply_msg = "$name, તમારો Lunch In સમય ($time_now) નોંધાઈ ગયો છે. Welcome back!";
        }
    }
    elseif ($message_text == 'out' || $message_text == 'office out') {
        if ($att_row && empty($att_row['punch_out'])) {
            $stmt = $conn->prepare("UPDATE attendance_logs SET punch_out=? WHERE id=?");
            $stmt->bind_param("si", $time_now, $att_row['id']);
            $stmt->execute();
            $stmt->close();
            $reply_msg = "$name, તમારો OUT સમય ($time_now) નોંધાઈ ગયો છે. Good Bye!";
        }
    }
    // નવું લોજિક: રજા (Leave) માટે
    elseif ($message_text == 'leave' || $message_text == 'raja') {
        if (!$att_row) {
            $stmt = $conn->prepare("INSERT INTO attendance_logs (user_id, log_date, is_leave) VALUES (?, ?, 1)");
            $stmt->bind_param("is", $user_id, $today);
            $stmt->execute();
            $stmt->close();
            $reply_msg = "$name, તમારી આજની રજા (Leave) મંજૂર કરવામાં આવી છે.";
        } else {
            $reply_msg = "$name, તમારી આજની એન્ટ્રી પહેલેથી જ સિસ્ટમમાં છે.";
        }
    }

    writeLog("Action: Marked '$message_text' for $name at $time_now");

    // ==========================================
    // 3. ડાયનેમિક હાજરી અને હાફ-ડે કેલ્ક્યુલેશન લોજિક
    // ==========================================
    $stmt_re = $conn->prepare('SELECT * FROM attendance_logs WHERE user_id = ? AND log_date = ? LIMIT 1');
    $stmt_re->bind_param('is', $user_id, $today);
    $stmt_re->execute();
    $att_query = $stmt_re->get_result();
    if ($att_query && $att_query->num_rows > 0) {
        $latest = $att_query->fetch_assoc();
        $p_in = $latest['punch_in'];
        $l_out = $latest['lunch_out'];
        $l_in = $latest['lunch_in'];
        $p_out = $latest['punch_out'];

        $total_minutes = 0;

        if (!empty($p_in)) {
            if (!empty($l_out) && empty($l_in) && empty($p_out)) {
                // શરત 1: સવારે આવ્યો, લંચમાં ગયો પણ પાછો નથી આવ્યો (Half Day)
                $total_minutes = round((strtotime($l_out) - strtotime($p_in)) / 60);
            
            } elseif (!empty($p_out)) {
                // શરત 2: Out મેસેજ કર્યો. (આખા દિવસનું કામ)
                $work_sec = strtotime($p_out) - strtotime($p_in);
                $lunch_sec = (!empty($l_in) && !empty($l_out)) ? (strtotime($l_in) - strtotime($l_out)) : 0;
                $total_minutes = round(($work_sec - $lunch_sec) / 60);
            }
        }
        
        // કેલ્ક્યુલેટ થયેલી મિનિટ્સ તરત જ ડેટાબેઝમાં સેવ કરી દો
        $upd_stmt = $conn->prepare("UPDATE attendance_logs SET total_worked_minutes=? WHERE id=?");
        $upd_stmt->bind_param("ii", $total_minutes, $latest['id']);
        $upd_stmt->execute();
        $upd_stmt->close();
    }
    if (isset($stmt_re)) {
        $stmt_re->close();
    }

   // ==========================================
    // 4. API Call - WhatsApp પર રિપ્લાય મોકલો (Gujarati Language Support)
    // ==========================================
    if ($reply_msg != "") {
        $encoded_msg = rawurlencode($reply_msg); // HTTP 500 error થી બચવા માટે
        
        // અહીં તમારી સાચી API URL અને સાચા પેરામીટર છે
        $api_url = "https://bulk.akdwk.in/api.php?number=$sender&message=$encoded_msg&session_id=$session_id&api_key=$api_key";
        
        writeLog("API Call URL Generated for $sender");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        
        writeLog("API Call Response: $response");
    }

} else {
    writeLog("Ignored invalid command from $name: $message_text");
}
?>