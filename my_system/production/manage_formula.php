<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'User';

if ($user_role !== 'ADMIN' && $user_dept !== 'แผนกผลิต 1' && $user_dept !== 'แผนกผลิต 2') { 
    echo "<script>alert('ไม่มีสิทธิ์เข้าถึง'); window.location='../index.php';</script>"; exit(); 
}

// 🚀 บันทึกสูตร
if (isset($_POST['save_formula'])) {
    $p_id = (int)$_POST['product_id'];
    $f_name = mysqli_real_escape_string($conn, $_POST['formula_name']);

    if (isset($_POST['items']) && is_array($_POST['items'])) {
        mysqli_query($conn, "DELETE FROM formulas WHERE product_id = '$p_id' AND formula_name = '$f_name'");
        
        $count_items = 0;
        foreach ($_POST['items'] as $item) {
            $ing_id = (int)$item['raw_id'];
            $qty = (float)$item['qty'];
            
            if ($ing_id > 0 && $qty > 0) {
                mysqli_query($conn, "INSERT INTO formulas (product_id, formula_name, ingredient_id, quantity_required) 
                                     VALUES ('$p_id', '$f_name', '$ing_id', '$qty')");
                $count_items++;
            }
        }

        if(function_exists('log_event') && $count_items > 0) {
            $p_name = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p_name FROM products WHERE id = $p_id"))['p_name'] ?? 'Unknown';
            log_event($conn, 'INSERT', 'formulas', "ตั้งค่าสูตร: $p_name ($f_name) จำนวน $count_items ส่วนผสม");
        }
    }
    header("Location: manage_formula.php?status=success"); exit();
}

// 🚀 ลบสูตรทั้งชุด
if (isset($_GET['del_formula']) && isset($_GET['p_id'])) {
    $p_id = (int)$_GET['p_id'];
    $f_name = mysqli_real_escape_string($conn, $_GET['del_formula']);
    mysqli_query($conn, "DELETE FROM formulas WHERE product_id = '$p_id' AND formula_name = '$f_name'");
    header("Location: manage_formula.php?status=deleted"); exit();
}

// 🚀 ลบวัตถุดิบย่อยรายตัว
if (isset($_GET['del_item_id'])) {
    $id = (int)$_GET['del_item_id'];
    mysqli_query($conn, "DELETE FROM formulas WHERE id = '$id'");
    header("Location: manage_formula.php?status=item_deleted"); exit();
}

$raw_opts = "<option value=''>-- เลือกวัตถุดิบ --</option>";
$q_raw = mysqli_query($conn, "SELECT id, p_name, p_unit FROM products WHERE p_type = 'RAW' ORDER BY p_name ASC");
while($r = mysqli_fetch_assoc($q_raw)) { 
    $raw_opts .= "<option value='{$r['id']}'>🌾 {$r['p_name']} (".($r['p_unit']??"กก.").")</option>"; 
}

include '../sidebar.php';
?>

<title>จัดการสูตรอาหาร | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.12/dist/sweetalert2.min.css" rel="stylesheet">

<style>
    :root { 
        --primary-light: #e0e7ff;
        --danger: #ef4444; --danger-hover: #dc2626; --danger-light: #fee2e2;
        --bg-color: #f8fafc; --card-bg: #ffffff; --border-color: #cbd5e1;
        --text-main: #1e293b; --text-muted: #64748b;
    }
    body { font-family: 'Sarabun', sans-serif; background-color: var(--bg-color); }
    .content-padding { padding: 24px; width: 100%; box-sizing: border-box; max-width: 1400px; margin: auto;}
    
    .card-formula { background: var(--card-bg); padding: 35px; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; margin-bottom: 30px; width: 100%; }
    
    .form-header-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
    @media (max-width: 768px) { .form-header-grid { grid-template-columns: 1fr; } }

    .form-control { width: 100%; padding: 12px 16px; border: 1.5px solid var(--border-color); border-radius: 10px; font-family: 'Sarabun'; font-size: 15px; color: var(--text-main); font-weight: 500; transition: 0.2s;}
    .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15); }
    
    .form-label { display: block; font-size: 14.5px; font-weight: 700; color: var(--text-main); margin-bottom: 8px; }

    .btn-submit { background: var(--primary); color: white; border: none; padding: 16px; border-radius: 12px; font-weight: 700; cursor: pointer; width: 100%; margin-top: 25px; font-size: 16px; transition: 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2);}
    .btn-submit:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 6px 15px rgba(79, 70, 229, 0.3); }

    /* 🚀 รื้อระบบ Select2 (Dropdown) ใหม่ทั้งหมดให้สวยเนี๊ยบ */
    .select2-container--default .select2-selection--single {
        height: 48px !important;
        border: 1.5px solid var(--border-color) !important;
        border-radius: 10px !important;
        display: flex !important;
        align-items: center;
        background-color: #fff;
        transition: all 0.2s ease;
    }
    .select2-container--default.select2-container--open .select2-selection--single,
    .select2-container--default.select2-container--focus .select2-selection--single {
        border-color: var(--primary) !important;
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15) !important;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        color: var(--text-main) !important;
        font-size: 15px;
        font-weight: 600;
        padding-left: 16px !important;
        line-height: normal !important;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 46px !important;
        right: 12px !important;
    }
    /* ปรับแต่ง Dropdown List ที่กางออกมา */
    .select2-dropdown {
        border: 1px solid #e2e8f0 !important;
        border-radius: 12px !important;
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1) !important;
        overflow: hidden;
    }
    .select2-search__field {
        border-radius: 8px !important;
        border: 1px solid #cbd5e1 !important;
        padding: 10px 14px !important;
        font-family: 'Sarabun';
    }

    /* ตารางเพิ่มวัตถุดิบ */
    .bom-box { background: #f8fafc; padding: 25px; border-radius: 16px; border: 1.5px dashed #cbd5e1; }
    .bom-table { width: 100%; border-collapse: collapse; }
    .bom-table th { padding: 0 10px 12px 10px; text-align: left; font-size: 14px; color: var(--text-muted); font-weight: 700; border-bottom: 2px solid #e2e8f0;}
    .bom-table td { padding: 15px 10px; border-bottom: 1px dashed #e2e8f0; vertical-align: top;}

    .btn-add-row { background: var(--primary-light); color: var(--primary); border: 1.5px dashed var(--primary); padding: 14px; width: 100%; border-radius: 10px; font-weight: 700; cursor: pointer; margin-top: 15px; font-family:'Sarabun'; transition:0.2s; font-size: 15px;}
    .btn-add-row:hover { background: #c7d2fe; }

    /* 🚀 สไตล์ Accordion (อัปเกรดความพรีเมียม) */
    .acc-item { border: 1px solid #e2e8f0; border-radius: 14px; margin-bottom: 16px; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.02); transition: 0.3s; overflow: hidden;}
    .acc-item:hover { box-shadow: 0 8px 20px rgba(0,0,0,0.06); border-color: #cbd5e1; transform: translateY(-2px);}
    
    .acc-header { padding: 20px 24px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: 0.2s; background: #fff;}
    .acc-header.active { background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
    
    .acc-title { display: flex; align-items: center; gap: 12px; font-size: 17px; font-weight: 700; color: var(--text-main); }
    .p-code-badge { background: #f1f5f9; color: #475569; padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 800; border: 1px solid #e2e8f0;}
    .formula-count { background: var(--primary-light); color: var(--primary); padding: 4px 12px; border-radius: 50px; font-size: 13px; font-weight: 800; margin-left: 8px; }

    .acc-icon { color: var(--primary); font-size: 16px; transition: transform 0.3s ease; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: var(--primary-light);}
    
    .acc-content { display: none; padding: 24px; background: #f8fafc; }

    /* 🚀 สไตล์การ์ดสูตรย่อย */
    .formula-card { background: white; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); overflow: hidden; }
    .formula-card:last-child { margin-bottom: 0; }
    
    .formula-card-header { background: #ffffff; border-bottom: 1px solid #f1f5f9; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
    .formula-name { font-weight: 800; color: var(--primary); font-size: 16px; display: flex; align-items: center; gap: 10px; }
    
    table.display-table { width: 100%; border-collapse: collapse; }
    table.display-table th { background: #fcfcfc; color: var(--text-muted); font-size: 13px; text-transform: uppercase; font-weight: 700; padding: 14px 24px; border-bottom: 1px solid #e2e8f0; text-align: left; }
    table.display-table td { padding: 16px 24px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 15px; font-weight: 600; color: var(--text-main);}
    table.display-table tr:last-child td { border-bottom: none; }
    table.display-table tr:hover td { background-color: #f8fafc; }

    .qty-badge { background: #f1f5f9; color: var(--text-main); border: 1px solid #cbd5e1; padding: 6px 14px; border-radius: 8px; font-weight: 700; font-size: 14px; display: inline-block; }
    
    .btn-delete-formula { background: var(--danger-light); color: var(--danger); padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; border: 1px solid #fca5a5;}
    .btn-delete-formula:hover { background: var(--danger); color: white; border-color: var(--danger); }

    .btn-delete-item { color: #94a3b8; background: #f1f5f9; width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; transition: 0.2s; text-decoration: none; }
    .btn-delete-item:hover { color: white; background: var(--danger); }
</style>

<div class="content-padding">
    <div class="card-formula" style="border-top: 5px solid var(--primary);">
        <h3 style="margin-top:0; color:var(--text-main); font-size:22px; font-weight:800; margin-bottom: 25px;">
            <i class="fa-solid fa-flask-vial" style="color:var(--primary); margin-right:8px;"></i> สร้าง / อัปเดตสูตรการผลิต
        </h3>
        
        <form method="POST">
            <div class="form-header-grid">
                <div>
                    <label class="form-label">1. เลือกสินค้าสำเร็จรูป (Product)</label>
                    <select name="product_id" class="select2 form-control" required>
                        <option value="">-- พิมพ์ค้นหาชื่อสินค้า --</option>
                        <?php 
                        $p_res = mysqli_query($conn, "SELECT id, p_name FROM products WHERE p_type = 'PRODUCT' ORDER BY p_name ASC");
                        while($row = mysqli_fetch_assoc($p_res)) { echo "<option value='{$row['id']}'>📦 {$row['p_name']}</option>"; }
                        ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">2. ระบุชื่อสูตร</label>
                    <input type="text" name="formula_name" class="form-control" placeholder="เช่น สูตรโปรตีนสูง, สูตรดั้งเดิม..." required>
                </div>
            </div>

            <div class="bom-box">
                <label class="form-label" style="color: var(--primary); font-size: 16px; margin-bottom: 20px;">
                    <i class="fa-solid fa-scale-balanced"></i> 3. รายการส่วนผสม (สัดส่วนต่อการผลิต 1 หน่วย)
                </label>
                <div style="overflow-x: auto;">
                    <table class="bom-table" id="bomTable">
                        <thead>
                            <tr>
                                <th width="65%">วัตถุดิบ (Raw Material)</th>
                                <th width="25%">ปริมาณ (กก.)</th>
                                <th width="10%" style="text-align:center;">ลบ</th>
                            </tr>
                        </thead>
                        <tbody id="bomBody">
                            <tr>
                                <td>
                                    <select name="items[0][raw_id]" class="form-control select2-item" required>
                                        <?php echo $raw_opts; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" step="0.001" name="items[0][qty]" class="form-control" placeholder="0.000" required>
                                </td>
                                <td align="center">
                                    <button type="button" class="btn-delete-item" style="border:none; cursor:pointer;" onclick="$(this).closest('tr').remove()"><i class="fa-solid fa-trash-can"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn-add-row" onclick="addRow()"><i class="fa-solid fa-plus"></i> เพิ่มแถววัตถุดิบ</button>
            </div>
            <button type="submit" name="save_formula" class="btn-submit"><i class="fa-solid fa-save"></i> ยืนยันบันทึกโครงสร้างสูตร</button>
        </form>
    </div>

    <div class="card-formula">
        <h3 style="margin-top:0; margin-bottom:25px; color:var(--text-main); font-size:20px; font-weight:800;">
            <i class="fa-solid fa-list-check" style="color:var(--primary); margin-right:8px;"></i> รายการสูตรแยกตามสินค้า
        </h3>
        
        <?php
        $q_products = mysqli_query($conn, "SELECT DISTINCT f.product_id, p.p_name, p.p_code FROM formulas f JOIN products p ON f.product_id = p.id");
        if(mysqli_num_rows($q_products) > 0) {
            while($p = mysqli_fetch_assoc($q_products)) {
                $pid = $p['product_id'];
                $q_count = mysqli_query($conn, "SELECT COUNT(DISTINCT formula_name) as fc FROM formulas WHERE product_id = '$pid'");
                $f_count = mysqli_fetch_assoc($q_count)['fc'] ?? 0;
        ?>
            <div class="acc-item">
                <div class="acc-header" onclick="toggleAcc('prod_<?= $pid ?>', this)">
                    <div class="acc-title">
                        <span class="p-code-badge"><?= $p['p_code']; ?></span>
                        <span><?= $p['p_name']; ?></span>
                        <span class="formula-count"><?= $f_count ?> สูตร</span>
                    </div>
                    <div class="acc-icon"><i class="fa-solid fa-chevron-down arrow-icon"></i></div>
                </div>
                
                <div class="acc-content" id="prod_<?= $pid ?>">
                    <?php
                    $q_f_names = mysqli_query($conn, "SELECT DISTINCT formula_name FROM formulas WHERE product_id = '$pid'");
                    while($fn = mysqli_fetch_assoc($q_f_names)) {
                        $fname = $fn['formula_name'];
                    ?>
                        <div class="formula-card">
                            <div class="formula-card-header">
                                <div class="formula-name"><i class="fa-solid fa-clipboard-list"></i> สูตร: <?= htmlspecialchars($fname) ?></div>
                                <a href="?del_formula=<?= urlencode($fname) ?>&p_id=<?= $pid ?>" class="btn-delete-formula" onclick="return confirm('ยืนยันลบสูตรนี้ทิ้งทั้งหมด?')"><i class="fa-solid fa-trash-can"></i> ลบทั้งสูตร</a>
                            </div>
                            <table class="display-table">
                                <thead>
                                    <tr>
                                        <th width="65%">วัตถุดิบ (Raw Material)</th>
                                        <th width="25%">ปริมาณที่ต้องใช้</th>
                                        <th width="10%" style="text-align:center;">ลบ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $q_items = mysqli_query($conn, "SELECT f.*, p.p_name FROM formulas f JOIN products p ON f.ingredient_id = p.id WHERE f.product_id = '$pid' AND f.formula_name = '$fname'");
                                    while($it = mysqli_fetch_assoc($q_items)) {
                                    ?>
                                        <tr>
                                            <td><i class="fa-solid fa-seedling" style="color:#10b981; margin-right:12px;"></i><?= $it['p_name'] ?></td>
                                            <td><span class="qty-badge"><?= number_format($it['quantity_required'], 3) ?> กก.</span></td>
                                            <td style="text-align:center;">
                                                <a href="?del_item_id=<?= $it['id']; ?>" class="btn-delete-item" title="ลบรายการนี้" onclick="return confirm('ลบวัตถุดิบนี้ออกจากสูตร?')"><i class="fa-solid fa-xmark"></i></a>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    <?php } ?>
                </div>
            </div>
        <?php } } else { echo "<div style='text-align:center; padding:50px; color:#94a3b8;'><i class='fa-solid fa-folder-open fa-3x' style='margin-bottom:20px; color:#e2e8f0;'></i><br>ยังไม่มีสูตรการผลิตในระบบ</div>"; } ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.12/dist/sweetalert2.all.min.js"></script>

<script>
    $(document).ready(function() {
        // อัปเกรด Select2 ให้สวยขึ้น
        $('.select2').select2({ width: '100%', language: { noResults: function() { return "ไม่พบข้อมูล"; } } });
        $('.select2-item').select2({ width: '100%' });

        const urlParams = new URLSearchParams(window.location.search);
        const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true });

        if (urlParams.get('status') === 'success') {
            Toast.fire({ icon: 'success', title: 'บันทึกโครงสร้างสูตรเรียบร้อย!' });
            window.history.replaceState(null, null, window.location.pathname);
        } else if (urlParams.get('status') === 'deleted' || urlParams.get('status') === 'item_deleted') {
            Toast.fire({ icon: 'success', title: 'ลบข้อมูลเรียบร้อย!' });
            window.history.replaceState(null, null, window.location.pathname);
        }
    });

    // 🚀 ระบบเปิด-ปิด Accordion
    function toggleAcc(id, el) {
        $(`#${id}`).slideToggle(300);
        $(el).toggleClass('active');
        $(el).find('.arrow-icon').toggleClass('fa-rotate-180');
    }

    let rowIdx = 1;
    const rawOpts = `<?php echo $raw_opts; ?>`;

    function addRow() {
        const tr = `<tr>
            <td><select name="items[${rowIdx}][raw_id]" class="form-control select2-item" required>${rawOpts}</select></td>
            <td><input type="number" step="0.001" name="items[${rowIdx}][qty]" class="form-control" placeholder="0.000" required></td>
            <td align="center"><button type="button" class="btn-delete-item" style="border:none; cursor:pointer;" onclick="$(this).closest('tr').remove()"><i class="fa-solid fa-trash-can"></i></button></td>
        </tr>`;
        $('#bomBody').append(tr);
        $('.select2-item').last().select2({ width: '100%' });
        rowIdx++;
    }
</script>
</body>
</html>