<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'QA Officer';

$allowed_depts = ['แผนก QA', 'แผนก QC', 'ฝ่ายวิชาการ'];
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $allowed_depts)) { 
    echo "<script>alert('เฉพาะแผนกควบคุมคุณภาพเท่านั้น'); window.location='../index.php';</script>"; exit(); 
}

// เช็คตาราง qa_outbound_logs ของคุณที่มีอยู่เดิม
$check_table = mysqli_query($conn, "SHOW TABLES LIKE 'qa_outbound_logs'");
if ($check_table && mysqli_num_rows($check_table) == 0) {
    mysqli_query($conn, "CREATE TABLE qa_outbound_logs (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        order_id INT(11) NOT NULL DEFAULT 0,
        lot_no VARCHAR(100) NOT NULL,
        moisture DECIMAL(5,2) NOT NULL,
        protein DECIMAL(5,2) NOT NULL,
        appearance VARCHAR(50) NOT NULL,
        qa_status VARCHAR(50) NOT NULL,
        remark TEXT,
        inspector_name VARCHAR(100) NOT NULL,
        inspected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_qa'])) {
    
    $order_id = (int)($_POST['order_id'] ?? 0);
    $lot_no = mysqli_real_escape_string($conn, $_POST['lot_no'] ?? '');
    $p_id = (int)($_POST['product_id'] ?? 0);
    $qty = (float)($_POST['qty'] ?? 0);
    
    $moisture = (float)($_POST['moisture'] ?? 0);
    $protein = (float)($_POST['protein'] ?? 0);
    $appearance = mysqli_real_escape_string($conn, $_POST['appearance'] ?? '');
    $qa_status = mysqli_real_escape_string($conn, $_POST['qa_status'] ?? '');
    $remark = mysqli_real_escape_string($conn, $_POST['remark'] ?? '');

    $is_rma = (strpos($lot_no, 'RMA-') === 0);

    // บันทึกผลแล็บลงตาราง qa_outbound_logs ของคุณ
    $sql_log = "INSERT INTO qa_outbound_logs (order_id, lot_no, moisture, protein, appearance, qa_status, remark, inspector_name) 
                VALUES ($order_id, '$lot_no', $moisture, $protein, '$appearance', '$qa_status', '$remark', '$fullname')";
            
    if (mysqli_query($conn, $sql_log)) {
        
        $p_name = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p_name FROM products WHERE id = $p_id"))['p_name'] ?? 'สินค้า FG';

        // 🚀 บันทึกประวัติ Log ลงระบบ
        if(function_exists('log_event')) {
            $log_status = ($qa_status == 'Approved') ? 'ผ่าน (Approved)' : 'ไม่ผ่าน (Rejected)';
            $log_type = $is_rma ? "ตรวจสินค้าตีกลับ (RMA)" : "ตรวจสินค้าสำเร็จรูป (FG)";
            log_event($conn, 'UPDATE', 'qa_outbound_logs', "QA $log_type $p_name (Lot: $lot_no) สถานะ: $log_status | หมายเหตุ: $remark");
        }

        if ($qa_status == 'Approved') {
            // ✅ ผ่าน QA
            mysqli_query($conn, "UPDATE inventory_lots SET status = 'Active' WHERE lot_no = '$lot_no'");
            mysqli_query($conn, "UPDATE products SET p_qty = p_qty + $qty WHERE id = $p_id");
            
            $ref_msg = $is_rma ? "รับคืนคลัง (RMA ผ่าน QA Lot: $lot_no)" : "ผลิตเสร็จ (ผ่าน QA Lot: $lot_no)";
            mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, reference, action_by) 
                                 VALUES ($p_id, 'IN', $qty, '$ref_msg', '$fullname')");
            
            $line_status = "✅ **สถานะ: ผ่าน (Approved)**\n👉 นำสินค้าเข้าสต็อกพร้อมขายเรียบร้อย";
            
        } else {
            // ❌ ไม่ผ่าน QA (ทำลายทิ้ง)
            mysqli_query($conn, "UPDATE inventory_lots SET qty = 0, status = 'Rejected_QA' WHERE lot_no = '$lot_no'");
            
            $reason_msg = $is_rma ? "สินค้าตีกลับเสียจริง ($remark)" : "ผลิตไม่ได้มาตรฐาน ($remark)";
            mysqli_query($conn, "INSERT INTO scrap_records (product_id, lot_no, qty, reason, reported_by) 
                                 VALUES ($p_id, '$lot_no', $qty, '$reason_msg', '$fullname')");
                                 
            $line_status = "❌ **สถานะ: ไม่ผ่าน (Rejected)**\n⚠️ ทำลาย LOT นี้ทิ้งเป็นของเสียแล้ว\n💬 เหตุผล: $remark";
        }

        // แจ้งเตือน LINE หาฝ่ายผลิต/ขาย และ คลังสินค้า
        include_once '../line_api.php';
        $doc_type = $is_rma ? "ตรวจสอบสินค้าตีกลับ (RMA)" : "ตรวจสอบสินค้าสำเร็จรูปใหม่";
        $msg = "🔬 [QA Report] แจ้งผล $doc_type\n\n📦 สินค้า: $p_name\n🏷️ Lot No: $lot_no\n⚖️ ปริมาณ: " . number_format($qty, 2) . " หน่วย\n\n$line_status";
        if(function_exists('sendLineMessage')) { sendLineMessage($msg); }
        
        header("Location: qa_outbound.php?status=success"); exit;
    }
}
include '../sidebar.php';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ตรวจสอบคุณภาพสินค้า (QA Outbound) | Top Feed Mills</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { 
            --primary: #10b981; --primary-hover: #059669; --primary-light: #d1fae5;
            --danger: #ef4444; --warning: #f59e0b; --info: #3b82f6;
            --bg-color: #f8fafc; --card-bg: #ffffff; --border-color: #e2e8f0;
            --text-main: #1e293b; --text-muted: #64748b;
        }
        body { font-family: 'Sarabun', sans-serif; background-color: var(--bg-color); }
        .content-padding { padding: 24px; width: 100%; box-sizing: border-box; max-width: 1400px; margin: auto;}
        
        .card-qa { background: var(--card-bg); padding: 30px; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); border: 1px solid var(--border-color); margin-bottom: 25px; width: 100%; }
        
        h3 { display: flex; align-items: center; gap: 10px; margin-top: 0; margin-bottom: 20px; color: var(--text-main); font-weight: 800; font-size: 20px;}
        
        /* Table Styles */
        .table-responsive { width: 100%; overflow-x: auto; border-radius: 12px; border: 1px solid var(--border-color); }
        table.display-table { width: 100%; border-collapse: collapse; min-width: 900px; }
        table.display-table th { background: #f8fafc; color: var(--text-muted); font-size: 13.5px; text-transform: uppercase; font-weight: 700; padding: 15px 20px; border-bottom: 2px solid var(--border-color); text-align: left; }
        table.display-table td { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 15px; font-weight: 500; color: var(--text-main); }
        table.display-table tr:hover td { background-color: #f8fafc; }

        /* Badges */
        .badge { padding: 6px 14px; border-radius: 50px; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
        .bg-pending { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .bg-active { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .bg-reject { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .st-rma { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; padding: 4px 10px; border-radius: 6px; font-size: 13px; font-weight: 700; display: inline-block;}

        .btn-action { background: var(--primary); color: white; border: none; padding: 10px 16px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; font-family: 'Sarabun'; display: inline-flex; align-items: center; gap: 6px; font-size: 14px;}
        .btn-action:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3); }

        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-content { background: white; border-radius: 20px; width: 90%; max-width: 600px; padding: 30px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); animation: pop 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        @keyframes pop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-size: 14.5px; font-weight: 700; color: var(--text-main); margin-bottom: 8px; }
        .form-control { width: 100%; padding: 12px 16px; border: 1.5px solid var(--border-color); border-radius: 10px; font-family: 'Sarabun'; font-size: 15px; transition: 0.2s; box-sizing: border-box;}
        .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15); }
        
        .btn-submit { background: var(--primary); color: white; width: 100%; padding: 14px; border: none; border-radius: 10px; font-size: 16px; font-weight: bold; cursor: pointer; transition: 0.3s; font-family: 'Sarabun';}
        .btn-submit:hover { background: var(--primary-hover); transform: translateY(-2px); }
        .btn-cancel { background: #fef2f2; color: #ef4444; width: 100%; padding: 14px; border: none; border-radius: 10px; font-size: 16px; font-weight: bold; cursor: pointer; transition: 0.3s; font-family: 'Sarabun';}
        .btn-cancel:hover { background: #fecaca; }

        .info-box { background: #f8fafc; border: 1px dashed #cbd5e1; padding: 15px; border-radius: 12px; margin-bottom: 20px; }
    </style>
</head>
<body>

<div class="content-padding">
    
    <div class="card-qa" style="border-top: 5px solid var(--warning);">
        <h3><i class="fa-solid fa-microscope" style="color:var(--warning);"></i> สินค้าสำเร็จรูปที่รอการตรวจสอบ (Pending QA Release)</h3>
        <p style="color: var(--text-muted); font-size: 14px; margin-top: -10px; margin-bottom: 20px;">สินค้าเหล่านี้ผลิตเสร็จแล้ว หรือลูกค้ารับคืนมา แต่ยังไม่สามารถนำไปขายได้จนกว่า QA จะกดอนุมัติ (Pass)</p>

        <div class="table-responsive">
            <table class="display-table">
                <thead>
                    <tr>
                        <th width="20%">ประเภท / วันที่ผลิต</th>
                        <th width="20%">LOT Number</th>
                        <th width="30%">สินค้า (Product)</th>
                        <th width="15%">ปริมาณ</th>
                        <th width="15%" style="text-align:right;">ดำเนินการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // ดึงข้อมูลทั้ง LOT ที่ผลิตใหม่ (LOT-) และ LOT ที่ลูกค้าตีกลับ (RMA-)
                    $sql_pending = "SELECT l.id, l.lot_no, l.qty, l.product_id, l.mfg_date, p.p_name 
                                    FROM inventory_lots l 
                                    JOIN products p ON l.product_id = p.id 
                                    WHERE l.status = 'Pending_QA' AND (l.lot_no LIKE 'LOT-%' OR l.lot_no LIKE 'RMA-%') 
                                    ORDER BY l.id ASC";
                    $res_pending = mysqli_query($conn, $sql_pending);
                    
                    if ($res_pending && mysqli_num_rows($res_pending) > 0) {
                        while($row = mysqli_fetch_assoc($res_pending)) {
                            $is_rma = (strpos($row['lot_no'], 'RMA-') === 0);
                            $type_badge = $is_rma ? "<span class='st-rma'><i class='fa-solid fa-arrow-rotate-left'></i> รับคืนจากลูกค้า</span>" : "<span style='color:#64748b; font-size:14px; font-weight:bold;'><i class='fa-solid fa-industry'></i> ผลิตใหม่</span>";
                            
                            // สกัดเอา Order ID ออกมาจาก Lot (ถ้ามี)
                            $order_id = 0;
                            if (!$is_rma) {
                                $parts = explode('-', $row['lot_no']);
                                if(isset($parts[2])) $order_id = (int)$parts[2];
                            }
                    ?>
                        <tr>
                            <td>
                                <?= $type_badge ?><br>
                                <small style="color: var(--text-muted);">ว/ด/ป: <?= date('d/m/Y', strtotime($row['mfg_date'])) ?></small>
                            </td>
                            <td>
                                <strong style="color: var(--info); font-size:15px; background: #e0f2fe; padding: 4px 10px; border-radius: 6px;"><?= $row['lot_no'] ?></strong>
                            </td>
                            <td>
                                <strong style="font-size:16px; color:var(--text-main);"><i class="fa-solid fa-box-open" style="color:#94a3b8; margin-right:5px;"></i><?= htmlspecialchars($row['p_name']) ?></strong>
                            </td>
                            <td>
                                <strong style="color:var(--danger); font-size:16px;"><?= number_format($row['qty'], 2) ?></strong>
                            </td>
                            <td align="right">
                                <button class="btn-action" onclick="openQAModal('<?= $order_id ?>', '<?= $row['product_id'] ?>', '<?= htmlspecialchars($row['p_name']) ?>', '<?= $row['qty'] ?>', '<?= $row['lot_no'] ?>')">
                                    <i class="fa-solid fa-flask-vial"></i> บันทึกผลแล็บ
                                </button>
                            </td>
                        </tr>
                    <?php 
                        }
                    } else { 
                        echo "<tr><td colspan='5' style='text-align:center; padding:50px; color:var(--text-muted);'><i class='fa-solid fa-check-circle fa-3x' style='margin-bottom:15px; color:#10b981;'></i><br>ไม่มีสินค้าที่รอการตรวจสอบ</td></tr>";
                    } 
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card-qa" style="border-top: 5px solid var(--info);">
        <h3><i class="fa-solid fa-file-certificate" style="color:var(--info);"></i> ประวัติการตรวจสอบสินค้า (QA History)</h3>
        
        <div class="table-responsive">
            <table class="display-table">
                <thead>
                    <tr>
                        <th width="15%">วัน/เวลา ที่ตรวจ</th>
                        <th width="30%">Lot No. / สินค้า</th>
                        <th width="25%">ผลแล็บ (Lab Results)</th>
                        <th width="15%">ผู้ตรวจ</th>
                        <th width="15%">สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // ดึงประวัติจากตาราง qa_outbound_logs ของคุณ
                    $res_history = mysqli_query($conn, "SELECT qa.*, p.p_name 
                                                        FROM qa_outbound_logs qa 
                                                        LEFT JOIN inventory_lots l ON qa.lot_no = l.lot_no 
                                                        LEFT JOIN products p ON l.product_id = p.id 
                                                        ORDER BY qa.id DESC LIMIT 50");
                    if ($res_history && mysqli_num_rows($res_history) > 0) {
                        while($row = mysqli_fetch_assoc($res_history)) {
                            $badge = ($row['qa_status'] == 'Approved') ? "<span class='badge bg-active'><i class='fa-solid fa-check-circle'></i> ผ่าน (เข้าคลัง)</span>" : "<span class='badge bg-reject'><i class='fa-solid fa-xmark-circle'></i> ไม่ผ่าน (ทำลาย)</span>";
                            $pname = $row['p_name'] ?? 'ไม่ทราบข้อมูลสินค้า';
                    ?>
                        <tr>
                            <td><small style="color:var(--text-muted);"><?= date('d/m/Y H:i', strtotime($row['inspected_at'])) ?></small></td>
                            <td>
                                <strong style="color: var(--text-main); font-size:15px;"><?= $row['lot_no'] ?></strong><br>
                                <span style="color: var(--text-muted); font-size:14px;"><?= $pname ?></span>
                            </td>
                            <td>
                                <span style="font-size:14px; color:#475569;">ชื้น: <strong style="color:#0ea5e9;"><?= $row['moisture'] ?>%</strong> | โปรตีน: <strong style="color:#10b981;"><?= $row['protein'] ?>%</strong></span><br>
                                <?php if(!empty($row['remark'])): ?>
                                    <small style="color: var(--danger); display:block; margin-top:4px;"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($row['remark']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><small><i class="fa-solid fa-user-shield" style="color:#94a3b8; margin-right:5px;"></i><?= htmlspecialchars($row['inspector_name']) ?></small></td>
                            <td><?= $badge ?></td>
                        </tr>
                    <?php 
                        }
                    } else { 
                        echo "<tr><td colspan='5' style='text-align:center; padding:40px; color:var(--text-muted);'>ยังไม่มีประวัติการตรวจสอบ</td></tr>";
                    } 
                    ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<div id="qaModal" class="modal-overlay">
    <div class="modal-content">
        <h3 style="margin-top:0; color:var(--text-main); font-size:22px;"><i class="fa-solid fa-microscope" style="color:var(--primary);"></i> บันทึกผลแล็บวิเคราะห์ (Lab Result)</h3>
        
        <div class="info-box">
            <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 3px;">สินค้าสำเร็จรูป:</div>
            <div style="font-size: 16px; font-weight: bold; color: var(--text-main);" id="qa_product_name"></div>
            
            <div style="display:flex; justify-content:space-between; margin-top:10px;">
                <div>
                    <div style="font-size: 13px; color: var(--text-muted);">LOT Number:</div>
                    <div style="font-size: 15px; font-weight: bold; color: var(--info);" id="qa_lot_display"></div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size: 13px; color: var(--text-muted);">จำนวนที่จะอนุมัติ:</div>
                    <div style="font-size: 15px; font-weight: bold; color: var(--danger);" id="qa_qty"></div>
                </div>
            </div>
        </div>
        
        <form method="POST" onsubmit="return confirm('ยืนยันผลการตรวจ? ระบบจะอัปเดตสต็อกทันที');">
            <input type="hidden" name="order_id" id="qa_order_id">
            <input type="hidden" name="product_id" id="qa_product_id">
            <input type="hidden" name="lot_no" id="qa_lot_no">
            <input type="hidden" name="qty" id="qa_qty_val">
            
            <div style="display:flex; gap:15px;">
                <div class="form-group" style="flex:1;">
                    <label class="form-label">ความชื้น (%) <span style="color:red;">*</span></label>
                    <input type="number" step="0.01" name="moisture" class="form-control" placeholder="เช่น 12.5" required>
                </div>
                <div class="form-group" style="flex:1;">
                    <label class="form-label">โปรตีน (%) <span style="color:red;">*</span></label>
                    <input type="number" step="0.01" name="protein" class="form-control" placeholder="เช่น 18.0" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">ลักษณะทางกายภาพ <span style="color:red;">*</span></label>
                <select name="appearance" class="form-control" required>
                    <option value="ปกติ">ปกติ (สี กลิ่น ขนาดเม็ด ผ่านเกณฑ์)</option>
                    <option value="ผิดปกติ">ผิดปกติ (พบสิ่งเจือปน / มอด / แมลง)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">สถานะ (การตัดสินใจ) <span style="color:red;">*</span></label>
                <select name="qa_status" id="mod_status" class="form-control" required onchange="toggleRemark()">
                    <option value="">-- เลือกผลการตัดสิน --</option>
                    <option value="Approved" style="color:#059669; font-weight:bold;">✅ ผ่าน (Approved / นำเข้าสต็อกคลังพร้อมขาย)</option>
                    <option value="Rejected" style="color:#dc2626; font-weight:bold;">❌ ไม่ผ่าน (Rejected / ทำลาย LOT ทิ้งเป็นของเสีย)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">หมายเหตุ <span id="remark_req" style="color:red; display:none;">* (บังคับใส่เมื่อไม่ผ่าน)</span></label>
                <textarea name="remark" id="mod_remark" class="form-control" rows="2" placeholder="ระบุเหตุผล หากไม่ผ่าน..."></textarea>
            </div>
            
            <div style="display: flex; gap: 15px; margin-top: 25px;">
                <button type="button" class="btn-cancel" onclick="closeModal()">ยกเลิก</button>
                <button type="submit" name="save_qa" class="btn-submit">บันทึกผลตรวจสอบ</button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    function openQAModal(oid, pid, name, qty, lot) {
        document.getElementById('qa_order_id').value = oid;
        document.getElementById('qa_product_id').value = pid;
        document.getElementById('qa_lot_no').value = lot;
        document.getElementById('qa_qty_val').value = qty;
        
        document.getElementById('qa_lot_display').innerText = lot;
        document.getElementById('qa_product_name').innerText = name;
        document.getElementById('qa_qty').innerText = qty + ' หน่วย';
        
        document.getElementById('mod_status').value = '';
        document.getElementById('mod_remark').value = '';
        document.getElementById('mod_remark').required = false;
        document.getElementById('remark_req').style.display = 'none';

        document.getElementById('qaModal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('qaModal').style.display = 'none';
    }

    function toggleRemark() {
        let status = document.getElementById('mod_status').value;
        let remarkField = document.getElementById('mod_remark');
        let remarkReq = document.getElementById('remark_req');
        
        if (status === 'Rejected') {
            remarkField.required = true;
            remarkReq.style.display = 'inline';
        } else {
            remarkField.required = false;
            remarkReq.style.display = 'none';
        }
    }

    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('status') === 'success') {
        Swal.fire({ icon: 'success', title: 'บันทึกผลตรวจสอบสำเร็จ!', text: 'ระบบอัปเดตสต็อกเรียบร้อยแล้ว', timer: 2000, showConfirmButton: false })
        .then(() => window.history.replaceState(null, null, window.location.pathname));
    }
</script>
</body>
</html>