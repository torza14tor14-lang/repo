<?php
session_start();
include '../db.php';

// สเต็ปที่ 1: ตรวจสอบว่า "ล็อกอินหรือยัง?" (ถ้ายัง ให้เด้งไปหน้า login ทันที)
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

// ตรวจสอบสิทธิ์
$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
if ($user_role !== 'ADMIN' && $user_dept !== 'ฝ่ายจัดซื้อ') { 
    echo "<script>alert('ไม่มีสิทธิ์เข้าถึง'); window.location='../index.php';</script>"; exit(); 
}

$status = '';
if (isset($_POST['save_quote'])) {
    $item_id = (int)$_POST['item_id'];
    $supplier = mysqli_real_escape_string($conn, $_POST['supplier_name']);
    $price = (float)$_POST['price'];
    $date = $_POST['quote_date'];

    $sql = "INSERT INTO supplier_quotes (item_id, supplier_name, price_quoted, quote_date) 
            VALUES ('$item_id', '$supplier', '$price', '$date')";
    if (mysqli_query($conn, $sql)) { $status = 'success'; }
}

if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    mysqli_query($conn, "DELETE FROM supplier_quotes WHERE id = '$del_id'");
    header("Location: compare_prices.php"); exit();
}

include '../sidebar.php';
?>

<title>เปรียบเทียบราคา | Top Feed Mills</title>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    .grid-container { display: grid; grid-template-columns: 350px 1fr; gap: 25px; align-items: start; }
    @media (max-width: 900px) { .grid-container { grid-template-columns: 1fr; } }
    .card { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
    .form-control { width: 100%; padding: 12px; border: 1.5px solid #e2e8f0; border-radius: 10px; margin-bottom: 15px; font-family: 'Sarabun'; box-sizing: border-box; transition: 0.3s; }
    .form-control:focus { border-color: #f6c23e; outline: none; box-shadow: 0 0 0 3px rgba(246, 194, 62, 0.15); }
    .btn-save { background: #f6c23e; color: #fff; border: none; padding: 14px; width: 100%; border-radius: 10px; font-weight: bold; cursor: pointer; transition: 0.3s; font-size: 16px; }
    .btn-save:hover { background: #e3b02e; transform: translateY(-2px); }
    
    .compare-group { margin-bottom: 25px; border: 1px solid #eaecf4; border-radius: 12px; overflow: hidden; }
    .compare-header { background: #f8f9fc; padding: 15px; color: #5a5c69; font-weight: bold; display: flex; justify-content: space-between; border-bottom: 2px solid #eaecf4; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 12px 15px; border-bottom: 1px solid #eaecf4; text-align: left; }
    .best-price { background: #fdfaf2; font-weight: bold; color: #d69c11; }

    /* ปรับแต่งหน้าตา Select2 ให้เข้ากับธีมของหน้านี้ */
    .select2-container { margin-bottom: 15px; }
    .select2-container--default .select2-selection--single {
        height: 48px;
        border: 1.5px solid #e2e8f0;
        border-radius: 10px;
        display: flex;
        align-items: center;
        font-family: 'Sarabun', sans-serif;
        outline: none;
        transition: 0.3s;
    }
    .select2-container--default .select2-selection--single:focus,
    .select2-container--open .select2-selection--single {
        border-color: #f6c23e;
        box-shadow: 0 0 0 3px rgba(246, 194, 62, 0.15);
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
        border: 1.5px solid #f6c23e;
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
    <h2 style="color: #2c3e50; margin-top:0;"><i class="fa-solid fa-scale-balanced" style="color: #f6c23e;"></i> เปรียบเทียบราคาวัตถุดิบ (Supplier Quotes)</h2>
    
    <div class="grid-container" style="margin-top: 20px;">
        <div class="card" style="border-top: 5px solid #f6c23e;">
            <h4 style="margin-top:0;">📝 บันทึกราคาเสนอขาย</h4>
            <form method="POST">
                <label style="font-size:14px; font-weight:bold; color:#555;">ค้นหาวัตถุดิบ (RAW)</label>
                <select name="item_id" class="form-control select2-search" required>
                    <option value="">-- พิมพ์ชื่อเพื่อค้นหาวัตถุดิบ --</option>
                    <?php 
                    $res = mysqli_query($conn, "SELECT id, p_name FROM products WHERE p_type = 'RAW' ORDER BY p_name ASC");
                    while($row = mysqli_fetch_assoc($res)) { echo "<option value='{$row['id']}'>🌾 {$row['p_name']}</option>"; }
                    ?>
                </select>

                <label style="font-size:14px; font-weight:bold; color:#555;">ชื่อร้านค้า / Supplier</label>
                <select name="supplier_name" class="form-control select2-search" required>
                    <option value="">-- เลือกผู้ขาย --</option>
                    <?php 
                    $s_list = mysqli_query($conn, "SELECT s_name FROM suppliers ORDER BY s_name ASC");
                    while($s = mysqli_fetch_assoc($s_list)) {
                        echo "<option value='{$s['s_name']}'>🏢 {$s['s_name']}</option>";
                    }
                    ?>
                </select>

                <label style="font-size:14px; font-weight:bold; color:#555;">ราคาเสนอ (บาท/หน่วย)</label>
                <input type="number" step="0.01" name="price" class="form-control" placeholder="0.00" required>

                <label style="font-size:14px; font-weight:bold; color:#555;">วันที่เสนอราคา</label>
                <input type="date" name="quote_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>

                <button type="submit" name="save_quote" class="btn-save"><i class="fa-solid fa-save"></i> บันทึกข้อมูล</button>
            </form>
        </div>

        <div class="card">
            <h4 style="margin-top:0;">📊 ตารางเปรียบเทียบ (เรียงจากถูกสุด)</h4>
            <?php
            $items_sql = "SELECT DISTINCT sq.item_id, p.p_name FROM supplier_quotes sq JOIN products p ON sq.item_id = p.id";
            $items_res = mysqli_query($conn, $items_sql);
            if(mysqli_num_rows($items_res) > 0) {
                while($item = mysqli_fetch_assoc($items_res)) {
                    $item_id = $item['item_id'];
            ?>
                <div class="compare-group">
                    <div class="compare-header">
                        <span><i class="fa-solid fa-wheat-awn"></i> <?php echo $item['p_name']; ?></span>
                    </div>
                    <table>
                        <tr style="color: #888; font-size: 13px;"><th>Supplier</th><th>ราคา (฿)</th><th>วันที่อัปเดต</th><th></th></tr>
                        <?php
                        $quotes = mysqli_query($conn, "SELECT * FROM supplier_quotes WHERE item_id = '$item_id' ORDER BY price_quoted ASC");
                        $first = true;
                        while($q = mysqli_fetch_assoc($quotes)) {
                            $row_class = $first ? "class='best-price'" : "";
                            $icon = $first ? "<i class='fa-solid fa-crown' style='color:#f6c23e;' title='ราคาถูกที่สุด'></i>" : "";
                        ?>
                            <tr <?php echo $row_class; ?>>
                                <td><?php echo $icon . " " . $q['supplier_name']; ?></td>
                                <td><?php echo number_format($q['price_quoted'], 2); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($q['quote_date'])); ?></td>
                                <td style="text-align:right;">
                                    <a href="?delete=<?php echo $q['id']; ?>" onclick="return confirm('ลบข้อมูลนี้?')" style="color:#e74a3b;"><i class="fa-solid fa-trash-can"></i></a>
                                </td>
                            </tr>
                        <?php $first = false; } ?>
                    </table>
                </div>
            <?php 
                } 
            } else { echo "<p style='color:#888; text-align:center;'>ยังไม่มีข้อมูลการเสนอราคา</p>"; }
            ?>
        </div>
    </div>
</div>

<script>
    // เริ่มต้นการทำงานของ Select2
    $(document).ready(function() {
        $('.select2-search').select2({
            placeholder: "-- พิมพ์ชื่อเพื่อค้นหาวัตถุดิบ --",
            allowClear: true,
            width: '100%' // ให้กว้างเต็มกล่องฟอร์ม
        });
    });

    <?php if($status == 'success'): ?>
    Swal.fire({ icon: 'success', title: 'บันทึกสำเร็จ', showConfirmButton: false, timer: 1500 });
    <?php endif; ?>
</script>