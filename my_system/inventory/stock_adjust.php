<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
$fullname = $_SESSION['fullname'] ?? 'พนักงานคลัง';

// สิทธิ์เข้าถึง: ADMIN, MANAGER, และ แผนกคลังสินค้า เท่านั้น
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && strpos($user_dept, 'คลังสินค้า') === false) { 
    echo "<script>alert('เฉพาะผู้ดูแลระบบและแผนกคลังสินค้าเท่านั้น'); window.location='stock.php';</script>"; 
    exit(); 
}

// รับค่าจากหน้า แผงควบคุมคลัง (ถ้ามีการกดปุ่มมาจากหน้านั้น)
$get_p_id = isset($_GET['p_id']) ? (int)$_GET['p_id'] : 0;
$get_wh_id = isset($_GET['wh_id']) ? (int)$_GET['wh_id'] : 0;

// 🚀 ประมวลผลเมื่อกดปุ่ม "บันทึกปรับปรุงยอด"
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_adjust'])) {
    $product_id = (int)$_POST['product_id'];
    $wh_id = (int)$_POST['wh_id'];
    $new_qty = (float)$_POST['new_qty'];
    $remark = mysqli_real_escape_string($conn, $_POST['remark']);

    // 1. ดึงยอดปัจจุบันของคลังนี้มาเทียบ
    $q_current = mysqli_query($conn, "SELECT qty FROM stock_balances WHERE product_id = $product_id AND wh_id = $wh_id");
    $current_qty = ($q_current && mysqli_num_rows($q_current) > 0) ? (float)mysqli_fetch_assoc($q_current)['qty'] : 0;

    $diff_qty = $new_qty - $current_qty; // หาผลต่าง (บวกคือรับเข้า, ลบคือตัดออก)

    if ($diff_qty != 0) {
        $type = ($diff_qty > 0) ? 'ADJUST_IN' : 'ADJUST_OUT';
        $abs_diff = abs($diff_qty);

        // 2. อัปเดตยอดในคลังที่เลือกลง stock_balances
        mysqli_query($conn, "INSERT INTO stock_balances (product_id, wh_id, qty) 
                             VALUES ($product_id, $wh_id, $new_qty) 
                             ON DUPLICATE KEY UPDATE qty = $new_qty");

        // 3. อัปเดตยอดรวมในตาราง products (เผื่อระบบเก่าเรียกใช้)
        mysqli_query($conn, "UPDATE products SET p_qty = p_qty + ($diff_qty) WHERE id = $product_id");

        // 4. บันทึกประวัติลง Stock Log
        // ถ้าบวกเพิ่ม ให้ลง to_wh_id | ถ้าลดลง ให้ลง from_wh_id
        if ($diff_qty > 0) {
            mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, to_wh_id, reference, action_by) 
                                 VALUES ($product_id, 'ADJUST', $abs_diff, $wh_id, 'ปรับปรุงยอด(เพิ่ม) | $remark', '$fullname')");
        } else {
            mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, from_wh_id, reference, action_by) 
                                 VALUES ($product_id, 'ADJUST', $abs_diff, $wh_id, 'ปรับปรุงยอด(ลด) | $remark', '$fullname')");
        }

        // 5. บันทึก System Log
        if (function_exists('log_event')) {
            $p_name = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p_name FROM products WHERE id = $product_id"))['p_name'];
            log_event($conn, 'UPDATE', 'stock_balances', "ปรับปรุงสต็อก $p_name คลัง ID:$wh_id จาก $current_qty เป็น $new_qty");
        }

        // แจ้งเตือน LINE ให้บัญชีรับรู้
        include_once '../line_api.php';
        $wh_name = mysqli_fetch_assoc(mysqli_query($conn, "SELECT wh_name FROM warehouses WHERE wh_id = $wh_id"))['wh_name'];
        $msg = "📝 [แจ้งเตือน] มีการปรับปรุงสต็อก (Stock Adjust)\n\n";
        $msg .= "📦 สินค้า: $p_name\n";
        $msg .= "🏢 คลัง/ไซโล: $wh_name\n";
        $msg .= "📊 ยอดเดิม: $current_qty ➔ ยอดใหม่: $new_qty\n";
        $msg .= "ผลต่าง: " . ($diff_qty > 0 ? "+" : "") . "$diff_qty\n";
        $msg .= "ผู้ทำรายการ: $fullname\n💬 เหตุผล: $remark";
        if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

        header("Location: stock.php?msg=adjusted"); exit();
    } else {
        $error_msg = "ยอดใหม่เท่ากับยอดเดิม ไม่มีการเปลี่ยนแปลงครับ";
    }
}

// ดึงรายการสินค้าทั้งหมด
$res_products = mysqli_query($conn, "SELECT id, p_name, p_unit FROM products ORDER BY p_name ASC");

// ดึงรายการคลังทั้งหมด
$res_wh = mysqli_query($conn, "SELECT * FROM warehouses ORDER BY plant ASC, wh_name ASC");

// ดึงสต็อกทั้งหมดมาเก็บไว้ใน JS Array สำหรับอัปเดตหน้าจออัตโนมัติ
$stock_data = [];
$q_all_stock = mysqli_query($conn, "SELECT product_id, wh_id, qty FROM stock_balances");
while($st = mysqli_fetch_assoc($q_all_stock)) {
    $stock_data[$st['product_id']."_".$st['wh_id']] = (float)$st['qty'];
}

include '../sidebar.php';
?>

<title>ปรับปรุงยอดสต็อก | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
    :root { --bg: #f8fafc; --card: #ffffff; --text-main: #1e293b; --text-muted: #64748b; }
    body { font-family: 'Sarabun', sans-serif; background: var(--bg); }
    .content-padding { padding: 30px; max-width: 800px; margin: auto; }
    
    .adjust-card { background: var(--card); padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border-top: 5px solid var(--primary); }
    
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-weight: 800; color: var(--text-main); margin-bottom: 10px; font-size: 14px; }
    .form-control { width: 100%; padding: 14px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-family: 'Sarabun'; font-size: 15px; box-sizing: border-box; transition: 0.2s;}
    .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15); }
    
    .select2-container--default .select2-selection--single { height: 50px; border: 1.5px solid #e2e8f0; border-radius: 10px; display: flex; align-items: center; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { padding-left: 15px; font-weight: bold; color: var(--text-main); }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 48px; right: 10px; }

    .current-stock-box { background: #f1f5f9; border: 1px dashed #cbd5e1; padding: 20px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
    
    .btn-submit { background: var(--primary); color: white; width: 100%; padding: 16px; border: none; border-radius: 10px; font-size: 18px; font-weight: 800; cursor: pointer; transition: 0.3s; font-family: 'Sarabun'; }
    .btn-submit:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(245, 158, 11, 0.3); }
</style>

<div class="content-padding">
    <div class="adjust-card">
        <h2 style="margin-top:0; color:var(--text-main); border-bottom:2px solid #f1f5f9; padding-bottom:15px; margin-bottom:25px;">
            <i class="fa-solid fa-sliders" style="color:var(--primary);"></i> ปรับปรุงยอดสต็อก (Stock Adjustment)
        </h2>

        <?php if(isset($error_msg)): ?>
            <div style="background: #fef2f2; color: #ef4444; padding: 15px; border-radius: 10px; margin-bottom: 20px; font-weight:bold;">
                <i class="fa-solid fa-circle-exclamation"></i> <?= $error_msg ?>
            </div>
        <?php endif; ?>

        <form method="POST" onsubmit="return confirm('ยืนยันการปรับปรุงยอด? ระบบจะแจ้งเตือนไปยังฝ่ายบัญชี');">
            
            <div class="form-group">
                <label class="form-label">1. เลือกสินค้า / วัตถุดิบ <span style="color:red;">*</span></label>
                <select name="product_id" id="product_id" class="form-control select2" required onchange="checkCurrentStock()">
                    <option value="">-- พิมพ์ค้นหาชื่อสินค้า --</option>
                    <?php while($p = mysqli_fetch_assoc($res_products)): ?>
                        <option value="<?= $p['id'] ?>" data-unit="<?= $p['p_unit'] ?>" <?= ($get_p_id == $p['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['p_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">2. เลือกคลังสินค้า หรือ ไซโล ที่ตรวจนับ <span style="color:red;">*</span></label>
                <select name="wh_id" id="wh_id" class="form-control select2" required onchange="checkCurrentStock()">
                    <option value="">-- เลือกสถานที่จัดเก็บ --</option>
                    <?php while($wh = mysqli_fetch_assoc($res_wh)): ?>
                        <option value="<?= $wh['wh_id'] ?>" <?= ($get_wh_id == $wh['wh_id']) ? 'selected' : '' ?>>
                            [<?= $wh['plant'] ?>] <?= $wh['wh_name'] ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="current-stock-box">
                <div>
                    <div style="font-size:13px; color:var(--text-muted); font-weight:bold;">ยอดคงเหลือปัจจุบัน (ในระบบ):</div>
                    <div style="font-size:24px; font-weight:900; color:var(--text-main);" id="current_qty_display">0.00</div>
                </div>
                <div style="font-size:16px; font-weight:bold; color:var(--text-muted);" id="unit_display">-</div>
            </div>

            <div class="form-group">
                <label class="form-label">3. ระบุยอดใหม่ที่ตรวจนับได้จริง (Counted Qty) <span style="color:red;">*</span></label>
                <input type="number" step="0.001" name="new_qty" class="form-control" placeholder="ระบุตัวเลขที่นับได้จริง..." required>
                <small style="color:var(--text-muted); margin-top:5px; display:block;">* ใส่จำนวนที่นับได้จริง ระบบจะคำนวณผลต่าง (บวก/ลบ) ให้อัตโนมัติ</small>
            </div>

            <div class="form-group">
                <label class="form-label">4. สาเหตุที่ต้องปรับปรุง <span style="color:red;">*</span></label>
                <select name="remark" class="form-control" required>
                    <option value="">-- เลือกเหตุผล --</option>
                    <option value="ตรวจนับสต็อกประจำเดือน (Stock Count)">ตรวจนับสต็อกประจำเดือน (Stock Count)</option>
                    <option value="ยอดยกมาเริ่มต้นระบบ (Opening Balance)">นำของเข้าครั้งแรก (Opening Balance)</option>
                    <option value="ของเสื่อมสภาพ/สูญหายระหว่างจัดเก็บ">ของเสื่อมสภาพ / สูญหายระหว่างจัดเก็บ</option>
                    <option value="ปรับปรุงยอดตามจดหมายขอปรับปรุงสต็อก">ปรับปรุงตามจดหมายขออนุมัติ</option>
                </select>
            </div>

            <button type="submit" name="submit_adjust" class="btn-submit">
                <i class="fa-solid fa-floppy-disk"></i> บันทึกการปรับปรุงยอด
            </button>
            <a href="stock.php" style="display:block; text-align:center; margin-top:15px; color:var(--text-muted); text-decoration:none; font-weight:bold;">กลับไปแผงควบคุมคลัง</a>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    const stockData = <?= json_encode($stock_data) ?>;

    $(document).ready(function() {
        $('.select2').select2({ width: '100%' });
        
        // ถ้ารับค่ามาจาก URL ให้รันเช็คสต็อกเลย
        if($('#product_id').val() != '' && $('#wh_id').val() != '') {
            checkCurrentStock();
        }
    });

    function checkCurrentStock() {
        let p_id = $('#product_id').val();
        let wh_id = $('#wh_id').val();
        let unit = $('#product_id').find(':selected').data('unit');
        
        if (p_id && wh_id) {
            let key = p_id + "_" + wh_id;
            let currentQty = stockData[key] !== undefined ? parseFloat(stockData[key]) : 0;
            
            $('#current_qty_display').text(currentQty.toLocaleString('en-US', {minimumFractionDigits: 2}));
            $('#unit_display').text(unit);
        } else {
            $('#current_qty_display').text('0.00');
            $('#unit_display').text('-');
        }
    }
</script>