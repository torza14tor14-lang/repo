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

$allowed_depts = ['แผนก QA', 'แผนก QC', 'ฝ่ายวิชาการ', 'Premix&micro'];
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $allowed_depts)) { 
    echo "<script>alert('เฉพาะฝ่ายวิชาการและตรวจสอบคุณภาพ (QA/QC) เท่านั้น'); window.location='../index.php';</script>"; 
    exit(); 
}

// 🚀 ประมวลผลเมื่อ QA บันทึกผลการตรวจสอบ (Receipt from Production / Transfer)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_qa'])) {
    
    // ข้อมูลจากระบบแล็บของคุณ
    $order_id = (int)($_POST['order_id'] ?? 0);
    $moisture = (float)($_POST['moisture'] ?? 0);
    $protein = (float)($_POST['protein'] ?? 0);
    $appearance = mysqli_real_escape_string($conn, $_POST['appearance'] ?? '');
    
    // ข้อมูลการเคลื่อนย้ายคลัง
    $lot_id = (int)$_POST['lot_id'];
    $product_id = (int)$_POST['product_id'];
    $lot_no = mysqli_real_escape_string($conn, $_POST['lot_no']);
    $qty = (float)$_POST['qty'];
    
    $qa_status = $_POST['qa_status']; // Approved หรือ Rejected
    $from_wh_id = (int)$_POST['from_wh_id']; // 🚀 คลัง Hold ที่ของอยู่ปัจจุบัน
    $to_wh_id = (int)$_POST['wh_id']; // 🚀 คลังปลายทางที่ QA เลือกลงของ
    $remark = mysqli_real_escape_string($conn, $_POST['remark']);

    $is_rma = (strpos($lot_no, 'RMA-') === 0);

    if ($to_wh_id > 0) {
        
        // A. บันทึกผลแล็บลงตารางเก่าของคุณ (เพื่อให้ประวัติแล็บไม่หาย)
        $sql_log = "INSERT INTO qa_outbound_logs (order_id, lot_no, moisture, protein, appearance, qa_status, remark, inspector_name) 
                    VALUES ($order_id, '$lot_no', $moisture, $protein, '$appearance', '$qa_status', '$remark', '$fullname')";
        mysqli_query($conn, $sql_log);

        // B. อัปเดตสถานะ LOT สินค้า
        mysqli_query($conn, "UPDATE inventory_lots SET status = '$qa_status', remark = '$remark' WHERE id = $lot_id");

        // C. 🚀 โอนย้ายสต็อกจากคลัง Hold ไปคลังหลัก (Transfer Logic)
        $status_text = ($qa_status == 'Approved') ? "✅ ผ่าน QA" : "❌ ไม่ผ่าน QA (Reject)";
        $ref_msg = "ตรวจปล่อยสินค้า FG ($status_text) Lot: $lot_no | หมายเหตุ: $remark";

        if ($from_wh_id > 0 && $from_wh_id != $to_wh_id) {
            // หักออกจาก Hold
            mysqli_query($conn, "UPDATE stock_balances SET qty = qty - $qty WHERE product_id = $product_id AND wh_id = $from_wh_id");
            // บวกเข้าคลังใหม่
            mysqli_query($conn, "INSERT INTO stock_balances (product_id, wh_id, qty) VALUES ($product_id, $to_wh_id, $qty) ON DUPLICATE KEY UPDATE qty = qty + $qty");
            // บันทึก Transfer Log
            mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, from_wh_id, to_wh_id, reference, action_by) 
                                 VALUES ($product_id, 'TRANSFER', $qty, $from_wh_id, $to_wh_id, '$ref_msg', '$fullname')");
        } else {
            // กรณีเป็นของเก่าที่ไม่มี from_wh_id หรือช่างเลือกคลังเดิม ก็แค่ยัดของลงไป (กันพัง)
            mysqli_query($conn, "INSERT INTO stock_balances (product_id, wh_id, qty) VALUES ($product_id, $to_wh_id, $qty) ON DUPLICATE KEY UPDATE qty = qty + $qty");
            mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, to_wh_id, reference, action_by) 
                                 VALUES ($product_id, 'IN', $qty, $to_wh_id, '$ref_msg', '$fullname')");
        }

        // D. บันทึก System Log
        if (function_exists('log_event')) {
            $log_type = $is_rma ? "ตรวจสินค้าตีกลับ (RMA)" : "ตรวจสินค้าสำเร็จรูป (FG)";
            log_event($conn, 'UPDATE', 'qa_outbound_logs', "QA $log_type (Lot: $lot_no) สถานะ: $status_text | เข้าคลัง: $to_wh_id");
        }

        // E. แจ้งเตือน LINE
        $q_info = mysqli_query($conn, "SELECT p.p_name, w.wh_name FROM products p, warehouses w WHERE p.id = $product_id AND w.wh_id = $to_wh_id");
        $info = mysqli_fetch_assoc($q_info);
        $p_name = $info['p_name'] ?? 'Unknown';
        $wh_name = $info['wh_name'] ?? 'Unknown';

        include_once '../line_api.php';
        if ($qa_status == 'Approved') {
            $msg = "🔬 [QA ตรวจผ่าน] สินค้าสำเร็จรูปพร้อมขาย!\n\n📦 สินค้า: $p_name\n🔖 Lot No: $lot_no\n⚖️ จำนวน: " . number_format($qty, 2) . "\n📥 ย้ายเข้าเก็บที่: $wh_name\n\n👉 ฝ่ายขายสามารถเปิดบิลขายสินค้านี้ได้เลยครับ";
        } else {
            $msg = "🚨 [QA ตรวจไม่ผ่าน] พบสินค้าไม่ได้คุณภาพ!\n\n📦 สินค้า: $p_name\n🔖 Lot No: $lot_no\n⚖️ จำนวน: " . number_format($qty, 2) . "\n📥 กักกันไว้ที่: $wh_name\n💬 สาเหตุ: $remark\n\n👉 ฝ่ายผลิตโปรดตรวจสอบสาเหตุโดยด่วนครับ";
        }
        if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

        header("Location: qa_outbound.php?status=success"); exit();
    }
}

// 🚀 ดึงรายการรอตรวจ พร้อมกับบอกว่าตอนนี้ของอยู่ที่คลัง Hold ไหน
$sql_pending = "SELECT il.*, p.p_name, p.p_code, p.p_unit, w.wh_name as hold_wh_name, w.plant 
                FROM inventory_lots il
                JOIN products p ON il.product_id = p.id
                LEFT JOIN warehouses w ON il.wh_id = w.wh_id
                WHERE il.status = 'Pending_QA' AND il.lot_no NOT LIKE 'REC-%'
                ORDER BY il.id ASC";
$res_pending = mysqli_query($conn, $sql_pending);

// 🚀 ดึงรายชื่อคลังให้ QA เลือกโอน
$res_wh = mysqli_query($conn, "SELECT * FROM warehouses ORDER BY plant ASC, wh_name ASC");

include '../sidebar.php';
?>

<title>ตรวจปล่อยสินค้า (QA Outbound) | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    :root { --primary: #8b5cf6; --success: #10b981; --danger: #ef4444; --warning: #f59e0b; --info: #0ea5e9; --bg: #f8fafc; }
    body { font-family: 'Sarabun', sans-serif; background: var(--bg); }
    .content-padding { padding: 30px; max-width: 1400px; margin: auto; }
    
    .qa-card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 20px; border-left: 5px solid var(--primary); display: flex; justify-content: space-between; align-items: center; transition: 0.3s; flex-wrap: wrap; gap: 15px;}
    .qa-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
    
    .btn-qa { background: var(--primary); color: white; border: none; padding: 12px 20px; border-radius: 10px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.2s;}
    .btn-qa:hover { background: #7c3aed; }
    
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); overflow-y:auto; padding:20px 0;}
    .modal-content { background: white; border-radius: 20px; width: 90%; max-width: 600px; padding: 35px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); animation: pop 0.3s ease; margin:auto;}
    @keyframes pop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    
    .form-label { display: block; font-weight: 800; margin-bottom: 8px; color: #334155; font-size: 14px; }
    .form-control { width: 100%; padding: 12px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-family: 'Sarabun'; margin-bottom: 15px; box-sizing: border-box; }
    .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.15); }
    
    .radio-group { display: flex; gap: 15px; margin-bottom: 20px; }
    .radio-card { flex: 1; border: 2px solid #e2e8f0; border-radius: 12px; padding: 15px; text-align: center; cursor: pointer; transition: 0.2s; font-weight: bold; color: #64748b; }
    .radio-card.active-pass { border-color: var(--success); background: #dcfce7; color: #166534; }
    .radio-card.active-fail { border-color: var(--danger); background: #fee2e2; color: #991b1b; }
    input[type="radio"] { display: none; }

    .history-section { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-top: 40px; }
    table { width: 100%; border-collapse: collapse; min-width:800px; }
    th { text-align: left; padding: 15px; background: #f8fafc; font-size: 13px; font-weight: 800; color: #64748b; border-bottom: 2px solid #e2e8f0; }
    td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 14px; color: #1e293b; vertical-align: middle;}
    tr:hover { background: #f8fafc; }
    .status-badge { padding: 5px 12px; border-radius: 50px; font-size: 12px; font-weight: bold; display:inline-block; }
    .st-rma { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; display: inline-block;}
</style>

<div class="content-padding">
    <div style="margin-bottom: 30px;">
        <h2 style="margin:0; color:#1e293b; font-weight:800;"><i class="fa-solid fa-microscope" style="color:var(--primary);"></i> ตรวจปล่อยสินค้าสำเร็จรูป (QA Outbound)</h2>
        <p style="color:#64748b;">บันทึกผลแล็บ และนำสินค้าเก็บเข้าคลังสินค้าหลัก (ระบบจะโอนย้ายออกจากคลัง Hold ให้อัตโนมัติ)</p>
    </div>

    <!-- 🚀 ส่วนที่ 1: รายการรอตรวจสอบ (Pending) -->
    <h3 style="color:#475569; margin-bottom: 15px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;"><i class="fa-regular fa-clock"></i> รอตรวจสอบคุณภาพ</h3>
    <?php if ($res_pending && mysqli_num_rows($res_pending) > 0): ?>
        <?php while($row = mysqli_fetch_assoc($res_pending)): 
            $is_rma = (strpos($row['lot_no'], 'RMA-') === 0);
            $type_badge = $is_rma ? "<span class='st-rma'><i class='fa-solid fa-arrow-rotate-left'></i> รับคืนจากลูกค้า</span>" : "<span style='color:#64748b; font-size:13px; font-weight:bold;'><i class='fa-solid fa-industry'></i> ผลิตใหม่</span>";
            
            // หา order_id จาก lot (ถ้ามี)
            $order_id = 0;
            if (!$is_rma) {
                $parts = explode('-', $row['lot_no']);
                if(isset($parts[2])) $order_id = (int)$parts[2];
            }
            
            // คลังปัจจุบันที่ของรออยู่
            $from_wh_id = $row['wh_id'];
            $hold_wh_name = ($row['hold_wh_name']) ? "[{$row['plant']}] {$row['hold_wh_name']}" : "ไม่ระบุคลัง";
            
            // ถ้าเป็นของเก่าที่ไม่มี wh_id ใน inventory_lots ให้ลองสืบจาก stock_log
            if (empty($from_wh_id)) {
                $p_id_sr = $row['product_id'];
                $lot_sr = $row['lot_no'];
                $q_find_wh = mysqli_query($conn, "SELECT sl.to_wh_id, w.wh_name, w.plant FROM stock_log sl JOIN warehouses w ON sl.to_wh_id = w.wh_id WHERE sl.product_id = $p_id_sr AND sl.reference LIKE '%$lot_sr%' AND sl.type = 'IN' ORDER BY sl.id DESC LIMIT 1");
                if($q_find_wh && mysqli_num_rows($q_find_wh) > 0) {
                    $f_wh = mysqli_fetch_assoc($q_find_wh);
                    $from_wh_id = $f_wh['to_wh_id'];
                    $hold_wh_name = "[{$f_wh['plant']}] {$f_wh['wh_name']}";
                }
            }
        ?>
            <div class="qa-card" style="<?= $is_rma ? 'border-left-color: var(--info);' : '' ?>">
                <div>
                    <?= $type_badge ?><br>
                    <h3 style="margin: 10px 0 5px 0; color:#1e293b;"><?= htmlspecialchars($row['p_name']) ?></h3>
                    <p style="margin:0; color:#64748b; font-size:14px; margin-bottom:5px;">
                        <i class="fa-solid fa-tag"></i> Lot No: <strong style="color:var(--primary);"><?= htmlspecialchars($row['lot_no']) ?></strong> | 
                        <i class="fa-solid fa-calendar-day"></i> ผลิต: <?= date('d/m/Y', strtotime($row['mfg_date'])) ?>
                    </p>
                    <span style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; padding:3px 10px; border-radius:6px; font-size:12px; font-weight:bold;">
                        <i class="fa-solid fa-warehouse"></i> ปัจจุบันกองอยู่ที่: <?= $hold_wh_name ?>
                    </span>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 24px; font-weight: 900; color: #1e293b; margin-bottom: 10px;">
                        <?= number_format($row['qty'], 2) ?> <small style="font-size: 15px; color:#64748b;"><?= $row['p_unit'] ?></small>
                    </div>
                    <button class="btn-qa" onclick="openQAModal('<?= $row['id'] ?>', '<?= $order_id ?>', '<?= $row['product_id'] ?>', '<?= $row['lot_no'] ?>', '<?= htmlspecialchars($row['p_name']) ?>', '<?= $row['qty'] ?>', '<?= $from_wh_id ?>', '<?= $hold_wh_name ?>')">
                        <i class="fa-solid fa-flask-vial"></i> ตรวจแล็บ & โอนย้ายคลัง
                    </button>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div style="text-align:center; padding:50px; background:white; border-radius:20px; color:#94a3b8; border: 1px dashed #cbd5e1;">
            <i class="fa-solid fa-clipboard-check fa-3x" style="margin-bottom:15px; opacity:0.5; color:#10b981;"></i><br>
            <h4 style="margin:0;">เคลียร์งานครบแล้ว!</h4>
        </div>
    <?php endif; ?>

    <!-- 🚀 ส่วนที่ 2: ประวัติการตรวจสอบ (History เดิมของคุณ) -->
    <div class="history-section">
        <h3 style="color:#475569; margin-top: 0; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;"><i class="fa-solid fa-file-certificate"></i> ประวัติการตรวจสอบสินค้า (QA History)</h3>
        
        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th width="15%">วัน/เวลา ที่ตรวจ</th>
                        <th width="25%">Lot No. / สินค้า</th>
                        <th width="30%">ผลแล็บ (Lab Results)</th>
                        <th width="15%">ผู้ตรวจ</th>
                        <th width="15%">สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // คิวรีดึงประวัติจากตาราง qa_outbound_logs ของคุณ
                    $res_history = mysqli_query($conn, "SELECT qa.*, p.p_name 
                                                        FROM qa_outbound_logs qa 
                                                        LEFT JOIN inventory_lots l ON qa.lot_no = l.lot_no 
                                                        LEFT JOIN products p ON l.product_id = p.id 
                                                        ORDER BY qa.id DESC LIMIT 50");
                    if ($res_history && mysqli_num_rows($res_history) > 0): 
                        while($hist = mysqli_fetch_assoc($res_history)): 
                            $badge = ($hist['qa_status'] == 'Approved') ? "<span class='status-badge' style='background:#ecfdf5; color:#047857;'><i class='fa-solid fa-check-circle'></i> ผ่าน</span>" : "<span class='status-badge' style='background:#fef2f2; color:#b91c1c;'><i class='fa-solid fa-xmark-circle'></i> ไม่ผ่าน</span>";
                    ?>
                            <tr>
                                <td><small style="color:#64748b; font-weight:bold;"><?= date('d/m/Y H:i', strtotime($hist['inspected_at'])) ?></small></td>
                                <td>
                                    <strong style="color: #1e293b; font-size:14px;"><?= $hist['lot_no'] ?></strong><br>
                                    <span style="color: #64748b; font-size:13px;"><?= $hist['p_name'] ?? 'ไม่ทราบสินค้า' ?></span>
                                </td>
                                <td>
                                    <span style="font-size:13px; color:#475569;">ชื้น: <strong style="color:#0ea5e9;"><?= $hist['moisture'] ?>%</strong> | โปรตีน: <strong style="color:#10b981;"><?= $hist['protein'] ?>%</strong> | ลักษณะ: <?= htmlspecialchars($hist['appearance']) ?></span><br>
                                    <?php if(!empty($hist['remark'])): ?>
                                        <small style="color: var(--danger); display:block; margin-top:4px;"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($hist['remark']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><small><i class="fa-solid fa-user-shield" style="color:#cbd5e1;"></i> <?= htmlspecialchars($hist['inspector_name']) ?></small></td>
                                <td><?= $badge ?></td>
                            </tr>
                    <?php 
                        endwhile; 
                    else: 
                    ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding:30px; color:#94a3b8;">ยังไม่มีประวัติการตรวจสอบ</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 🚀 Modal สำหรับบันทึกผลตรวจสอบ (รวมฟอร์มเก่าและใหม่) -->
<div id="qaModal" class="modal-overlay">
    <div class="modal-content">
        <h3 style="margin-top:0; color:#1e293b; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 20px;">
            <i class="fa-solid fa-microscope" style="color:var(--primary);"></i> บันทึกผลแล็บ & ย้ายคลัง
        </h3>
        
        <form method="POST" onsubmit="return confirm('ยืนยันผลการตรวจ? ระบบจะอัปเดตสต็อกและโอนย้ายคลังให้ทันที');">
            <!-- ซ่อนค่า ID ไว้ส่งไป PHP -->
            <input type="hidden" name="lot_id" id="m_lot_id">
            <input type="hidden" name="order_id" id="m_order_id">
            <input type="hidden" name="product_id" id="m_p_id">
            <input type="hidden" name="lot_no" id="m_lot_no">
            <input type="hidden" name="qty" id="m_qty">
            <input type="hidden" name="from_wh_id" id="m_from_wh_id">

            <div style="background: #f8fafc; padding: 15px; border-radius: 12px; margin-bottom: 20px; border: 1px dashed #cbd5e1; display:flex; justify-content:space-between;">
                <div>
                    <span style="font-size: 13px; color:#64748b;">สินค้าสำเร็จรูป:</span><br>
                    <strong id="m_p_name" style="font-size: 16px; color: #1e293b;">-</strong><br>
                    <span style="font-size: 13px; color:var(--primary); font-weight:bold;" id="m_lot_display">-</span>
                </div>
                <div style="text-align:right;">
                    <span style="font-size: 13px; color:#64748b;">จำนวนรับเข้า:</span><br>
                    <strong id="m_qty_display" style="font-size: 16px; color: var(--danger);">-</strong>
                </div>
            </div>

            <div style="background:#fffbeb; color:#b45309; padding:10px 15px; border-radius:8px; font-size:13px; font-weight:bold; margin-bottom:20px; border:1px solid #fde68a;">
                <i class="fa-solid fa-warehouse"></i> ปัจจุบันกองกักกันไว้ที่: <span id="m_hold_wh_display">-</span>
            </div>

            <!-- ส่วนผลแล็บของคุณ -->
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

            <!-- ส่วนกำหนดสถานะและคลังสินค้า (SAP Logic) -->
            <label class="form-label">1. ผลการตัดสิน (Decision) <span style="color:red;">*</span></label>
            <div class="radio-group">
                <label class="radio-card" id="card-pass" onclick="selectQA('Approved')">
                    <input type="radio" name="qa_status" value="Approved" id="radio-pass" required>
                    <i class="fa-solid fa-circle-check fa-2x" style="margin-bottom:5px;"></i><br>ผ่าน (Approved)
                </label>
                <label class="radio-card" id="card-fail" onclick="selectQA('Rejected')">
                    <input type="radio" name="qa_status" value="Rejected" id="radio-fail" required>
                    <i class="fa-solid fa-circle-xmark fa-2x" style="margin-bottom:5px;"></i><br>ไม่ผ่าน (Rejected)
                </label>
            </div>

            <div class="form-group">
                <label class="form-label">2. โอนย้ายไปโกดังปลายทาง <span style="color:red;">*</span> <small style="color:var(--primary);">(ระบบเลือกให้อัตโนมัติ)</small></label>
                <select name="wh_id" id="wh_select" class="form-control" required style="font-weight:bold; background:#e0f2fe; color:#0369a1;">
                    <option value="">-- กรุณาเลือกผลการตัดสินก่อน --</option>
                    <?php 
                    if ($res_wh) {
                        mysqli_data_seek($res_wh, 0);
                        while($wh = mysqli_fetch_assoc($res_wh)): 
                    ?>
                        <option value="<?= $wh['wh_id'] ?>" data-type="<?= $wh['wh_type'] ?>">
                            [<?= $wh['plant'] ?>] <?= $wh['wh_name'] ?> 
                        </option>
                    <?php 
                        endwhile; 
                    }
                    ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">3. หมายเหตุ <span id="remark_req" style="color:red; display:none;">* (บังคับใส่เมื่อไม่ผ่าน)</span></label>
                <textarea name="remark" id="mod_remark" class="form-control" rows="2" placeholder="เช่น ค่าความชื้นผ่านเกณฑ์, หรือ สีผิดเพี้ยนต้องกักกัน..."></textarea>
            </div>

            <div style="display:flex; gap:10px; margin-top:20px;">
                <button type="button" class="btn-qa" style="background:#e2e8f0; color:#475569; flex:1; justify-content:center;" onclick="closeModal()">ยกเลิก</button>
                <button type="submit" name="submit_qa" class="btn-qa" style="flex:2; justify-content:center;">
                    <i class="fa-solid fa-floppy-disk"></i> บันทึกและโอนย้ายคลัง
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openQAModal(lot_id, oid, pid, lot, name, qty, from_wh_id, hold_wh_name) {
        document.getElementById('m_lot_id').value = lot_id;
        document.getElementById('m_order_id').value = oid;
        document.getElementById('m_p_id').value = pid;
        document.getElementById('m_lot_no').value = lot;
        document.getElementById('m_qty').value = qty;
        document.getElementById('m_from_wh_id').value = from_wh_id;
        
        document.getElementById('m_p_name').innerText = name;
        document.getElementById('m_lot_display').innerText = 'Lot: ' + lot;
        document.getElementById('m_qty_display').innerText = qty + ' หน่วย';
        document.getElementById('m_hold_wh_display').innerText = hold_wh_name;
        
        document.getElementById('qaModal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('qaModal').style.display = 'none';
        document.getElementById('card-pass').classList.remove('active-pass');
        document.getElementById('card-fail').classList.remove('active-fail');
        document.getElementById('radio-pass').checked = false;
        document.getElementById('radio-fail').checked = false;
    }

    function selectQA(status) {
        let whSelect = document.getElementById('wh_select');
        let options = whSelect.options;
        let remarkField = document.getElementById('mod_remark');
        let remarkReq = document.getElementById('remark_req');

        if (status === 'Approved') {
            document.getElementById('card-pass').classList.add('active-pass');
            document.getElementById('card-fail').classList.remove('active-fail');
            document.getElementById('radio-pass').checked = true;
            remarkField.required = false;
            remarkReq.style.display = 'none';
            
            for (let i = 0; i < options.length; i++) {
                if(options[i].getAttribute('data-type') === 'Normal' && options[i].text.includes('โรงงาน 1')) {
                    whSelect.selectedIndex = i; break;
                }
            }
        } else {
            document.getElementById('card-fail').classList.add('active-fail');
            document.getElementById('card-pass').classList.remove('active-pass');
            document.getElementById('radio-fail').checked = true;
            remarkField.required = true;
            remarkReq.style.display = 'inline';
            
            for (let i = 0; i < options.length; i++) {
                if(options[i].getAttribute('data-type') === 'Hold' && options[i].text.includes('โรงงาน 1')) {
                    whSelect.selectedIndex = i; break;
                }
            }
        }
    }

    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('status') === 'success') {
        Swal.fire({ icon: 'success', title: 'บันทึกผลสำเร็จ!', text: 'ระบบโอนย้ายสต็อกเข้าคลังหลักเรียบร้อยแล้ว', timer: 2500, showConfirmButton: false })
        .then(() => { window.history.replaceState(null, null, window.location.pathname); });
    }
</script>
</body>
</html>