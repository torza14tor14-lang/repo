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
$fullname = $_SESSION['fullname'] ?? 'พนักงาน';

// สิทธิ์การโอนย้าย: ให้เฉพาะ ADMIN, MANAGER และ แผนกคลังสินค้า เท่านั้น
$allow_transfer = ($user_role === 'ADMIN' || $user_role === 'MANAGER' || strpos($user_dept, 'คลังสินค้า') !== false);
if (!$allow_transfer) {
    echo "<script>alert('เฉพาะแผนกคลังสินค้าเท่านั้นที่สามารถโอนย้ายสต็อกได้'); window.location='stock.php';</script>"; 
    exit();
}

// 🚀 ประมวลผลเมื่อกดโอนย้าย
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_transfer'])) {
    // รับค่าจากฟอร์ม
    list($product_id, $from_wh_id) = explode('|', $_POST['source_stock']); // แยกค่า Product และ คลังต้นทาง
    $product_id = (int)$product_id;
    $from_wh_id = (int)$from_wh_id;
    
    $to_wh_id = (int)$_POST['to_wh_id'];
    $transfer_qty = (float)$_POST['transfer_qty'];
    $remark = mysqli_real_escape_string($conn, $_POST['remark']);

    // 1. ตรวจสอบความถูกต้องพื้นฐาน
    if ($from_wh_id == $to_wh_id) {
        $error_msg = "ไม่สามารถโอนย้ายไปคลังเดียวกันได้!";
    } elseif ($transfer_qty <= 0) {
        $error_msg = "จำนวนที่โอนย้ายต้องมากกว่า 0!";
    } else {
        // 2. เช็คยอดคงเหลือในคลังต้นทาง ว่ามีพอให้โอนไหม?
        $q_check = mysqli_query($conn, "SELECT qty FROM stock_balances WHERE product_id = $product_id AND wh_id = $from_wh_id");
        $src_qty = mysqli_fetch_assoc($q_check)['qty'] ?? 0;

        if ($transfer_qty > $src_qty) {
            $error_msg = "ยอดสินค้าในคลังต้นทางไม่เพียงพอ! (มีแค่ $src_qty)";
        } else {
            // 🚀 3. เริ่มกระบวนการโอนย้าย (Transaction)
            
            // 3.1 หักยอดออกจากคลังต้นทาง
            mysqli_query($conn, "UPDATE stock_balances SET qty = qty - $transfer_qty WHERE product_id = $product_id AND wh_id = $from_wh_id");
            
            // 3.2 เพิ่มยอดเข้าคลังปลายทาง (ถ้ายกไปคลังใหม่ที่ยังไม่เคยมีของ ให้ INSERT ถ้ามีอยู่แล้วให้ UPDATE)
            mysqli_query($conn, "INSERT INTO stock_balances (product_id, wh_id, qty) 
                                 VALUES ($product_id, $to_wh_id, $transfer_qty) 
                                 ON DUPLICATE KEY UPDATE qty = qty + $transfer_qty");

            // 3.3 บันทึกประวัติลง Stock Log (ระบบคลัง)
            $ref_msg = "โอนย้ายคลัง: " . $remark;
            mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, from_wh_id, to_wh_id, reference, action_by) 
                                 VALUES ($product_id, 'TRANSFER', $transfer_qty, $from_wh_id, $to_wh_id, '$ref_msg', '$fullname')");
            
            // 3.4 บันทึก System Log (ระบบรวม)
            if (function_exists('log_event')) {
                $p_name = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p_name FROM products WHERE id = $product_id"))['p_name'] ?? 'Unknown';
                log_event($conn, 'UPDATE', 'stock_balances', "โอนย้าย $p_name จำนวน $transfer_qty (จากคลัง ID:$from_wh_id ไปคลัง ID:$to_wh_id)");
            }

            // แจ้งเตือน LINE เฉพาะถ้าย้ายไปคลังของเสีย (Scrap) หรือคลังกักกัน (Hold)
            $q_to_wh = mysqli_query($conn, "SELECT wh_name, wh_type FROM warehouses WHERE wh_id = $to_wh_id");
            $to_wh_data = mysqli_fetch_assoc($q_to_wh);
            if ($to_wh_data['wh_type'] == 'Scrap' || $to_wh_data['wh_type'] == 'Hold') {
                include_once '../line_api.php';
                $msg = "⚠️ [แจ้งเตือนคลังสินค้า] มีการโอนย้ายของผิดปกติ\n\n";
                $msg .= "📦 สินค้า: $p_name\n";
                $msg .= "⚖️ ปริมาณ: $transfer_qty\n";
                $msg .= "📥 โอนเข้า: " . $to_wh_data['wh_name'] . "\n";
                $msg .= "ผู้โอน: $fullname\n💬 หมายเหตุ: $remark";
                if(function_exists('sendLineMessage')) { sendLineMessage($msg); }
            }

            header("Location: stock.php?msg=transferred"); 
            exit();
        }
    }
}

// เตรียมข้อมูล Dropdown
// 1. ดึงรายการสินค้าที่มีสต็อกอยู่จริงเท่านั้น (เชื่อม products กับ stock_balances)
$sql_source = "SELECT sb.product_id, sb.wh_id, sb.qty, p.p_name, p.p_unit, w.wh_name, w.plant 
               FROM stock_balances sb
               JOIN products p ON sb.product_id = p.id
               JOIN warehouses w ON sb.wh_id = w.wh_id
               WHERE sb.qty > 0
               ORDER BY p.p_name ASC, w.plant ASC";
$res_source = mysqli_query($conn, $sql_source);

// 2. ดึงคลังปลายทางทั้งหมด
$sql_dest = "SELECT * FROM warehouses ORDER BY plant ASC, wh_code ASC";
$res_dest = mysqli_query($conn, $sql_dest);

include '../sidebar.php';
?>

<title>โอนย้ายสินค้า (Inventory Transfer) | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    :root { 
        --primary: #0ea5e9; --primary-hover: #0284c7; 
        --bg-color: #f1f5f9; --card-bg: #ffffff; --border-color: #e2e8f0;
        --text-main: #1e293b; --text-muted: #64748b;
    }
    body { font-family: 'Sarabun', sans-serif; background-color: var(--bg-color); }
    .content-padding { padding: 24px; max-width: 1000px; margin: auto; }
    
    .transfer-card { background: var(--card-bg); border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); padding: 40px; border-top: 5px solid var(--primary); }
    
    .form-group { margin-bottom: 25px; }
    .form-label { display: block; font-size: 15px; font-weight: 800; color: var(--text-main); margin-bottom: 10px; }
    
    .form-control { width: 100%; padding: 14px 18px; border: 1.5px solid var(--border-color); border-radius: 12px; font-family: 'Sarabun'; font-size: 15px; transition: 0.2s; box-sizing: border-box;}
    .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.15); }
    
    /* Select2 Custom */
    .select2-container--default .select2-selection--single { height: 50px !important; border: 1.5px solid var(--border-color) !important; border-radius: 12px !important; display: flex !important; align-items: center; }
    .select2-container--default.select2-container--open .select2-selection--single { border-color: var(--primary) !important; box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.15) !important; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { font-size: 15px; font-weight: 600; padding-left: 18px !important; color:var(--text-main) !important;}
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 48px !important; right: 12px !important; }

    .btn-submit { background: var(--primary); color: white; width: 100%; padding: 16px; border: none; border-radius: 12px; font-size: 18px; font-weight: 800; cursor: pointer; transition: 0.3s; font-family: 'Sarabun'; display:flex; align-items:center; justify-content:center; gap:10px; box-shadow: 0 4px 15px rgba(14, 165, 233, 0.2);}
    .btn-submit:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 8px 25px rgba(14, 165, 233, 0.3); }

    .arrow-icon { display: flex; justify-content: center; font-size: 30px; color: #cbd5e1; margin: 10px 0; }
</style>

<div class="content-padding">
    <div class="transfer-card">
        <h2 style="margin-top:0; color:var(--text-main); font-weight:800; font-size:26px; border-bottom: 2px solid #f1f5f9; padding-bottom:15px; margin-bottom:30px;">
            <i class="fa-solid fa-truck-ramp-box" style="color:var(--primary);"></i> โอนย้ายสินค้าระหว่างคลัง (Stock Transfer)
        </h2>

        <?php if(isset($error_msg)): ?>
            <div style="background: #fef2f2; color: #ef4444; padding: 15px; border-radius: 12px; margin-bottom: 25px; font-weight:bold; border: 1px solid #fecaca;">
                <i class="fa-solid fa-circle-exclamation"></i> <?= $error_msg ?>
            </div>
        <?php endif; ?>

        <form method="POST" onsubmit="return confirm('ยืนยันการโอนย้ายสต็อกใช่หรือไม่?');">
            
            <div class="form-group" style="background:#f8fafc; padding:20px; border-radius:15px; border:1px dashed #cbd5e1;">
                <label class="form-label"><i class="fa-solid fa-box-open"></i> 1. เลือกสินค้าและคลังต้นทาง (Source) <span style="color:red;">*</span></label>
                <select name="source_stock" class="select2 form-control" required id="source_select">
                    <option value="">-- พิมพ์ค้นหาชื่อสินค้า หรือ โกดังต้นทาง --</option>
                    <?php while($src = mysqli_fetch_assoc($res_source)): ?>
                        <option value="<?= $src['product_id'] ?>|<?= $src['wh_id'] ?>" data-max="<?= $src['qty'] ?>" data-unit="<?= $src['p_unit'] ?>">
                            <?= htmlspecialchars($src['p_name']) ?> — [<?= $src['plant'] ?>] <?= $src['wh_name'] ?> (คงเหลือ: <?= number_format($src['qty'],2) ?> <?= $src['p_unit'] ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="arrow-icon"><i class="fa-solid fa-angles-down"></i></div>

            <div class="form-group">
                <label class="form-label">2. จำนวนที่ต้องการโอนย้าย <span style="color:red;">*</span></label>
                <div style="display:flex; align-items:center; gap:15px;">
                    <input type="number" step="0.001" name="transfer_qty" id="transfer_qty" class="form-control" style="flex:1;" placeholder="ระบุจำนวนที่ต้องการย้าย..." required>
                    <span id="unit_display" style="font-weight:bold; color:var(--text-muted); width:60px;">-</span>
                </div>
                <small style="color:#ef4444; font-weight:bold; display:none; margin-top:5px;" id="qty_warning">⚠️ จำนวนที่โอนเกินยอดคงเหลือในคลังต้นทาง!</small>
            </div>

            <div class="form-group">
                <label class="form-label"><i class="fa-solid fa-building-circle-arrow-right" style="color:#10b981;"></i> 3. เลือกคลัง/ไซโลปลายทาง (Destination) <span style="color:red;">*</span></label>
                <select name="to_wh_id" class="select2 form-control" required>
                    <option value="">-- เลือกโกดัง หรือ ไซโล ปลายทาง --</option>
                    <?php while($dest = mysqli_fetch_assoc($res_dest)): ?>
                        <option value="<?= $dest['wh_id'] ?>">
                            [<?= $dest['plant'] ?>] <?= $dest['wh_name'] ?> 
                            <?= ($dest['wh_type'] == 'Hold' || $dest['wh_type'] == 'Scrap') ? '⚠️' : '' ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label"><i class="fa-regular fa-comment-dots"></i> 4. หมายเหตุ / เหตุผลการโอนย้าย</label>
                <input type="text" name="remark" class="form-control" placeholder="เช่น ย้ายปลาป่นไปเป่าลงไซโล A, ย้ายของเสื่อมสภาพรอตัดชำรุด...">
            </div>

            <button type="submit" name="submit_transfer" id="btn_submit" class="btn-submit">
                <i class="fa-solid fa-shuffle"></i> ยืนยันการโอนย้ายคลังสินค้า
            </button>
            <a href="stock.php" style="display:block; text-align:center; margin-top:15px; color:var(--text-muted); text-decoration:none; font-weight:bold;">กลับไปแผงควบคุมคลัง</a>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        // เปิดใช้งานระบบค้นหา (Select2)
        $('.select2').select2({ width: '100%' });

        // ตรวจสอบจำนวนแบบ Real-time
        $('#source_select, #transfer_qty').on('change keyup', function() {
            let selectedOption = $('#source_select').find(':selected');
            if(selectedOption.val() !== '') {
                let maxQty = parseFloat(selectedOption.data('max'));
                let unit = selectedOption.data('unit');
                let inputQty = parseFloat($('#transfer_qty').val());

                $('#unit_display').text(unit);

                if (inputQty > maxQty) {
                    $('#transfer_qty').css('border-color', '#ef4444');
                    $('#qty_warning').show();
                    $('#btn_submit').prop('disabled', true).css('opacity', '0.5');
                } else {
                    $('#transfer_qty').css('border-color', '#e2e8f0');
                    $('#qty_warning').hide();
                    $('#btn_submit').prop('disabled', false).css('opacity', '1');
                }
            } else {
                $('#unit_display').text('-');
            }
        });
    });
</script>
</body>
</html>