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

// 🚀 สร้างตารางเก็บประวัติแจ้งซ่อม (ถ้ายังไม่มี)
$check_table = mysqli_query($conn, "SHOW TABLES LIKE 'maintenance_requests'");
if (mysqli_num_rows($check_table) == 0) {
    mysqli_query($conn, "CREATE TABLE maintenance_requests (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        req_no VARCHAR(50) NOT NULL,
        machine_name VARCHAR(255) NOT NULL,
        problem_detail TEXT NOT NULL,
        urgency VARCHAR(50) NOT NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        reported_by VARCHAR(100) NOT NULL,
        technician VARCHAR(100) DEFAULT NULL,
        fix_detail TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

$status = '';
// 🚀 1. บันทึกแจ้งซ่อมใหม่
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_req'])) {
    $req_no = "MT-" . date("Ym") . "-" . rand(1000, 9999);
    $machine = mysqli_real_escape_string($conn, $_POST['machine_name']);
    $problem = mysqli_real_escape_string($conn, $_POST['problem_detail']);
    $urgency = mysqli_real_escape_string($conn, $_POST['urgency']);

    $sql = "INSERT INTO maintenance_requests (req_no, machine_name, problem_detail, urgency, reported_by) 
            VALUES ('$req_no', '$machine', '$problem', '$urgency', '$fullname')";
            
    if (mysqli_query($conn, $sql)) {
        if(function_exists('log_event')) { log_event($conn, 'INSERT', 'maintenance_requests', "แจ้งซ่อมเครื่องจักร $machine (อาการ: $problem)"); }

        // แจ้งเตือน LINE หาทีมช่าง
        include_once '../line_api.php';
        $emoji = ($urgency == 'Urgent') ? "🚨" : "🔧";
        $msg = "$emoji [ระบบแจ้งซ่อม] มีงานซ่อมบำรุงเข้าใหม่!\n\n";
        $msg .= "🔖 รหัสแจ้งซ่อม: $req_no\n🏭 เครื่องจักร/อุปกรณ์: $machine\n💬 อาการเสีย: $problem\n";
        $msg .= "⚡ ความเร่งด่วน: $urgency\n👤 ผู้แจ้ง: $fullname\n\n👉 ทีมช่างซ่อมบำรุงโปรดเข้าตรวจสอบและรับงานด้วยครับ";
        if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

        header("Location: request_maintenance.php?status=success"); exit;
    }
}

// 🚀 2. สำหรับช่างซ่อม กดรับงาน หรือ อัปเดตสถานะ
if (isset($_POST['update_status']) && ($user_role == 'ADMIN' || $user_dept == 'ฝ่ายซ่อมบำรุง')) {
    $req_id = (int)$_POST['req_id'];
    $new_status = mysqli_real_escape_string($conn, $_POST['new_status']);
    $fix_detail = mysqli_real_escape_string($conn, $_POST['fix_detail']);
    
    mysqli_query($conn, "UPDATE maintenance_requests SET status = '$new_status', technician = '$fullname', fix_detail = '$fix_detail' WHERE id = $req_id");
    
    if(function_exists('log_event')) { log_event($conn, 'UPDATE', 'maintenance_requests', "ช่าง ($fullname) อัปเดตงานซ่อม ID-$req_id เป็นสถานะ $new_status"); }
    
    // หากซ่อมเสร็จ แจ้ง LINE
    if ($new_status == 'Completed') {
        include_once '../line_api.php';
        $msg = "✅ [อัปเดตงานซ่อม] ซ่อมเสร็จเรียบร้อย!\n\n🔖 อ้างอิง ID: $req_id\nช่างผู้รับผิดชอบ: $fullname\n💬 รายละเอียดการซ่อม: $fix_detail\n\n👉 พนักงานสามารถกลับมาใช้งานเครื่องจักรได้ตามปกติครับ";
        if(function_exists('sendLineMessage')) { sendLineMessage($msg); }
    }
    header("Location: request_maintenance.php?status=updated"); exit;
}

include '../sidebar.php';
?>

<title>แจ้งซ่อมเครื่องจักร | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    .wrapper { font-family: 'Sarabun', sans-serif; animation: fadeIn 0.4s ease; }
    .card { background: #ffffff; padding: 25px 30px; border-radius: 16px; box-shadow: 0 5px 20px rgba(0,0,0,0.03); margin-bottom: 25px; border-top: 5px solid #e74a3b; }
    .form-control { width: 100%; padding: 12px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-family: 'Sarabun'; box-sizing: border-box; }
    .btn-submit { background: linear-gradient(135deg, #e74a3b 0%, #be2617 100%); color: white; border: none; padding: 12px 25px; border-radius: 10px; font-weight: bold; cursor: pointer; float: right; font-family: 'Sarabun'; }
    table { width: 100%; border-collapse: collapse; min-width: 800px; }
    .table-responsive { width: 100%; overflow-x: auto; border-radius: 8px; }
    th { background: #f8f9fc; padding: 12px; text-align: left; border-bottom: 2px solid #eaecf4; }
    td { padding: 12px; border-bottom: 1px solid #eaecf4; vertical-align: top; }
    .badge { padding: 5px 12px; border-radius: 50px; font-size: 12px; font-weight: bold; }
    .bg-pending { background: #fff3cd; color: #856404; }
    .bg-progress { background: #cce5ff; color: #004085; }
    .bg-completed { background: #d4edda; color: #155724; }
    .modal-overlay { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; }
    .modal-content { background: white; padding: 25px; border-radius: 16px; width: 90%; max-width: 500px; }
</style>

<div class="content-padding">
    <div class="wrapper">
        <h2 style="color: #2c3e50; margin-top:0;"><i class="fa-solid fa-toolbox" style="color: #e74a3b;"></i> ระบบแจ้งซ่อม (Maintenance)</h2>

        <div class="card">
            <h4 style="margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> แจ้งปัญหาเครื่องจักร / อุปกรณ์เสีย</h4>
            <form method="POST">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
                    <div>
                        <label style="font-weight:bold; color:#555;">ชื่อเครื่องจักร / บริเวณที่เสีย <span style="color:red;">*</span></label>
                        <input type="text" name="machine_name" class="form-control" placeholder="เช่น เครื่องโม่เบอร์ 2, ปั๊มน้ำ, สายพานลำเลียง" required>
                    </div>
                    <div>
                        <label style="font-weight:bold; color:#555;">ระดับความเร่งด่วน <span style="color:red;">*</span></label>
                        <select name="urgency" class="form-control" required>
                            <option value="Normal">🟢 ปกติ (ซ่อมภายใน 1-2 วัน)</option>
                            <option value="High">🟡 สูง (ควรรีบซ่อม)</option>
                            <option value="Urgent" style="color:red; font-weight:bold;">🔴 ด่วนมาก! (การผลิตหยุดชะงัก)</option>
                        </select>
                    </div>
                </div>
                <div style="margin-bottom:15px;">
                    <label style="font-weight:bold; color:#555;">อาการที่พบ <span style="color:red;">*</span></label>
                    <textarea name="problem_detail" rows="2" class="form-control" placeholder="อธิบายอาการเสียให้ช่างเตรียมเครื่องมือถูก..." required></textarea>
                </div>
                <div style="overflow:hidden;">
                    <button type="submit" name="add_req" class="btn-submit"><i class="fa-solid fa-paper-plane"></i> ส่งเรื่องแจ้งซ่อม</button>
                </div>
            </form>
        </div>

        <div class="card" style="border-top-color: #4e73df;">
            <h4 style="margin-top:0; color:#4e73df;"><i class="fa-solid fa-list"></i> รายการแจ้งซ่อมล่าสุด</h4>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>วันที่แจ้ง</th><th>เลขที่ / เครื่องจักร</th><th>อาการ</th><th>ผู้แจ้ง</th><th>สถานะ</th><th style="text-align:right;">ช่างดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $q_req = mysqli_query($conn, "SELECT * FROM maintenance_requests ORDER BY id DESC LIMIT 50");
                        if ($q_req && mysqli_num_rows($q_req) > 0) {
                            while($r = mysqli_fetch_assoc($q_req)) {
                                $badge = "";
                                if($r['status'] == 'Pending') $badge = "<span class='badge bg-pending'>รอรับงาน</span>";
                                elseif($r['status'] == 'In Progress') $badge = "<span class='badge bg-progress'>กำลังซ่อม</span>";
                                else $badge = "<span class='badge bg-completed'>ซ่อมเสร็จ</span>";
                        ?>
                        <tr>
                            <td><small><?= date('d/m/y H:i', strtotime($r['created_at'])) ?></small><br><strong style="color:<?= ($r['urgency']=='Urgent')?'red':(($r['urgency']=='High')?'orange':'green') ?>;">[<?= $r['urgency'] ?>]</strong></td>
                            <td><strong style="color:#4e73df;"><?= $r['req_no'] ?></strong><br><strong><?= htmlspecialchars($r['machine_name']) ?></strong></td>
                            <td><?= htmlspecialchars($r['problem_detail']) ?></td>
                            <td><?= htmlspecialchars($r['reported_by']) ?></td>
                            <td><?= $badge ?><br><small><?= $r['technician'] ?></small></td>
                            <td style="text-align:right;">
                                <?php if ($user_role == 'ADMIN' || $user_dept == 'ฝ่ายซ่อมบำรุง'): ?>
                                    <button class="btn-submit" style="background:#4e73df; padding:6px 12px; float:none; font-size:13px;" onclick="openUpdateModal(<?= $r['id'] ?>, '<?= $r['req_no'] ?>', '<?= $r['status'] ?>', '<?= htmlspecialchars($r['fix_detail'] ?? '') ?>')">
                                        อัปเดตงาน
                                    </button>
                                <?php else: ?>
                                    <span style="color:#ccc;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php } } else { echo "<tr><td colspan='6' style='text-align:center; padding:30px;'>ไม่มีรายการแจ้งซ่อม</td></tr>"; } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="updateModal" class="modal-overlay">
    <div class="modal-content">
        <h3 style="color:#4e73df; margin-top:0;">อัปเดตสถานะงานซ่อม <span id="mod_req_no"></span></h3>
        <form method="POST">
            <input type="hidden" name="req_id" id="mod_req_id">
            <div style="margin-bottom:15px;">
                <label style="font-weight:bold;">สถานะปัจจุบัน <span style="color:red;">*</span></label>
                <select name="new_status" id="mod_status" class="form-control" required>
                    <option value="Pending">รอรับงาน (Pending)</option>
                    <option value="In Progress">กำลังดำเนินการ (In Progress)</option>
                    <option value="Completed" style="color:green; font-weight:bold;">ซ่อมเสร็จแล้ว (Completed)</option>
                </select>
            </div>
            <div style="margin-bottom:15px;">
                <label style="font-weight:bold;">รายละเอียดการซ่อม / สาเหตุที่เจอ (ถ้ามี)</label>
                <textarea name="fix_detail" id="mod_fix_detail" rows="3" class="form-control" placeholder="เปลี่ยนอะไหล่ตัวไหนบ้าง อธิบายที่นี่..."></textarea>
            </div>
            <div style="display:flex; justify-content:space-between; gap:10px;">
                <button type="button" class="form-control" style="background:#eaecf4; width:30%; cursor:pointer; text-align:center; border:none; font-weight:bold;" onclick="document.getElementById('updateModal').style.display='none'">ยกเลิก</button>
                <button type="submit" name="update_status" class="form-control" style="background:#4e73df; color:white; width:70%; cursor:pointer; text-align:center; border:none; font-weight:bold;">บันทึกอัปเดต</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openUpdateModal(id, req_no, status, fix_detail) {
        document.getElementById('mod_req_id').value = id;
        document.getElementById('mod_req_no').innerText = req_no;
        document.getElementById('mod_status').value = status;
        document.getElementById('mod_fix_detail').value = fix_detail;
        document.getElementById('updateModal').style.display = 'flex';
    }
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('status') === 'success') { Swal.fire({icon: 'success', title: 'แจ้งซ่อมสำเร็จ!', timer: 2000, showConfirmButton: false}).then(()=>window.history.replaceState(null,null,window.location.pathname)); }
    else if (urlParams.get('status') === 'updated') { Swal.fire({icon: 'success', title: 'อัปเดตงานซ่อมสำเร็จ', timer: 1500, showConfirmButton: false}).then(()=>window.history.replaceState(null,null,window.location.pathname)); }
</script>
</body>
</html>