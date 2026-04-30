<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
$is_logged_in = !empty($_SESSION['userid']);
$current_page = basename($_SERVER['PHP_SELF']);

// 🚀 1. อัปเดตรายชื่อกลุ่มแผนกให้ตรงตามโครงสร้างองค์กร (Multi-Plant / Office / Academic)
$group_prod    = ['แผนกผลิต 1', 'แผนกผลิต 2', 'ผลิตอาหารสัตว์น้ำ', 'ฝ่ายงานวางแผน'];
$group_wh      = ['แผนกคลังสินค้า 1', 'แผนกคลังสินค้า 2'];
$group_pur     = ['ฝ่ายจัดซื้อ'];
$group_qa      = ['แผนก QA', 'แผนก QC', 'ฝ่ายวิชาการ'];
$group_mnt     = ['แผนกซ่อมบำรุง 1', 'แผนกซ่อมบำรุง 2', 'แผนกไฟฟ้า 1', 'แผนกไฟฟ้า 2', 'แผนก P&M - 1', 'แผนก P&M - 2'];
$group_sales   = ['ฝ่ายขาย'];
$group_finance = ['ฝ่ายบัญชี', 'ฝ่ายการเงิน', 'ฝ่ายสินเชื่อ', 'บัญชี - ท็อปธุรกิจ']; 
$group_hr      = ['ฝ่าย HR'];
$group_it      = ['แผนกคอมพิวเตอร์']; 
$group_logistics = ['แผนกจัดส่ง', 'ฝ่ายโลจิสติกส์'];

// ตัวแปรเก็บจำนวนแจ้งเตือน
$badge_pr = 0; $badge_po = 0; $badge_po_receive = 0; $badge_stock = 0; 
$badge_production = 0; $badge_qa_inbound = 0; $badge_qa_outbound = 0; 
$badge_maintenance = 0; $badge_approve = 0; $badge_payment = 0; 
$badge_ap = 0; $badge_delivery = 0; 

// 🚀 2. ดึงตัวเลขแจ้งเตือน (แยกตามสิทธิ์ที่ควรเห็น)
if ($is_logged_in) {
    if ($user_role == 'ADMIN' || $user_role == 'MANAGER') {
        $q = mysqli_query($conn, "SELECT COUNT(*) as c FROM purchase_requests WHERE status = 'Pending'");
        if($q) $badge_pr = mysqli_fetch_assoc($q)['c'];
    }
    if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_pur)) {
        $q = mysqli_query($conn, "SELECT COUNT(*) as c FROM purchase_orders WHERE status = 'Pending'");
        if($q) $badge_po = mysqli_fetch_assoc($q)['c'];
    }
    // GRN (คลังรับของ) ให้เห็นเฉพาะคลัง, จัดซื้อ และผู้บริหาร
    if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_wh) || in_array($user_dept, $group_pur)) {
        $q = mysqli_query($conn, "SELECT COUNT(*) as c FROM purchase_orders WHERE status = 'Manager_Approved'");
        if($q) $badge_po_receive = mysqli_fetch_assoc($q)['c'];
    }
    // แจ้งเตือนของใกล้หมด (ให้จัดซื้อ, คลัง, ผลิต, ฝ่ายขาย และผู้บริหารเห็น)
    if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_wh) || in_array($user_dept, $group_pur) || in_array($user_dept, $group_prod) || in_array($user_dept, $group_sales)) { 
        $q = mysqli_query($conn, "SELECT COUNT(*) as c FROM products p 
                          LEFT JOIN (SELECT product_id, SUM(qty) as total FROM stock_balances GROUP BY product_id) sb 
                          ON p.id = sb.product_id 
                          WHERE IFNULL(sb.total, 0) <= p.p_min");
        if($q) $badge_stock = mysqli_fetch_assoc($q)['c']; 
    }
    // ผลิต
    if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_prod)) {
        $dept_filter = ($user_role === 'ADMIN' || $user_role === 'MANAGER' || $user_dept === 'ฝ่ายงานวางแผน') ? "" : "AND production_line = '$user_dept'";
        $q = mysqli_query($conn, "SELECT COUNT(*) as c FROM production_orders WHERE status = 'Pending' $dept_filter");
        if($q) $badge_production = mysqli_fetch_assoc($q)['c'];
    }
    // QA
    if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_qa)) {
        $q_in = mysqli_query($conn, "SELECT COUNT(DISTINCT lot_no) as c FROM inventory_lots WHERE status = 'Pending_QA' AND qty > 0 AND lot_no LIKE 'REC-%'");
        if($q_in) $badge_qa_inbound = mysqli_fetch_assoc($q_in)['c'];
        
        $q_out = mysqli_query($conn, "SELECT COUNT(DISTINCT lot_no) as c FROM inventory_lots WHERE status = 'Pending_QA' AND qty > 0 AND lot_no NOT LIKE 'REC-%'");
        if($q_out) $badge_qa_outbound = mysqli_fetch_assoc($q_out)['c'];
    }
    // ซ่อมบำรุง
    if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_mnt)) { 
        $q = mysqli_query($conn, "SELECT COUNT(*) as c FROM maintenance_requests WHERE status = 'Pending'");
        if($q) $badge_maintenance = mysqli_fetch_assoc($q)['c']; 
    }
    // HR
    if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_hr)) {
        $q_ot = mysqli_query($conn, "SELECT COUNT(*) as c FROM ot_records WHERE status = 'Pending'");
        if($q_ot) $badge_approve += mysqli_fetch_assoc($q_ot)['c'];
    }
    // การเงิน / สินเชื่อ (AR/AP)
    if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_finance) || in_array($user_dept, $group_sales)) {
        $q_pay = mysqli_query($conn, "SELECT COUNT(*) as c FROM sales_orders WHERE payment_status IN ('Unpaid', 'Credit')");
        if($q_pay) $badge_payment = mysqli_fetch_assoc($q_pay)['c'];
    }
    if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_finance)) {
        $check_col = mysqli_query($conn, "SHOW COLUMNS FROM `purchase_orders` LIKE 'payment_status'");
        if ($check_col && mysqli_num_rows($check_col) > 0) {
            $q_ap = mysqli_query($conn, "SELECT COUNT(*) as c FROM purchase_orders WHERE status IN ('Completed', 'Delivered') AND payment_status = 'Unpaid'");
            if($q_ap) $badge_ap = mysqli_fetch_assoc($q_ap)['c'];
        }
    }
    // จัดส่ง (Logistics)
    if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_logistics) || in_array($user_dept, $group_sales)) {
        $check_col_del = mysqli_query($conn, "SHOW COLUMNS FROM `sales_orders` LIKE 'delivery_status'");
        if ($check_col_del && mysqli_num_rows($check_col_del) > 0) {
            $q_del = mysqli_query($conn, "SELECT COUNT(*) as c FROM sales_orders WHERE delivery_status = 'Pending'");
            if($q_del) $badge_delivery = mysqli_fetch_assoc($q_del)['c'];
        }
    }
}
$total_notifications = $badge_pr + $badge_po + $badge_po_receive + $badge_stock + $badge_production + $badge_qa_inbound + $badge_qa_outbound + $badge_maintenance + $badge_approve + $badge_payment + $badge_ap + $badge_delivery; 

$wh_total = $badge_po_receive + $badge_stock;
$qa_total = $badge_qa_inbound + $badge_qa_outbound;
$finance_total = $badge_payment + $badge_ap; 
?>

<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

<style>
    :root { --sidebar-width: 260px; --primary: #4e73df; --dark: #2c3e50; --darker: #1a252f; --hover-bg: #34495e; }
    body { margin: 0; font-family: 'Sarabun', sans-serif; background: #f8f9fc; display: flex; min-height: 100vh; overflow-x: hidden; }
    .sidebar { width: var(--sidebar-width); background: var(--dark); color: white; position: fixed; height: 100vh; z-index: 100; overflow-y: auto; overflow-x: hidden; box-shadow: 2px 0 15px rgba(0,0,0,0.1); }
    .sidebar::-webkit-scrollbar { width: 6px; }
    .sidebar::-webkit-scrollbar-track { background: var(--dark); }
    .sidebar::-webkit-scrollbar-thumb { background: #5a6a7a; border-radius: 10px; }
    .sidebar-header { padding: 25px 20px; text-align: center; background: var(--darker); font-size: 20px; font-weight: bold; border-bottom: 1px solid rgba(255,255,255,0.05); }
    .sidebar-header a { display: block; color: #ffffff; text-decoration: none; transition: 0.3s; }
    
    .menu-toggle { display: flex; justify-content: space-between; align-items: center; color: #858796; font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; padding: 15px 20px; border-top: 1px solid rgba(255,255,255,0.05); cursor: pointer; transition: 0.3s; }
    .menu-toggle:hover { color: #ffffff; background: rgba(255,255,255,0.05); }
    .menu-toggle.active { color: #ffffff; background: rgba(255,255,255,0.1); border-left: 4px solid var(--primary); padding-left: 16px; }
    .menu-toggle .arrow { font-size: 10px; transition: transform 0.3s ease; }
    .menu-toggle.active .arrow { transform: rotate(180deg); }
    
    .nav-menu { list-style: none; padding: 0 0 30px 0; margin: 0; }
    .sub-menu { list-style: none; padding: 0; margin: 0; display: none; background: rgba(0,0,0,0.15); border-bottom: 1px solid rgba(255,255,255,0.05); }
    .sub-menu.show { display: block; animation: fadeInMenu 0.3s ease; }
    @keyframes fadeInMenu { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
    
    .sub-menu li a { display: flex; align-items: center; padding: 12px 25px 12px 35px; color: #bdc3c7; text-decoration: none; transition: 0.3s; font-size: 13.5px; border-left: 4px solid transparent; }
    .sub-menu li a i { width: 25px; text-align: center; margin-right: 10px; }
    .sub-menu li a:hover { background: var(--hover-bg); color: white; padding-left: 40px; border-left-color: rgba(78, 115, 223, 0.5); }
    .sub-menu li a.active { background: var(--hover-bg); color: #ffffff; padding-left: 40px; border-left-color: var(--primary); font-weight: 600; }
    
    .nav-badge { background: #e74a3b; color: white; font-size: 11px; font-weight: bold; padding: 2px 7px; border-radius: 20px; margin-left: auto; display: none; }
    .nav-badge.show { display: inline-block; animation: pop 0.3s ease; }
    @keyframes pop { 0% { transform: scale(0.5); } 50% { transform: scale(1.2); } 100% { transform: scale(1); } }

    .main-content { margin-left: var(--sidebar-width); flex: 1; display: flex; flex-direction: column; width: calc(100% - var(--sidebar-width)); min-height: 100vh; padding-top: 70px; }
    .topbar { height: 70px; background: white; display: flex; align-items: center; justify-content: flex-end; padding: 0 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); position: fixed; top: 0; right: 0; left: var(--sidebar-width); z-index: 105; gap: 20px;}
    .notification-bell { position: relative; cursor: pointer; color: #858796; font-size: 20px; transition: 0.3s; padding: 5px; }
    .notification-bell:hover { color: #4e73df; }
    .bell-badge { position: absolute; top: -2px; right: -5px; background: #e74a3b; color: white; font-size: 10px; font-weight: bold; padding: 2px 5px; border-radius: 50px; border: 2px solid white; display: none;}
    .bell-badge.show { display: inline-block; animation: pop 0.3s ease; }
    .noti-dropdown { display: none; position: absolute; top: 55px; right: 0; width: 340px; background: white; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); z-index: 1000; overflow: hidden; animation: fadeIn 0.2s ease-in-out; border: 1px solid #eaecf4; }
    .noti-dropdown.show { display: block; }
    .noti-header { background: #4e73df; color: white; padding: 15px 20px; font-weight: bold; font-size: 14px; letter-spacing: 0.5px; }
    .noti-list { max-height: 380px; overflow-y: auto; }
    .noti-list::-webkit-scrollbar { width: 5px; }
    .noti-list::-webkit-scrollbar-thumb { background: #d1d3e2; border-radius: 10px; }
    .noti-item { display: flex; align-items: center; padding: 15px 20px; border-bottom: 1px solid #eaecf4; text-decoration: none; color: #5a5c69; transition: 0.3s; }
    .noti-item:hover { background: #f8f9fc; padding-left: 25px; border-left: 3px solid #4e73df; }
    .noti-icon { width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 15px; color: white; font-size: 16px; flex-shrink: 0;}
    .noti-text { font-size: 13px; line-height: 1.4; width: 100%;}
    .noti-text strong { color: #2c3e50; font-size: 14px;}
    .noti-empty { padding: 40px 20px; text-align: center; color: #858796; font-size: 14px; }
    .user-badge { background: #f8f9fc; padding: 6px 6px 6px 18px; border-radius: 50px; font-size: 14px; color: #5a5c69; border: 1px solid #e3e6f0; display: flex; align-items: center; }
    .btn-logout { background: #ffe5e5; color: #e74a3b; text-decoration: none; margin-left: 15px; font-weight: bold; padding: 6px 15px; border-radius: 50px; font-size: 13px; transition: 0.2s;}
    .btn-logout:hover { background: #e74a3b; color: white; }
    .btn-profile { background: #e3f2fd; color: #4e73df; text-decoration: none; margin-left: 15px; font-weight: bold; padding: 6px 15px; border-radius: 50px; font-size: 13px; transition: 0.2s;}
    .btn-profile:hover { background: #4e73df; color: white; }
    .btn-login { background: var(--primary); color: white; text-decoration: none; padding: 8px 20px; border-radius: 50px; font-weight: bold; transition: 0.3s; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    .btn-login:hover { background: #2e59d9; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(78, 115, 223, 0.3); }
    .content-padding { padding: 30px; }
    .hamburger-btn { display: none; background: none; border: none; color: var(--primary); font-size: 24px; cursor: pointer; padding: 5px; }
    .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1005; }
    *, *::before, *::after { box-sizing: border-box !important; }
    @media (max-width: 768px) {
        html, body { width: 100vw !important; max-width: 100vw !important; overflow-x: hidden !important; }
        .sidebar { transform: translateX(-100%); transition: transform 0.3s ease-in-out; box-shadow: 5px 0 15px rgba(0,0,0,0.2); z-index: 1010 !important; }
        .sidebar.active { transform: translateX(0); }
        .sidebar-overlay.active { display: block; }
        .topbar { left: 0 !important; width: 100vw !important; justify-content: space-between !important; padding: 0 15px !important; box-sizing: border-box !important; gap:10px;}
        .hamburger-btn { display: block; }
        .user-badge { font-size: 12px; padding: 4px 10px; border-radius: 8px; }
        .hide-mobile { display: none !important; }
        .btn-profile, .btn-logout { margin-left: 5px; padding: 4px 10px; font-size: 11px; border-radius: 8px; }
        .main-content { margin-left: 0 !important; width: 100vw !important; max-width: 100vw !important; overflow-x: hidden !important; }
        .content-padding { padding: 15px !important; width: 100% !important; max-width: 100% !important; overflow-x: hidden !important; box-sizing: border-box !important; }
        *[style*="grid-template-columns"], .form-row, .hr-grid, .ot-time-grid, .dashboard-grid, .contact-grid-inner { display: flex !important; flex-direction: column !important; width: 100% !important; max-width: 100% !important; gap: 10px !important; box-sizing: border-box !important; }
        div[class*="-card"], .card, .dashboard-card { width: 100% !important; max-width: 100% !important; margin-left: 0 !important; margin-right: 0 !important; padding: 15px !important; box-sizing: border-box !important; }
        .input-group, input, select, textarea, form { width: 100% !important; max-width: 100% !important; box-sizing: border-box !important; min-width: 0 !important; }
        table, .table-responsive { display: block !important; width: 100% !important; max-width: 100% !important; overflow-x: auto !important; -webkit-overflow-scrolling: touch; }
        th, td { white-space: nowrap !important; } 
        h2 { font-size: 1.3rem !important; }
        h3, .section-title { font-size: 1.1rem !important; }
        .noti-dropdown { right: -15px; width: 320px; max-width: 90vw; }
    }
</style>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar">
    <div class="sidebar-header"><a href="/my_system/index.php"><i class="fa-solid fa-industry"></i> Top Feed Mills</a></div>
    <ul class="nav-menu">
        
        <?php if ($is_logged_in): ?>
            <li>
                <div class="menu-toggle" onclick="toggleSubMenu(this)">
                    <span>📝 บริการพนักงาน (Self-Service)</span>
                    <i class="fa-solid fa-chevron-down arrow"></i>
                </div>
                <ul class="sub-menu">
                    <li><a href="/my_system/hr/manage_leave.php" class="<?= ($current_page == 'manage_leave.php') ? 'active' : '' ?>"><i class="fa-solid fa-calendar-plus"></i> บันทึกขออนุญาตลา</a></li>
                    <li><a href="/my_system/hr/history_leave.php" class="<?= ($current_page == 'history_leave.php') ? 'active' : '' ?>"><i class="fa-solid fa-calendar-check"></i> ประวัติและสถานะการลา</a></li>
                    <li><a href="/my_system/hr/manage_ot.php" class="<?= ($current_page == 'manage_ot.php') ? 'active' : '' ?>"><i class="fa-solid fa-stopwatch"></i> บันทึกขอทำ OT</a></li>
                    <li><a href="/my_system/hr/history_ot.php" class="<?= ($current_page == 'history_ot.php') ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice-dollar"></i> ประวัติและสถานะ OT</a></li>
                    <li><a href="/my_system/maintenance/request_maintenance.php" class="<?= ($current_page == 'request_maintenance.php') ? 'active' : '' ?>"><i class="fa-solid fa-screwdriver-wrench"></i> แจ้งซ่อมเครื่องจักร</a></li>
                    <li><a href="/my_system/purchase/create_pr.php" class="<?= ($current_page == 'create_pr.php') ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice"></i> สร้างใบขอสั่งซื้อ (PR)</a></li>
                    <li><a href="/my_system/hr/my_payslip.php" class="<?= ($current_page == 'my_payslip.php') ? 'active' : '' ?>"><i class="fa-solid fa-receipt"></i> สลิปเงินเดือนของฉัน</a></li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER'): ?>
            <li>
                <div class="menu-toggle" onclick="toggleSubMenu(this)">
                    <span style="display:flex; align-items:center; gap:8px;">
                        ✅ หัวหน้างาน (Approval)
                        <span id="badge-approve-main" class="nav-badge <?= ($badge_approve > 0) ? 'show' : '' ?>"><?= $badge_approve ?></span>
                    </span>
                    <i class="fa-solid fa-chevron-down arrow"></i>
                </div>
                <ul class="sub-menu">
                    <li>
                        <a href="/my_system/hr/approve_requests.php" class="<?= ($current_page == 'approve_requests.php') ? 'active' : '' ?>">
                            <i class="fa-solid fa-clipboard-check"></i> อนุมัติ OT / การลา
                            <span id="badge-approve" class="nav-badge <?= ($badge_approve > 0) ? 'show' : '' ?>"><?= $badge_approve ?></span>
                        </a>
                    </li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_hr)): ?>
            <li>
                <div class="menu-toggle" onclick="toggleSubMenu(this)">
                    <span>👥 ทรัพยากรบุคคล (HR)</span>
                    <i class="fa-solid fa-chevron-down arrow"></i>
                </div>
                <ul class="sub-menu">
                    <li><a href="/my_system/hr/manage_users.php" class="<?= ($current_page == 'manage_users.php') ? 'active' : '' ?>"><i class="fa-solid fa-users-gear"></i> ฐานข้อมูลพนักงาน</a></li>
                    <li><a href="/my_system/hr/manage_attendance.php" class="<?= ($current_page == 'manage_attendance.php') ? 'active' : '' ?>"><i class="fa-solid fa-fingerprint"></i> บันทึกเวลาเข้า-ออกงาน</a></li>
                </ul>
            </li>
            <li>
                <div class="menu-toggle" onclick="toggleSubMenu(this)">
                    <span>🖨️ ระบบรายงาน (HR)</span>
                    <i class="fa-solid fa-chevron-down arrow"></i>
                </div>
                <ul class="sub-menu">
                    <li><a href="/my_system/hr/report_log_ot.php" class="<?= ($current_page == 'report_log_ot.php') ? 'active' : '' ?>"><i class="fa-solid fa-print"></i> รายงานขอ OT</a></li>
                    <li><a href="/my_system/hr/report_ot.php" class="<?= ($current_page == 'report_ot.php') ? 'active' : '' ?>"><i class="fa-solid fa-print"></i> รายงานประวัติ OT</a></li>
                    <li><a href="/my_system/hr/report_leave.php" class="<?= ($current_page == 'report_leave.php') ? 'active' : '' ?>"><i class="fa-solid fa-print"></i> รายงานประวัติการลา</a></li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_hr) || in_array($user_dept, $group_finance)): ?>
            <li>
                <div class="menu-toggle" onclick="toggleSubMenu(this)">
                    <span>💸 บัญชีเงินเดือน (PAYROLL)</span>
                    <i class="fa-solid fa-chevron-down arrow"></i>
                </div>
                <ul class="sub-menu">
                    <li><a href="/my_system/hr/payroll_summary.php" class="<?= ($current_page == 'payroll_summary.php') ? 'active' : '' ?>"><i class="fa-solid fa-calculator"></i> ประมวลผลเงินเดือน</a></li>
                    <li><a href="/my_system/hr/manage_payroll.php" class="<?= ($current_page == 'manage_payroll.php') ? 'active' : '' ?>"><i class="fa-solid fa-money-check-dollar"></i> ประวัติการจ่ายเงินเดือน</a></li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_sales) || in_array($user_dept, $group_finance)): ?>
            <li>
                <div class="menu-toggle" onclick="toggleSubMenu(this)">
                    <span style="display:flex; align-items:center; gap:8px;">
                        💰 ฝ่ายขาย และ บัญชีการเงิน
                        <span id="badge-sales-main" class="nav-badge <?= ($finance_total > 0) ? 'show' : '' ?>"><?= $finance_total ?></span>
                    </span>
                    <i class="fa-solid fa-chevron-down arrow"></i>
                </div>
                <ul class="sub-menu">
                    <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, ['ฝ่ายสินเชื่อ', 'ฝ่ายการเงิน'])): ?>
                        <li><a href="/my_system/sales/credit_approval.php" class="<?= ($current_page == 'credit_approval.php') ? 'active' : '' ?>"><i class="fa-solid fa-file-shield"></i> อนุมัติวงเงินเครดิตลูกค้า</a></li>
                    <?php endif; ?>
                    
                    <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_sales)): ?>
                        <li><a href="/my_system/sales/manage_customers.php" class="<?= ($current_page == 'manage_customers.php') ? 'active' : '' ?>"><i class="fa-solid fa-address-book"></i> จัดการรายชื่อลูกค้า</a></li>
                        <li><a href="/my_system/sales/create_sales.php" class="<?= ($current_page == 'create_sales.php') ? 'active' : '' ?>"><i class="fa-solid fa-cart-arrow-down"></i> เปิดบิลขาย (ตัดสต็อก)</a></li>
                        <li><a href="/my_system/sales/sales_history.php" class="<?= ($current_page == 'sales_history.php') ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice"></i> ประวัติการขาย</a></li>
                        <li><a href="/my_system/sales/return_order.php" class="<?= ($current_page == 'return_order.php') ? 'active' : '' ?>"><i class="fa-solid fa-arrow-rotate-left"></i> รับคืนสินค้า (RMA / ลดหนี้)</a></li>
                    <?php endif; ?>
                    
                    <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_finance)): ?>
                        <li>
                            <a href="/my_system/sales/payment_tracker.php" class="<?= ($current_page == 'payment_tracker.php') ? 'active' : '' ?>">
                                <i class="fa-solid fa-money-bill-trend-up"></i> ติดตามรับเงินลูกค้า (AR)
                                <span id="badge-payment" class="nav-badge <?= ($badge_payment > 0) ? 'show' : '' ?>"><?= $badge_payment ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="/my_system/purchase/payment_supplier.php" class="<?= ($current_page == 'payment_supplier.php') ? 'active' : '' ?>">
                                <i class="fa-solid fa-file-invoice"></i> จ่ายเงินผู้จำหน่าย (AP)
                                <span id="badge-ap" class="nav-badge <?= ($badge_ap > 0) ? 'show' : '' ?>"><?= $badge_ap ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php endif; ?>
        
        <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_logistics) || in_array($user_dept, $group_sales)): ?>
            <li>
                <div class="menu-toggle" onclick="toggleSubMenu(this)">
                    <span style="display:flex; align-items:center; gap:8px;">
                        🚚 ขนส่งและจัดส่ง
                        <span id="badge-delivery-main" class="nav-badge <?= ($badge_delivery > 0) ? 'show' : '' ?>"><?= $badge_delivery ?></span>
                    </span>
                    <i class="fa-solid fa-chevron-down arrow"></i>
                </div>
                <ul class="sub-menu">
                    <li>
                        <a href="/my_system/logistics/manage_delivery.php" class="<?= ($current_page == 'manage_delivery.php') ? 'active' : '' ?>">
                            <i class="fa-solid fa-truck-fast"></i> จัดคิวรถและสถานะ
                            <span id="badge-delivery" class="nav-badge <?= ($badge_delivery > 0) ? 'show' : '' ?>"><?= $badge_delivery ?></span>
                        </a>
                    </li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_wh) || in_array($user_dept, $group_pur) || in_array($user_dept, $group_prod) || in_array($user_dept, $group_qa) || in_array($user_dept, $group_sales) || in_array($user_dept, $group_logistics)): ?>
            <li>
                <div class="menu-toggle" onclick="toggleSubMenu(this)">
                    <span style="display:flex; align-items:center; gap:8px;">
                        📦 คลังสินค้า
                        <span id="badge-wh-main" class="nav-badge <?= ($wh_total > 0) ? 'show' : '' ?>"><?= $wh_total ?></span>
                    </span>
                    <i class="fa-solid fa-chevron-down arrow"></i>
                </div>
                <ul class="sub-menu">
                    <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_wh) || in_array($user_dept, $group_pur)): ?>
                    <li>
                        <a href="/my_system/inventory/receive_po.php" class="<?= ($current_page == 'receive_po.php') ? 'active' : '' ?>">
                            <i class="fa-solid fa-truck-ramp-box"></i> รับของจาก PO (GRN)
                            <span id="badge-po-receive" class="nav-badge <?= ($badge_po_receive > 0) ? 'show' : '' ?>"><?= $badge_po_receive ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <li>
                        <a href="/my_system/inventory/stock.php" class="<?= ($current_page == 'stock.php') ? 'active' : '' ?>">
                            <i class="fa-solid fa-cubes"></i> แผงควบคุมคลัง
                        </a>
                    </li>
                    
                    <li>
                        <a href="/my_system/inventory/low_stock_report.php" class="<?= ($current_page == 'low_stock_report.php') ? 'active' : '' ?>">
                            <i class="fa-solid fa-triangle-exclamation"></i> รายการของใกล้หมด
                            <span id="badge-stock" class="nav-badge <?= ($badge_stock > 0) ? 'show' : '' ?>" style="background:#f6c23e; color:#333;"><?= $badge_stock ?></span>
                        </a>
                    </li>

                    <li><a href="/my_system/inventory/history.php" class="<?= ($current_page == 'history.php') ? 'active' : '' ?>"><i class="fa-solid fa-clock-rotate-left"></i> ประวัติความเคลื่อนไหว</a></li>
                    
                    <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_wh)): ?>
                        <li><a href="/my_system/inventory/add_product.php" class="<?= ($current_page == 'add_product.php') ? 'active' : '' ?>"><i class="fa-solid fa-folder-plus"></i> เพิ่มรายการวัตถุดิบ</a></li>
                        <li><a href="/my_system/inventory/stock_adjust.php" class="<?= ($current_page == 'stock_adjust.php') ? 'active' : '' ?>"><i class="fa-solid fa-sliders"></i> ปรับปรุงยอดสต็อก</a></li>
                        <li><a href="/my_system/inventory/scrap_product.php" class="<?= ($current_page == 'scrap_product.php') ? 'active' : '' ?>"><i class="fa-solid fa-dumpster-fire"></i> บันทึกตัดชำรุด (Scrap)</a></li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php endif; ?>

        <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_prod)): ?>
            <li>
                <div class="menu-toggle" onclick="toggleSubMenu(this)">
                    <span style="display:flex; align-items:center; gap:8px;">
                        🏭 วางแผนและผลิต
                        <span id="badge-prod-main" class="nav-badge <?= ($badge_production > 0) ? 'show' : '' ?>"><?= $badge_production ?></span>
                    </span>
                    <i class="fa-solid fa-chevron-down arrow"></i>
                </div>
                <ul class="sub-menu">
                    <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, ['ฝ่ายงานวางแผน'])): ?>
                        <li><a href="/my_system/production/production_orders.php" class="<?= ($current_page == 'production_orders.php') ? 'active' : '' ?>"><i class="fa-solid fa-clipboard-list"></i> ใบสั่งผลิต (Master Plan)</a></li>
                    <?php endif; ?>
                    <li>
                        <a href="/my_system/production/create_production.php" class="<?= ($current_page == 'create_production.php') ? 'active' : '' ?>">
                            <i class="fa-solid fa-gears"></i> บันทึกการผลิต
                            <span id="badge-production" class="nav-badge <?= ($badge_production > 0) ? 'show' : '' ?>"><?= $badge_production ?></span>
                        </a>
                    </li>
                    <li><a href="/my_system/production/manage_formula.php" class="<?= ($current_page == 'manage_formula.php') ? 'active' : '' ?>"><i class="fa-solid fa-flask"></i> จัดการสูตรอาหาร</a></li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_qa)): ?>
            <li>
                <div class="menu-toggle" onclick="toggleSubMenu(this)">
                    <span style="display:flex; align-items:center; gap:8px;">
                        🔬 ควบคุมคุณภาพ (QA/QC)
                        <span id="badge-qa-main" class="nav-badge <?= ($qa_total > 0) ? 'show' : '' ?>"><?= $qa_total ?></span>
                    </span>
                    <i class="fa-solid fa-chevron-down arrow"></i>
                </div>
                <ul class="sub-menu">
                    <li>
                        <a href="/my_system/qa/qa_inbound.php" class="<?= ($current_page == 'qa_inbound.php') ? 'active' : '' ?>">
                            <i class="fa-solid fa-truck-droplet"></i> ตรวจวัตถุดิบ (Inbound)
                            <span id="badge-qa-inbound" class="nav-badge <?= ($badge_qa_inbound > 0) ? 'show' : '' ?>"><?= $badge_qa_inbound ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="/my_system/qa/qa_outbound.php" class="<?= ($current_page == 'qa_outbound.php') ? 'active' : '' ?>">
                            <i class="fa-solid fa-box-open"></i> ตรวจสินค้า (Outbound)
                            <span id="badge-qa-outbound" class="nav-badge <?= ($badge_qa_outbound > 0) ? 'show' : '' ?>"><?= $badge_qa_outbound ?></span>
                        </a>
                    </li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_pur)): ?>
            <li>
                <div class="menu-toggle" onclick="toggleSubMenu(this)">
                    <span style="display:flex; align-items:center; gap:8px;">
                        🛒 จัดซื้อ (Purchasing)
                        <span id="badge-pur-main" class="nav-badge <?= ($badge_po > 0) ? 'show' : '' ?>"><?= $badge_po ?></span>
                    </span>
                    <i class="fa-solid fa-chevron-down arrow"></i>
                </div>
                <ul class="sub-menu">
                    <li><a href="/my_system/purchase/manage_suppliers.php" class="<?= ($current_page == 'manage_suppliers.php') ? 'active' : '' ?>"><i class="fa-solid fa-handshake"></i> จัดการรายชื่อผู้ขาย</a></li>
                    <li><a href="/my_system/purchase/compare_prices.php" class="<?= ($current_page == 'compare_prices.php') ? 'active' : '' ?>"><i class="fa-solid fa-scale-balanced"></i> เปรียบเทียบราคา</a></li>
                    <li><a href="/my_system/purchase/create_po.php" class="<?= ($current_page == 'create_po.php') ? 'active' : '' ?>"><i class="fa-solid fa-file-signature"></i> สร้างใบสั่งซื้อ (PO)</a></li>
                    <li><a href="/my_system/purchase/process_pr.php" class="<?= ($current_page == 'process_pr.php') ? 'active' : '' ?>"><i class="fa-solid fa-clipboard-list"></i> จัดการใบขอซื้อ (PR)</a></li>
                    <li><a href="/my_system/purchase/view_pos.php" class="<?= ($current_page == 'view_pos.php') ? 'active' : '' ?>"><i class="fa-solid fa-clipboard-list"></i> ติดตามรายการสั่งซื้อ<span id="badge-po" class="nav-badge <?= ($badge_po > 0) ? 'show' : '' ?>"><?= $badge_po ?></span></a></li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_mnt)): ?>
            <li>
                <div class="menu-toggle" onclick="toggleSubMenu(this)">
                    <span style="display:flex; align-items:center; gap:8px;">
                        🛠️ ฝ่ายซ่อมบำรุง
                        <span id="badge-mnt-main" class="nav-badge <?= ($badge_maintenance > 0) ? 'show' : '' ?>"><?= $badge_maintenance ?></span>
                    </span>
                    <i class="fa-solid fa-chevron-down arrow"></i>
                </div>
                <ul class="sub-menu">
                    <li>
                        <a href="/my_system/maintenance/manage_maintenance.php" class="<?= ($current_page == 'manage_maintenance.php') ? 'active' : '' ?>">
                            <i class="fa-solid fa-toolbox"></i> กระดานงานช่าง
                            <span id="badge-maintenance" class="nav-badge <?= ($badge_maintenance > 0) ? 'show' : '' ?>"><?= $badge_maintenance ?></span>
                        </a>
                    </li>
                    <li><a href="/my_system/maintenance/manage_pm.php" class="<?= ($current_page == 'manage_pm.php') ? 'active' : '' ?>"><i class="fa-solid fa-calendar-plus"></i> แผนบำรุงรักษา (PM)</a></li>
                    <li><a href="/my_system/maintenance/issue_parts.php" class="<?= ($current_page == 'issue_parts.php') ? 'active' : '' ?>"><i class="fa-solid fa-screwdriver-wrench"></i> เบิกอะไหล่ / วัสดุซ่อม</a></li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_it) || in_array($user_dept, $group_hr)): ?>
            <li>
                <div class="menu-toggle" onclick="toggleSubMenu(this)">
                    <span>⚙️ จัดการระบบบริษัท</span>
                    <i class="fa-solid fa-chevron-down arrow"></i>
                </div>
                <ul class="sub-menu">
                    <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_it)): ?>
                        <li><a href="/my_system/admin/report_center.php" class="<?= ($current_page == 'report_center.php') ? 'active' : '' ?>"><i class="fa-solid fa-chart-line"></i> ศูนย์รายงาน (Dashboard)</a></li>
                        <li><a href="/my_system/admin/admin_news.php" class="<?= ($current_page == 'admin_news.php') ? 'active' : '' ?>"><i class="fa-solid fa-bullhorn"></i> จัดการประกาศข่าวสาร</a></li>
                    <?php endif; ?>
                    
                    <?php if ($user_role == 'ADMIN' || $user_role == 'MANAGER' || in_array($user_dept, $group_hr)): ?>
                        <li><a href="/my_system/hr/manage_shifts.php" class="<?= ($current_page == 'manage_shifts.php') ? 'active' : '' ?>"><i class="fa-solid fa-business-time"></i> ตั้งค่ากะการทำงาน</a></li>
                        <li><a href="/my_system/hr/manage_holidays.php" class="<?= ($current_page == 'manage_holidays.php') ? 'active' : '' ?>"><i class="fa-solid fa-calendar-day"></i> ตั้งค่าวันหยุดบริษัท</a></li>
                    <?php endif; ?>

                    <?php if ($user_role == 'ADMIN' || in_array($user_dept, $group_it)): ?>
                        <li><a href="/my_system/admin/view_logs.php" class="<?= ($current_page == 'view_logs.php') ? 'active' : '' ?>"><i class="fa-solid fa-shield-halved"></i> ประวัติการใช้งานระบบ (Logs)</a></li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php endif; ?>

    </ul>
</div>

<div class="main-content">
    <div class="topbar">
        
        <button class="hamburger-btn" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>

        <?php if ($is_logged_in): ?>
            <div style="position: relative;">
                <div class="notification-bell" onclick="toggleNotiDropdown(event)">
                    <i class="fa-solid fa-bell"></i>
                    <span id="top-bell-badge" class="bell-badge <?= ($total_notifications > 0) ? 'show' : '' ?>"><?= $total_notifications ?></span>
                </div>
                
                <div id="notiDropdown" class="noti-dropdown">
                    <div class="noti-header">
                        <i class="fa-solid fa-bell-concierge"></i> การแจ้งเตือนทั้งหมด
                    </div>
                    <div id="notiList" class="noti-list">
                        </div>
                </div>
            </div>

            <div class="user-badge">
                <i class="fa-solid fa-circle-user" style="color: #4e73df; font-size: 18px;"></i> 
                <strong style="margin-left: 8px;"><?php echo !empty($_SESSION['fullname']) ? htmlspecialchars($_SESSION['fullname']) : 'พนักงาน'; ?></strong> 
                <span class="hide-mobile" style="font-size: 12px; color: #858796; margin-left: 5px;">(ID: <?php echo htmlspecialchars($_SESSION['userid']); ?>)</span>
                <span class="hide-mobile" style="color: #ccc; margin: 0 10px;">|</span>
                <span class="hide-mobile">แผนก: <?php echo htmlspecialchars($_SESSION['dept']); ?></span>
                
                <a href="/my_system/profile.php" class="btn-profile"><i class="fa-solid fa-user-gear"></i> <span class="hide-mobile">ข้อมูลส่วนตัว</span><span style="display:none;" class="show-mobile">ตั้งค่า</span></a>
                <a href="/my_system/logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> <span class="hide-mobile">ออกจากระบบ</span><span style="display:none;" class="show-mobile">ออก</span></a>
            </div>
        <?php else: ?>
            <a href="/my_system/login.php" class="btn-login"><i class="fa-solid fa-key"></i> เข้าสู่ระบบ</a>
        <?php endif; ?>
    </div>

    <script>
        function toggleSubMenu(element) {
            element.classList.toggle('active');
            let subMenu = element.nextElementSibling;
            subMenu.classList.toggle('show');
        }

        document.addEventListener("DOMContentLoaded", function() {
            let activeLinks = document.querySelectorAll('.sub-menu a.active');
            activeLinks.forEach(function(link) {
                let subMenu = link.closest('.sub-menu');
                if (subMenu) {
                    subMenu.classList.add('show');
                    let toggleBtn = subMenu.previousElementSibling;
                    if (toggleBtn && toggleBtn.classList.contains('menu-toggle')) {
                        toggleBtn.classList.add('active');
                    }
                }
            });
        });

        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
            document.querySelector('.sidebar-overlay').classList.toggle('active');
        }

        <?php if ($is_logged_in): ?>
        
        function toggleNotiDropdown(event) {
            event.stopPropagation();
            document.getElementById('notiDropdown').classList.toggle('show');
            if(document.getElementById('notiDropdown').classList.contains('show')) {
                fetchNotifications();
            }
        }

        window.onclick = function(event) {
            if (!event.target.closest('.noti-dropdown')) {
                var dropdowns = document.getElementsByClassName("noti-dropdown");
                for (var i = 0; i < dropdowns.length; i++) {
                    var openDropdown = dropdowns[i];
                    if (openDropdown.classList.contains('show')) {
                        openDropdown.classList.remove('show');
                    }
                }
            }
        }

        function updateBadges(id, count, hideOnPage = '') {
            let badge = document.getElementById(id);
            if(badge) {
                let isCurrentPage = hideOnPage !== '' && window.location.pathname.endsWith(hideOnPage);
                if(count > 0 && !isCurrentPage) {
                    if (badge.innerText !== count.toString()) {
                        badge.innerText = count;
                        badge.classList.remove('show');
                        void badge.offsetWidth; 
                        badge.classList.add('show');
                    }
                } else {
                    badge.classList.remove('show');
                }
            }
        }
        
        function renderNotificationDropdown(data) {
            let list = document.getElementById('notiList');
            let html = '';
            let total = 0;

            if (data.badge_approve > 0) {
                html += `<a href="/my_system/hr/approve_requests.php" class="noti-item">
                            <div class="noti-icon" style="background:#1cc88a;"><i class="fa-solid fa-clipboard-user"></i></div>
                            <div class="noti-text"><strong>รออนุมัติ / ประมวลผล (HR)</strong><br>มีคำขอลาหรือ OT จำนวน <span style="color:#e74a3b; font-weight:bold;">${data.badge_approve}</span> รายการ</div>
                         </a>`;
                total += data.badge_approve;
            }
            
            if (data.badge_pr > 0) {
                html += `<a href="/my_system/purchase/create_po.php" class="noti-item">
                            <div class="noti-icon" style="background:#f6c23e;"><i class="fa-solid fa-file-pen"></i></div>
                            <div class="noti-text"><strong>ใบขอซื้อ (PR) ใหม่</strong><br>มีใบขอสั่งซื้อ <span style="color:#e74a3b; font-weight:bold;">${data.badge_pr}</span> รายการ รอพิจารณาอนุมัติ</div>
                         </a>`;
                total += data.badge_pr;
            }

            if (data.badge_po > 0) {
                html += `<a href="/my_system/purchase/view_pos.php" class="noti-item">
                            <div class="noti-icon" style="background:#4e73df;"><i class="fa-solid fa-file-invoice"></i></div>
                            <div class="noti-text"><strong>ใบสั่งซื้อ (PO) รอดำเนินการ</strong><br>มีใบสั่งซื้อ <span style="color:#e74a3b; font-weight:bold;">${data.badge_po}</span> รายการ รอส่งให้ซัพพลายเออร์</div>
                         </a>`;
                total += data.badge_po;
            }

            if (data.badge_po_receive > 0) {
                html += `<a href="/my_system/inventory/receive_po.php" class="noti-item">
                            <div class="noti-icon" style="background:#36b9cc;"><i class="fa-solid fa-truck-ramp-box"></i></div>
                            <div class="noti-text"><strong>รถขนส่งกำลังมา! (GRN)</strong><br>มี PO <span style="color:#e74a3b; font-weight:bold;">${data.badge_po_receive}</span> รายการ รอคลังรับเข้า</div>
                         </a>`;
                total += data.badge_po_receive;
            }

            if (data.badge_stock > 0) {
                html += `<a href="/my_system/inventory/low_stock_report.php" class="noti-item">
                            <div class="noti-icon" style="background:#e74a3b;"><i class="fa-solid fa-triangle-exclamation"></i></div>
                            <div class="noti-text"><strong>แจ้งเตือนสต็อกต่ำ!</strong><br>มีสินค้า <span style="color:#e74a3b; font-weight:bold;">${data.badge_stock}</span> รายการ ที่ต่ำกว่าจุดสั่งซื้อ/ผลิต</div>
                         </a>`;
                total += data.badge_stock;
            }

            if (data.badge_expire > 0) {
                html += `<a href="/my_system/inventory/stock.php" class="noti-item">
                            <div class="noti-icon" style="background:#e74a3b;"><i class="fa-solid fa-calendar-xmark"></i></div>
                            <div class="noti-text"><strong>สินค้าค้างสต็อกนาน!</strong><br>มีสินค้า (FG) จำนวน <span style="color:#e74a3b; font-weight:bold;">${data.badge_expire}</span> ล็อต จัดเก็บนานเกิน 90 วัน</div>
                         </a>`;
                total += data.badge_expire;
            }

            if (data.badge_production > 0) {
                html += `<a href="/my_system/production/create_production.php" class="noti-item">
                            <div class="noti-icon" style="background:#858796;"><i class="fa-solid fa-gears"></i></div>
                            <div class="noti-text"><strong>ใบสั่งผลิตใหม่</strong><br>มีออเดอร์ผลิต <span style="color:#e74a3b; font-weight:bold;">${data.badge_production}</span> รายการ ที่ต้องดำเนินการด่วน</div>
                         </a>`;
                total += data.badge_production;
            }

            if (data.badge_qa_inbound > 0) {
                html += `<a href="/my_system/qa/qa_inbound.php" class="noti-item">
                            <div class="noti-icon" style="background:#f6c23e;"><i class="fa-solid fa-truck-droplet"></i></div>
                            <div class="noti-text"><strong>รอตรวจวัตถุดิบ (QA Inbound)</strong><br>มีวัตถุดิบเพิ่งรับเข้า <span style="color:#e74a3b; font-weight:bold;">${data.badge_qa_inbound}</span> ล็อต รอกักกันตรวจสอบ</div>
                         </a>`;
                total += data.badge_qa_inbound;
            }

            if (data.badge_qa_outbound > 0) {
                html += `<a href="/my_system/qa/qa_outbound.php" class="noti-item">
                            <div class="noti-icon" style="background:#1cc88a;"><i class="fa-solid fa-box-open"></i></div>
                            <div class="noti-text"><strong>รอตรวจสินค้า (QA Outbound)</strong><br>มีสินค้าเพิ่งผลิตเสร็จ <span style="color:#e74a3b; font-weight:bold;">${data.badge_qa_outbound}</span> ล็อต รอกักกันตรวจสอบ</div>
                         </a>`;
                total += data.badge_qa_outbound;
            }

            if (data.badge_maintenance > 0) {
                html += `<a href="/my_system/maintenance/manage_maintenance.php" class="noti-item">
                            <div class="noti-icon" style="background:#e74a3b;"><i class="fa-solid fa-screwdriver-wrench"></i></div>
                            <div class="noti-text"><strong>แจ้งซ่อมเครื่องจักร</strong><br>มีงานซ่อมบำรุง <span style="color:#e74a3b; font-weight:bold;">${data.badge_maintenance}</span> รายการ ที่กำลังรอช่างรับงาน</div>
                         </a>`;
                total += data.badge_maintenance;
            }

            if (data.badge_payment > 0) {
                html += `<a href="/my_system/sales/payment_tracker.php" class="noti-item">
                            <div class="noti-icon" style="background:#1cc88a;"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                            <div class="noti-text"><strong>ลูกหนี้รอเรียกเก็บ (AR)</strong><br>มีบิลขาย <span style="color:#e74a3b; font-weight:bold;">${data.badge_payment}</span> รายการ รอฝ่ายบัญชีติดตามทวงถาม</div>
                         </a>`;
                total += data.badge_payment;
            }

            if (data.badge_ap > 0) {
                html += `<a href="/my_system/purchase/payment_supplier.php" class="noti-item">
                            <div class="noti-icon" style="background:#e74a3b;"><i class="fa-solid fa-money-bill-transfer"></i></div>
                            <div class="noti-text"><strong>รอจ่ายเงินซัพพลายเออร์ (AP)</strong><br>มีบิลสั่งซื้อ (PO) <span style="color:#e74a3b; font-weight:bold;">${data.badge_ap}</span> รายการ ที่รับของแล้วรอฝ่ายบัญชีโอนเงิน</div>
                         </a>`;
                total += data.badge_ap;
            }
            
            if (data.badge_delivery > 0) {
                html += `<a href="/my_system/logistics/manage_delivery.php" class="noti-item">
                            <div class="noti-icon" style="background:#0ea5e9;"><i class="fa-solid fa-truck-fast"></i></div>
                            <div class="noti-text"><strong>รอจัดคิวรถ (จัดส่ง)</strong><br>มีบิลขายจำนวน <span style="color:#e74a3b; font-weight:bold;">${data.badge_delivery}</span> ใบ ที่รอการจัดคิวรถ</div>
                         </a>`;
                total += data.badge_delivery;
            }

            if (html === '') {
                html = `<div class="noti-empty">
                            <i class="fa-regular fa-face-smile-beam fa-2x" style="color:#d1d3e2; margin-bottom:15px;"></i><br>
                            ไม่มีการแจ้งเตือนใหม่ในขณะนี้<br>ทุกอย่างเรียบร้อยดี!
                        </div>`;
            }

            if (list.innerHTML !== html) {
                list.innerHTML = html;
            }
            
            updateBadges('top-bell-badge', total);
        }

        function fetchNotifications() {
            fetch('/my_system/api_notifications.php')
                .then(response => response.json())
                .then(data => {
                    updateBadges('badge-po', data.badge_po);
                    updateBadges('badge-po-receive', data.badge_po_receive);
                    updateBadges('badge-stock', data.badge_stock);
                    updateBadges('badge-production', data.badge_production);
                    updateBadges('badge-qa-inbound', data.badge_qa_inbound);
                    updateBadges('badge-qa-outbound', data.badge_qa_outbound);
                    updateBadges('badge-ot', data.badge_ot);
                    updateBadges('badge-leave', data.badge_leave);
                    updateBadges('badge-maintenance', data.badge_maintenance);
                    updateBadges('badge-approve', data.badge_approve); 
                    updateBadges('badge-payment', data.badge_payment);
                    updateBadges('badge-ap', data.badge_ap); 
                    updateBadges('badge-delivery', data.badge_delivery); 
                    
                    updateBadges('badge-approve-main', data.badge_approve);
                    updateBadges('badge-sales-main', data.badge_payment + data.badge_ap); 
                    updateBadges('badge-wh-main', data.badge_po_receive + data.badge_stock);
                    updateBadges('badge-prod-main', data.badge_production);
                    updateBadges('badge-qa-main', data.badge_qa_inbound + data.badge_qa_outbound);
                    updateBadges('badge-pur-main', data.badge_po);
                    updateBadges('badge-mnt-main', data.badge_maintenance);
                    updateBadges('badge-delivery-main', data.badge_delivery); 

                    renderNotificationDropdown(data);
                })
                .catch(error => console.error('Error fetching notifications:', error));
        }
        
        fetchNotifications();
        setInterval(fetchNotifications, 10000); 
        <?php endif; ?>
    </script>
    
    <div class="content-padding">