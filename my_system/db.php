<?php
date_default_timezone_set('Asia/Bangkok');
$conn = mysqli_connect("localhost", "root", "524255", "company_inventory");
if (!$conn) { die("Connection failed: " . mysqli_connect_error()); }
mysqli_set_charset($conn, "utf8mb4");

// ฟังก์ชันบันทึก Log (วางไว้ใน db.php)
function log_event($conn, $action, $table, $details) {
    $u_id = $_SESSION['userid'] ?? 'SYSTEM';
    $u_name = $_SESSION['fullname'] ?? 'SYSTEM';
    $ip = $_SERVER['REMOTE_ADDR'];
    
    $details_esc = mysqli_real_escape_string($conn, $details);
    
    $sql = "INSERT INTO system_logs (userid, username, action_type, affected_table, details, ip_address) 
            VALUES ('$u_id', '$u_name', '$action', '$table', '$details_esc', '$ip')";
    mysqli_query($conn, $sql);
}
?>