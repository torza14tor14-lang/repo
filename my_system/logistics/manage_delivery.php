<?php
session_start();
include '../db.php';

// 1. ตรวจสอบการล็อกอิน
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
$fullname = $_SESSION['fullname'] ?? $_SESSION['username'];

// สิทธิ์ (ADMIN, MANAGER, ฝ่ายขาย, ฝ่ายจัดส่ง)
$allowed_depts = ['ฝ่ายขาย', 'แผนกจัดส่ง', 'ฝ่ายโลจิสติกส์'];
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $allowed_depts)) { 
    echo "<script>alert('เฉพาะฝ่ายจัดส่งหรือฝ่ายขายเท่านั้น'); window.location='../index.php';</script>"; exit(); 
}

// 🚀 [Auto-Update & Create Tables] 
// 1. เพิ่มคอลัมน์สถานะจัดส่งใน sales_orders
$check_col = mysqli_query($conn, "SHOW COLUMNS FROM `sales_orders` LIKE 'delivery_status'");
if(mysqli_num_rows($check_col) == 0){
    mysqli_query($conn, "ALTER TABLE `sales_orders` ADD `delivery_status` VARCHAR(50) NOT NULL DEFAULT 'Pending' AFTER `payment_status`");
}

// 2. สร้างตารางจัดคิวรถ (Delivery Orders)
$check_table = mysqli_query($conn, "SHOW TABLES LIKE 'delivery_orders'");
if (mysqli_num_rows($check_table) == 0) {
    mysqli_query($conn, "CREATE TABLE delivery_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        do_no VARCHAR(50) NOT NULL,
        sale_id INT NOT NULL,
        driver_name VARCHAR(150) NOT NULL,
        license_plate VARCHAR(50) NOT NULL,
        delivery_date DATE NOT NULL,
        status VARCHAR(50) DEFAULT 'Scheduled',
        remark TEXT,
        created_by VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

// 🚀 Action 1: จัดคิวรถ (Assign Delivery)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_delivery'])) {
    $sale_id = (int)$_POST['sale_id'];
    $driver = mysqli_real_escape_string($conn, $_POST['driver_name']);
    $plate = mysqli_real_escape_string($conn, $_POST['license_plate']);
    $d_date = $_POST['delivery_date'];
    
    // สร้างเลขที่ใบส่งของ (DO-YYYYMM-001)
    $prefix = "DO-" . date("Ym");
    $q_last = mysqli_query($conn, "SELECT do_no FROM delivery_orders WHERE do_no LIKE '$prefix%' ORDER BY id DESC LIMIT 1");
    $run_no = 1;
    if ($row_last = mysqli_fetch_assoc($q_last)) {
        $run_no = intval(substr($row_last['do_no'], -3)) + 1;
    }
    $do_no = $prefix . "-" . str_pad($run_no, 3, "0", STR_PAD_LEFT);

    // บันทึกตาราง delivery_orders
    mysqli_query($conn, "INSERT INTO delivery_orders (do_no, sale_id, driver_name, license_plate, delivery_date, created_by) 
                         VALUES ('$do_no', $sale_id, '$driver', '$plate', '$d_date', '$fullname')");
    
    // อัปเดตสถานะใน sales_orders
    mysqli_query($conn, "UPDATE sales_orders SET delivery_status = 'Scheduled' WHERE sale_id = $sale_id");

    // แจ้งเตือน LINE คิวรถ
    $q_sale = mysqli_query($conn, "SELECT customer_name FROM sales_orders WHERE sale_id = $sale_id");
    $cust_name = mysqli_fetch_assoc($q_sale)['customer_name'] ?? '-';
    
    include_once '../line_api.php';
    $msg = "🚚 [ฝ่ายจัดส่ง] จัดคิวรถส่งของเรียบร้อย\n\n";
    $msg .= "🏢 ลูกค้า: $cust_name\n";
    $msg .= "อ้างอิงบิลขาย: INV-" . str_pad($sale_id, 5, "0", STR_PAD_LEFT) . "\n";
    $msg .= "👤 คนขับ: $driver ($plate)\n";
    $msg .= "📅 วันที่ส่ง: " . date('d/m/Y', strtotime($d_date)) . "\n\n";
    $msg .= "👉 โปรดตรวจสอบความถูกต้องก่อนรถออกจากโรงงานครับ";
    if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

    if(function_exists('log_event')) { log_event($conn, 'INSERT', 'delivery_orders', "จัดคิวรถให้บิลขาย INV-$sale_id (คนขับ: $driver)"); }

    header("Location: manage_delivery.php?status=assigned"); exit;
}

// 🚀 Action 2: อัปเดตสถานะว่า "ส่งถึงลูกค้าแล้ว"
if (isset($_POST['update_status'])) {
    $do_id = (int)$_POST['do_id'];
    $sale_id = (int)$_POST['update_sale_id'];
    $new_status = mysqli_real_escape_string($conn, $_POST['new_status']);
    $remark = mysqli_real_escape_string($conn, $_POST['remark']);

    mysqli_query($conn, "UPDATE delivery_orders SET status = '$new_status', remark = '$remark' WHERE id = $do_id");
    
    // ถ้าส่งสำเร็จให้อัปเดตบิลแม่ด้วย
    if ($new_status == 'Delivered') {
        mysqli_query($conn, "UPDATE sales_orders SET delivery_status = 'Delivered' WHERE sale_id = $sale_id");
    } elseif ($new_status == 'Failed') {
        mysqli_query($conn, "UPDATE sales_orders SET delivery_status = 'Failed' WHERE sale_id = $sale_id");
    }

    // แจ้งเตือน LINE (เฉพาะส่งสำเร็จ/ล้มเหลว)
    if ($new_status == 'Delivered' || $new_status == 'Failed') {
        $q_sale = mysqli_query($conn, "SELECT customer_name FROM sales_orders WHERE sale_id = $sale_id");
        $cust_name = mysqli_fetch_assoc($q_sale)['customer_name'] ?? '-';
        
        include_once '../line_api.php';
        $icon = ($new_status == 'Delivered') ? "✅" : "❌";
        $status_th = ($new_status == 'Delivered') ? "ส่งของสำเร็จ" : "ส่งของล้มเหลว/ตีกลับ";
        $msg = "$icon [อัปเดตสถานะจัดส่ง] $status_th\n\n";
        $msg .= "🏢 ลูกค้า: $cust_name\n";
        $msg .= "อ้างอิงบิล: INV-" . str_pad($sale_id, 5, "0", STR_PAD_LEFT) . "\n";
        $msg .= "💬 หมายเหตุ: $remark\n";
        $msg .= "ผู้บันทึก: $fullname";
        if(function_exists('sendLineMessage')) { sendLineMessage($msg); }
    }

    if(function_exists('log_event')) { log_event($conn, 'UPDATE', 'delivery_orders', "อัปเดตสถานะขนส่ง DO ID-$do_id เป็น $new_status"); }
    header("Location: manage_delivery.php?status=updated"); exit;
}

// 🚀 Option 1: บิลขายที่ "รอจัดส่ง" (Pending)
$sale_opts = "<option value=''>-- พิมพ์ค้นหาเลขบิล หรือ ชื่อลูกค้า --</option>";
$q_pending_sales = mysqli_query($conn, "SELECT sale_id, customer_name, sale_date FROM sales_orders WHERE delivery_status = 'Pending' ORDER BY sale_id DESC");
if($q_pending_sales){
    while($s = mysqli_fetch_assoc($q_pending_sales)) {
        $inv_no = "INV-" . str_pad($s['sale_id'], 5, "0", STR_PAD_LEFT);
        $sale_opts .= "<option value='{$s['sale_id']}'>📄 $inv_no | 👤 {$s['customer_name']} (" . date('d/m/Y', strtotime($s['sale_date'])) . ")</option>";
    }
}

include '../sidebar.php';
?>

<title>จัดการคิวรถและจัดส่ง | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    :root { 
        --primary-light: #e0f2fe;
        --success: #10b981; --warning: #f59e0b; --danger: #ef4444;
        --bg-color: #f8fafc; --card-bg: #ffffff; --border-color: #e2e8f0;
        --text-main: #1e293b; --text-muted: #64748b;
    }
    body { font-family: 'Sarabun', sans-serif; background-color: var(--bg-color); }
    .content-padding { padding: 24px; width: 100%; box-sizing: border-box; max-width: 1400px; margin: auto;}
    
    .card-logistic { background: var(--card-bg); padding: 35px; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 30px; width: 100%; }
    
    .form-grid-3 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 24px; margin-bottom: 24px; }
    .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
    @media (max-width: 992px) { .form-grid-3, .form-grid-2 { grid-template-columns: 1fr; } }

    .form-control { width: 100%; padding: 12px 16px; border: 1.5px solid var(--border-color); border-radius: 10px; font-family: 'Sarabun'; font-size: 15px; color: var(--text-main); font-weight: 500; transition: 0.2s;}
    .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.15); }
    .form-label { display: block; font-size: 14.5px; font-weight: 700; color: var(--text-main); margin-bottom: 8px; }

    .btn-submit { background: var(--primary); color: white; border: none; padding: 16px; border-radius: 12px; font-weight: 700; cursor: pointer; width: 100%; font-size: 16px; transition: 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 10px rgba(14, 165, 233, 0.2);}
    .btn-submit:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 6px 15px rgba(14, 165, 233, 0.3); }

    /* Select2 Custom Styling */
    .select2-container--default .select2-selection--single { height: 48px !important; border: 1.5px solid var(--border-color) !important; border-radius: 10px !important; display: flex !important; align-items: center; background-color: #fff; }
    .select2-container--default.select2-container--open .select2-selection--single, .select2-container--default.select2-container--focus .select2-selection--single { border-color: var(--primary) !important; box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.15) !important; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { color: var(--text-main) !important; font-size: 15px; font-weight: 600; padding-left: 16px !important; line-height: normal !important; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 46px !important; right: 12px !important; }

    /* ตาราง */
    .table-responsive { width: 100%; overflow-x: auto; border-radius: 12px; border: 1px solid var(--border-color);}
    table.display-table { width: 100%; border-collapse: collapse; min-width: 900px;}
    table.display-table th { background: #f8fafc; color: var(--text-muted); font-size: 13px; text-transform: uppercase; font-weight: 700; padding: 16px 20px; border-bottom: 2px solid var(--border-color); text-align: left; }
    table.display-table td { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 15px; font-weight: 500; color: var(--text-main);}
    table.display-table tr:hover td { background-color: #f8fafc; }

    .badge { padding: 6px 14px; border-radius: 50px; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
    .bg-scheduled { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
    .bg-transit { background: var(--primary-light); color: var(--primary-hover); border: 1px solid #bae6fd; }
    .bg-delivered { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .bg-failed { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

    .btn-update { background: var(--bg-color); border: 1px solid var(--border-color); color: var(--text-main); padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; transition: 0.2s; display:inline-flex; align-items:center; gap:5px;}
    .btn-update:hover { background: var(--primary); color: white; border-color: var(--primary); }

    /* Modal */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    .modal-content { background: white; border-radius: 20px; width: 90%; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); padding: 30px; animation: pop 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
    @keyframes pop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
</style>

<div class="content-padding">
    
    <div class="card-logistic" style="border-top: 5px solid var(--primary);">
        <h3 style="margin-top:0; color:var(--text-main); font-size:22px; font-weight:800; margin-bottom: 25px;">
            <i class="fa-solid fa-truck-fast" style="color:var(--primary); margin-right:8px;"></i> จัดคิวรถบรรทุก / ขนส่ง (Dispatch)
        </h3>
        
        <form method="POST" onsubmit="return confirm('ยืนยันการจัดคิวรถให้บิลนี้ใช่หรือไม่?');">
            <div class="form-grid-2">
                <div style="grid-column: 1 / -1;">
                    <label class="form-label">1. เลือกบิลขาย (Sales Order) ที่รอจัดส่ง <span style="color:red;">*</span></label>
                    <select name="sale_id" class="select2 form-control" required>
                        <?php echo $sale_opts; ?>
                    </select>
                </div>
            </div>

            <div class="form-grid-3">
                <div>
                    <label class="form-label">2. ชื่อพนักงานขับรถ <span style="color:red;">*</span></label>
                    <input type="text" name="driver_name" class="form-control" placeholder="เช่น นายสมชาย ขยันขับ" required>
                </div>
                <div>
                    <label class="form-label">3. ทะเบียนรถ <span style="color:red;">*</span></label>
                    <input type="text" name="license_plate" class="form-control" placeholder="เช่น บม 1234 กทม." required>
                </div>
                <div>
                    <label class="form-label">4. กำหนดวันไปส่ง <span style="color:red;">*</span></label>
                    <input type="date" name="delivery_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <button type="submit" name="assign_delivery" class="btn-submit">
                <i class="fa-solid fa-calendar-check"></i> บันทึกจัดคิวรถ (สร้างใบ DO)
            </button>
        </form>
    </div>

    <div class="card-logistic">
        <h3 style="margin-top:0; margin-bottom:25px; color:var(--text-main); font-size:20px; font-weight:800;">
            <i class="fa-solid fa-clipboard-check" style="color:var(--primary); margin-right:8px;"></i> สถานะการจัดส่ง (Delivery Tracking)
        </h3>
        
        <div class="table-responsive">
            <table class="display-table">
                <thead>
                    <tr>
                        <th width="15%">ใบส่งของ (DO)</th>
                        <th width="25%">ลูกค้า / บิลขาย</th>
                        <th width="25%">คนขับ / ทะเบียนรถ</th>
                        <th width="15%">สถานะ</th>
                        <th width="20%" style="text-align:right;">อัปเดตสถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql_del = "SELECT d.*, s.customer_name 
                                FROM delivery_orders d 
                                JOIN sales_orders s ON d.sale_id = s.sale_id 
                                ORDER BY d.id DESC LIMIT 50";
                    $res_del = mysqli_query($conn, $sql_del);
                    
                    if(mysqli_num_rows($res_del) > 0) {
                        while($row = mysqli_fetch_assoc($res_del)) {
                            $inv_no = "INV-" . str_pad($row['sale_id'], 5, "0", STR_PAD_LEFT);
                            $badge = "";
                            if($row['status'] == 'Scheduled') $badge = "<span class='badge bg-scheduled'><i class='fa-regular fa-clock'></i> รอขึ้นของ</span>";
                            elseif($row['status'] == 'In_Transit') $badge = "<span class='badge bg-transit'><i class='fa-solid fa-truck-moving'></i> กำลังไปส่ง</span>";
                            elseif($row['status'] == 'Delivered') $badge = "<span class='badge bg-delivered'><i class='fa-solid fa-check-double'></i> ส่งสำเร็จ</span>";
                            else $badge = "<span class='badge bg-failed'><i class='fa-solid fa-triangle-exclamation'></i> มีปัญหา/ตีกลับ</span>";
                    ?>
                        <tr>
                            <td>
                                <strong style="color: var(--primary); font-size:15px;"><?= $row['do_no'] ?></strong><br>
                                <small style="color: var(--text-muted);">ส่ง: <?= date('d/m/Y', strtotime($row['delivery_date'])) ?></small>
                            </td>
                            <td>
                                <strong style="color: var(--text-main); font-size:15px;"><i class="fa-regular fa-building" style="color:#94a3b8; margin-right:5px;"></i><?= htmlspecialchars($row['customer_name']) ?></strong><br>
                                <span style="font-size:13px; color:var(--text-muted);">อ้างอิง: <?= $inv_no ?></span>
                            </td>
                            <td>
                                <span><i class="fa-solid fa-user-check" style="color:#94a3b8; margin-right:5px;"></i><?= htmlspecialchars($row['driver_name']) ?></span><br>
                                <span style="font-size:13px; color:var(--text-muted);"><i class="fa-solid fa-car-side" style="color:#94a3b8; margin-right:5px;"></i>ป้าย: <?= htmlspecialchars($row['license_plate']) ?></span>
                            </td>
                            <td>
                                <?= $badge ?><br>
                                <small style="color: var(--danger);"><?= htmlspecialchars($row['remark']) ?></small>
                            </td>
                            <td align="right">
                                <?php if($row['status'] != 'Delivered' && $row['status'] != 'Failed'): ?>
                                    <button class="btn-update" onclick="openUpdateModal(<?= $row['id'] ?>, <?= $row['sale_id'] ?>, '<?= $row['do_no'] ?>', '<?= $row['status'] ?>', '<?= htmlspecialchars($row['remark']) ?>')">
                                        <i class="fa-solid fa-pen-to-square"></i> อัปเดตงาน
                                    </button>
                                <?php else: ?>
                                    <span style="color:#cbd5e1; font-size:13px;"><i class="fa-solid fa-lock"></i> ปิดงานแล้ว</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php 
                        }
                    } else { 
                        echo "<tr><td colspan='5' style='text-align:center; padding:50px; color:var(--text-muted);'><i class='fa-solid fa-box-open fa-3x' style='margin-bottom:15px; color:#e2e8f0;'></i><br>ยังไม่มีคิวจัดส่งในระบบ</td></tr>";
                    } 
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="updateModal" class="modal-overlay">
    <div class="modal-content">
        <h3 style="margin-top:0; color:var(--primary); font-size:20px;"><i class="fa-solid fa-location-dot"></i> อัปเดตสถานะขนส่ง</h3>
        <p style="color:var(--text-muted); font-size:14px; margin-bottom:20px;">เลขที่ใบส่งของ: <strong id="mod_do_no" style="color:var(--text-main);"></strong></p>
        
        <form method="POST">
            <input type="hidden" name="do_id" id="mod_do_id">
            <input type="hidden" name="update_sale_id" id="mod_sale_id">
            
            <div class="form-group">
                <label class="form-label">สถานะปัจจุบัน</label>
                <select name="new_status" id="mod_status" class="form-control" required>
                    <option value="Scheduled">รอขึ้นของ / รอออกรถ (Scheduled)</option>
                    <option value="In_Transit">กำลังเดินทางไปส่ง (In Transit)</option>
                    <option value="Delivered" style="color:#10b981; font-weight:bold;">✅ ลูกค้าเซ็นรับของแล้ว (Delivered)</option>
                    <option value="Failed" style="color:#ef4444; font-weight:bold;">❌ มีปัญหา / ส่งไม่ได้ / ตีกลับ (Failed)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">หมายเหตุ (ถ้ามี)</label>
                <textarea name="remark" id="mod_remark" class="form-control" rows="3" placeholder="ระบุผู้เซ็นรับของ หรือ สาเหตุที่ส่งไม่ได้..."></textarea>
            </div>
            
            <div style="display: flex; gap: 15px; margin-top: 25px;">
                <button type="button" class="btn-update" style="width:50%; justify-content:center; padding:14px;" onclick="closeModal()">ยกเลิก</button>
                <button type="submit" name="update_status" class="btn-submit" style="width:50%; margin:0; padding:14px;">บันทึกสถานะ</button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        $('.select2').select2({ width: '100%', language: { noResults: function() { return "ไม่พบบิลที่รอจัดส่ง"; } } });
        
        const urlParams = new URLSearchParams(window.location.search);
        if(urlParams.get('status')==='assigned') Swal.fire({ icon:'success', title:'จัดคิวรถสำเร็จ!', text:'ระบบส่งแจ้งเตือน LINE เรียบร้อย', timer:2500, showConfirmButton:false }).then(()=>window.history.replaceState(null,null,window.location.pathname));
        if(urlParams.get('status')==='updated') Swal.fire({ icon:'success', title:'อัปเดตสถานะขนส่งแล้ว!', timer:1500, showConfirmButton:false }).then(()=>window.history.replaceState(null,null,window.location.pathname));
    });

    function openUpdateModal(do_id, sale_id, do_no, status, remark) {
        $('#mod_do_id').val(do_id);
        $('#mod_sale_id').val(sale_id);
        $('#mod_do_no').text(do_no);
        $('#mod_status').val(status);
        $('#mod_remark').val(remark);
        $('#updateModal').css('display', 'flex');
    }

    function closeModal() {
        $('#updateModal').css('display', 'none');
    }
</script>
</body>
</html>