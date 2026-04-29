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

$allowed_depts = ['แผนก QA', 'แผนก QC', 'ฝ่ายวิชาการ'];
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $allowed_depts)) { 
    echo "<script>alert('เฉพาะแผนกควบคุมคุณภาพเท่านั้น'); window.location='../index.php';</script>"; exit(); 
}

$check_table = mysqli_query($conn, "SHOW TABLES LIKE 'qa_inbound_logs'");
if (mysqli_num_rows($check_table) == 0) {
    mysqli_query($conn, "CREATE TABLE qa_inbound_logs (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        lot_no VARCHAR(100) NOT NULL,
        product_id INT(11) NOT NULL,
        moisture DECIMAL(5,2) NOT NULL,
        visual_check VARCHAR(50) NOT NULL,
        qa_status VARCHAR(50) NOT NULL,
        remark TEXT,
        inspector_name VARCHAR(100) NOT NULL,
        inspected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_qa'])) {
    $lot_no = mysqli_real_escape_string($conn, $_POST['lot_no']);
    $p_id = (int)$_POST['product_id'];
    $qty = (float)$_POST['qty'];
    $moisture = (float)$_POST['moisture'];
    $visual = mysqli_real_escape_string($conn, $_POST['visual']);
    $qa_status = mysqli_real_escape_string($conn, $_POST['qa_status']);
    $remark = mysqli_real_escape_string($conn, $_POST['remark']);

    // บันทึกผลแล็บ
    $sql_log = "INSERT INTO qa_inbound_logs (lot_no, product_id, moisture, visual_check, qa_status, remark, inspector_name) 
                VALUES ('$lot_no', $p_id, $moisture, '$visual', '$qa_status', '$remark', '$fullname')";
    
    if (mysqli_query($conn, $sql_log)) {
        
        $p_name = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p_name FROM products WHERE id = $p_id"))['p_name'] ?? 'วัตถุดิบ';

        // 🚀 บันทึกประวัติ Log ลงระบบ (แทรกตรงนี้)
        if(function_exists('log_event')) {
            $log_status = ($qa_status == 'Approved') ? 'ผ่าน (Approved)' : 'ไม่ผ่าน (Rejected)';
            log_event($conn, 'UPDATE', 'qa_inbound_logs', "QA ตรวจวัตถุดิบ $p_name (Lot: $lot_no) สถานะ: $log_status | หมายเหตุ: $remark");
        }

        if ($qa_status == 'Approved') {
            mysqli_query($conn, "UPDATE inventory_lots SET status = 'Active' WHERE lot_no = '$lot_no'");
            mysqli_query($conn, "UPDATE products SET p_qty = p_qty + $qty WHERE id = $p_id");
            mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, reference, action_by) 
                                 VALUES ($p_id, 'IN', $qty, 'รับเข้า (ผ่าน QA ขาเข้า Lot: $lot_no)', '$fullname')");
            $line_status = "✅ **สถานะ: ผ่าน (Approved)**";
        } else {
            mysqli_query($conn, "UPDATE inventory_lots SET qty = 0, status = 'Rejected_QA' WHERE lot_no = '$lot_no'");
            mysqli_query($conn, "INSERT INTO scrap_records (product_id, lot_no, qty, reason, reported_by) 
                                 VALUES ($p_id, '$lot_no', $qty, 'ตีกลับ (ไม่ผ่าน QA: $remark)', '$fullname')");
            $line_status = "❌ **สถานะ: ไม่ผ่าน (Rejected)** - ระบบทำลายวัตถุดิบ/ตีกลับ แล้ว";
        }

        // 🚀 ระบบอัปเดตสถานะของ PO กลับไปหาฝ่ายจัดซื้อ
        // แกะรหัส PO ID ออกมาจาก LOT No (รูปแบบ: REC-20260428-0001-12)
        $lot_parts = explode('-', $lot_no);
        $extracted_po_id = isset($lot_parts[2]) ? (int)$lot_parts[2] : 0;
        
        if ($extracted_po_id > 0) {
            $po_str = str_pad($extracted_po_id, 4, "0", STR_PAD_LEFT);
            
            // เช็คว่าใน PO ใบนี้ ยังเหลือ LOT อื่นที่กักกันอยู่อีกไหม?
            $q_pend = mysqli_query($conn, "SELECT COUNT(*) as c FROM inventory_lots WHERE lot_no LIKE 'REC-%-$po_str-%' AND status = 'Pending_QA'");
            $pend_count = $q_pend ? (mysqli_fetch_assoc($q_pend)['c'] ?? 0) : 0;
            
            if ($pend_count == 0) {
                // ถ้าตรวจครบทุกรายการใน PO แล้ว -> ให้เช็คว่ามีของที่ถูก "ตีกลับ" ไหม?
                $q_rej = mysqli_query($conn, "SELECT COUNT(*) as c FROM inventory_lots WHERE lot_no LIKE 'REC-%-$po_str-%' AND status = 'Rejected_QA'");
                $rej_count = $q_rej ? (mysqli_fetch_assoc($q_rej)['c'] ?? 0) : 0;
                
                if ($rej_count > 0) {
                    // ถ้ามีของเสีย/ตีกลับแม้อย่างเดียว -> ปรับสถานะ PO ให้เป็น QA ตีกลับ
                    mysqli_query($conn, "UPDATE purchase_orders SET status = 'QA_Rejected' WHERE po_id = $extracted_po_id");
                } else {
                    // ถ้าผ่านทุกอย่าง -> ปรับสถานะ PO เป็น Completed
                    mysqli_query($conn, "UPDATE purchase_orders SET status = 'Completed' WHERE po_id = $extracted_po_id");
                }
            }
        }

        include_once '../line_api.php';
        $msg = "🔬 [QA Report] แจ้งผลตรวจวัตถุดิบขาเข้า\n\n📦 $p_name\n🏷️ Lot: $lot_no\n$line_status";
        if(function_exists('sendLineMessage')) { sendLineMessage($msg); }
        
        header("Location: qa_inbound.php?status=success"); exit;
    }
}

include '../sidebar.php';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Top Feed Mills | ตรวจวัตถุดิบขาเข้า (QA Inbound)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .wrapper { font-family: 'Sarabun', sans-serif; animation: fadeIn 0.5s ease-in-out; }
        .card { background: #ffffff; padding: 25px 30px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); margin-bottom: 25px; border-top: 4px solid #f6c23e;}
        h3 { color: #2c3e50; margin-top: 0; margin-bottom: 20px; font-weight: 600; border-bottom: 2px solid #f1f2f6; padding-bottom: 12px;}
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th { background: #f8f9fa; color: #4e73df; padding: 15px; text-align: left; }
        td { padding: 15px; border-bottom: 1px solid #f0f0f0; }
        .btn-cens { background: linear-gradient(135deg, #c81c1c 0%, #851313 100%); color: white; border: none; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-family: 'Sarabun'; font-weight:bold;}
        .btn-inspect { background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%); color: white; border: none; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-family: 'Sarabun'; font-weight:bold; }
        .badge-status { padding: 5px 12px; border-radius: 50px; font-size: 12px; font-weight: bold; }
        .st-approved { background: #d4edda; color: #155724; } .st-rejected { background: #f8d7da; color: #721c24; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; }
        .modal-content { background: white; border-radius: 16px; width: 90%; max-width: 600px; padding: 25px; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 8px; margin-bottom: 15px; box-sizing: border-box; font-family: 'Sarabun';}
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

<div class="content-padding">
    <div class="wrapper">
        <div class="card">
            <h3><i class="fa-solid fa-truck-droplet" style="color: #f6c23e;"></i> วัตถุดิบเพิ่งรับเข้า (รอกักกันตรวจสอบคุณภาพ)</h3>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr><th>รหัส LOT (รับเข้า)</th><th>ชื่อวัตถุดิบ (RAW)</th><th>วันที่รับเข้า</th><th>ปริมาณ</th><th>สถานะ QA</th><th style="text-align:right;">ดำเนินการ</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $sql_pending = "SELECT l.*, p.p_name, p.p_unit FROM inventory_lots l JOIN products p ON l.product_id = p.id WHERE l.status = 'Pending_QA' AND l.lot_no LIKE 'REC-%' ORDER BY l.id ASC";
                        $res_pending = mysqli_query($conn, $sql_pending);
                        
                        if ($res_pending && mysqli_num_rows($res_pending) > 0) {
                            while($row = mysqli_fetch_assoc($res_pending)) {
                        ?>
                            <tr>
                                <td><span style="background:#fff3cd; color:#856404; padding:4px 8px; border-radius:4px; font-weight:bold; border:1px solid #ffeeba;"><i class="fa-solid fa-lock"></i> <?= $row['lot_no'] ?></span></td>
                                <td><strong><?= htmlspecialchars($row['p_name']) ?></strong></td>
                                <td><?= date('d/m/Y', strtotime($row['mfg_date'])) ?></td>
                                <td><strong style="color:#e74a3b;"><?= number_format($row['qty'], 2) ?> <?= $row['p_unit'] ?></strong></td>
                                <td><span class="badge-status" style="background:#fceceb; color:#e74a3b;">รอกักกันตรวจสอบ</span></td>
                                <td style="text-align:right;">
                                    <button class="btn-inspect" onclick="openQAModal('<?= $row['lot_no'] ?>', '<?= $row['product_id'] ?>', '<?= htmlspecialchars($row['p_name']) ?>', '<?= $row['qty'] ?>')">
                                        <i class="fa-solid fa-flask-vial"></i> บันทึกผลแล็บ
                                    </button>
                                </td>
                            </tr>
                        <?php 
                            } 
                        } else { echo "<tr><td colspan='6' style='text-align:center; color:#888; padding:30px;'>ไม่มีวัตถุดิบรอตรวจสอบในลานกักกัน</td></tr>"; }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" style="border-top: 4px solid #4e73df;">
            <h3><i class="fa-solid fa-file-certificate" style="color:#4e73df;"></i> ประวัติการตรวจสอบ (History)</h3>
            <div style="overflow-x: auto;">
                <table>
                    <tr><th>วันที่ตรวจ</th><th>LOT / วัตถุดิบ</th><th>ความชื้น / กายภาพ</th><th>ผู้ตรวจ</th><th>สถานะ</th></tr>
                    <?php
                    $res_hist = mysqli_query($conn, "SELECT q.*, p.p_name FROM qa_inbound_logs q JOIN products p ON q.product_id = p.id ORDER BY q.id DESC LIMIT 30");
                    if($res_hist && mysqli_num_rows($res_hist)>0) {
                        while($r = mysqli_fetch_assoc($res_hist)) {
                            $badge = ($r['qa_status'] == 'Approved') ? "<span class='badge-status st-approved'>ผ่าน (เข้าคลัง)</span>" : "<span class='badge-status st-rejected'>ไม่ผ่าน (ตีกลับ)</span>";
                            echo "<tr>
                                    <td>".date('d/m/Y H:i', strtotime($r['inspected_at']))."</td>
                                    <td><strong>{$r['lot_no']}</strong><br>{$r['p_name']}</td>
                                    <td>ชื้น: {$r['moisture']}% | กายภาพ: {$r['visual_check']}</td>
                                    <td>{$r['inspector_name']}</td><td>{$badge}</td>
                                  </tr>";
                        }
                    } else { echo "<tr><td colspan='5' style='text-align:center;'>ยังไม่มีประวัติ</td></tr>"; }
                    ?>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="qaModal" class="modal-overlay">
    <div class="modal-content">
        <h3 style="color:#4e73df; border-bottom:1px solid #ddd; padding-bottom:10px;">บันทึกผลวิเคราะห์ (Inbound)</h3>
        <form method="POST">
            <input type="hidden" name="lot_no" id="qa_lot_no">
            <input type="hidden" name="product_id" id="qa_p_id">
            <input type="hidden" name="qty" id="qa_qty_val">
            <div style="background:#f8f9fc; padding:15px; border-radius:8px; margin-bottom:15px; border:1px dashed #d1d3e2;">
                วัตถุดิบ: <strong id="qa_p_name" style="color:#2c3e50; font-size:16px;"></strong><br>
                ปริมาณ: <span id="qa_qty_display" style="color:#e74a3b; font-weight:bold;"></span>
            </div>
            <div style="display:flex; gap:10px;">
                <div style="flex:1;"><label>ความชื้น % (Moisture)</label><input type="number" step="0.01" name="moisture" class="form-control" required></div>
                <div style="flex:1;">
                    <label>กายภาพ (มอด/แมลง)</label>
                    <select name="visual" class="form-control" required>
                        <option value="ปกติ (ผ่านเกณฑ์)">ปกติ (ผ่านเกณฑ์)</option>
                        <option value="พบแมลง/มอด">พบแมลง/มอด</option>
                        <option value="พบเชื้อรา/ชื้นเกิน">พบเชื้อรา/ชื้นเกิน</option>
                    </select>
                </div>
            </div>
            <label>สถานะ (การตัดสินใจ) <span style="color:red;">*</span></label>
            <select name="qa_status" class="form-control" style="font-weight:bold; font-size:15px;" required>
                <option value="Approved" style="color:green;">✅ ผ่าน (ปลดล็อคนำเข้าสต็อกคลัง)</option>
                <option value="Rejected" style="color:red;">❌ ไม่ผ่าน (ตีกลับซัพพลายเออร์ / ทำลาย)</option>
            </select>
            <label>หมายเหตุ</label>
            <input type="text" name="remark" class="form-control" placeholder="เช่น หักค่าความชื้น 2%...">
            <div style="display:flex; gap:10px; margin-top:10px;">
                <button type="button" class="btn-cens" style="width:100%; font-size:16px;" onclick="document.getElementById('qaModal').style.display='none'">ยกเลิก</button>
                <button type="submit" name="save_qa" class="btn-inspect" style="width:100%; font-size:16px;">บันทึกผลและอัปเดตสต็อก</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openQAModal(lot, pid, name, qty) {
        document.getElementById('qa_lot_no').value = lot;
        document.getElementById('qa_p_id').value = pid;
        document.getElementById('qa_qty_val').value = qty;
        document.getElementById('qa_p_name').innerText = name + " (LOT: " + lot + ")";
        document.getElementById('qa_qty_display').innerText = qty;
        document.getElementById('qaModal').style.display = 'flex';
    }
</script>
</body>
</html>