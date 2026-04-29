<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
$allowed_depts = ['แผนกคลังสินค้า 1', 'แผนกคลังสินค้า 2'];

if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $allowed_depts)) { 
    echo "<script>alert('ไม่มีสิทธิ์เข้าถึง'); window.location='../index.php';</script>"; 
    exit(); 
}

// 🚀 [Auto-Update DB] เช็คก่อนเพิ่มคอลัมน์
$check_unit = mysqli_query($conn, "SHOW COLUMNS FROM `products` LIKE 'p_unit'");
if (mysqli_num_rows($check_unit) == 0) {
    mysqli_query($conn, "ALTER TABLE `products` ADD `p_unit` VARCHAR(50) NOT NULL DEFAULT 'หน่วย' AFTER `p_type`");
}

$check_shelf = mysqli_query($conn, "SHOW COLUMNS FROM `products` LIKE 'shelf_life_days'");
if (mysqli_num_rows($check_shelf) == 0) {
    mysqli_query($conn, "ALTER TABLE `products` ADD `shelf_life_days` INT(11) NOT NULL DEFAULT 0 AFTER `p_min`");
}

$status = '';
$error_msg = '';

if (isset($_POST['submit'])) {
    $p_code = mysqli_real_escape_string($conn, $_POST['p_code']);
    $p_name = mysqli_real_escape_string($conn, $_POST['p_name']);
    $p_type = mysqli_real_escape_string($conn, $_POST['p_type']);
    $p_unit = mysqli_real_escape_string($conn, $_POST['p_unit']);
    $p_qty  = (float)$_POST['p_qty'];
    $p_min  = (float)$_POST['p_min'];
    $shelf_life = (int)$_POST['shelf_life_days'];

    $check = mysqli_query($conn, "SELECT id FROM products WHERE p_code = '$p_code'");
    if ($check && mysqli_num_rows($check) > 0) {
        $status = 'error';
        $error_msg = 'รหัสสินค้านี้ ('.$p_code.') มีในระบบแล้ว!';
    } else {
        $sql = "INSERT INTO products (p_code, p_name, p_type, p_qty, p_min, p_unit, shelf_life_days) 
                VALUES ('$p_code', '$p_name', '$p_type', '$p_qty', '$p_min', '$p_unit', '$shelf_life')";
        
        if (mysqli_query($conn, $sql)) {
            $new_product_id = mysqli_insert_id($conn);
            
            // 🚀 ถ้าเป็นสินค้าสำเร็จรูป (PRODUCT) และมีจำนวนยกมา ให้สร้าง Lot อัตโนมัติทันที
            if ($p_type == 'PRODUCT' && $p_qty > 0) {
                $lot_no = "LOT-OPENING-" . $new_product_id;
                $mfg_date = date('Y-m-d');
                $exp_date = ($shelf_life > 0) ? date('Y-m-d', strtotime("+$shelf_life days")) : '2099-12-31';
                
                mysqli_query($conn, "INSERT INTO inventory_lots (product_id, lot_no, mfg_date, exp_date, qty) 
                                     VALUES ($new_product_id, '$lot_no', '$mfg_date', '$exp_date', $p_qty)");
            }
            
            $status = 'success';
        } else {
            $status = 'error';
            $error_msg = 'เกิดข้อผิดพลาด: ' . mysqli_error($conn);
        }
    }
}
include '../sidebar.php';
?>

<title>เพิ่มรายการสินค้า/วัตถุดิบ | Top Feed Mills</title>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    .wrapper { font-family: 'Sarabun', sans-serif; animation: fadeIn 0.5s ease-in-out; display: flex; justify-content: center; }
    .card-form { background: white; padding: 35px 40px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); width: 100%; max-width: 650px; border-top: 5px solid #1cc88a; }
    h3 { color: #2c3e50; margin-top: 0; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
    .form-group { margin-bottom: 18px; }
    .form-group label { display: block; font-weight: 600; margin-bottom: 8px; color: #4a5568; font-size: 0.95rem; }
    .form-control { width: 100%; padding: 12px 15px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-family: 'Sarabun'; font-size: 1rem; transition: 0.3s; box-sizing: border-box; }
    .form-control:focus { border-color: #1cc88a; outline: none; box-shadow: 0 0 0 3px rgba(28, 200, 138, 0.15); }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .btn-submit { background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%); color: white; border: none; padding: 14px; width: 100%; border-radius: 10px; font-weight: bold; cursor: pointer; font-size: 1.05rem; margin-top: 15px; transition: 0.3s; display: flex; justify-content: center; align-items: center; gap: 8px; font-family: 'Sarabun'; }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(28, 200, 138, 0.3); }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>

<div class="content-padding">
    <div class="wrapper">
        <div class="card-form">
            <h3 style="color:#1cc88a;"><i class="fa-solid fa-box-open"></i> เพิ่มข้อมูลสินค้าสู่คลัง (Master Data)</h3>
            
            <form method="POST">
                <div class="form-group">
                    <label>ประเภทรายการ (Category) <span style="color:red;">*</span></label>
                    <select name="p_type" id="p_type" class="form-control" required onchange="toggleShelfLife()">
                        <option value="">-- เลือกประเภท --</option>
                        <option value="RAW">🌾 วัตถุดิบ (Raw Material)</option>
                        <option value="PRODUCT">📦 สินค้าสำเร็จรูป (Finished Good)</option>
                        <option value="SPARE">🛠️ อะไหล่/วัสดุสิ้นเปลือง (Spare Part)</option>
                    </select>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>รหัสรายการ <span style="color:red;">*</span></label>
                        <input type="text" name="p_code" class="form-control" placeholder="เช่น RM-001" required>
                    </div>
                    <div class="form-group">
                        <label>หน่วยนับ <span style="color:red;">*</span></label>
                        <input type="text" name="p_unit" class="form-control" placeholder="เช่น ตัน, กระสอบ, ชิ้น" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>ชื่อรายการ <span style="color:red;">*</span></label>
                    <input type="text" name="p_name" class="form-control" placeholder="ระบุชื่อให้ชัดเจน..." required>
                </div>
                
                <div class="grid-2" style="background:#fffcf5; padding: 15px; border-radius: 10px; border: 1px dashed #f6c23e;" id="shelf_life_box">
                    <div class="form-group" style="margin-bottom: 0; grid-column: span 2;">
                        <label style="color:#e74a3b;"><i class="fa-solid fa-calendar-xmark"></i> อายุการจัดเก็บ (Shelf Life) / วัน</label>
                        <input type="number" name="shelf_life_days" class="form-control" placeholder="ตัวอย่าง: 90 (หากไม่มีวันหมดอายุให้ใส่ 0)" value="0" required>
                        <small style="color: #888;">* ระบบจะใช้วันนี้ไปบวกกับวันที่ผลิต เพื่อคำนวณวันหมดอายุอัตโนมัติ</small>
                    </div>
                </div>

                <div class="grid-2" style="background:#f8f9fc; padding: 15px; border-radius: 10px; border: 1px dashed #d1d3e2; margin-top: 15px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>ยอดยกมา (สต็อกเริ่มต้น)</label>
                        <input type="number" step="0.01" name="p_qty" class="form-control" value="0" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>แจ้งเตือนสต็อกต่ำ (Min)</label>
                        <input type="number" step="0.01" name="p_min" class="form-control" value="0" required>
                    </div>
                </div>
                <button type="submit" name="submit" class="btn-submit"><i class="fa-solid fa-save"></i> บันทึกรายการ</button>
            </form>
        </div>
    </div>
</div>

<script>
    function toggleShelfLife() {
        let type = document.getElementById('p_type').value;
        let box = document.getElementById('shelf_life_box');
        if(type === 'RAW' || type === 'PRODUCT') {
            box.style.display = 'grid';
        } else {
            box.style.display = 'none';
        }
    }
    toggleShelfLife();

    <?php if($status == 'success'): ?>
        Swal.fire({ icon: 'success', title: 'บันทึกสำเร็จ!', confirmButtonColor: '#1cc88a' }).then(() => { window.location.href = 'stock.php'; });
    <?php elseif($status == 'error'): ?>
        Swal.fire({ icon: 'error', title: 'ผิดพลาด', text: '<?php echo $error_msg; ?>', confirmButtonColor: '#e74a3b' });
    <?php endif; ?>
</script>