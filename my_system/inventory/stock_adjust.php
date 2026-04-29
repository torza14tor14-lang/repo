<?php
session_start();
include '../db.php';

// สเต็ปที่ 1: ตรวจสอบว่า "ล็อกอินหรือยัง?" (ถ้ายัง ให้เด้งไปหน้า login ทันที)
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

// ตรวจสอบสิทธิ์ (เฉพาะ Admin หรือ ผู้จัดการคลังสินค้า)
$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
if ($user_role !== 'ADMIN' && $user_dept !== 'แผนกคลังสินค้า 1' && $user_dept !== 'แผนกคลังสินค้า 2') { 
    echo "<script>alert('เฉพาะผู้จัดการคลังสินค้าเท่านั้นที่มีสิทธิ์'); window.location='../index.php';</script>"; exit(); 
}

$status = '';
if (isset($_POST['adjust_stock'])) {
    $p_id = (int)$_POST['product_id'];
    $type = $_POST['adj_type']; // IN หรือ OUT
    $qty = (float)$_POST['qty'];
    $reason = mysqli_real_escape_string($conn, $_POST['reason']);
    $user = $_SESSION['fullname'];

    // ดึงยอดปัจจุบันมาเช็ค
    $p_data = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p_name, p_qty FROM products WHERE id = '$p_id'"));
    
    if ($type == 'OUT' && $p_data['p_qty'] < $qty) {
        $status = 'error';
        $msg = "ยอดคงเหลือไม่พอให้ปรับลด (ปัจจุบันมี {$p_data['p_qty']})";
    } else {
        // อัปเดตยอด
        $operator = ($type == 'IN') ? "+" : "-";
        mysqli_query($conn, "UPDATE products SET p_qty = p_qty $operator $qty WHERE id = '$p_id'");
        
        // บันทึกลง Log
        mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, reference, remark, action_by) 
                             VALUES ('$p_id', '$type', '$qty', 'ปรับปรุงสต็อกด้วยมือ', '$reason', '$user')");
        $status = 'success';
    }
}

include '../sidebar.php';
?>

<title>ปรับปรุงยอดสต็อก | Top Feed Mills</title>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    .adjust-card { background: white; padding: 35px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); max-width: 650px; margin: 20px auto; border-top: 6px solid #6c757d; }
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-weight: bold; color: #4a5568; margin-bottom: 8px; }
    .form-control { width: 100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 10px; font-family: 'Sarabun'; box-sizing: border-box; }
    .adj-type-group { display: flex; gap: 15px; margin-bottom: 20px; }
    .adj-btn { flex: 1; padding: 15px; border: 2px solid #e2e8f0; border-radius: 12px; text-align: center; cursor: pointer; transition: 0.3s; font-weight: bold; }
    .adj-btn input { display: none; }
    .btn-in.active { background: #e8f9f3; border-color: #1cc88a; color: #1cc88a; }
    .btn-out.active { background: #ffe5e5; border-color: #e74a3b; color: #e74a3b; }
    .btn-save { background: #6c757d; color: white; width: 100%; padding: 15px; border: none; border-radius: 10px; font-weight: bold; cursor: pointer; font-size: 16px; transition: 0.3s; }
    .btn-save:hover { background: #5a6268; box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3); }

    /* ปรับแต่งหน้าตา Select2 ให้เข้ากับธีมช่องกรอกข้อมูลของคุณ */
    .select2-container--default .select2-selection--single {
        height: 48px;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        display: flex;
        align-items: center;
        font-family: 'Sarabun', sans-serif;
        outline: none;
    }
    .select2-container--default .select2-selection--single:focus {
        border-color: #4e73df;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        color: #4a5568;
        padding-left: 12px;
        font-size: 15px;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 46px;
        right: 10px;
    }
    .select2-dropdown {
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        font-family: 'Sarabun', sans-serif;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        border-top: none;
    }
    .select2-search__field {
        border-radius: 5px !important;
        border: 1px solid #ccc !important;
        padding: 8px !important;
    }
</style>

<div class="content-padding">
    <div class="adjust-card">
        <h2 style="margin-top:0; color:#2c3e50;"><i class="fa-solid fa-sliders"></i> ปรับปรุงยอดสต็อก (Stock Adjustment)</h2>
        <p style="color: #e74a3b; font-size: 14px; background: #fff5f5; padding: 10px; border-radius: 8px; border: 1px solid #fceceb;">
            <i class="fa-solid fa-triangle-exclamation"></i> <b>ข้อควรระวัง:</b> หน้านี้ใช้ปรับเฉพาะยอด <b>"วัตถุดิบ (RAW)"</b> และ <b>"อะไหล่ (SPARE)"</b> เท่านั้น (ยอดสินค้าสำเร็จรูปจะถูกควบคุมโดยระบบผลิตและระบบขายโดยอัตโนมัติ)
        </p>
        <hr style="border:0; border-top:1px solid #eee; margin: 20px 0;">

        <form method="POST">
            <div class="form-group">
                <label class="form-label">ค้นหาและเลือกรายการวัตถุดิบ / อะไหล่</label>
                <select name="product_id" class="form-control select2-search" required>
                    <option value="">-- พิมพ์ชื่อรหัส หรือชื่อรายการเพื่อค้นหา --</option>
                    <?php 
                    // 🚀 แก้ไข: ดึงเฉพาะ RAW และ SPARE ป้องกันการแก้ FG โดยพลการ
                    $res = mysqli_query($conn, "SELECT id, p_name, p_qty, p_type, p_unit FROM products WHERE p_type IN ('RAW', 'SPARE') ORDER BY p_type DESC, p_name ASC");
                    if ($res && mysqli_num_rows($res) > 0) {
                        while($row = mysqli_fetch_assoc($res)) { 
                            $icon = ($row['p_type'] == 'RAW') ? '🌾' : '🛠️';
                            $unit = $row['p_unit'] ?: 'หน่วย';
                            $qty_format = number_format($row['p_qty'], 2);
                            echo "<option value='{$row['id']}'>$icon {$row['p_name']} (คงเหลือ: {$qty_format} $unit)</option>"; 
                        }
                    } else {
                        echo "<option value='' disabled>ไม่พบรายการข้อมูลในระบบ</option>";
                    }
                    ?>
                </select>
            </div>

            <label class="form-label">ประเภทการปรับปรุง</label>
            <div class="adj-type-group">
                <label class="adj-btn btn-in" id="lbl-in">
                    <input type="radio" name="adj_type" value="IN" required onclick="selectType('IN')"> 
                    <i class="fa-solid fa-plus-circle"></i> ปรับยอดเพิ่ม (In)
                </label>
                <label class="adj-btn btn-out" id="lbl-out">
                    <input type="radio" name="adj_type" value="OUT" onclick="selectType('OUT')"> 
                    <i class="fa-solid fa-minus-circle"></i> ปรับยอดลด (Out)
                </label>
            </div>

            <div class="form-group">
                <label class="form-label">จำนวนที่ต้องการปรับ (ตัวเลขเท่านั้น)</label>
                <input type="number" step="0.01" name="qty" class="form-control" placeholder="0.00" required>
            </div>

            <div class="form-group">
                <label class="form-label">เหตุผลการปรับปรุง (เพื่อการตรวจสอบบัญชี)</label>
                <textarea name="reason" class="form-control" rows="3" placeholder="เช่น ของเสื่อมสภาพ, ปรับยอดให้ตรงกับการนับจริงตอนสิ้นเดือน..." required></textarea>
            </div>

            <button type="submit" name="adjust_stock" class="btn-save"><i class="fa-solid fa-save"></i> บันทึกการปรับปรุงยอด</button>
        </form>
    </div>
</div>

<script>
    // เริ่มต้นการทำงานของ Select2 เมื่อโหลดหน้าเว็บเสร็จ
    $(document).ready(function() {
        $('.select2-search').select2({
            placeholder: "-- พิมพ์ชื่อรหัส หรือชื่อรายการเพื่อค้นหา --",
            allowClear: true,
            width: '100%' // ให้กว้างเต็มกล่องเหมือนเดิม
        });
    });

    function selectType(type) {
        document.getElementById('lbl-in').classList.remove('active');
        document.getElementById('lbl-out').classList.remove('active');
        if(type == 'IN') document.getElementById('lbl-in').classList.add('active');
        if(type == 'OUT') document.getElementById('lbl-out').classList.add('active');
    }

    <?php if($status == 'success'): ?>
    Swal.fire({ icon: 'success', title: 'ปรับปรุงยอดสำเร็จ', text: 'ข้อมูลสต็อกและประวัติการเคลื่อนไหวถูกอัปเดตแล้ว' });
    <?php elseif($status == 'error'): ?>
    Swal.fire({ icon: 'error', title: 'ไม่สามารถปรับยอดได้', text: '<?php echo $msg; ?>' });
    <?php endif; ?>
</script>