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
    echo "<script>alert('เฉพาะแผนกควบคุมคุณภาพเท่านั้น'); window.location='../index.php';</script>"; exit(); 
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_qa'])) {
    
    $lot_id = (int)$_POST['lot_id'];
    $p_id = (int)$_POST['product_id'];
    $lot_no = mysqli_real_escape_string($conn, $_POST['lot_no']);
    $qty = (float)$_POST['qty'];
    
    $qa_status = mysqli_real_escape_string($conn, $_POST['qa_status']);
    $remark = mysqli_real_escape_string($conn, $_POST['remark']);

    $from_wh_id = isset($_POST['from_wh_id']) ? (int)$_POST['from_wh_id'] : 0; // คลังกักกันที่ของอยู่
    $to_wh_id = isset($_POST['wh_id']) ? (int)$_POST['wh_id'] : 0; // 🚀 คลังปลายทางที่ QA เลือก

    if ($to_wh_id > 0) {
        $final_status = ($qa_status == 'Approved') ? 'Active' : 'Rejected_QA';
        
        // อัปเดตสถานะ Lot และเปลี่ยน wh_id ให้เป็นคลังใหม่
        mysqli_query($conn, "UPDATE inventory_lots SET status = '$final_status', wh_id = $to_wh_id, remark = '$remark' WHERE id = $lot_id");

        // 🚀 โอนย้ายสต็อกจาก Hold ไปคลัง/ไซโลใหม่
        if ($from_wh_id > 0 && $from_wh_id != $to_wh_id) {
            mysqli_query($conn, "UPDATE stock_balances SET qty = qty - $qty WHERE product_id = $p_id AND wh_id = $from_wh_id");
            mysqli_query($conn, "INSERT INTO stock_balances (product_id, wh_id, qty) VALUES ($p_id, $to_wh_id, $qty) ON DUPLICATE KEY UPDATE qty = qty + $qty");
            
            $ref_msg = "QA ตรวจ" . ($qa_status=='Approved'?"ผ่าน":"ไม่ผ่าน(โอนกักกัน)") . " โอนย้าย (Lot: $lot_no)";
            mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, from_wh_id, to_wh_id, reference, action_by) VALUES ($p_id, 'TRANSFER', $qty, $from_wh_id, $to_wh_id, '$ref_msg', '$fullname')");
        } else {
            // เผื่อ QA เลือกให้อยู่คลังเดิม
            mysqli_query($conn, "INSERT INTO stock_balances (product_id, wh_id, qty) VALUES ($p_id, $to_wh_id, $qty) ON DUPLICATE KEY UPDATE qty = qty + $qty");
        }

        // อัปเดตยอดรวมให้หน้าจอระบบเก่า
        if ($qa_status == 'Approved') {
            mysqli_query($conn, "UPDATE products SET p_qty = p_qty + $qty WHERE id = $p_id");
            if(function_exists('log_event')) { log_event($conn, 'UPDATE', 'inventory_lots', "QA ตรวจรับวัตถุดิบ (Lot: $lot_no) ผ่าน โอนเข้าคลัง ID: $to_wh_id"); }
        } else {
            if(function_exists('log_event')) { log_event($conn, 'UPDATE', 'inventory_lots', "QA ตรวจรับวัตถุดิบ (Lot: $lot_no) ไม่ผ่าน โอนเข้าของเสีย ID: $to_wh_id"); }
        }

        include_once '../line_api.php';
        $p_name = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p_name FROM products WHERE id = $p_id"))['p_name'] ?? 'วัตถุดิบ';
        $wh_name = mysqli_fetch_assoc(mysqli_query($conn, "SELECT wh_name FROM warehouses WHERE wh_id = $to_wh_id"))['wh_name'] ?? '-';
        
        if ($qa_status == 'Approved') {
            $msg = "✅ [QA Inbound] ตรวจผ่านโอนย้ายสำเร็จ!\n\n📦 วัตถุดิบ: $p_name\n🏷️ Lot: $lot_no\n📥 โอนเข้าเก็บที่: $wh_name\n\n👉 ฝ่ายผลิตสามารถเบิกใช้งานวัตถุดิบนี้ได้เลยครับ";
        } else {
            $msg = "🚨 [QA Inbound] ตรวจไม่ผ่าน (ตกเกรด)!\n\n📦 วัตถุดิบ: $p_name\n🏷️ Lot: $lot_no\n📥 โอนกักกันที่: $wh_name\n💬 สาเหตุ: $remark\n\n👉 ฝ่ายจัดซื้อโปรดประสานงานซัพพลายเออร์เพื่อส่งคืนครับ";
        }
        if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

        header("Location: qa_inbound.php?status=success"); exit;
    }
}

// 🚀 ดึงรายการที่รอตรวจ (พร้อมบอกคลัง Hold ที่มันกองอยู่)
$sql_pending = "SELECT il.*, p.p_name, p.p_unit, w.wh_name as hold_wh_name, w.plant 
                FROM inventory_lots il 
                JOIN products p ON il.product_id = p.id 
                LEFT JOIN warehouses w ON il.wh_id = w.wh_id
                WHERE il.status = 'Pending_QA' AND il.lot_no LIKE 'REC-%'
                ORDER BY il.id ASC";
$res_pending = mysqli_query($conn, $sql_pending);

$sql_history = "SELECT il.*, p.p_name, p.p_unit FROM inventory_lots il JOIN products p ON il.product_id = p.id WHERE il.status IN ('Active', 'Rejected_QA') AND il.lot_no LIKE 'REC-%' ORDER BY il.updated_at DESC LIMIT 50";
$res_history = mysqli_query($conn, $sql_history);

// 🚀 ดึงคลังให้ QA เลือกโอนย้าย
$res_wh = mysqli_query($conn, "SELECT * FROM warehouses ORDER BY plant ASC, wh_name ASC");

include '../sidebar.php';
?>

<title>ตรวจรับวัตถุดิบขาเข้า (QA Inbound) | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    :root { --primary: #f59e0b; --success: #10b981; --danger: #ef4444; --bg: #f8fafc; }
    body { font-family: 'Sarabun', sans-serif; background: var(--bg); }
    .content-padding { padding: 30px; max-width: 1400px; margin: auto; }
    
    .qa-card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 20px; border-left: 5px solid var(--primary); display: flex; justify-content: space-between; align-items: center; flex-wrap:wrap; gap:15px;}
    .btn-qa { background: var(--primary); color: white; border: none; padding: 12px 20px; border-radius: 10px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.2s;}
    .btn-qa:hover { background: #d97706; }
    
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); overflow-y:auto; padding:20px 0;}
    .modal-content { background: white; border-radius: 20px; width: 90%; max-width: 550px; padding: 35px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); margin:auto; }
    
    .form-label { display: block; font-weight: 800; margin-bottom: 8px; color: #334155; font-size: 14px; }
    .form-control { width: 100%; padding: 12px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-family: 'Sarabun'; margin-bottom: 20px; box-sizing: border-box; }
    
    .radio-group { display: flex; gap: 15px; margin-bottom: 20px; }
    .radio-card { flex: 1; border: 2px solid #e2e8f0; border-radius: 12px; padding: 15px; text-align: center; cursor: pointer; transition: 0.2s; font-weight: bold; color: #64748b; }
    .radio-card.active-pass { border-color: var(--success); background: #dcfce7; color: #166534; }
    .radio-card.active-fail { border-color: var(--danger); background: #fee2e2; color: #991b1b; }
    input[type="radio"] { display: none; }

    table { width: 100%; border-collapse: collapse; min-width:800px; }
    th { text-align: left; padding: 15px; background: #f8fafc; font-size: 13px; font-weight: 800; color: #64748b; border-bottom: 2px solid #e2e8f0; }
    td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 14px; color: #1e293b; }
</style>

<div class="content-padding">
    <div style="margin-bottom: 30px;">
        <h2 style="margin:0; color:#1e293b; font-weight:800;"><i class="fa-solid fa-microscope" style="color:var(--primary);"></i> ตรวจรับวัตถุดิบ (QA Inbound)</h2>
        <p style="color:#64748b;">ตรวจสอบคุณภาพวัตถุดิบ และทำการโอนย้ายจากคลังกักกัน(Hold) เข้าไซโล/คลังหลัก</p>
    </div>

    <h3 style="color:#475569; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;"><i class="fa-regular fa-clock"></i> รอตรวจสอบคุณภาพ</h3>
    <?php if ($res_pending && mysqli_num_rows($res_pending) > 0): ?>
        <?php while($row = mysqli_fetch_assoc($res_pending)): 
            $from_wh_id = $row['wh_id'];
            $hold_wh_name = ($row['hold_wh_name']) ? "[{$row['plant']}] {$row['hold_wh_name']}" : "ไม่ระบุคลัง";
            
            // หาคลังเผื่อเป็นของเก่า
            if (empty($from_wh_id)) {
                $p_id_sr = $row['product_id']; $lot_sr = $row['lot_no'];
                $q_find_wh = mysqli_query($conn, "SELECT sl.to_wh_id, w.wh_name, w.plant FROM stock_log sl JOIN warehouses w ON sl.to_wh_id = w.wh_id WHERE sl.product_id = $p_id_sr AND sl.reference LIKE '%$lot_sr%' AND sl.type = 'IN' ORDER BY sl.id DESC LIMIT 1");
                if($q_find_wh && mysqli_num_rows($q_find_wh) > 0) {
                    $f_wh = mysqli_fetch_assoc($q_find_wh);
                    $from_wh_id = $f_wh['to_wh_id'];
                    $hold_wh_name = "[{$f_wh['plant']}] {$f_wh['wh_name']}";
                }
            }
        ?>
            <div class="qa-card">
                <div>
                    <span style="background:#fef3c7; color:#b45309; padding:4px 12px; border-radius:50px; font-size:12px; font-weight:bold;"><i class="fa-solid fa-truck-ramp-box"></i> รับเข้าจากผู้ขาย</span><br>
                    <h3 style="margin: 10px 0 5px 0; color:#1e293b;"><?= htmlspecialchars($row['p_name']) ?></h3>
                    <p style="margin:0; color:#64748b; font-size:14px; margin-bottom:5px;">
                        <i class="fa-solid fa-tag"></i> Lot No: <strong style="color:var(--primary);"><?= $row['lot_no'] ?></strong> | 
                        <i class="fa-solid fa-calendar-day"></i> วันที่รับเข้า: <?= date('d/m/Y', strtotime($row['mfg_date'])) ?>
                    </p>
                    <span style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; padding:3px 10px; border-radius:6px; font-size:12px; font-weight:bold;">
                        <i class="fa-solid fa-warehouse"></i> ปัจจุบันกองกักกันอยู่ที่: <?= $hold_wh_name ?>
                    </span>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 24px; font-weight: 900; color: #1e293b; margin-bottom: 10px;">
                        <?= number_format($row['qty'], 2) ?> <small style="font-size: 15px; color:#64748b;"><?= $row['p_unit'] ?></small>
                    </div>
                    <button class="btn-qa" onclick="openQAModal('<?= $row['id'] ?>', '<?= $row['product_id'] ?>', '<?= $row['lot_no'] ?>', '<?= htmlspecialchars($row['p_name']) ?>', '<?= $row['qty'] ?>', '<?= $from_wh_id ?>', '<?= $hold_wh_name ?>')">
                        <i class="fa-solid fa-clipboard-check"></i> ตรวจแล็บ & โอนย้ายเข้าไซโล
                    </button>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div style="text-align:center; padding:50px; background:white; border-radius:20px; color:#94a3b8; border: 1px dashed #cbd5e1;">
            <i class="fa-solid fa-check-circle fa-3x" style="margin-bottom:15px; color:#10b981; opacity:0.5;"></i><br>
            <h4>ไม่มีวัตถุดิบรอตรวจในขณะนี้</h4>
        </div>
    <?php endif; ?>

    <div style="background:white; border-radius:15px; padding:25px; margin-top:40px; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
        <h3 style="color:#475569; margin-top: 0; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;"><i class="fa-solid fa-clock-rotate-left"></i> ประวัติการตรวจวัตถุดิบ (QA History)</h3>
        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>วันที่ตรวจประเมิน</th>
                        <th>Lot No. / วัตถุดิบ</th>
                        <th style="text-align:right;">ปริมาณ</th>
                        <th>ผลการตรวจสอบ</th>
                        <th>หมายเหตุ QA</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(mysqli_num_rows($res_history) > 0): ?>
                        <?php while($hist = mysqli_fetch_assoc($res_history)): ?>
                            <tr>
                                <td><small style="color:#64748b; font-weight:bold;"><?= date('d/m/Y H:i', strtotime($hist['updated_at'])) ?></small></td>
                                <td>
                                    <strong style="color: #1e293b; font-size:14px;"><?= $hist['lot_no'] ?></strong><br>
                                    <span style="color: #64748b; font-size:13px;"><?= htmlspecialchars($hist['p_name']) ?></span>
                                </td>
                                <td align="right"><b><?= number_format($hist['qty'], 2) ?></b> <small><?= $hist['p_unit'] ?></small></td>
                                <td>
                                    <?php if($hist['status'] == 'Active'): ?>
                                        <span style="background:#dcfce7; color:#166534; padding:5px 12px; border-radius:50px; font-size:12px; font-weight:bold;"><i class="fa-solid fa-check-circle"></i> ผ่าน (โอนเข้าคลังหลัก)</span>
                                    <?php else: ?>
                                        <span style="background:#fee2e2; color:#991b1b; padding:5px 12px; border-radius:50px; font-size:12px; font-weight:bold;"><i class="fa-solid fa-xmark-circle"></i> ตกเกรด (ของเสีย)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:#64748b; font-size:13px;"><?= htmlspecialchars($hist['remark']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align:center; padding:30px; color:#94a3b8;">ยังไม่มีประวัติการตรวจสอบ</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="qaModal" class="modal-overlay">
    <div class="modal-content">
        <h3 style="margin-top:0; color:var(--text-main); font-size:22px;"><i class="fa-solid fa-microscope" style="color:var(--primary);"></i> บันทึกผลแล็บ & โอนย้ายไซโล</h3>
        
        <form method="POST" onsubmit="return confirm('ยืนยันผลการตรวจ? ระบบจะทำการโอนย้ายคลังให้ทันที');">
            <input type="hidden" name="lot_id" id="m_lot_id">
            <input type="hidden" name="product_id" id="m_p_id">
            <input type="hidden" name="lot_no" id="m_lot_no">
            <input type="hidden" name="qty" id="m_qty">
            <input type="hidden" name="from_wh_id" id="m_from_wh_id">

            <div style="background:#f8fafc; padding:15px; border-radius:12px; margin-bottom:20px; border:1px dashed #cbd5e1; display:flex; justify-content:space-between;">
                <div>
                    <span style="font-size:13px; color:#64748b;">วัตถุดิบที่กำลังตรวจ:</span><br>
                    <strong id="m_p_name" style="font-size:16px; color:#1e293b;">-</strong>
                    <div style="color:var(--primary); font-weight:bold; margin-top:5px;" id="m_lot_display">-</div>
                </div>
                <div style="text-align:right;">
                    <span style="font-size:13px; color:#64748b;">ปริมาณโอนย้าย:</span><br>
                    <strong id="m_qty_display" style="font-size:16px; color:var(--danger);">-</strong>
                </div>
            </div>

            <div style="background:#fffbeb; color:#b45309; padding:10px 15px; border-radius:8px; font-size:13px; font-weight:bold; margin-bottom:20px; border:1px solid #fde68a;">
                <i class="fa-solid fa-warehouse"></i> ปัจจุบันกองกักกันไว้ที่: <span id="m_hold_wh_display">-</span>
            </div>

            <label class="form-label">1. ผลการตัดสิน (QA Decision) <span style="color:red;">*</span></label>
            <div class="radio-group">
                <label class="radio-card" id="card-pass" onclick="selectQA('Approved')">
                    <input type="radio" name="qa_status" value="Approved" id="radio-pass" required>
                    <i class="fa-solid fa-circle-check fa-2x" style="margin-bottom:5px;"></i><br>ผ่าน (โอนเข้าไซโล)
                </label>
                <label class="radio-card" id="card-fail" onclick="selectQA('Rejected')">
                    <input type="radio" name="qa_status" value="Rejected" id="radio-fail" required>
                    <i class="fa-solid fa-circle-xmark fa-2x" style="margin-bottom:5px;"></i><br>ไม่ผ่าน (ตีกลับ/ทิ้ง)
                </label>
            </div>

            <div class="form-group">
                <label class="form-label">2. โอนย้ายเข้าโกดัง / ไซโลปลายทาง <span style="color:red;">*</span> <small style="color:var(--primary);">(ระบบเลือกให้อัตโนมัติ)</small></label>
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
                <textarea name="remark" id="mod_remark" class="form-control" rows="2" placeholder="ระบุสาเหตุ..."></textarea>
            </div>

            <div style="display:flex; gap:10px; margin-top:20px;">
                <button type="button" class="btn-qa" style="background:#e2e8f0; color:#475569; flex:1; justify-content:center;" onclick="closeModal()">ยกเลิก</button>
                <button type="submit" name="save_qa" class="btn-qa" style="flex:2; justify-content:center;">บันทึกผล & โอนย้ายคลัง</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openQAModal(lot_id, pid, lot, name, qty, from_wh_id, hold_wh_name) {
        document.getElementById('m_lot_id').value = lot_id;
        document.getElementById('m_p_id').value = pid;
        document.getElementById('m_lot_no').value = lot;
        document.getElementById('m_qty').value = qty;
        document.getElementById('m_from_wh_id').value = from_wh_id;
        
        document.getElementById('m_p_name').innerText = name;
        document.getElementById('m_lot_display').innerText = 'Lot: ' + lot;
        document.getElementById('m_qty_display').innerText = parseFloat(qty).toLocaleString() + ' หน่วย';
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
                if(options[i].getAttribute('data-type') === 'Silo' || options[i].getAttribute('data-type') === 'Normal') {
                    if(options[i].text.includes('โรงงาน 1')) { whSelect.selectedIndex = i; break; }
                }
            }
        } else {
            document.getElementById('card-fail').classList.add('active-fail');
            document.getElementById('card-pass').classList.remove('active-pass');
            document.getElementById('radio-fail').checked = true;
            remarkField.required = true;
            remarkReq.style.display = 'inline';
            
            for (let i = 0; i < options.length; i++) {
                if(options[i].getAttribute('data-type') === 'Hold' || options[i].getAttribute('data-type') === 'Scrap') {
                    if(options[i].text.includes('โรงงาน 1')) { whSelect.selectedIndex = i; break; }
                }
            }
        }
    }

    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('status') === 'success') {
        Swal.fire({ icon: 'success', title: 'ตรวจและโอนย้ายสำเร็จ!', text: 'ระบบย้ายวัตถุดิบเข้าไซโลเรียบร้อยแล้ว', timer: 2500, showConfirmButton: false })
        .then(() => { window.history.replaceState(null, null, window.location.pathname); });
    }
</script>