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

// เช็คสิทธิ์ (ต้องเป็น ADMIN, MANAGER หรือ ฝ่ายงานวางแผน)
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && $user_dept !== 'ฝ่ายงานวางแผน') { 
    echo "<script>alert('เฉพาะฝ่ายวางแผนและผู้บริหารเท่านั้น'); window.location='../index.php';</script>"; exit(); 
}

// 🚀 [AJAX] ดึงข้อมูลสูตร เมื่อฝ่ายวางแผนเลือก "สินค้า"
if (isset($_POST['action']) && $_POST['action'] == 'get_formulas') {
    $p_id = (int)$_POST['product_id'];
    $formulas = [];
    $q_f = mysqli_query($conn, "SELECT DISTINCT formula_name FROM formulas WHERE product_id = $p_id ORDER BY formula_name ASC");
    if ($q_f) {
        while($r = mysqli_fetch_assoc($q_f)) {
            $formulas[] = $r['formula_name'];
        }
    }
    echo json_encode($formulas);
    exit;
}

// 🚀 [Auto-Create/Update Table] สร้างและอัปเดตตาราง
$check_table = mysqli_query($conn, "SHOW TABLES LIKE 'production_orders'");
if (mysqli_num_rows($check_table) == 0) {
    $create_tbl = "CREATE TABLE production_orders (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        order_no VARCHAR(50) NOT NULL,
        product_id INT(11) NOT NULL DEFAULT 0,
        formula_name VARCHAR(255) NOT NULL,
        target_qty DECIMAL(10,2) NOT NULL,
        unit VARCHAR(50) DEFAULT 'ตัน',
        production_line VARCHAR(100) NOT NULL,
        start_date DATE NOT NULL,
        due_date DATE NOT NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        created_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;";
    mysqli_query($conn, $create_tbl);
} else {
    // เพิ่มคอลัมน์ product_id ถ้ายังไม่มี
    $check_col = mysqli_query($conn, "SHOW COLUMNS FROM `production_orders` LIKE 'product_id'");
    if(mysqli_num_rows($check_col) == 0){
        mysqli_query($conn, "ALTER TABLE `production_orders` ADD `product_id` INT(11) NOT NULL DEFAULT 0 AFTER `order_no`");
    }
}

// 🚀 1. บันทึกใบสั่งผลิตใหม่
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_order'])) {
    $prefix = "PRD-" . date("Ym");
    $q_last = mysqli_query($conn, "SELECT order_no FROM production_orders WHERE order_no LIKE '$prefix%' ORDER BY id DESC LIMIT 1");
    $run_no = 1;
    if ($row_last = mysqli_fetch_assoc($q_last)) {
        $last_no = intval(substr($row_last['order_no'], -3));
        $run_no = $last_no + 1;
    }
    $order_no = $prefix . "-" . str_pad($run_no, 3, "0", STR_PAD_LEFT);

    $product_id = (int)$_POST['product_id'];
    $formula_name = mysqli_real_escape_string($conn, $_POST['formula_name']);
    $target_qty = (float)$_POST['target_qty'];
    $production_line = mysqli_real_escape_string($conn, $_POST['production_line']);
    $start_date = $_POST['start_date'];
    $due_date = $_POST['due_date'];
    $created_by = $_SESSION['fullname'];

    $sql = "INSERT INTO production_orders (order_no, product_id, formula_name, target_qty, production_line, start_date, due_date, created_by) 
            VALUES ('$order_no', $product_id, '$formula_name', '$target_qty', '$production_line', '$start_date', '$due_date', '$created_by')";
    if (mysqli_query($conn, $sql)) {
        header("Location: production_orders.php?status=added");
        exit;
    }
}

// 🚀 2. ลบใบสั่งผลิต
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    mysqli_query($conn, "DELETE FROM production_orders WHERE id = $id");
    header("Location: production_orders.php?status=deleted");
    exit;
}

// 🚀 3. ดึงรายชื่อ "สินค้า" ที่มีการตั้งสูตรไว้แล้ว (เพื่อเอาไปแสดงใน Dropdown 1)
$product_opts = "<option value=''>-- เลือกสินค้า --</option>";
$q_p = mysqli_query($conn, "SELECT DISTINCT p.id, p.p_name FROM products p JOIN formulas f ON p.id = f.product_id ORDER BY p.p_name ASC");
if($q_p) { 
    while($rp = mysqli_fetch_assoc($q_p)) { 
        $product_opts .= "<option value='{$rp['id']}'>📦 {$rp['p_name']}</option>"; 
    } 
}

include '../sidebar.php';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Top Feed Mills | ใบสั่งผลิต (Master Plan)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <style>
        .wrapper { font-family: 'Sarabun', sans-serif; animation: fadeIn 0.5s ease-in-out; }
        
        .container-stacked { display: flex; flex-direction: column; gap: 25px; width: 100%; }

        .card { 
            background: #ffffff; 
            padding: 30px; 
            border-radius: 16px; 
            box-shadow: 0 5px 20px rgba(0,0,0,0.05); 
            border: 1px solid #f0f0f0; 
            width: 100%;
            box-sizing: border-box;
        }

        h3 { color: #2c3e50; margin-top: 0; margin-bottom: 25px; font-weight: 600; border-bottom: 2px solid #f1f2f6; padding-bottom: 15px; display: flex; align-items: center; gap: 10px; }
        h3 i { color: #4e73df; }

        /* 🚀 จัดฟอร์มให้มีพื้นที่หายใจ (ตาม UI เดิมเป๊ะๆ) */
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
        .form-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px; }
        @media (max-width: 768px) { 
            .form-grid-2, .form-grid-3 { grid-template-columns: 1fr; } 
        }

        .form-group { text-align: left; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #4a5568; font-size: 0.95rem; }
        
        input, select { 
            width: 100%; 
            padding: 12px 15px; 
            border: 1.5px solid #e2e8f0; 
            border-radius: 10px; 
            font-family: 'Sarabun'; 
            font-size: 1rem; 
            transition: 0.3s; 
            box-sizing: border-box; 
        }
        input:focus, select:focus { border-color: #4e73df; outline: none; box-shadow: 0 0 0 3px rgba(78, 115, 223, 0.15); }

        /* Select2 Custom Styling เข้ากับ Input ปกติ */
        .select2-container--default .select2-selection--single {
            height: 46px; border: 1.5px solid #e2e8f0; border-radius: 10px; display: flex; align-items: center;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            padding-left: 15px; font-size: 1rem; font-family: 'Sarabun'; color: #444;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 44px; right: 10px; }
        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: #4e73df; box-shadow: 0 0 0 3px rgba(78, 115, 223, 0.15);
        }

        .btn-submit { 
            background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); 
            color: white; border: none; padding: 14px 20px; 
            border-radius: 10px; cursor: pointer; font-weight: bold; 
            font-size: 1.05rem; transition: 0.3s; width: 100%;
            display: flex; justify-content: center; align-items: center; gap: 8px; font-family: 'Sarabun';
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(78, 115, 223, 0.4); }

        /* ตาราง */
        .table-responsive { overflow-x: auto; width: 100%; border-radius: 10px; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 1000px; }
        th, td { padding: 15px; border-bottom: 1px solid #f0f0f0; text-align: left; vertical-align: middle; }
        th { background: #f8f9fa; color: #6c757d; font-weight: bold; font-size: 0.9rem; text-transform: uppercase; white-space: nowrap; }
        th:first-child { border-top-left-radius: 10px; }
        th:last-child { border-top-right-radius: 10px; }
        tr:hover { background-color: #f8f9fc; }

        .badge-status { padding: 5px 12px; border-radius: 50px; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
        .st-pending { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .st-progress { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }
        .st-done { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }

        .btn-del { color: #e74a3b; background: #fceceb; padding: 8px 12px; border-radius: 8px; font-size: 0.9rem; font-weight: bold; text-decoration: none; transition: 0.3s; display: inline-flex; align-items: center; gap: 5px; }
        .btn-del:hover { background: #e74a3b; color: white; }
        
        .progress-bg { width: 100%; background: #eaecf4; height: 6px; border-radius: 10px; margin-top: 8px; overflow: hidden; }
        .progress-bar { height: 100%; background: #1cc88a; transition: 0.5s; }

        .line-badge { background: #e3f2fd; color: #1976d2; padding: 5px 10px; border-radius: 6px; font-size: 13px; font-weight: bold; border: 1px solid #bbdefb; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

<div class="content-padding">
    <div class="wrapper">
        <div class="container-stacked">
            
            <div class="card">
                <h3><i class="fa-solid fa-file-circle-plus"></i> ออกใบสั่งผลิตใหม่ (New Production Order)</h3>
                <form method="POST">
                    
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>สายการผลิต (แผนกที่รับผิดชอบ)</label>
                            <select name="production_line" required>
                                <option value="">-- เลือกแผนก --</option>
                                <option value="แผนกผลิต 1">แผนกผลิต 1</option>
                                <option value="แผนกผลิต 2">แผนกผลิต 2</option>
                                <option value="ผลิตอาหารสัตว์น้ำ">ผลิตอาหารสัตว์น้ำ</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>1. สินค้าที่ต้องการผลิต (Product)</label>
                            <select name="product_id" id="product_select" class="select2" required>
                                <?php echo $product_opts; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group" style="background:#f8f9fc; padding: 10px; border-radius:10px; border:1px solid #e2e8f0;">
                            <label style="color:#4e73df;">2. เลือกสูตรการผลิต <span style="color:red;">*</span></label>
                            <select name="formula_name" id="formula_select" class="form-control" required disabled>
                                <option value="">-- กรุณาเลือกสินค้าก่อน --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>จำนวนเป้าหมาย (หน่วย: ตัน)</label>
                            <input type="number" step="0.01" name="target_qty" required placeholder="ระบุจำนวน...">
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>วันที่เริ่มผลิต</label>
                            <input type="date" name="start_date" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group">
                            <label>กำหนดเสร็จ (Deadline)</label>
                            <input type="date" name="due_date" required value="<?= date('Y-m-d', strtotime('+3 days')) ?>">
                        </div>
                    </div>

                    <button type="submit" name="add_order" class="btn-submit">
                        <i class="fa-solid fa-industry"></i> สร้างใบสั่งผลิตเข้าระบบ
                    </button>
                    
                </form>
            </div>

            <div class="card">
                <h3><i class="fa-solid fa-list-check"></i> แผนการผลิตทั้งหมด (Master Production Schedule)</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 12%;">เลขที่คำสั่ง</th>
                                <th style="width: 15%;">แผนกรับผิดชอบ</th>
                                <th style="width: 25%;">สินค้าและจำนวนเป้าหมาย</th>
                                <th style="width: 18%;">กำหนดการ</th>
                                <th style="width: 15%;">สถานะ / ความคืบหน้า</th>
                                <th style="width: 15%; text-align:right;">การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // 🚀 แก้ไขให้ใช้ LEFT JOIN เพื่อให้ข้อมูลเก่า (ที่ไม่มี product_id) ไม่หาย
                            $sql = "SELECT po.*, p.p_name FROM production_orders po LEFT JOIN products p ON po.product_id = p.id ORDER BY po.id DESC LIMIT 100";
                            $res = mysqli_query($conn, $sql);
                            if (mysqli_num_rows($res) > 0) {
                                while($row = mysqli_fetch_assoc($res)) {
                                    $status = $row['status'];
                                    $badge = ""; $progress = 0;
                                    if ($status == 'Pending') { $badge = "<span class='badge-status st-pending'><i class='fa-regular fa-clock'></i> รอผลิต</span>"; $progress = 0; }
                                    elseif ($status == 'In Progress' || $status == 'In_Progress') { $badge = "<span class='badge-status st-progress'><i class='fa-solid fa-gears'></i> กำลังผลิต</span>"; $progress = 50; }
                                    elseif ($status == 'Completed') { $badge = "<span class='badge-status st-done'><i class='fa-solid fa-check-double'></i> เสร็จสิ้น</span>"; $progress = 100; }
                                    
                                    // 🚀 แสดงข้อมูลให้รองรับทั้งระบบเก่าและระบบใหม่
                                    $disp_name = !empty($row['p_name']) ? htmlspecialchars($row['p_name']) : htmlspecialchars($row['formula_name']);
                                    $disp_formula = !empty($row['p_name']) ? "สูตร: " . htmlspecialchars($row['formula_name']) : "ข้อมูลเก่า (ก่อนอัปเกรดระบบ)";
                            ?>
                                <tr>
                                    <td>
                                        <strong style="color: #4e73df; font-size: 14px;"><?= $row['order_no'] ?></strong><br>
                                        <small style="color: #888;">สร้างโดย <?= $row['created_by'] ?></small>
                                    </td>
                                    <td><span class="line-badge"><?= $row['production_line'] ?></span></td>
                                    <td>
                                        <strong style="font-size: 1.05rem; color: #2c3e50;"><i class="fa-solid fa-box-open" style="color:#aaa;"></i> <?= $disp_name ?></strong><br>
                                        <small style="color: #888;"><?= $disp_formula ?></small><br>
                                        <span style="color:#e74a3b; font-weight:bold; font-size: 14px;">เป้า: <?= number_format($row['target_qty'], 2) ?> <?= $row['unit'] ?></span>
                                    </td>
                                    <td>
                                        <small style="color:#555; font-size: 13px;">เริ่ม: <?= date('d/m/Y', strtotime($row['start_date'])) ?></small><br>
                                        <small style="color:#e74a3b; font-weight:bold; font-size: 13px;">ดิว: <?= date('d/m/Y', strtotime($row['due_date'])) ?></small>
                                    </td>
                                    <td>
                                        <?= $badge ?>
                                        <div class="progress-bg"><div class="progress-bar" style="width: <?= $progress ?>%;"></div></div>
                                    </td>
                                    <td style="text-align:right;">
                                        <?php if($status == 'Pending'): ?>
                                            <a href="#" class="btn-del" onclick="confirmDelete(<?= $row['id'] ?>)"><i class="fa-solid fa-trash"></i> ลบ</a>
                                        <?php else: ?>
                                            <span style="color:#ccc; font-size:13px;"><i class="fa-solid fa-lock"></i> ลบไม่ได้</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php 
                                }
                            } else {
                                echo "<tr><td colspan='6' style='text-align:center; color:#999; padding:50px;'><i class='fa-solid fa-clipboard-list fa-3x'></i><br><br>ยังไม่มีข้อมูลในแผนการผลิต</td></tr>";
                            }
                            ?>
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
        // เปิดใช้งาน Select2
        $('.select2').select2({ width: '100%' });

        // 🚀 ระบบดึงสูตรอัตโนมัติเมื่อเลือกสินค้า
        $('#product_select').on('change', function() {
            let pid = $(this).val();
            let formulaSelect = $('#formula_select');
            
            if(!pid) {
                formulaSelect.html('<option value="">-- กรุณาเลือกสินค้าก่อน --</option>').prop('disabled', true);
                return;
            }
            
            formulaSelect.html('<option value="">กำลังโหลดสูตร...</option>').prop('disabled', true);
            
            $.ajax({
                url: 'production_orders.php',
                type: 'POST',
                dataType: 'json',
                data: { action: 'get_formulas', product_id: pid },
                success: function(data) {
                    if(data.length > 0) {
                        let options = '<option value="">-- เลือกสูตรที่ต้องการผลิต --</option>';
                        $.each(data, function(i, val) {
                            options += `<option value="${val}">${val}</option>`;
                        });
                        formulaSelect.html(options).prop('disabled', false);
                    } else {
                        formulaSelect.html('<option value="">-- ไม่พบการตั้งสูตรของสินค้านี้ --</option>');
                    }
                }
            });
        });

        // แจ้งเตือน SweetAlert2
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('status')) {
            const status = urlParams.get('status');
            if (status === 'added') {
                Swal.fire({ icon: 'success', title: 'สร้างใบสั่งผลิตสำเร็จ!', timer: 2000, showConfirmButton: false });
            } else if (status === 'deleted') {
                Swal.fire({ icon: 'success', title: 'ลบใบสั่งผลิตเรียบร้อย', timer: 2000, showConfirmButton: false });
            }
            window.history.replaceState(null, null, window.location.pathname);
        }
    });

    function confirmDelete(id) {
        Swal.fire({
            title: 'ยืนยันการลบ?',
            text: "คุณต้องการลบใบสั่งผลิตนี้ใช่หรือไม่?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e74a3b',
            cancelButtonColor: '#858796',
            confirmButtonText: 'ใช่, ลบเลย!',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) window.location.href = '?delete=' + id;
        });
    }
</script>

</body>
</html>