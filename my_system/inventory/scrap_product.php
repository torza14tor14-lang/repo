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
$fullname = $_SESSION['fullname'] ?? $_SESSION['username'];

// สิทธิ์เข้าถึง (คลังสินค้า และ ผู้บริหาร)
$allowed_depts = ['แผนกคลังสินค้า 1', 'แผนกคลังสินค้า 2'];
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $allowed_depts)) { 
    echo "<script>alert('เฉพาะเจ้าหน้าที่คลังสินค้าและผู้บริหารเท่านั้น'); window.location='../index.php';</script>"; exit(); 
}

// 🚀 [Auto-Create Table] สร้างตารางเก็บประวัติของเสีย
$check_table = mysqli_query($conn, "SHOW TABLES LIKE 'scrap_records'");
if (mysqli_num_rows($check_table) == 0) {
    mysqli_query($conn, "CREATE TABLE scrap_records (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        product_id INT(11) NOT NULL,
        lot_no VARCHAR(100) NOT NULL,
        qty DECIMAL(15,2) NOT NULL,
        reason TEXT NOT NULL,
        reported_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}

// 🚀 AJAX: ดึงรายการ LOT (แสดงเฉพาะ Lot ที่มีของเหลือ และ "ผ่าน QA แล้ว (Active)")
if (isset($_POST['action']) && $_POST['action'] == 'get_lots') {
    $p_id = (int)$_POST['product_id'];
    $lots = [];
    // 🐛 แก้ไข: เพิ่ม AND status = 'Active' เข้าไป
    $q_lots = mysqli_query($conn, "SELECT lot_no, qty, exp_date FROM inventory_lots WHERE product_id = $p_id AND qty > 0 AND status = 'Active' ORDER BY exp_date ASC");
    if($q_lots) {
        while($row = mysqli_fetch_assoc($q_lots)) {
            $row['exp_date_th'] = date('d/m/Y', strtotime($row['exp_date']));
            $lots[] = $row;
        }
    }
    echo json_encode($lots);
    exit();
}

$status = '';
$error_msg = '';

if (isset($_POST['save_scrap'])) {
    $p_id = (int)$_POST['product_id'];
    $lot_no = mysqli_real_escape_string($conn, $_POST['lot_no']);
    $qty_scrap = (float)$_POST['qty'];
    $reason = mysqli_real_escape_string($conn, $_POST['reason']);

    // ดึงเช็คเฉพาะสถานะ Active ด้วยเพื่อความปลอดภัย 2 ชั้น
    $q_check = mysqli_query($conn, "SELECT qty FROM inventory_lots WHERE lot_no = '$lot_no' AND product_id = $p_id AND status = 'Active'");
    if ($q_check && mysqli_num_rows($q_check) > 0) {
        $lot_data = mysqli_fetch_assoc($q_check);
        
        if ($lot_data['qty'] >= $qty_scrap) {
            
            // 1. หักสต็อกออกจาก inventory_lots
            mysqli_query($conn, "UPDATE inventory_lots SET qty = qty - $qty_scrap WHERE lot_no = '$lot_no'");
            // 2. หักสต็อกรวมใน products
            mysqli_query($conn, "UPDATE products SET p_qty = p_qty - $qty_scrap WHERE id = $p_id");

            // 3. บันทึกลง stock_log
            mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, reference, remark, action_by) 
                                 VALUES ($p_id, 'OUT', $qty_scrap, 'ตัดชำรุด (Scrap) Lot: $lot_no', '$reason', '$fullname')");

            // 4. บันทึกลง scrap_records
            mysqli_query($conn, "INSERT INTO scrap_records (product_id, lot_no, qty, reason, reported_by) 
                                 VALUES ($p_id, '$lot_no', $qty_scrap, '$reason', '$fullname')");

            // ดึงชื่อสินค้าสำหรับแจ้งเตือนและ Log
            $p_name = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p_name FROM products WHERE id = $p_id"))['p_name'] ?? 'สินค้า';

            // -----------------------------------------------------------------
            // 🚀 บันทึกประวัติ Log ลงระบบ (System Logs)
            // -----------------------------------------------------------------
            if(function_exists('log_event')) {
                log_event($conn, 'DELETE', 'inventory_lots', "ตัดชำรุดสินค้า FG $p_name (Lot: $lot_no) จำนวน $qty_scrap หน่วย | สาเหตุ: $reason");
            }
            // -----------------------------------------------------------------

            // 5. แจ้งเตือน LINE
            include_once '../line_api.php';
            $msg = "🗑️ [คลังสินค้า] บันทึกตัดชำรุด/ของเสีย (Scrap)\n\n";
            $msg .= "📦 สินค้า: $p_name\n🔖 Lot No: $lot_no\n➖ จำนวนที่ตัดทิ้ง: " . number_format($qty_scrap, 2) . " หน่วย\n💬 สาเหตุ: $reason\n\n";
            $msg .= "ผู้ทำรายการ: $fullname\n👉 ฝ่ายบัญชีโปรดรับทราบเพื่อลงบันทึกเป็นค่าใช้จ่ายของเสียครับ";

            if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

            $status = 'success';
        } else {
            $status = 'error';
            $error_msg = 'ยอดสต็อกใน LOT นี้มีไม่เพียงพอให้ตัดชำรุด (เหลือแค่ ' . number_format($lot_data['qty'], 2) . ')';
        }
    } else {
        $status = 'error';
        $error_msg = 'ไม่พบข้อมูล LOT นี้ในระบบ หรือ LOT นี้ยังไม่ผ่านการอนุมัติจาก QA';
    }
}

include '../sidebar.php';
?>

<title>Top Feed Mills | บันทึกตัดชำรุดและของเสีย</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .wrapper { font-family: 'Sarabun', sans-serif; animation: fadeIn 0.4s ease; }
    
    .dashboard-container { display: flex; flex-direction: column; gap: 30px; }
    
    .adjust-card { background: white; padding: 30px 40px; border-radius: 16px; box-shadow: 0 5px 20px rgba(0,0,0,0.03); border-top: 5px solid #e74a3b; }
    .history-card { background: white; padding: 30px 40px; border-radius: 16px; box-shadow: 0 5px 20px rgba(0,0,0,0.03); border-top: 5px solid #4e73df; }
    
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    @media (max-width: 768px) { .form-grid { grid-template-columns: 1fr; } }
    
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-weight: bold; color: #4a5568; margin-bottom: 8px; font-size: 14px;}
    .form-control { width: 100%; padding: 12px 15px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-family: 'Sarabun'; box-sizing: border-box; font-size:15px; transition: 0.3s;}
    .form-control:focus { border-color: #e74a3b; outline:none; box-shadow: 0 0 0 3px rgba(231, 74, 59, 0.15); }
    
    .btn-save { background: linear-gradient(135deg, #e74a3b 0%, #be2617 100%); color: white; padding: 15px 30px; border: none; border-radius: 10px; font-weight: bold; cursor: pointer; font-size: 16px; transition: 0.3s; font-family: 'Sarabun'; float: right; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 15px rgba(231, 74, 59, 0.3);}
    .btn-save:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(231, 74, 59, 0.4); }

    .select2-container--default .select2-selection--single { height: 48px; border: 1.5px solid #e2e8f0; border-radius: 10px; display: flex; align-items: center; font-family: 'Sarabun'; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { color: #4a5568; padding-left: 12px; font-size: 15px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 46px; right: 10px; }

    table { width: 100%; border-collapse: collapse; min-width: 800px; }
    .table-responsive { overflow-x: auto; width: 100%; border-radius: 8px; border: 1px solid #eaecf4;}
    th { background: #f8f9fc; padding: 15px; text-align: left; color: #5a5c69; border-bottom: 2px solid #eaecf4; font-size: 13px; text-transform: uppercase; white-space: nowrap;}
    td { padding: 15px; border-bottom: 1px solid #eaecf4; color: #333; font-size: 14px; vertical-align: top;}
    tr:hover { background: #f8f9fc; }

    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>

<div class="content-padding">
    <div class="wrapper">
        <h2 style="color: #2c3e50; margin-top:0; margin-bottom: 25px;"><i class="fa-solid fa-dumpster-fire" style="color: #e74a3b;"></i> ศูนย์จัดการของเสียและตัดชำรุด (Scrap Dashboard)</h2>

        <div class="dashboard-container">
            
            <div class="adjust-card">
                <h3 style="margin-top:0; color:#e74a3b; font-size: 18px; border-bottom: 1px solid #f0f0f0; padding-bottom: 15px;"><i class="fa-solid fa-plus-circle"></i> บันทึกรายการตัดชำรุดใหม่</h3>
                
                <form method="POST">
                    <div class="form-grid">
                        <div>
                            <div class="form-group">
                                <label class="form-label">1. เลือกสินค้าสำเร็จรูป (FG) ที่ชำรุด <span style="color:red;">*</span></label>
                                <select name="product_id" id="productSelect" class="form-control select2-search" required>
                                    <option value="">-- พิมพ์ค้นหาสินค้า --</option>
                                    <?php 
                                    $res = mysqli_query($conn, "SELECT id, p_name, p_qty, p_unit FROM products WHERE p_type = 'PRODUCT' ORDER BY p_name ASC");
                                    if ($res && mysqli_num_rows($res) > 0) {
                                        while($row = mysqli_fetch_assoc($res)) { 
                                            $unit = $row['p_unit'] ?: 'หน่วย';
                                            echo "<option value='{$row['id']}'>📦 {$row['p_name']} (รวมทุก Lot: {$row['p_qty']} $unit)</option>"; 
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="form-group" style="background:#f8f9fc; padding:15px; border-radius:10px; border:1px solid #d1d3e2;">
                                <label class="form-label" style="color:#4e73df;">2. ระบุเลข LOT ที่ต้องการตัดทิ้ง <span style="color:red;">*</span></label>
                                <select name="lot_no" id="lotSelect" class="form-control" required disabled>
                                    <option value="">-- กรุณาเลือกสินค้าด้านบนก่อน --</option>
                                </select>
                                <small id="lotInfo" style="color:#e74a3b; display:block; margin-top:8px;"></small>
                            </div>
                        </div>

                        <div>
                            <div class="form-group">
                                <label class="form-label">3. จำนวนที่เสียหาย (ตัวเลขเท่านั้น) <span style="color:red;">*</span></label>
                                <input type="number" step="0.01" name="qty" class="form-control" placeholder="ระบุจำนวนที่เสียหาย..." required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">4. สาเหตุที่ชำรุด (สำคัญต่อบัญชี) <span style="color:red;">*</span></label>
                                <textarea name="reason" class="form-control" rows="3" placeholder="เช่น ถุงฉีกขาด, ขึ้นรา, หมดอายุคาคลัง..." required></textarea>
                            </div>
                        </div>
                    </div>

                    <div style="clear:both; overflow:hidden; margin-top: 10px;">
                        <button type="submit" name="save_scrap" class="btn-save" onclick="return confirm('ยืนยันการตัดชำรุด? ยอดจะถูกหักออกจากคลังถาวร');">
                            <i class="fa-solid fa-trash-can"></i> ยืนยันการตัดชำรุด
                        </button>
                    </div>
                </form>
            </div>

            <div class="history-card">
                <h3 style="margin-top:0; color:#4e73df; font-size: 18px; border-bottom: 1px solid #f0f0f0; padding-bottom: 15px;"><i class="fa-solid fa-clock-rotate-left"></i> ประวัติรายการของเสีย (Scrap History)</h3>
                
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 15%;">วันที่บันทึก</th>
                                <th style="width: 30%;">สินค้า / เลข LOT</th>
                                <th style="width: 15%;">จำนวนที่เสีย</th>
                                <th style="width: 25%;">สาเหตุ (Reason)</th>
                                <th style="width: 15%;">ผู้ทำรายการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $q_scrap = mysqli_query($conn, "SELECT s.*, p.p_name, p.p_unit 
                                                            FROM scrap_records s 
                                                            JOIN products p ON s.product_id = p.id 
                                                            ORDER BY s.id DESC LIMIT 50");
                            if ($q_scrap && mysqli_num_rows($q_scrap) > 0) {
                                while($s = mysqli_fetch_assoc($q_scrap)) {
                                    $is_rma = (strpos($s['lot_no'], 'RMA-') === 0);
                                    $lot_color = $is_rma ? '#e74a3b' : '#4e73df';
                                    $unit = $s['p_unit'] ?: 'หน่วย';
                            ?>
                            <tr>
                                <td style="white-space: nowrap; color: #555;"><?= date('d/m/Y H:i', strtotime($s['created_at'])) ?></td>
                                <td>
                                    <strong style="color: #2c3e50; font-size: 15px;"><?= htmlspecialchars($s['p_name']) ?></strong><br>
                                    <span style="background: #f8f9fc; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; color: <?= $lot_color ?>; border: 1px solid <?= $lot_color ?>; display: inline-block; margin-top: 5px;">
                                        <?= htmlspecialchars($s['lot_no']) ?>
                                    </span>
                                </td>
                                <td><strong style="color:#e74a3b; font-size: 16px;"><?= number_format($s['qty'], 2) ?></strong> <small><?= $unit ?></small></td>
                                <td style="line-height: 1.5; color: #444;"><?= htmlspecialchars($s['reason']) ?></td>
                                <td><span style="background: #eaecf4; color: #5a5c69; padding: 4px 10px; border-radius: 50px; font-size: 12px;"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($s['reported_by']) ?></span></td>
                            </tr>
                            <?php } } else { echo "<tr><td colspan='5' style='text-align:center; padding:40px; color:#888;'><i class='fa-solid fa-box-open fa-2x' style='color:#ccc; margin-bottom:15px;'></i><br>ยังไม่มีประวัติของเสียในระบบ</td></tr>"; } ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    $(document).ready(function() {
        $('.select2-search').select2({ width: '100%' });

        $('#productSelect').on('change', function() {
            let pid = $(this).val();
            let lotSelect = $('#lotSelect');
            let lotInfo = $('#lotInfo');

            if (!pid) {
                lotSelect.html('<option value="">-- กรุณาเลือกสินค้าด้านบนก่อน --</option>').prop('disabled', true);
                lotInfo.text('');
                return;
            }

            lotSelect.html('<option value="">กำลังโหลดรายการ LOT...</option>').prop('disabled', true);
            lotInfo.text('');

            $.ajax({
                url: 'scrap_product.php',
                type: 'POST',
                data: { action: 'get_lots', product_id: pid },
                dataType: 'json',
                success: function(data) {
                    if (data.length > 0) {
                        let options = '<option value="">-- เลือก LOT ที่ชำรุด --</option>';
                        $.each(data, function(index, lot) {
                            options += `<option value="${lot.lot_no}">${lot.lot_no} (มี: ${lot.qty} | หมดอายุ: ${lot.exp_date_th})</option>`;
                        });
                        lotSelect.html(options).prop('disabled', false);
                    } else {
                        lotSelect.html('<option value="">-- ไม่มีสต็อกหลงเหลือใน LOT ใดๆ เลย --</option>');
                        lotInfo.html('<i class="fa-solid fa-circle-xmark"></i> สินค้านี้สต็อกเกลี้ยงคลัง หรือยังไม่ผ่าน QA ครับ');
                    }
                },
                error: function() {
                    lotSelect.html('<option value="">-- เกิดข้อผิดพลาดในการโหลดข้อมูล --</option>');
                }
            });
        });
    });

    <?php if($status == 'success'): ?>
    Swal.fire({ icon: 'success', title: 'บันทึกตัดชำรุดสำเร็จ', text: 'ระบบลบยอดจาก LOT และเก็บประวัติให้ฝ่ายบัญชีตรวจสอบแล้ว', confirmButtonColor: '#e74a3b' }).then(()=> { window.location.href='scrap_product.php'; });
    <?php elseif($status == 'error'): ?>
    Swal.fire({ icon: 'error', title: 'ข้อผิดพลาด', text: '<?php echo $error_msg; ?>', confirmButtonColor: '#e74a3b' });
    <?php endif; ?>
</script>
</body>
</html>