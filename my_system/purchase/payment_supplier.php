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

$allowed_depts = ['ฝ่ายบัญชี', 'ฝ่ายการเงิน', 'บัญชี - ท็อปธุรกิจ'];
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $allowed_depts)) { 
    echo "<script>alert('เฉพาะฝ่ายบัญชีและการเงินเท่านั้น'); window.location='../index.php';</script>"; exit(); 
}

// เช็คและเพิ่มคอลัมน์สำหรับการจ่ายเงินในตาราง PO ถ้ายังไม่มี
$check_col = mysqli_query($conn, "SHOW COLUMNS FROM `purchase_orders` LIKE 'payment_status'");
if (mysqli_num_rows($check_col) == 0) {
    mysqli_query($conn, "ALTER TABLE `purchase_orders` ADD `payment_status` VARCHAR(50) DEFAULT 'Unpaid' AFTER `status`");
    mysqli_query($conn, "ALTER TABLE `purchase_orders` ADD `payment_date` DATE NULL AFTER `payment_status`");
    mysqli_query($conn, "ALTER TABLE `purchase_orders` ADD `payment_ref` VARCHAR(100) NULL AFTER `payment_date`");
    mysqli_query($conn, "ALTER TABLE `purchase_orders` ADD `payment_by` VARCHAR(100) NULL AFTER `payment_ref`");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['pay_supplier'])) {
    $po_id = (int)$_POST['po_id'];
    $pay_date = mysqli_real_escape_string($conn, $_POST['payment_date']);
    $pay_ref = mysqli_real_escape_string($conn, $_POST['payment_ref']);
    
    $sql_pay = "UPDATE purchase_orders SET 
                payment_status = 'Paid', payment_date = '$pay_date', 
                payment_ref = '$pay_ref', payment_by = '$fullname' 
                WHERE po_id = $po_id";
                
    if (mysqli_query($conn, $sql_pay)) {
        $q_info = "SELECT po.supplier_name, (SELECT SUM(quantity * unit_price) FROM po_items WHERE po_id = po.po_id) as total_amount FROM purchase_orders po WHERE po.po_id = $po_id";
        $po_info = mysqli_fetch_assoc(mysqli_query($conn, $q_info));
        $po_total = (float)($po_info['total_amount'] ?? 0);

        // 🚀 บันทึกประวัติ Log ลงระบบ
        if(function_exists('log_event')) {
            log_event($conn, 'UPDATE', 'purchase_orders', "โอนเงินชำระหนี้ AP ให้ {$po_info['supplier_name']} (บิล PO-$po_id) ยอด " . number_format($po_total, 2) . " ฿ | Ref: $pay_ref");
        }

        include_once '../line_api.php';
        $msg = "💸 [ฝ่ายบัญชี] โอนเงินชำระค่าสินค้าซัพพลายเออร์เรียบร้อย\n\n";
        $msg .= "🧾 อ้างอิง PO ID: PO-" . str_pad($po_id, 5, '0', STR_PAD_LEFT) . "\n";
        $msg .= "🏢 จ่ายให้: " . ($po_info['supplier_name'] ?? 'ไม่ระบุ') . "\n";
        $msg .= "💰 ยอดเงิน: " . number_format($po_total, 2) . " บาท\n";
        $msg .= "📝 เลขที่อ้างอิง/Slip: $pay_ref\n";
        $msg .= "ผู้ทำรายการ: $fullname\n\n";
        $msg .= "👉 ถือเป็นการปิดลูปการสั่งซื้อ (Procure-to-Pay) ของเอกสารใบนี้อย่างสมบูรณ์ครับ";
        if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

        header("Location: payment_supplier.php?status=success"); exit;
    }
}

include '../sidebar.php';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Top Feed Mills | จ่ายเงินซัพพลายเออร์ (AP)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .wrapper { font-family: 'Sarabun', sans-serif; animation: fadeIn 0.5s ease-in-out; }
        .container-stacked { display: flex; flex-direction: column; gap: 25px; width: 100%; }
        .card { background: #ffffff; padding: 25px 30px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); border: 1px solid #f0f0f0; }
        h3 { color: #2c3e50; margin-top: 0; margin-bottom: 20px; font-weight: 600; border-bottom: 2px solid #f1f2f6; padding-bottom: 12px; display: flex; align-items: center; gap: 10px; }
        .form-group { text-align: left; margin-bottom: 15px;}
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #4a5568; font-size: 0.9rem; }
        input, select, textarea { width: 100%; padding: 10px 15px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-family: 'Sarabun'; font-size: 1rem; box-sizing: border-box; }
        .btn-action { background: linear-gradient(135deg, #e74a3b 0%, #be2617 100%); color: white; border: none; padding: 8px 15px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.3s; display: inline-flex; align-items: center; gap: 5px; font-family: 'Sarabun'; font-size:13px;}
        .btn-action:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(231,74,59,0.3); }
        .table-responsive { overflow-x: auto; width: 100%; border-radius: 10px; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 900px; }
        th, td { padding: 15px; border-bottom: 1px solid #f0f0f0; text-align: left; vertical-align: middle; }
        th { background: #f8f9fa; color: #6c757d; font-weight: bold; font-size: 0.85rem; text-transform: uppercase; white-space: nowrap; }
        .badge-status { padding: 5px 12px; border-radius: 50px; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(3px);}
        .modal-content { background: white; border-radius: 16px; width: 90%; max-width: 500px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); animation: pop 0.3s ease; }
        @keyframes pop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .modal-header { padding: 20px; border-bottom: 1px solid #eaecf4; display: flex; justify-content: space-between; align-items: center; background: #f8f9fc; border-radius: 16px 16px 0 0;}
        .modal-close { cursor: pointer; font-size: 24px; color: #858796; }
        .modal-body { padding: 25px; }
        .info-box { background: #fff5f5; padding: 15px; border-radius: 8px; margin-bottom: 20px; color: #c62828; font-size: 15px; border: 1px solid #ffcdd2;}
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

<div class="content-padding">
    <div class="wrapper">
        <h2 style="color: #2c3e50; margin-top:0;"><i class="fa-solid fa-file-invoice" style="color: #e74a3b;"></i> ระบบเจ้าหนี้การค้า (Accounts Payable - AP)</h2>
        <p style="color: #888; margin-bottom: 20px;">* เฉพาะรายการ PO ที่ผ่านการรับของ (GRN) และผ่านการตรวจสอบจาก QA ลงคลังแล้วเท่านั้น ถึงจะปรากฏให้บัญชีโอนเงินครับ (3-Way Matching)</p>

        <div class="container-stacked">

            <div class="card" style="border-top: 4px solid #e74a3b;">
                <h3><i class="fa-solid fa-money-check-dollar" style="color: #e74a3b;"></i> บิลรอชำระเงิน (Unpaid)</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>PO ID</th>
                                <th>ซัพพลายเออร์ (ผู้ขาย)</th>
                                <th>วันที่รับของเข้า</th>
                                <th>ยอดที่ต้องจ่าย</th>
                                <th>สถานะสินค้า</th>
                                <th style="text-align:right;">ดำเนินการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // 🚀 คำนวณยอดเงินรวม (Subquery) จากตาราง po_items ให้ถูกต้อง
                            $sql_ap = "SELECT po.*, 
                                       (SELECT SUM(quantity * unit_price) FROM po_items WHERE po_id = po.po_id) as total_amount 
                                       FROM purchase_orders po 
                                       WHERE po.status IN ('Completed', 'Delivered') AND po.payment_status = 'Unpaid' 
                                       ORDER BY po.expected_delivery_date ASC";
                            $res_ap = mysqli_query($conn, $sql_ap);
                            
                            if ($res_ap && mysqli_num_rows($res_ap) > 0) {
                                while($row = mysqli_fetch_assoc($res_ap)) {
                                    $amt = (float)($row['total_amount'] ?? 0);
                            ?>
                                <tr>
                                    <td><strong style="color:#4e73df; font-size: 1.1rem;">PO-<?= str_pad($row['po_id'], 5, '0', STR_PAD_LEFT) ?></strong></td>
                                    <td><strong style="color:#2c3e50;"><i class="fa-solid fa-building"></i> <?= htmlspecialchars($row['supplier_name']) ?></strong></td>
                                    <td><small style="color:#555;"><i class="fa-regular fa-calendar"></i> <?= date('d/m/Y', strtotime($row['expected_delivery_date'])) ?></small></td>
                                    <td><strong style="color:#e74a3b; font-size:18px;"><?= number_format($amt, 2) ?> ฿</strong></td>
                                    <td><span class="badge-status" style="background:#d4edda; color:#155724;"><i class="fa-solid fa-box-check"></i> ตรวจผ่านเข้าคลังแล้ว</span></td>
                                    <td style="text-align:right;">
                                        <button type="button" class="btn-action" 
                                            data-id="<?= $row['po_id'] ?>" 
                                            data-supplier="<?= htmlspecialchars($row['supplier_name']) ?>" 
                                            data-amount="<?= $amt ?>" 
                                            onclick="openPayModal(this)">
                                            <i class="fa-solid fa-money-bill-transfer"></i> บันทึกการโอนเงิน
                                        </button>
                                    </td>
                                </tr>
                            <?php } } else { echo "<tr><td colspan='6' style='text-align:center; padding:50px; color:#888;'><i class='fa-solid fa-check-circle fa-3x' style='color:#1cc88a; margin-bottom:15px;'></i><br>ไม่มีหนี้ค้างชำระ ยอดเยี่ยมมากครับ!</td></tr>"; } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card" style="border-top: 4px solid #1cc88a;">
                <h3><i class="fa-solid fa-clock-rotate-left" style="color: #1cc88a;"></i> ประวัติการโอนเงิน (Paid)</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>PO ID</th>
                                <th>ซัพพลายเออร์</th>
                                <th>วันที่โอน</th>
                                <th>ยอดเงิน (฿)</th>
                                <th>เลขที่อ้างอิง/Slip</th>
                                <th>ผู้ทำรายการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // 🚀 ดึงประวัติพร้อมคำนวณยอดเงินรวม
                            $sql_paid = "SELECT po.*, 
                                         (SELECT SUM(quantity * unit_price) FROM po_items WHERE po_id = po.po_id) as total_amount 
                                         FROM purchase_orders po 
                                         WHERE po.payment_status = 'Paid' 
                                         ORDER BY po.payment_date DESC, po.po_id DESC LIMIT 30";
                            $res_paid = mysqli_query($conn, $sql_paid);
                            
                            if ($res_paid && mysqli_num_rows($res_paid) > 0) {
                                while($row = mysqli_fetch_assoc($res_paid)) {
                                    $amt = (float)($row['total_amount'] ?? 0);
                            ?>
                                <tr>
                                    <td><strong style="color:#4e73df;">PO-<?= str_pad($row['po_id'], 5, '0', STR_PAD_LEFT) ?></strong></td>
                                    <td><strong style="color:#2c3e50;"><?= htmlspecialchars($row['supplier_name']) ?></strong></td>
                                    <td><?= date('d/m/Y', strtotime($row['payment_date'])) ?></td>
                                    <td><strong style="color:#1cc88a;"><?= number_format($amt, 2) ?></strong></td>
                                    <td><span style="background:#f8f9fc; padding:3px 8px; border-radius:4px; font-family:monospace;"><?= htmlspecialchars($row['payment_ref']) ?></span></td>
                                    <td><small><?= htmlspecialchars($row['payment_by']) ?></small></td>
                                </tr>
                            <?php } } else { echo "<tr><td colspan='6' style='text-align:center; padding:30px; color:#888;'>ยังไม่มีประวัติการจ่ายเงิน</td></tr>"; } ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<div id="payModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3 style="color:#e74a3b;"><i class="fa-solid fa-money-bill-transfer"></i> ยืนยันการชำระเงิน</h3>
            <div class="modal-close" onclick="closePayModal()"><i class="fa-solid fa-xmark"></i></div>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="po_id" id="mod_po_id">
                <div class="info-box">
                    <i class="fa-solid fa-building"></i> <strong>สั่งจ่ายให้:</strong> <span id="mod_supplier"></span><br>
                    <i class="fa-solid fa-coins" style="margin-top: 8px;"></i> <strong>ยอดที่ต้องโอน:</strong> <span id="mod_amount" style="font-weight:bold; font-size:18px;"></span> ฿
                </div>
                
                <div class="form-group">
                    <label>วันที่ทำรายการโอน <span style="color:red;">*</span></label>
                    <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>เลขที่อ้างอิง / เลขสลิป / เลขที่เช็ค <span style="color:red;">*</span></label>
                    <input type="text" name="payment_ref" placeholder="ระบุเลข Transaction..." required>
                </div>
                <div class="form-group">
                    <label>หมายเหตุ</label>
                    <textarea name="remark" rows="2"></textarea>
                </div>
                <button type="submit" name="pay_supplier" class="btn-action" style="width:100%; justify-content:center; font-size: 16px; padding: 12px;" onclick="return confirm('ยืนยันว่าทำการโอนชำระเงินเรียบร้อยแล้ว?')">
                    <i class="fa-solid fa-check-circle"></i> ยืนยันว่าโอนเงินแล้ว
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openPayModal(btn) {
        document.getElementById('mod_po_id').value = btn.getAttribute('data-id');
        document.getElementById('mod_supplier').innerText = btn.getAttribute('data-supplier');
        
        let amt = parseFloat(btn.getAttribute('data-amount'));
        document.getElementById('mod_amount').innerText = amt.toLocaleString('en-US', {minimumFractionDigits: 2});
        
        document.getElementById('payModal').style.display = 'flex';
    }
    function closePayModal() { document.getElementById('payModal').style.display = 'none'; }
    window.onclick = function(e) { if (e.target == document.getElementById('payModal')) closePayModal(); }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('status') === 'success') {
        Swal.fire({ icon: 'success', title: 'บันทึกการจ่ายเงินสำเร็จ!', text: 'ระบบอัปเดตประวัติและส่ง LINE แล้ว', timer: 3000, showConfirmButton: false })
        .then(() => window.history.replaceState(null, null, window.location.pathname));
    }
</script>
</body>
</html>