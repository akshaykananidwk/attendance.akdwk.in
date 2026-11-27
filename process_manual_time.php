<?php
session_start();

// સિક્યોરિટી ચેક
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

require_once '../db.php';

// જો એડમિન મેન્યુઅલ એન્ટ્રી ફોર્મ સબમિટ કરે
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['manual_entry'])) {
    
    $stmt_check = $conn->prepare("SELECT id FROM attendance_logs WHERE user_id=? AND log_date=?");
    $stmt_check->bind_param("is", $user_id, $log_date);
    $stmt_check->execute();
    $check_query = $stmt_check->get_result();
    
    $total_minutes = 0;
    $lunch_sec = 0;

    // --- લંચની ગણતરી ---
    if ($final_in && $final_out) { // Time calculation only if both in/out are set
        if ($final_l_out && $final_l_in) {
            $l_out_time = strtotime($final_l_out);
            $l_in_time = strtotime($final_l_in);
            if ($l_in_time < $l_out_time) { $l_in_time += 86400; } 
            $lunch_sec = $l_in_time - $l_out_time;
        }

        $w_in_time = strtotime($final_in);
        $w_out_time = strtotime($final_out);
        if ($w_out_time < $w_in_time) { $w_out_time += 86400; }
        
        $work_sec = $w_out_time - $w_in_time;
        $total_minutes = round(($work_sec - $lunch_sec) / 60);
        if ($total_minutes < 0) $total_minutes = 0;
    }

    if ($check_query->num_rows > 0) {
        $existing = $check_query->fetch_assoc();
        $stmt_upd = $conn->prepare("UPDATE attendance_logs SET punch_in=?, lunch_out=?, lunch_in=?, punch_out=?, total_worked_minutes=? WHERE id=?");
        $stmt_upd->bind_param("ssssii", $final_in, $final_l_out, $final_l_in, $final_out, $total_minutes, $existing['id']);
        $stmt_upd->execute();
        $stmt_upd->close();
    } else {
        $stmt_ins = $conn->prepare("INSERT INTO attendance_logs (user_id, log_date, punch_in, lunch_out, lunch_in, punch_out, total_worked_minutes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt_ins->bind_param("isssssi", $user_id, $log_date, $final_in, $final_l_out, $final_l_in, $final_out, $total_minutes);
        $stmt_ins->execute();
        $stmt_ins->close();
    }
    $stmt_check->close();

    echo "<script>
            alert('સ્ટાફની હાજરી અને કામની મિનિટો 100% સચોટ રીતે અપડેટ થઈ ગઈ છે!');
            window.location.href = 'admin_manual_time.php';
          </script>";
    exit();

} else {
    header("Location: admin_dashboard.php");
    exit();
}
?>