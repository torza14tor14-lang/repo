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

    // บันทึกผลแล็บลงฐานข้อมูล QA
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
    <title>Top Feed Mills | ตรวจสินค้าสำเร็จรูป (QA)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .wrapper { font-family: 'Sarabun', sans-serif; animation: fadeIn 0.5s ease-in-out; }
        .card { background: #ffffff; padding: 25px 30px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); margin-bottom: 25px;}
        h3 { color: #2c3e50; margin-top: 0; margin-bottom: 20px; font-weight: 600; border-bottom: 2px solid #f1f2f6; padding-bottom: 12px;}
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th { background: #f8f9fa; color: #4e73df; padding: 15px; text-align: left; }
        td { padding: 15px; border-bottom: 1px solid #f0f0f0; }
        .btn-cens { background: linear-gradient(135deg, #c81c1c 0%, #851313 100%); color: white; border: none; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-family: 'Sarabun'; font-weight:bold;}
        .btn-inspect { background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%); color: white; border: none; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-family: 'Sarabun'; font-weight:bold;}
        .badge-status { padding: 5px 12px; border-radius: 50px; font-size: 12px; font-weight: bold; }
        .st-approved { background: #d4edda; color: #155724; } .st-rejected { background: #f8d7da; color: #721c24; }
        .st-rma { background: #e3f2fd; color: #0d47a1; border: 1px solid #bbdefb; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(2px);}
        .modal-content { background: white; border-radius: 16px; width: 90%; max-width: 600px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); animation: pop 0.3s ease; }
        @keyframes pop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 8px; margin-bottom: 15px; box-sizing: border-box; font-family: 'Sarabun';}
    </style>
</head>
<body>

<div class="content-padding">
    <div class="wrapper">
        <div class="card" style="border-top: 4px solid #f6c23e;">
            <h3><i class="fa-solid fa-microscope" style="color: #f6c23e;"></i> สินค้าสำเร็จรูป (รอกักกันตรวจสอบคุณภาพ)</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ประเภท</th>
                            <th>LOT No. (จากผลิต/คลัง)</th>
                            <th>สินค้า (Formula)</th>
                            <th>ปริมาณ</th>
                            <th>สถานะ QA</th>
                            <th style="text-align:right;">ดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // ดึงข้อมูลทั้ง LOT ที่ผลิตใหม่ (LOT-) และ LOT ที่ลูกค้าตีกลับ (RMA-)
                        $sql_pending = "SELECT l.id, l.lot_no, l.qty, l.product_id, p.p_name 
                                        FROM inventory_lots l 
                                        JOIN products p ON l.product_id = p.id 
                                        WHERE l.status = 'Pending_QA' AND (l.lot_no LIKE 'LOT-%' OR l.lot_no LIKE 'RMA-%') 
                                        ORDER BY l.id ASC";
                        $res_pending = mysqli_query($conn, $sql_pending);
                        
                        if ($res_pending && mysqli_num_rows($res_pending) > 0) {
                            while($row = mysqli_fetch_assoc($res_pending)) {
                                $is_rma = (strpos($row['lot_no'], 'RMA-') === 0);
                                $type_badge = $is_rma ? "<span class='badge-status st-rma'><i class='fa-solid fa-arrow-rotate-left'></i> รับคืนจากลูกค้า</span>" : "<span style='color:#888; font-size:13px;'><i class='fa-solid fa-industry'></i> ผลิตใหม่</span>";
                                
                                // สกัดเอา Order ID ออกมาจาก Lot (ถ้ามี)
                                $order_id = 0;
                                if (!$is_rma) {
                                    $parts = explode('-', $row['lot_no']);
                                    if(isset($parts[2])) $order_id = (int)$parts[2];
                                }
                        ?>
                                <tr>
                                    <td><?= $type_badge ?></td>
                                    <td><span style="background:#e3f2fd; color:#4e73df; padding:4px 8px; border-radius:4px; font-weight:bold;"><?= $row['lot_no'] ?></span></td>
                                    <td><strong><?= htmlspecialchars($row['p_name']) ?></strong></td>
                                    <td><strong style="color:#e74a3b;"><?= number_format($row['qty'], 2) ?></strong></td>
                                    <td><span class="badge-status" style="background:#fff3cd; color:#856404;">รอกักกันตรวจสอบ</span></td>
                                    <td style="text-align:right;">
                                        <button class="btn-inspect" onclick="openQAModal('<?= $order_id ?>', '<?= $row['product_id'] ?>', '<?= htmlspecialchars($row['p_name']) ?>', '<?= $row['qty'] ?>', '<?= $row['lot_no'] ?>')">
                                            <i class="fa-solid fa-flask-vial"></i> บันทึกผล
                                        </button>
                                    </td>
                                </tr>
                        <?php 
                            } 
                        } else { echo "<tr><td colspan='6' style='text-align:center; color:#888; padding:30px;'><i class='fa-solid fa-check-circle fa-2x' style='color:#1cc88a; margin-bottom:10px;'></i><br>ไม่มีสินค้าที่รอการตรวจสอบ</td></tr>"; }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" style="border-top: 4px solid #4e73df;">
            <h3><i class="fa-solid fa-file-certificate"></i> ประวัติการตรวจสอบ (History)</h3>
            <div class="table-responsive">
                <table>
                    <tr><th>วันที่ตรวจ</th><th>Lot No. / สินค้า</th><th>ผลแล็บ</th><th>ผู้ตรวจ</th><th>สถานะ</th></tr>
                    <?php
                    $res_history = mysqli_query($conn, "SELECT qa.*, p.p_name FROM qa_outbound_logs qa LEFT JOIN inventory_lots l ON qa.lot_no = l.lot_no LEFT JOIN products p ON l.product_id = p.id ORDER BY qa.id DESC LIMIT 50");
                    if ($res_history && mysqli_num_rows($res_history) > 0) {
                        while($row = mysqli_fetch_assoc($res_history)) {
                            $badge = ($row['qa_status'] == 'Approved') ? "<span class='badge-status st-approved'>ผ่าน (นำเข้าคลัง)</span>" : "<span class='badge-status st-rejected'>ไม่ผ่าน (ทำลายทิ้ง)</span>";
                            $pname = $row['p_name'] ?? 'ไม่ทราบข้อมูลสินค้า';
                            echo "<tr>
                                    <td>".date('d/m/Y H:i', strtotime($row['inspected_at']))."</td>
                                    <td><strong>{$row['lot_no']}</strong><br>{$pname}</td>
                                    <td>ชื้น: {$row['moisture']}% | โปรตีน: {$row['protein']}%</td>
                                    <td>{$row['inspector_name']}</td><td>{$badge}</td>
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
        <h3 style="color:#4e73df; border-bottom:1px solid #ddd; padding-bottom:10px;">บันทึกผลวิเคราะห์</h3>
        <form method="POST">
            <input type="hidden" name="order_id" id="qa_order_id">
            <input type="hidden" name="product_id" id="qa_product_id">
            <input type="hidden" name="lot_no" id="qa_lot_no">
            <input type="hidden" name="qty" id="qa_qty_val">

            <div style="background:#f8f9fc; padding:15px; border-radius:8px; margin-bottom:15px;">
                สินค้า: <strong id="qa_product_name" style="font-size:16px;"></strong> (<span id="qa_qty" style="color:#e74a3b; font-weight:bold;"></span>)<br>
                LOT: <strong id="qa_lot_display" style="color:#4e73df; font-size:15px;"></strong>
            </div>

            <div style="display:flex; gap:10px;">
                <div style="flex:1;"><label>ความชื้น %</label><input type="number" step="0.01" name="moisture" class="form-control" required></div>
                <div style="flex:1;"><label>โปรตีน %</label><input type="number" step="0.01" name="protein" class="form-control" required></div>
            </div>
            
            <label>กายภาพ</label>
            <select name="appearance" class="form-control"><option value="ปกติ">ปกติ (ไม่มีมอด/แมลง)</option><option value="ผิดปกติ">ผิดปกติ (พบสิ่งเจือปน)</option></select>
            
            <label>สถานะ (การตัดสินใจ) <span style="color:red;">*</span></label>
            <select name="qa_status" class="form-control" style="font-weight:bold; font-size:15px;" required>
                <option value="Approved" style="color:green;">✅ ผ่าน (นำเข้าสต็อกคลังพร้อมขาย)</option>
                <option value="Rejected" style="color:red;">❌ ไม่ผ่าน (ทำลาย LOT ทิ้งเป็นของเสีย)</option>
            </select>
            
            <label>หมายเหตุ</label>
            <input type="text" name="remark" class="form-control" placeholder="ระบุเหตุผล หากไม่ผ่าน...">
            
            <div style="display:flex; gap:10px; margin-top:10px;">
                <button type="button" class="btn-cens" style="width:100%; font-size:16px;" onclick="document.getElementById('qaModal').style.display='none'">ยกเลิก</button>
                <button type="submit" name="save_qa" class="btn-inspect" style="width:100%; font-size:16px;" onclick="return confirm('ยืนยันผลการตรวจ? ระบบจะอัปเดตสต็อกทันที')">บันทึกผลตรวจสอบ</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openQAModal(oid, pid, name, qty, lot) {
        document.getElementById('qa_order_id').value = oid;
        document.getElementById('qa_product_id').value = pid;
        document.getElementById('qa_lot_no').value = lot;
        document.getElementById('qa_qty_val').value = qty;
        
        document.getElementById('qa_lot_display').innerText = lot;
        document.getElementById('qa_product_name').innerText = name;
        document.getElementById('qa_qty').innerText = qty + ' หน่วย';
        
        document.getElementById('qaModal').style.display = 'flex';
    }
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('status') === 'success') {
        Swal.fire({ icon: 'success', title: 'บันทึกสำเร็จ', text: 'ระบบอัปเดตสต็อกเรียบร้อยแล้ว', timer: 2000, showConfirmButton: false })
        .then(()=>window.history.replaceState(null,null,window.location.pathname));
    }
</script>
</body>
</html>