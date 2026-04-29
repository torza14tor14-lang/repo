<?php
session_start();
include '../db.php';

// ตรวจสอบการล็อกอิน
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';

// ดักจับชื่อพนักงานให้ปลอดภัย
$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'System Admin';

// เช็คสิทธิ์ (ADMIN, MANAGER, ฝ่ายสินเชื่อ, ฝ่ายการเงิน)
$allowed_depts = ['ฝ่ายสินเชื่อ', 'ฝ่ายการเงิน', 'ฝ่ายบัญชี', 'บัญชี - ท็อปธุรกิจ'];
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $allowed_depts)) { 
    echo "<script>alert('เฉพาะฝ่ายสินเชื่อและผู้บริหารเท่านั้น'); window.location='../index.php';</script>"; exit(); 
}

// 🚀 [Auto-Update Table] เช็คและเพิ่มคอลัมน์ credit_limit ในตาราง customers ถ้ายังไม่มี
$check_col = mysqli_query($conn, "SHOW COLUMNS FROM `customers` LIKE 'credit_limit'");
if (mysqli_num_rows($check_col) == 0) {
    mysqli_query($conn, "ALTER TABLE `customers` ADD `credit_limit` DECIMAL(15,2) DEFAULT 0.00 AFTER `cus_tax_id`");
}

// 🚀 [Auto-Create Table] สร้างตารางเก็บคำขอวงเงินเครดิต
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS credit_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    requested_limit DECIMAL(15,2) NOT NULL,
    reason TEXT,
    status VARCHAR(50) DEFAULT 'Pending',
    requested_by VARCHAR(100),
    approved_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

// ดึงรายชื่อลูกค้ามาทำ Dropdown
$cus_options = "<option value=''>-- พิมพ์ค้นหาชื่อลูกค้า / ฟาร์ม --</option>";
$q_all_cus = mysqli_query($conn, "SELECT id, cus_name, credit_limit FROM customers ORDER BY cus_name ASC");
if ($q_all_cus) {
    while($c = mysqli_fetch_assoc($q_all_cus)) {
        $limit_text = number_format($c['credit_limit'], 2);
        $cus_options .= "<option value='{$c['id']}'>👤 {$c['cus_name']} (วงเงินปัจจุบัน: $limit_text ฿)</option>";
    }
}

// 🚀 1. จำลองการสร้างคำขอ
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_test_req'])) {
    $cust_id = (int)$_POST['customer_id'];
    $req_limit = (float)$_POST['req_limit'];
    
    $q_cust = mysqli_query($conn, "SELECT id FROM customers WHERE id = '$cust_id' LIMIT 1");
    if ($q_cust && mysqli_num_rows($q_cust) > 0) {
        $req_by_name = mysqli_real_escape_string($conn, $fullname);
        mysqli_query($conn, "INSERT INTO credit_requests (customer_id, requested_limit, requested_by) VALUES ($cust_id, $req_limit, '$req_by_name (ระบบจำลอง)')");
        header("Location: credit_approval.php?status=req_added"); exit;
    } else {
        echo "<script>alert('ไม่พบข้อมูลลูกค้าในระบบ!');</script>";
    }
}

// 🚀 2. จัดการเมื่อฝ่ายสินเชื่อกด "อนุมัติ" หรือ "ปฏิเสธ"
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $req_id = (int)$_POST['req_id'];
    $action = $_POST['action']; 
    $remark = mysqli_real_escape_string($conn, $_POST['remark'] ?? '');

    $q_req = mysqli_query($conn, "SELECT cr.customer_id, cr.requested_limit, c.cus_name 
                                   FROM credit_requests cr 
                                   JOIN customers c ON cr.customer_id = c.id 
                                   WHERE cr.id = $req_id");
                                   
    if ($q_req && $r_req = mysqli_fetch_assoc($q_req)) {
        $customer_id = (int)$r_req['customer_id'];
        $req_limit = (float)$r_req['requested_limit'];
        $customer_name = $r_req['cus_name'];

        if ($action == 'APPROVE') {
            mysqli_query($conn, "UPDATE customers SET credit_limit = $req_limit WHERE id = $customer_id");
            $approved_by_name = mysqli_real_escape_string($conn, $fullname);
            mysqli_query($conn, "UPDATE credit_requests SET status = 'Approved', approved_by = '$approved_by_name' WHERE id = $req_id");

            include_once '../line_api.php';
            $msg = "✅ [ฝ่ายสินเชื่อ] อนุมัติวงเงินเครดิตสำเร็จ!\n\n🏢 ลูกค้า: $customer_name\n💰 วงเงินใหม่: " . number_format($req_limit, 2) . " บาท\nผู้อนุมัติ: $fullname";
            if(function_exists('sendLineMessage')) { sendLineMessage($msg); }
            header("Location: credit_approval.php?status=approved"); exit;
            
        } elseif ($action == 'REJECT') {
            $approved_by_name = mysqli_real_escape_string($conn, $fullname);
            mysqli_query($conn, "UPDATE credit_requests SET status = 'Rejected', approved_by = '$approved_by_name' WHERE id = $req_id");

            include_once '../line_api.php';
            $msg = "❌ [ฝ่ายสินเชื่อ] ไม่อนุมัติวงเงินเครดิต\n\n🏢 ลูกค้า: $customer_name\nยอดที่ขอ: " . number_format($req_limit, 2) . " บาท\nผู้พิจารณา: $fullname\n💬 เหตุผล: " . ($remark != '' ? $remark : 'ไม่ผ่านเกณฑ์พิจารณาสินเชื่อ');
            if(function_exists('sendLineMessage')) { sendLineMessage($msg); }
            header("Location: credit_approval.php?status=rejected"); exit;
        }
    }
}

include '../sidebar.php';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Top Feed Mills | อนุมัติวงเงินเครดิต</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .wrapper { font-family: 'Sarabun', sans-serif; animation: fadeIn 0.5s ease-in-out; }
        .container-stacked { display: flex; flex-direction: column; gap: 25px; width: 100%; }

        .card { 
            background: #ffffff; padding: 25px 30px; border-radius: 16px; 
            box-shadow: 0 5px 20px rgba(0,0,0,0.02); border: 1px solid #f0f0f0; width: 100%; box-sizing: border-box;
        }

        h3 { color: #2c3e50; margin-top: 0; margin-bottom: 20px; font-weight: 600; border-bottom: 2px solid #f1f2f6; padding-bottom: 12px; display: flex; align-items: center; gap: 10px; }
        
        /* Layout การจัดวางส่วนจำลองคำขอ */
        .admin-simulator-grid { 
            display: grid; 
            grid-template-columns: 2fr 1fr 150px; 
            gap: 15px; 
            align-items: flex-end; /* ทำให้ทุกอย่างฐานตรงกัน */
        }
        @media (max-width: 992px) { .admin-simulator-grid { grid-template-columns: 1fr; } }
        
        .form-group { text-align: left; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #4a5568; font-size: 0.85rem; }
        
        .form-control { 
            width: 100%; padding: 10px 15px; border: 1.5px solid #e2e8f0; border-radius: 10px; 
            font-family: 'Sarabun'; font-size: 1rem; transition: 0.3s; box-sizing: border-box; 
        }
        .form-control:focus { border-color: #4e73df; outline: none; box-shadow: 0 0 0 3px rgba(78, 115, 223, 0.1); }

        .btn-test { 
            background: #4e73df; color: white; border: none; height: 44px; border-radius: 10px; 
            font-weight: bold; cursor: pointer; transition: 0.3s; font-family: 'Sarabun'; width: 100%;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-test:hover { background: #2e59d9; transform: translateY(-1px); }

        /* Select2 Custom Styling */
        .select2-container--default .select2-selection--single { height: 40px; border: 1.5px solid #e2e8f0; border-radius: 10px; display: flex; align-items: center; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { padding-left: 15px; font-size: 1rem; color: #444; font-family: 'Sarabun'; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 38px; right: 10px; }

        /* Table Styling */
        .table-responsive { overflow-x: auto; width: 100%; border-radius: 12px; border: 1px solid #f0f0f0; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th, td { padding: 16px; border-bottom: 1px solid #f8f9fa; text-align: left; vertical-align: middle; }
        th { background: #f8f9fa; color: #6c757d; font-weight: bold; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:hover { background-color: #fcfcfc; }

        .badge-status { padding: 6px 14px; border-radius: 50px; font-size: 11px; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; }
        .st-approved { background: #e6fffa; color: #088a74; border: 1px solid #b2f5ea; }
        .st-rejected { background: #fff5f5; color: #c53030; border: 1px solid #fed7d7; }
        .st-wait { background: #fffaf0; color: #9c4221; border: 1px solid #feebc8; }

        .btn-action { 
            background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%); color: white; border: none; 
            padding: 8px 16px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.3s; 
            display: inline-flex; align-items: center; gap: 8px; font-family: 'Sarabun'; font-size: 14px;
        }
        .btn-action:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(28, 200, 138, 0.25); }

        /* Modal Customization */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(10, 25, 41, 0.7); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-content { background: white; border-radius: 20px; width: 90%; max-width: 550px; box-shadow: 0 20px 50px rgba(0,0,0,0.15); animation: pop 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); overflow: hidden; }
        @keyframes pop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .modal-header { padding: 20px 25px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; background: #fff; }
        .modal-header h3 { margin: 0; border: none; color: #1a202c; font-size: 1.2rem; }
        .modal-close { cursor: pointer; font-size: 20px; color: #a0aec0; transition: 0.2s; }
        .modal-body { padding: 30px; }

        .info-box { background: #f7fafc; padding: 20px; border-radius: 12px; margin-bottom: 25px; border: 1px solid #edf2f7; line-height: 1.6; }
        .info-box strong { color: #2d3748; }

        .modal-footer { display: flex; gap: 12px; margin-top: 10px; }
        .btn-modal { flex: 1; padding: 12px; border-radius: 10px; font-weight: bold; font-family: 'Sarabun'; cursor: pointer; border: none; transition: 0.2s; font-size: 15px; }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

<div class="content-padding">
    <div class="wrapper">
        <div class="container-stacked">

            <?php if($user_role == 'ADMIN'): ?>
            <div class="card" style="border-top: 4px solid #4e73df;">
                <h3><i class="fa-solid fa-vial-circle-check" style="color:#4e73df"></i> จำลองคำขอเพิ่มวงเงิน (Simulator)</h3>
                <form method="POST">
                    <div class="admin-simulator-grid">
                        <div class="form-group">
                            <label>เลือกลูกค้าในระบบ</label>
                            <select name="customer_id" class="form-control select2" required>
                                <?php echo $cus_options; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>วงเงินที่ร้องขอ (บาท)</label>
                            <input type="number" step="0.01" name="req_limit" class="form-control" required placeholder="เช่น 50000">
                        </div>
                        <button type="submit" name="create_test_req" class="btn-test">
                            <i class="fa-solid fa-paper-plane"></i> ส่งคำขอ
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
            
            <div class="card" style="border-top: 4px solid #f6c23e;">
                <h3><i class="fa-solid fa-clock-rotate-left" style="color: #f6c23e;"></i> รายการที่รอการพิจารณาสินเชื่อ</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:18%">วันที่ส่งคำขอ</th>
                                <th style="width:32%">ชื่อลูกค้า / บริษัท</th>
                                <th style="width:18%">วงเงินที่ขอ (บาท)</th>
                                <th style="width:17%">ผู้ส่งคำขอ</th>
                                <th style="width:15%; text-align:right;">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql_pending = "SELECT cr.*, c.cus_name FROM credit_requests cr JOIN customers c ON cr.customer_id = c.id WHERE cr.status = 'Pending' ORDER BY cr.created_at ASC";
                            $res_pending = mysqli_query($conn, $sql_pending);
                            if ($res_pending && mysqli_num_rows($res_pending) > 0) {
                                while($row = mysqli_fetch_assoc($res_pending)) {
                            ?>
                                <tr>
                                    <td><small style="color:#718096;"><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></small></td>
                                    <td><strong style="color:#2d3748;"><i class="fa-solid fa-building-user" style="color:#cbd5e0;"></i> <?= htmlspecialchars($row['cus_name']) ?></strong></td>
                                    <td><strong style="color:#e53e3e; font-size: 16px;"><?= number_format($row['requested_limit'], 2) ?></strong></td>
                                    <td><span style="font-size:13px; color:#4a5568;"><?= htmlspecialchars($row['requested_by']) ?></span></td>
                                    <td style="text-align:right;">
                                        <button type="button" class="btn-action" 
                                            data-id="<?= $row['id'] ?>" 
                                            data-name="<?= htmlspecialchars($row['cus_name']) ?>" 
                                            data-limit="<?= $row['requested_limit'] ?>" 
                                            onclick="openApproveModal(this)">
                                            พิจารณา
                                        </button>
                                    </td>
                                </tr>
                            <?php } } else { echo "<tr><td colspan='5' style='text-align:center; padding:50px; color:#a0aec0;'><i class='fa-solid fa-check-double fa-3x' style='margin-bottom:15px; opacity:0.3;'></i><br>เรียบร้อย! ไม่มีรายการค้างพิจารณา</td></tr>"; } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <h3><i class="fa-solid fa-history" style="color: #a0aec0;"></i> ประวัติการดำเนินการล่าสุด</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>วันที่พิจารณา</th>
                                <th>ลูกค้า</th>
                                <th>วงเงิน (บาท)</th>
                                <th>ผู้ดำเนินการ</th>
                                <th>สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql_history = "SELECT cr.*, c.cus_name FROM credit_requests cr JOIN customers c ON cr.customer_id = c.id WHERE cr.status != 'Pending' ORDER BY cr.id DESC LIMIT 15";
                            $res_history = mysqli_query($conn, $sql_history);
                            if ($res_history && mysqli_num_rows($res_history) > 0) {
                                while($row = mysqli_fetch_assoc($res_history)) {
                                    $badge = ($row['status'] == 'Approved') ? "st-approved" : "st-rejected";
                                    $icon = ($row['status'] == 'Approved') ? "fa-check-circle" : "fa-ban";
                                    $text = ($row['status'] == 'Approved') ? "อนุมัติแล้ว" : "ไม่อนุมัติ";
                            ?>
                                <tr>
                                    <td><small><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></small></td>
                                    <td><strong><?= htmlspecialchars($row['cus_name']) ?></strong></td>
                                    <td><?= number_format($row['requested_limit'], 2) ?></td>
                                    <td><small><?= htmlspecialchars($row['approved_by']) ?></small></td>
                                    <td><span class="badge-status <?= $badge ?>"><i class="fa-solid <?= $icon ?>"></i> <?= $text ?></span></td>
                                </tr>
                            <?php } } else { echo "<tr><td colspan='5' style='text-align:center; padding:30px; color:#888;'>ยังไม่มีประวัติในระบบ</td></tr>"; } ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<div id="approveModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>พิจารณาคำขอวงเงินเครดิต</h3>
            <div class="modal-close" onclick="closeApproveModal()"><i class="fa-solid fa-xmark"></i></div>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="req_id" id="mod_req_id">
                
                <div class="info-box">
                    <span style="color:#718096; font-size:13px;">ชื่อลูกค้า:</span><br>
                    <strong id="mod_cust_name" style="font-size:1.1rem;"></strong><br>
                    <div style="margin-top:10px;">
                        <span style="color:#718096; font-size:13px;">วงเงินที่ร้องขอ:</span><br>
                        <span id="mod_limit" style="font-size: 24px; font-weight: 800; color: #2d3748;"></span> <span style="font-weight:bold;">฿</span>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 25px;">
                    <label>หมายเหตุประกอบการพิจารณา (สำคัญมากกรณีไม่อนุมัติ)</label>
                    <textarea name="remark" rows="5" class="form-control" style="resize: none;" placeholder="ระบุเหตุผลที่อนุมัติ หรือสาเหตุที่ปฏิเสธเพื่อแจ้งฝ่ายขายทราบ..."></textarea>
                </div>

                <div class="modal-footer">
                    <button type="submit" name="action" value="REJECT" class="btn-modal" style="background:#fff1f0; color:#cf1322; border:1px solid #ffa39e;" onclick="return confirm('ยืนยันการ ปฏิเสธ?')">
                        <i class="fa-solid fa-times-circle"></i> ปฏิเสธคำขอ
                    </button>
                    <button type="submit" name="action" value="APPROVE" class="btn-modal" style="background:#4e73df; color:white; box-shadow:0 4px 10px rgba(78,115,223,0.3);" onclick="return confirm('ยืนยันการ อนุมัติ?')">
                        <i class="fa-solid fa-check-circle"></i> อนุมัติวงเงิน
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    $(document).ready(function() {
        $('.select2').select2({ width: '100%' });
    });

    function openApproveModal(btn) {
        document.getElementById('mod_req_id').value = btn.getAttribute('data-id');
        document.getElementById('mod_cust_name').innerText = btn.getAttribute('data-name');
        let limit = parseFloat(btn.getAttribute('data-limit'));
        document.getElementById('mod_limit').innerText = limit.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('approveModal').style.display = 'flex';
    }

    function closeApproveModal() { document.getElementById('approveModal').style.display = 'none'; }
    window.onclick = function(e) { if (e.target == document.getElementById('approveModal')) closeApproveModal(); }

    const urlParams = new URLSearchParams(window.location.search);
    const alerts = {
        'approved': { icon: 'success', title: 'อนุมัติวงเงินเรียบร้อย!' },
        'rejected': { icon: 'info', title: 'ปฏิเสธคำขอเรียบร้อย' },
        'req_added': { icon: 'success', title: 'สร้างคำขอจำลองสำเร็จ' }
    };
    const status = urlParams.get('status');
    if (alerts[status]) {
        Swal.fire({ ...alerts[status], timer: 2000, showConfirmButton: false })
        .then(() => window.history.replaceState(null, null, window.location.pathname));
    }
</script>
</body>
</html>