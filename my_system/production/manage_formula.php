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
    :root { --primary: #4f46e5; --primary-hover: #4338ca; --danger: #ef4444; --bg-color: #f8fafc; --card-bg: #ffffff; --border-color: #e2e8f0; }
    body { font-family: 'Sarabun', sans-serif; background-color: var(--bg-color); }
    .content-padding { padding: 24px; width: 100%; box-sizing: border-box; max-width: 1400px; margin: auto;}
    
    .card-formula { background: var(--card-bg); padding: 30px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 25px; width: 100%; }
    
    /* Layout ฟอร์มด้านบน */
    .form-header-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    @media (max-width: 768px) { .form-header-grid { grid-template-columns: 1fr; } }

    .form-control { width: 100%; padding: 12px; border: 1.5px solid var(--border-color); border-radius: 10px; font-family: 'Sarabun'; }
    .btn-submit { background: var(--primary); color: white; border: none; padding: 14px; border-radius: 10px; font-weight: 600; cursor: pointer; width: 100%; margin-top: 20px; font-size: 16px; transition: 0.3s; }
    .btn-submit:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(79, 70, 229, 0.3); }

    /* ตารางเพิ่มวัตถุดิบ (ฟอร์มสร้าง) */
    .bom-table { width: 100%; border-collapse: collapse; }
    .bom-table th { background: #f1f5f9; padding: 12px; text-align: left; font-size: 13px; color: #64748b; }
    .bom-table td { padding: 10px; border-bottom: 1px solid #f1f5f9; }

    /* 🚀 สไตล์สำหรับ Accordion (พับเก็บได้) อัปเกรดใหม่ */
    .acc-item { border: 1px solid var(--border-color); border-radius: 12px; margin-bottom: 15px; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.02); transition: 0.3s; }
    .acc-item:hover { box-shadow: 0 5px 15px rgba(0,0,0,0.06); border-color: #cbd5e1; }
    
    .acc-header { padding: 18px 25px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: 0.2s; border-radius: 12px; }
    .acc-header.active { border-bottom-left-radius: 0; border-bottom-right-radius: 0; border-bottom: 1px solid var(--border-color); background: #f8fafc; }
    
    .acc-title { display: flex; align-items: center; gap: 12px; font-size: 16px; font-weight: 700; color: var(--text-main); }
    .p-code-badge { background: #e2e8f0; color: #475569; padding: 4px 10px; border-radius: 6px; font-size: 13px; font-weight: 700; letter-spacing: 0.5px;}
    .formula-count { font-size: 13px; color: #64748b; font-weight: normal; margin-left: 5px; }

    .acc-icon { color: var(--primary); font-size: 16px; transition: transform 0.3s ease; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: #e0e7ff;}
    
    .acc-content { display: none; padding: 25px; background: #f8fafc; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px; }

    /* 🚀 สไตล์กล่องสูตรย่อย (ด้านใน Accordion) */
    .formula-card { background: white; border: 1px solid var(--border-color); border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.02); overflow: hidden; }
    .formula-card:last-child { margin-bottom: 0; }
    
    .formula-card-header { background: #ffffff; border-bottom: 1px solid #f1f5f9; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
    .formula-name { font-weight: 700; color: var(--primary); font-size: 16px; display: flex; align-items: center; gap: 8px; }
    
    /* ตารางแสดงผลในสูตร */
    table.display-table { width: 100%; border-collapse: collapse; }
    table.display-table th { background: #fcfcfc; color: var(--text-muted); font-size: 13px; text-transform: uppercase; font-weight: 600; padding: 12px 20px; border-bottom: 1px solid #f1f5f9; text-align: left; }
    table.display-table td { padding: 12px 20px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 15px; }
    table.display-table tr:last-child td { border-bottom: none; }
    table.display-table tr:hover td { background-color: #f8fafc; }

    .qty-badge { background: #e0e7ff; color: var(--primary); padding: 5px 12px; border-radius: 20px; font-weight: 700; font-size: 14px; display: inline-block; }
    
    .btn-add-row { background: #e0e7ff; color: var(--primary); border: 1px dashed var(--primary); padding: 10px; width: 100%; border-radius: 8px; font-weight: bold; cursor: pointer; margin-top: 10px; font-family:'Sarabun'; transition:0.2s;}
    .btn-add-row:hover { background: #c7d2fe; }
    
    .btn-delete-formula { background: #fee2e2; color: var(--danger); padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: bold; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s; border: 1px solid #fca5a5;}
    .btn-delete-formula:hover { background: var(--danger); color: white; border-color: var(--danger); }

    .btn-delete-item { color: #cbd5e1; font-size: 18px; transition: 0.2s; text-decoration: none; padding: 5px; }
    .btn-delete-item:hover { color: var(--danger); }
</style>

<div class="content-padding">
    <div class="card-formula" style="border-top: 5px solid var(--primary);">
        <h3 style="margin-top:0; color:var(--text-main); font-size:20px;"><i class="fa-solid fa-flask-vial" style="color:var(--primary);"></i> สร้าง / อัปเดตสูตรการผลิต</h3>
        <p style="color:var(--text-muted); font-size:14px; margin-top:-5px; margin-bottom:20px;">เพิ่มสูตรใหม่ หรือปรับปรุงสัดส่วนวัตถุดิบ (สัดส่วนต่อการผลิต 1 หน่วย)</p>
        
        <form method="POST">
            <div class="form-header-grid">
                <div>
                    <label style="font-weight:bold; display:block; margin-bottom:8px; color:var(--text-main);">1. เลือกสินค้าสำเร็จรูป (Product)</label>
                    <select name="product_id" class="select2 form-control" required>
                        <option value="">-- พิมพ์ค้นหาชื่อสินค้า --</option>
                        <?php 
                        $p_res = mysqli_query($conn, "SELECT id, p_name FROM products WHERE p_type = 'PRODUCT' ORDER BY p_name ASC");
                        while($row = mysqli_fetch_assoc($p_res)) { echo "<option value='{$row['id']}'>📦 {$row['p_name']}</option>"; }
                        ?>
                    </select>
                </div>
                <div>
                    <label style="font-weight:bold; display:block; margin-bottom:8px; color:var(--text-main);">2. ระบุชื่อสูตร</label>
                    <input type="text" name="formula_name" class="form-control" placeholder="เช่น สูตรโปรตีนสูง, สูตรดั้งเดิม..." required>
                </div>
            </div>

            <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px dashed #cbd5e1;">
                <label style="font-weight:bold; color:var(--primary); margin-bottom:15px; display:block; font-size:15px;">3. รายการส่วนผสม (Ingredients)</label>
                <table class="bom-table" id="bomTable">
                    <thead>
                        <tr>
                            <th width="70%">วัตถุดิบ (Raw Material)</th>
                            <th width="20%">ปริมาณ / สัดส่วน</th>
                            <th width="10%" style="text-align:center;">ลบ</th>
                        </tr>
                    </thead>
                    <tbody id="bomBody">
                        <tr>
                            <td><select name="items[0][raw_id]" class="form-control select2-item" required><?php echo $raw_opts; ?></select></td>
                            <td>
                                <div style="position:relative;">
                                    <input type="number" step="0.001" name="items[0][qty]" class="form-control" placeholder="0.000" required>
                                </div>
                            </td>
                            <td style="text-align:center;">-</td>
                        </tr>
                    </tbody>
                </table>
                <button type="button" class="btn-add-row" onclick="addRow()"><i class="fa-solid fa-plus"></i> เพิ่มแถววัตถุดิบ</button>
            </div>
            <button type="submit" name="save_formula" class="btn-submit"><i class="fa-solid fa-save"></i> บันทึกข้อมูลสูตรการผลิต</button>
        </form>
    </div>

    <div class="card-formula">
        <h3 style="margin-top:0; margin-bottom:25px; color:var(--text-main); font-size:20px;"><i class="fa-solid fa-list-check" style="color:var(--primary);"></i> รายการสูตรแยกตามสินค้า</h3>
        
        <?php
        $q_products = mysqli_query($conn, "SELECT DISTINCT f.product_id, p.p_name, p.p_code FROM formulas f JOIN products p ON f.product_id = p.id");
        if(mysqli_num_rows($q_products) > 0) {
            while($p = mysqli_fetch_assoc($q_products)) {
                $pid = $p['product_id'];
                
                // นับว่าสินค้านี้มีกี่สูตร
                $q_count = mysqli_query($conn, "SELECT COUNT(DISTINCT formula_name) as fc FROM formulas WHERE product_id = '$pid'");
                $f_count = mysqli_fetch_assoc($q_count)['fc'] ?? 0;
        ?>
            <div class="acc-item">
                <div class="acc-header" onclick="toggleAcc('prod_<?= $pid ?>', this)">
                    <div class="acc-title">
                        <span class="p-code-badge"><?= $p['p_code']; ?></span>
                        <span><i class="fa-solid fa-box" style="color: #94a3b8; margin-right:5px;"></i> <?= $p['p_name']; ?> <span class="formula-count">(<?= $f_count ?> สูตร)</span></span>
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
                                <a href="?del_formula=<?= urlencode($fname) ?>&p_id=<?= $pid ?>" class="btn-delete-formula" onclick="return confirm('ลบสูตรนี้ทิ้งทั้งหมดเลยหรือไม่?')"><i class="fa-solid fa-trash-can"></i> ลบทั้งสูตร</a>
                            </div>
                            <table class="display-table">
                                <thead>
                                    <tr>
                                        <th width="65%">วัตถุดิบ (Raw Material)</th>
                                        <th width="25%">ปริมาณที่ต้องใช้</th>
                                        <th width="10%" style="text-align:center;">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $q_items = mysqli_query($conn, "SELECT f.*, p.p_name FROM formulas f JOIN products p ON f.ingredient_id = p.id WHERE f.product_id = '$pid' AND f.formula_name = '$fname'");
                                    while($it = mysqli_fetch_assoc($q_items)) {
                                    ?>
                                        <tr>
                                            <td><i class="fa-solid fa-seedling" style="color:#10b981; margin-right:10px;"></i><?= $it['p_name'] ?></td>
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
        <?php } } else { echo "<div style='text-align:center; padding:40px; color:#94a3b8;'><i class='fa-solid fa-folder-open fa-3x' style='margin-bottom:15px; color:#cbd5e1;'></i><br>ยังไม่มีสูตรการผลิตในระบบ</div>"; } ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.12/dist/sweetalert2.all.min.js"></script>

<script>
    $(document).ready(function() {
        $('.select2').select2({ width: '100%' });
        $('.select2-item').select2({ width: '100%' });
    });

    let rowIdx = 1;
    const rawOpts = `<?php echo $raw_opts; ?>`;

    function addRow() {
        const tr = `<tr>
            <td><select name="items[${rowIdx}][raw_id]" class="form-control select2-item" required>${rawOpts}</select></td>
            <td><input type="number" step="0.001" name="items[${rowIdx}][qty]" class="form-control" required></td>
            <td align="center"><button type="button" class="btn text-danger" style="background:none; border:none; cursor:pointer; color:#ef4444; font-size:18px;" onclick="$(this).closest('tr').remove()"><i class="fa-solid fa-trash-can"></i></button></td>
        </tr>`;
        $('#bomBody').append(tr);
        $('.select2-item').last().select2({ width: '100%' });
        rowIdx++;
    }

    function toggleAcc(id, el) {
        $(`#${id}`).slideToggle(250);
        $(el).toggleClass('active');
        $(el).find('.arrow-icon').toggleClass('fa-rotate-180');
    }

    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('status')==='success') Swal.fire({ icon:'success', title:'บันทึกสำเร็จ!', timer:1500, showConfirmButton:false }).then(()=>window.history.replaceState(null,null,window.location.pathname));
    if(urlParams.get('status')==='deleted') Swal.fire({ icon:'success', title:'ลบสูตรเรียบร้อย!', timer:1500, showConfirmButton:false }).then(()=>window.history.replaceState(null,null,window.location.pathname));
    if(urlParams.get('status')==='item_deleted') Swal.fire({ icon:'success', title:'นำวัตถุดิบออกแล้ว!', timer:1500, showConfirmButton:false }).then(()=>window.history.replaceState(null,null,window.location.pathname));
</script>
</body>
</html>