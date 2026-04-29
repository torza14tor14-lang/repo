<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
$fullname = $_SESSION['fullname'] ?? 'พนักงานคลังสินค้า';

$allowed_depts = ['แผนกคลังสินค้า 1', 'แผนกคลังสินค้า 2', 'ฝ่ายจัดซื้อ'];
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $allowed_depts)) { 
    echo "<script>alert('เฉพาะฝ่ายคลังสินค้าและผู้บริหารเท่านั้น'); window.location='../index.php';</script>"; exit(); 
}

// 🚀 ปลดล็อคคอลัมน์ status ให้สามารถรับคำศัพท์ใหม่ๆ ได้
mysqli_query($conn, "ALTER TABLE `purchase_orders` MODIFY `status` VARCHAR(50) DEFAULT 'Pending'");

// 🚀 ซ่อมแซมข้อมูลที่อาจจะเคยบันทึกพังเป็นค่าว่างไปก่อนหน้านี้
mysqli_query($conn, "UPDATE `purchase_orders` SET `status` = 'Received_Pending_QA' WHERE `status` = ''");

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['receive_po'])) {
    $po_id = (int)$_POST['po_id'];
    $invoice_ref = mysqli_real_escape_string($conn, $_POST['invoice_ref']);
    $remark = mysqli_real_escape_string($conn, $_POST['remark']);

    $q_items = mysqli_query($conn, "SELECT * FROM po_items WHERE po_id = $po_id");
    $receive_success = true;
    $po_needs_qa = false; // ตัวแปรเช็คว่าในบิลนี้มี "วัตถุดิบ" ที่ต้องผ่าน QA ไหม
    
    if ($q_items && mysqli_num_rows($q_items) > 0) {
        while ($item = mysqli_fetch_assoc($q_items)) {
            $product_id = (int)$item['item_id'];
            $qty = (float)$item['quantity'];

            // 🚀 ดึงประเภทสินค้า (p_type) มาเพื่อคัดแยกทางด่วน
            $q_prod = mysqli_query($conn, "SELECT p_name, p_type, shelf_life_days FROM products WHERE id = $product_id");
            $prod = mysqli_fetch_assoc($q_prod);
            $p_type = $prod['p_type'] ?? '';
            $shelf_life = (int)($prod['shelf_life_days'] ?? 0);
            
            // สร้างเลข LOT ให้กับสินค้าที่เข้ามา
            $lot_no = "REC-" . date('Ymd') . "-" . str_pad($po_id, 4, "0", STR_PAD_LEFT) . "-" . $product_id;
            $mfg = date('Y-m-d');
            $exp = ($shelf_life > 0) ? date('Y-m-d', strtotime("+$shelf_life days")) : '2099-12-31';

            // 🚀 ตรรกะคัดแยกประเภทสินค้า (Bypass Logic)
            if ($p_type === 'RAW') {
                // 🛑 กรณี 1: วัตถุดิบ (RAW) -> ต้องเข้าโซนกักกันรอ QA ตรวจก่อน
                $sql_lot = "INSERT INTO inventory_lots (product_id, lot_no, mfg_date, exp_date, qty, status) 
                            VALUES ($product_id, '$lot_no', '$mfg', '$exp', $qty, 'Pending_QA')";
                if (!mysqli_query($conn, $sql_lot)) { $receive_success = false; }
                
                $po_needs_qa = true; // ล็อคเป้าว่าบิลนี้ยังไงก็ต้องรอ QA เคาะ
                
            } else {
                // 🟢 กรณี 2: ของใช้/อะไหล่ (SPARE, SUPPLY, ASSET ฯลฯ) -> ทะลุเข้าสต็อก Active เลย
                $sql_lot = "INSERT INTO inventory_lots (product_id, lot_no, mfg_date, exp_date, qty, status) 
                            VALUES ($product_id, '$lot_no', '$mfg', '$exp', $qty, 'Active')";
                
                if (mysqli_query($conn, $sql_lot)) {
                    // ปรับยอดสต็อกหลัก (products) ให้เบิกได้ทันที
                    mysqli_query($conn, "UPDATE products SET p_qty = p_qty + $qty WHERE id = $product_id");
                    
                    // บันทึก Log ว่าของชิ้นนี้ไม่ต้องผ่าน QA
                    mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, reference, action_by) 
                                         VALUES ($product_id, 'IN', $qty, 'รับเข้าคลัง: ของใช้/อะไหล่ PO-$po_id (Bypass QA)', '$fullname')");
                } else {
                    $receive_success = false;
                }
            }
        }

        if ($receive_success) {
            // 🚀 อัปเดตสถานะบิล PO ตามประเภทของที่อยู่ข้างใน
            $final_status = $po_needs_qa ? 'Received_Pending_QA' : 'Delivered';
            mysqli_query($conn, "UPDATE purchase_orders SET status = '$final_status' WHERE po_id = $po_id");

            // 🚀 บันทึกประวัติ Log ลงระบบ
            if(function_exists('log_event')) {
                $log_msg = $po_needs_qa ? "รับวัตถุดิบเข้าลาน PO-$po_id (ส่งรอกักกัน QA)" : "รับของใช้/อะไหล่ PO-$po_id (ผ่านเข้าสต็อกสำเร็จ)";
                log_event($conn, 'UPDATE', 'purchase_orders', $log_msg);
            }

            // แจ้งเตือน LINE โดยแยกตามประเภท
            include_once '../line_api.php';
            $po_info = mysqli_fetch_assoc(mysqli_query($conn, "SELECT supplier_name FROM purchase_orders WHERE po_id = $po_id"));
            $msg = "🚛 [คลังสินค้า] รับสินค้าลงลาน/เข้าคลังเรียบร้อย\n\n";
            $msg .= "🔖 อ้างอิง PO ID: PO-" . str_pad($po_id, 5, '0', STR_PAD_LEFT) . "\n";
            $msg .= "🏢 จากซัพพลายเออร์: " . ($po_info['supplier_name'] ?? 'ไม่ระบุ') . "\n";
            $msg .= "📄 เลขที่บิล/ใบส่งของ: " . $invoice_ref . "\n\n";
            
            if ($po_needs_qa) {
                $msg .= "⚠️ สถานะ: มีวัตถุดิบกักกันรอตรวจสอบคุณภาพ\n";
                $msg .= "👉 ฝ่าย QA โปรดเข้าตรวจสอบก่อนอนุมัติให้ฝ่ายผลิตใช้งานครับ";
            } else {
                $msg .= "✅ สถานะ: ของใช้/อะไหล่ นำเข้าสต็อกคลังสำเร็จ\n";
                $msg .= "👉 พนักงานสามารถดำเนินการเบิกไปใช้งานได้ทันทีครับ (ไม่ต้องผ่าน QA)";
            }
            
            if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

            header("Location: receive_po.php?status=success"); exit;
        }
    } else {
        header("Location: receive_po.php?status=error_no_items"); exit;
    }
}

include '../sidebar.php';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Top Feed Mills | รับสินค้าเข้าคลัง (GRN)</title>
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
        .btn-action { background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%); color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.3s; display: inline-flex; align-items: center; gap: 8px; font-family: 'Sarabun'; }
        .table-responsive { overflow-x: auto; width: 100%; border-radius: 10px; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 900px; }
        th, td { padding: 15px; border-bottom: 1px solid #f0f0f0; text-align: left; vertical-align: middle; }
        th { background: #f8f9fa; color: #6c757d; font-weight: bold; font-size: 0.85rem; text-transform: uppercase; white-space: nowrap; }
        .badge-status { padding: 5px 12px; border-radius: 50px; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
        .st-approved { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }
        .st-delivered { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .st-wait { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .st-rejected { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; }
        .modal-content { background: white; border-radius: 16px; width: 90%; max-width: 600px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); animation: pop 0.3s ease; }
        @keyframes pop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .modal-header { padding: 20px; border-bottom: 1px solid #eaecf4; display: flex; justify-content: space-between; align-items: center; background: #f8f9fc; border-radius: 16px 16px 0 0;}
        .modal-close { cursor: pointer; font-size: 24px; color: #858796; }
        .modal-body { padding: 25px; }
        .info-box { background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 20px; color: #0d47a1; font-size: 15px;}
    </style>
</head>
<body>

<div class="content-padding">
    <div class="wrapper">
        <div class="container-stacked">

            <div class="card" style="border-top: 4px solid #f6c23e;">
                <h3><i class="fa-solid fa-truck-ramp-box" style="color: #f6c23e;"></i> รายการสั่งซื้อที่รอรับสินค้าเข้าลาน/เข้าคลัง (GRN)</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>PO ID / เลขอ้างอิง</th>
                                <th>ซัพพลายเออร์ (ผู้ขาย)</th>
                                <th>กำหนดส่งของ</th>
                                <th>จำนวนรายการ</th>
                                <th>สถานะ</th>
                                <th style="text-align:right;">ดำเนินการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql_pending = "SELECT po.*, (SELECT COUNT(*) FROM po_items WHERE po_id = po.po_id) as total_items 
                                            FROM purchase_orders po 
                                            WHERE po.status IN ('Approved', 'Manager_Approved')
                                            ORDER BY po.expected_delivery_date ASC";
                            $res_pending = mysqli_query($conn, $sql_pending);
                            
                            if ($res_pending && mysqli_num_rows($res_pending) > 0) {
                                while($row = mysqli_fetch_assoc($res_pending)) {
                            ?>
                                <tr>
                                    <td><strong style="color:#4e73df; font-size: 1.1rem;">PO-<?= str_pad($row['po_id'], 5, '0', STR_PAD_LEFT) ?></strong></td>
                                    <td><strong style="color:#2c3e50;"><i class="fa-solid fa-building"></i> <?= htmlspecialchars($row['supplier_name']) ?></strong></td>
                                    <td><strong style="color:#e74a3b;"><?= date('d/m/Y', strtotime($row['expected_delivery_date'])) ?></strong></td>
                                    <td><span style="background:#e2e8f0; padding:4px 10px; border-radius:50px; font-weight:bold; color:#333;"><?= $row['total_items'] ?> รายการ</span></td>
                                    <td><span class="badge-status st-approved"><i class="fa-solid fa-plane-arrival"></i> รถกำลังมาส่ง</span></td>
                                    <td style="text-align:right;">
                                        <button type="button" class="btn-action" 
                                            data-id="<?= $row['po_id'] ?>" 
                                            data-supplier="<?= htmlspecialchars($row['supplier_name']) ?>" 
                                            data-items="<?= $row['total_items'] ?>" 
                                            onclick="openReceiveModal(this)">
                                            <i class="fa-solid fa-box-open"></i> รับสินค้าเข้าคลัง
                                        </button>
                                    </td>
                                </tr>
                            <?php } } else { echo "<tr><td colspan='6' style='text-align:center; padding:50px; color:#888;'>ไม่มีรายการสั่งซื้อที่รอรับของ</td></tr>"; } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card" style="border-top: 4px solid #1cc88a;">
                <h3><i class="fa-solid fa-clock-rotate-left" style="color: #1cc88a;"></i> ประวัติการรับสินค้าเข้าคลังและผลตรวจ QA</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>PO ID</th>
                                <th>ซัพพลายเออร์</th>
                                <th>สถานะปัจจุบัน</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql_history = "SELECT * FROM purchase_orders WHERE status IN ('Received_Pending_QA', 'Completed', 'QA_Rejected', 'Delivered') ORDER BY po_id DESC LIMIT 30";
                            $res_history = mysqli_query($conn, $sql_history);
                            
                            if ($res_history && mysqli_num_rows($res_history) > 0) {
                                while($row = mysqli_fetch_assoc($res_history)) {
                                    if ($row['status'] == 'Received_Pending_QA') {
                                        $badge = "<span class='badge-status st-wait'><i class='fa-solid fa-microscope'></i> กักกัน (รอ QA ตรวจวัตถุดิบ)</span>";
                                    } elseif ($row['status'] == 'QA_Rejected') {
                                        $badge = "<span class='badge-status st-rejected'><i class='fa-solid fa-ban'></i> QA ตีกลับ (ของเสีย)</span>";
                                    } else {
                                        $badge = "<span class='badge-status st-delivered'><i class='fa-solid fa-check-double'></i> ของเข้าคลังสมบูรณ์</span>";
                                    }
                            ?>
                                <tr>
                                    <td><strong style="color:#4e73df;">PO-<?= str_pad($row['po_id'], 5, '0', STR_PAD_LEFT) ?></strong></td>
                                    <td><strong style="color:#2c3e50;"><?= htmlspecialchars($row['supplier_name']) ?></strong></td>
                                    <td><?= $badge ?></td>
                                </tr>
                            <?php } } else { echo "<tr><td colspan='3' style='text-align:center; padding:30px; color:#888;'>ยังไม่มีประวัติการรับสินค้า</td></tr>"; } ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<div id="receiveModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3 style="color:#4e73df; margin:0;"><i class="fa-solid fa-dolly"></i> ยืนยันรับสินค้าเข้าคลัง</h3>
            <div class="modal-close" onclick="closeReceiveModal()"><i class="fa-solid fa-xmark"></i></div>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="po_id" id="mod_po_id">
                <div class="info-box">
                    <i class="fa-solid fa-truck"></i> <strong>รับของจาก:</strong> <span id="mod_supplier"></span><br>
                    <i class="fa-solid fa-list-check" style="margin-top: 8px;"></i> <strong>จำนวน:</strong> <span id="mod_items" style="color: #e74a3b;"></span> รายการ
                </div>
                <div style="background: #fff5f5; padding: 12px; border-radius: 8px; border: 1px dashed #f5c6cb; color: #c0392b; font-size: 13px; margin-bottom: 15px;">
                    <i class="fa-solid fa-circle-info"></i> ระบบจะคัดแยกอัตโนมัติ: ถ้าเป็น <b>"วัตถุดิบ"</b> จะถูกกักกันส่งให้ QA ตรวจ แต่ถ้าเป็น <b>"อะไหล่/ของใช้"</b> จะตัดเข้าคลังให้เบิกใช้งานได้ทันที
                </div>
                <div class="form-group">
                    <label>เลขที่ใบส่งของ (Invoice Ref.) <span style="color:red;">*</span></label>
                    <input type="text" name="invoice_ref" required>
                </div>
                <div class="form-group">
                    <label>หมายเหตุ</label>
                    <textarea name="remark" rows="2"></textarea>
                </div>
                <button type="submit" name="receive_po" class="btn-action" style="width:100%; justify-content:center; font-size: 16px; padding: 12px;">
                    <i class="fa-solid fa-check-double"></i> ยืนยันรับของ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openReceiveModal(btn) {
        document.getElementById('mod_po_id').value = btn.getAttribute('data-id');
        document.getElementById('mod_supplier').innerText = btn.getAttribute('data-supplier');
        document.getElementById('mod_items').innerText = btn.getAttribute('data-items');
        document.getElementById('receiveModal').style.display = 'flex';
    }
    function closeReceiveModal() { document.getElementById('receiveModal').style.display = 'none'; }
    window.onclick = function(e) { if (e.target == document.getElementById('receiveModal')) closeReceiveModal(); }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('status') === 'success') {
        Swal.fire({ icon: 'success', title: 'รับสินค้าสำเร็จ!', text: 'ระบบจัดเรียงและตัดสต็อกสินค้าเรียบร้อยแล้ว', timer: 3000, showConfirmButton: false })
        .then(() => window.history.replaceState(null, null, window.location.pathname));
    } else if (urlParams.get('status') === 'error_no_items') {
        Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: 'ไม่พบรายการสินค้าในใบสั่งซื้อนี้', timer: 3000, showConfirmButton: false })
        .then(() => window.history.replaceState(null, null, window.location.pathname));
    }
</script>
</body>
</html>