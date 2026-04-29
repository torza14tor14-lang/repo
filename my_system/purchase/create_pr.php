<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
$fullname = $_SESSION['fullname'] ?? $_SESSION['username'];

// 🚀 [Auto-Create Table] สร้างตารางเก็บใบขอซื้ออัตโนมัติ
$check_table = mysqli_query($conn, "SHOW TABLES LIKE 'purchase_requests'");
if (mysqli_num_rows($check_table) == 0) {
    mysqli_query($conn, "CREATE TABLE purchase_requests (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        pr_no VARCHAR(50) NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        qty DECIMAL(10,2) NOT NULL,
        unit VARCHAR(50) NOT NULL,
        reason TEXT,
        request_by VARCHAR(100) NOT NULL,
        request_dept VARCHAR(100) NOT NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        approved_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}

// 🚀 1. บันทึกการขอซื้อใหม่ (Create PR)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_pr'])) {
    $prefix = "PR-" . date("Ym");
    $q_last = mysqli_query($conn, "SELECT pr_no FROM purchase_requests WHERE pr_no LIKE '$prefix%' ORDER BY id DESC LIMIT 1");
    $run_no = 1;
    if ($q_last && $row_last = mysqli_fetch_assoc($q_last)) {
        $last_no = intval(substr($row_last['pr_no'], -3));
        $run_no = $last_no + 1;
    }
    $pr_no = $prefix . "-" . str_pad($run_no, 3, "0", STR_PAD_LEFT);

    $item_name = mysqli_real_escape_string($conn, $_POST['item_name']);
    $qty = (float)$_POST['qty'];
    $unit = mysqli_real_escape_string($conn, $_POST['unit']);
    $reason = mysqli_real_escape_string($conn, $_POST['reason']);

    $sql = "INSERT INTO purchase_requests (pr_no, item_name, qty, unit, reason, request_by, request_dept) 
            VALUES ('$pr_no', '$item_name', $qty, '$unit', '$reason', '$fullname', '$user_dept')";
    
    if (mysqli_query($conn, $sql)) {
        if(function_exists('log_event')) { log_event($conn, 'INSERT', 'purchase_requests', "สร้างใบขอซื้อใหม่: $item_name จำนวน $qty (โดย: $fullname)"); }

        // แจ้งเตือน LINE ให้ผู้จัดการ
        include_once '../line_api.php';
        $msg = "📝 มีใบขอสั่งซื้อ (PR) ใหม่เข้าสู่ระบบ!\n\n";
        $msg .= "🔖 เลขที่: $pr_no\n👤 ผู้ขอ: $fullname ($user_dept)\n📦 รายการ: $item_name\n⚖️ จำนวน: " . number_format($qty, 2) . " $unit\n💬 เหตุผล: $reason\n\n👉 ผู้จัดการโปรดเข้าสู่ระบบเพื่อพิจารณาอนุมัติครับ";
        if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

        header("Location: create_pr.php?status=added"); exit;
    }
}

// 🚀 2. ผู้บริหาร/ผู้จัดการ กดอนุมัติหรือปฏิเสธ
if (isset($_GET['action']) && isset($_GET['id']) && ($user_role == 'ADMIN' || $user_role == 'MANAGER')) {
    $id = (int)$_GET['id'];
    $action = $_GET['action'];
    $new_status = ($action == 'approve') ? 'Approved' : 'Rejected';

    $q_info = mysqli_query($conn, "SELECT * FROM purchase_requests WHERE id = $id");
    if($q_info && $row = mysqli_fetch_assoc($q_info)) {
        mysqli_query($conn, "UPDATE purchase_requests SET status = '$new_status', approved_by = '$fullname' WHERE id = $id");

        if(function_exists('log_event')) { log_event($conn, 'UPDATE', 'purchase_requests', "ผู้จัดการ $new_status ใบขอซื้อ {$row['pr_no']} ({$row['item_name']})"); }

        // แจ้งเตือน LINE ให้จัดซื้อไปเปิดบิลต่อ
        include_once '../line_api.php';
        if ($new_status == 'Approved') {
            $msg = "✅ ใบขอสั่งซื้อ [{$row['pr_no']}] ได้รับการ 'อนุมัติ' แล้ว!\n\n📦 รายการ: {$row['item_name']}\nผู้อนุมัติ: $fullname\n\n👉 ฝ่ายจัดซื้อสามารถดำเนินการไปที่เมนู 'จัดการใบขอซื้อ' เพื่อสั่งของได้เลยครับ";
        } else {
            $msg = "❌ ใบขอสั่งซื้อ [{$row['pr_no']}] 'ถูกปฏิเสธ'\n\n📦 รายการ: {$row['item_name']}\nผู้พิจารณา: $fullname";
        }
        if(function_exists('sendLineMessage')) { sendLineMessage($msg); }
        
        header("Location: create_pr.php?status=updated"); exit;
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    mysqli_query($conn, "DELETE FROM purchase_requests WHERE id = $id");
    header("Location: create_pr.php?status=deleted"); exit;
}

include '../sidebar.php';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Top Feed Mills | ใบขอสั่งซื้อ (PR)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .wrapper { font-family: 'Sarabun', sans-serif; animation: fadeIn 0.5s ease-in-out; }
        .container-stacked { display: flex; flex-direction: column; gap: 25px; width: 100%; }
        .card { background: #ffffff; padding: 25px 30px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); border: 1px solid #f0f0f0; width: 100%; box-sizing: border-box; }
        h3 { color: #2c3e50; margin-top: 0; margin-bottom: 20px; font-weight: 600; border-bottom: 2px solid #f1f2f6; padding-bottom: 12px; display: flex; align-items: center; gap: 10px; }
        .form-grid-3 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 15px; margin-bottom: 15px; }
        .form-grid-1 { display: grid; grid-template-columns: 1fr; gap: 15px; margin-bottom: 15px; }
        @media (max-width: 768px) { .form-grid-3 { grid-template-columns: 1fr; } }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #4a5568; font-size: 0.9rem; }
        input, select, textarea { width: 100%; padding: 10px 15px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-family: 'Sarabun'; font-size: 1rem; box-sizing: border-box; }
        .btn-submit { background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); color: white; border: none; padding: 12px 20px; border-radius: 10px; cursor: pointer; font-weight: bold; font-size: 1rem; width: 100%; margin-top: 5px; font-family: 'Sarabun';}
        .table-responsive { overflow-x: auto; width: 100%; border-radius: 10px; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 1000px; }
        th, td { padding: 15px; border-bottom: 1px solid #f0f0f0; text-align: left; vertical-align: middle; }
        th { background: #f8f9fa; color: #6c757d; font-weight: bold; font-size: 0.85rem; text-transform: uppercase; white-space: nowrap; }
        .badge-status { padding: 5px 12px; border-radius: 50px; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
        .st-pending { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .st-approved { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .st-rejected { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .st-ordered { background: #e3f2fd; color: #0d47a1; border: 1px solid #bbdefb; }
        .btn-action { padding: 6px 10px; border-radius: 6px; font-size: 0.85rem; font-weight: bold; text-decoration: none; transition: 0.2s; border:none; cursor:pointer;}
        .btn-approve { background: #1cc88a; color: white; }
        .btn-reject { background: #e74a3b; color: white; }
        .btn-delete { background: #eaecf4; color: #858796; }
    </style>
</head>
<body>
<div class="content-padding">
    <div class="wrapper">
        <div class="container-stacked">
            
            <div class="card" style="border-top: 4px solid #4e73df;">
                <h3><i class="fa-solid fa-pen-to-square" style="color: #4e73df;"></i> สร้างใบขอสั่งซื้อ/ขอเบิก (PR)</h3>
                <form method="POST">
                    <div class="form-grid-3">
                        <div><label>รายการที่ต้องการขอซื้อ <span style="color:red;">*</span></label><input type="text" name="item_name" placeholder="เช่น คอมพิวเตอร์, กระดาษ A4, อะไหล่" required></div>
                        <div><label>จำนวน <span style="color:red;">*</span></label><input type="number" step="0.01" name="qty" placeholder="0" required></div>
                        <div><label>หน่วยนับ <span style="color:red;">*</span></label>
                            <select name="unit" required>
                                <option value="ชิ้น">ชิ้น / อัน</option><option value="เครื่อง">เครื่อง</option><option value="กล่อง">กล่อง / แพ็ค</option>
                                <option value="รีม">รีม</option><option value="ชุด">ชุด</option><option value="กิโลกรัม">กิโลกรัม</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-grid-1">
                        <div><label>เหตุผลความจำเป็น / นำไปใช้ทำอะไร <span style="color:red;">*</span></label><textarea name="reason" rows="2" placeholder="ระบุเหตุผลเพื่อประกอบการอนุมัติ..." required></textarea></div>
                    </div>
                    <button type="submit" name="add_pr" class="btn-submit"><i class="fa-solid fa-paper-plane"></i> ยืนยันการขอซื้อ (ส่งเรื่องให้หัวหน้า)</button>
                </form>
            </div>

            <div class="card" style="border-top: 4px solid #f6c23e;">
                <h3><i class="fa-solid fa-list-check" style="color: #f6c23e;"></i> รายการขอซื้อของคุณ และรอการอนุมัติ</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>เลขที่ PR</th><th>ผู้ขอเบิก (แผนก)</th><th>รายการ</th><th>จำนวน</th><th>เหตุผล</th><th>สถานะ</th><th style="text-align:right;">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $where_clause = ($user_role == 'ADMIN' || $user_role == 'MANAGER') ? "" : "WHERE request_by = '$fullname'";
                            $q_history = mysqli_query($conn, "SELECT * FROM purchase_requests $where_clause ORDER BY id DESC LIMIT 50");
                            if ($q_history && mysqli_num_rows($q_history) > 0) {
                                while($r = mysqli_fetch_assoc($q_history)) {
                                    if ($r['status'] == 'Approved') $badge = "<span class='badge-status st-approved'><i class='fa-solid fa-check'></i> อนุมัติแล้ว (รอจัดซื้อ)</span>";
                                    elseif ($r['status'] == 'Ordered') $badge = "<span class='badge-status st-ordered'><i class='fa-solid fa-truck'></i> จัดซื้อสั่งของแล้ว</span>";
                                    elseif ($r['status'] == 'Rejected') $badge = "<span class='badge-status st-rejected'><i class='fa-solid fa-xmark'></i> ไม่อนุมัติ</span>";
                                    else $badge = "<span class='badge-status st-pending'><i class='fa-solid fa-clock'></i> รอพิจารณา</span>";
                            ?>
                            <tr>
                                <td><strong style="color:#4e73df;"><?= $r['pr_no'] ?></strong><br><small><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></small></td>
                                <td><?= htmlspecialchars($r['request_by']) ?><br><small style="color:#888;"><?= htmlspecialchars($r['request_dept']) ?></small></td>
                                <td><strong><?= htmlspecialchars($r['item_name']) ?></strong></td>
                                <td><strong style="color:#e74a3b;"><?= number_format($r['qty'], 2) ?></strong> <small><?= htmlspecialchars($r['unit']) ?></small></td>
                                <td><?= htmlspecialchars($r['reason']) ?></td>
                                <td><?= $badge ?></td>
                                <td style="text-align:right;">
                                    <?php if (($user_role == 'ADMIN' || $user_role == 'MANAGER') && $r['status'] == 'Pending'): ?>
                                        <a href="?action=approve&id=<?= $r['id'] ?>" class="btn-action btn-approve" onclick="return confirm('อนุมัติให้จัดซื้อดำเนินการ?')">อนุมัติ</a>
                                        <a href="?action=reject&id=<?= $r['id'] ?>" class="btn-action btn-reject" onclick="return confirm('ปฏิเสธรายการนี้?')">ไม่อนุมัติ</a>
                                    <?php endif; ?>
                                    <?php if ($r['status'] == 'Pending' && ($user_role == 'ADMIN' || $r['request_by'] == $fullname)): ?>
                                        <a href="?delete=<?= $r['id'] ?>" class="btn-action btn-delete" onclick="return confirm('ต้องการยกเลิกคำขอนี้?')"><i class="fa-solid fa-trash"></i></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php } } else { echo "<tr><td colspan='7' style='text-align:center; padding:30px; color:#888;'>ยังไม่มีประวัติการขอซื้อ</td></tr>"; } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('status') === 'added') { Swal.fire({ icon: 'success', title: 'ส่งคำขอสำเร็จ!', text: 'ส่ง LINE แจ้งเตือนหัวหน้าแล้ว', timer: 2000, showConfirmButton: false }).then(()=>window.history.replaceState(null,null,window.location.pathname)); }
    else if (urlParams.get('status') === 'updated') { Swal.fire({ icon: 'success', title: 'อัปเดตสถานะสำเร็จ!', timer: 1500, showConfirmButton: false }).then(()=>window.history.replaceState(null,null,window.location.pathname)); }
</script>
</body>
</html>