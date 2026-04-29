<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';

$response = [
    'badge_pr' => 0,
    'badge_po' => 0,
    'badge_po_receive' => 0,
    'badge_stock' => 0,
    'badge_expire' => 0,
    'badge_production' => 0,
    'badge_qa_inbound' => 0, 
    'badge_qa_outbound' => 0,
    'badge_maintenance' => 0,
    'badge_approve' => 0,
    'badge_payment' => 0, // หนี้ลูกค้า (ลูกหนี้/AR)
    'badge_ap' => 0,      // 🚀 ตัวใหม่: หนี้ซัพพลายเออร์ (เจ้าหนี้/AP)
    'total_admin' => 0
];

if (!empty($_SESSION['userid'])) {
    
    if ($user_role == 'ADMIN' || $user_role == 'MANAGER') {
        $q = mysqli_query($conn, "SELECT COUNT(*) as c FROM purchase_requests WHERE status = 'Pending'");
        if($q) $response['badge_pr'] += mysqli_fetch_assoc($q)['c'];
        
        $q = mysqli_query($conn, "SELECT COUNT(*) as c FROM purchase_orders WHERE status = 'Pending'");
        if($q) $response['badge_po'] += mysqli_fetch_assoc($q)['c'];
    }
    if ($user_dept == 'ฝ่ายจัดซื้อ') {
        $q = mysqli_query($conn, "SELECT COUNT(*) as c FROM purchase_orders WHERE status = 'Pending'");
        if($q) $response['badge_po'] += mysqli_fetch_assoc($q)['c'];
    }

    if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || strpos($user_dept, 'คลังสินค้า') !== false || $user_dept == 'ฝ่ายจัดซื้อ' || strpos($user_dept, 'ผลิต') !== false || $user_dept == 'ฝ่ายงานวางแผน') {
        $q = mysqli_query($conn, "SELECT COUNT(*) as c FROM products WHERE p_qty <= p_min");
        if($q) $response['badge_stock'] += mysqli_fetch_assoc($q)['c'];
    }
    if ($user_role == 'ADMIN' || strpos($user_dept, 'คลังสินค้า') !== false) {
        $q = mysqli_query($conn, "SELECT COUNT(*) as c FROM purchase_orders WHERE status = 'Manager_Approved'");
        if($q) $response['badge_po_receive'] += mysqli_fetch_assoc($q)['c'];
    }
    if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || strpos($user_dept, 'คลังสินค้า') !== false || $user_dept == 'ฝ่ายขาย') {
        $check_lot_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'inventory_lots'");
        if (mysqli_num_rows($check_lot_tbl) > 0) {
            $q_exp = mysqli_query($conn, "SELECT COUNT(*) as c FROM inventory_lots WHERE DATEDIFF(CURDATE(), mfg_date) >= 90 AND qty > 0 AND status = 'Active'");
            if($q_exp) $response['badge_expire'] += mysqli_fetch_assoc($q_exp)['c'];
        }
    }

    if ($user_role == 'ADMIN' || strpos($user_dept, 'ผลิต') !== false) {
        $dept_filter = ($user_role === 'ADMIN') ? "" : "AND production_line = '$user_dept'";
        $q = mysqli_query($conn, "SELECT COUNT(*) as c FROM production_orders WHERE status = 'Pending' $dept_filter");
        if($q) $response['badge_production'] += mysqli_fetch_assoc($q)['c'];
    }

    if ($user_role == 'ADMIN' || strpos($user_dept, 'QA') !== false || strpos($user_dept, 'QC') !== false || strpos($user_dept, 'วิชาการ') !== false) {
        $q_in = mysqli_query($conn, "SELECT COUNT(DISTINCT lot_no) as c FROM inventory_lots WHERE status = 'Pending_QA' AND qty > 0 AND lot_no LIKE 'REC-%'");
        if($q_in) $response['badge_qa_inbound'] += mysqli_fetch_assoc($q_in)['c'];
        
        $q_out = mysqli_query($conn, "SELECT COUNT(DISTINCT lot_no) as c FROM inventory_lots WHERE status = 'Pending_QA' AND qty > 0 AND lot_no NOT LIKE 'REC-%'");
        if($q_out) $response['badge_qa_outbound'] += mysqli_fetch_assoc($q_out)['c'];
    }

    if ($user_role == 'ADMIN' || strpos($user_dept, 'ซ่อมบำรุง') !== false || strpos($user_dept, 'ไฟฟ้า') !== false || strpos($user_dept, 'P&M') !== false || $user_role == 'MANAGER') {
        $q = mysqli_query($conn, "SELECT COUNT(*) as c FROM maintenance_requests WHERE status = 'Pending'");
        if($q) $response['badge_maintenance'] += mysqli_fetch_assoc($q)['c'];
    }

    $badge_ot = 0; $badge_leave = 0;
    if ($user_role == 'MANAGER') {
        $q_ot = mysqli_query($conn, "SELECT COUNT(*) as c FROM ot_records o JOIN employees e ON o.userid = e.userid WHERE o.status = 'Pending' AND e.dept = '$user_dept'");
        if($q_ot) $badge_ot += mysqli_fetch_assoc($q_ot)['c'];
        $q_lv = mysqli_query($conn, "SELECT COUNT(*) as c FROM leave_records l JOIN employees e ON l.userid = e.userid WHERE l.status = 'Pending' AND e.dept = '$user_dept'");
        if($q_lv) $badge_leave += mysqli_fetch_assoc($q_lv)['c'];
    }
    if ($user_dept == 'ฝ่าย HR') {
        $q_ot = mysqli_query($conn, "SELECT COUNT(*) as c FROM ot_records WHERE status = 'Manager_Approved'");
        if($q_ot) $badge_ot += mysqli_fetch_assoc($q_ot)['c'];
        $q_lv = mysqli_query($conn, "SELECT COUNT(*) as c FROM leave_records WHERE status = 'Manager_Approved'");
        if($q_lv) $badge_leave += mysqli_fetch_assoc($q_lv)['c'];
    }
    if ($user_role == 'ADMIN') {
        $q_ot = mysqli_query($conn, "SELECT COUNT(*) as c FROM ot_records WHERE status IN ('Pending', 'Manager_Approved')");
        if($q_ot) $badge_ot += mysqli_fetch_assoc($q_ot)['c'];
        $q_lv = mysqli_query($conn, "SELECT COUNT(*) as c FROM leave_records WHERE status IN ('Pending', 'Manager_Approved')");
        if($q_lv) $badge_leave += mysqli_fetch_assoc($q_lv)['c'];
    }
    $response['badge_approve'] = $badge_ot + $badge_leave;

    // ระบบบัญชี: AR (ลูกหนี้)
    if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || strpos($user_dept, 'บัญชี') !== false || strpos($user_dept, 'การเงิน') !== false || strpos($user_dept, 'สินเชื่อ') !== false || strpos($user_dept, 'ขาย') !== false) {
        $q_pay = mysqli_query($conn, "SELECT COUNT(*) as c FROM sales_orders WHERE payment_status IN ('Unpaid', 'Credit')");
        if($q_pay) $response['badge_payment'] += mysqli_fetch_assoc($q_pay)['c'];
    }
    
    // 🚀 ระบบบัญชี: AP (เจ้าหนี้รอโอนเงิน)
    if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || strpos($user_dept, 'บัญชี') !== false || strpos($user_dept, 'การเงิน') !== false) {
        $check_col = mysqli_query($conn, "SHOW COLUMNS FROM `purchase_orders` LIKE 'payment_status'");
        if ($check_col && mysqli_num_rows($check_col) > 0) {
            $q_ap = mysqli_query($conn, "SELECT COUNT(*) as c FROM purchase_orders WHERE status IN ('Completed', 'Delivered') AND payment_status = 'Unpaid'");
            if($q_ap) $response['badge_ap'] += mysqli_fetch_assoc($q_ap)['c'];
        }
    }

    $response['total_admin'] = $response['badge_po'] + $response['badge_approve'];
}

echo json_encode($response);
?>