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
$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'ช่างซ่อมบำรุง';

// เช็คสิทธิ์ (ADMIN, MANAGER, แผนกซ่อมบำรุง)
$group_mnt = ['แผนกซ่อมบำรุง 1', 'แผนกซ่อมบำรุง 2', 'แผนกไฟฟ้า 1', 'แผนกไฟฟ้า 2', 'แผนก P&M - 1', 'แผนก P&M - 2'];
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $group_mnt)) { 
    echo "<script>alert('เฉพาะเจ้าหน้าที่ฝ่ายซ่อมบำรุงและผู้บริหารเท่านั้น'); window.location='../index.php';</script>"; exit(); 
}

// 🚀 [Auto-Create Table] อัปเกรดตารางให้รองรับการเชื่อมโยงกับใบแจ้งซ่อม (maintenance_id)
$check_table = mysqli_query($conn, "SHOW TABLES LIKE 'maintenance_parts_issue'");
if ($check_table && mysqli_num_rows($check_table) == 0) {
    mysqli_query($conn, "CREATE TABLE maintenance_parts_issue (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        product_id INT(11) NOT NULL,
        qty DECIMAL(10,2) NOT NULL,
        maintenance_id INT(11) NULL DEFAULT NULL,
        machine_name VARCHAR(255) NOT NULL,
        reason TEXT,
        withdrawn_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
} else {
    $check_col = mysqli_query($conn, "SHOW COLUMNS FROM `maintenance_parts_issue` LIKE 'maintenance_id'");
    if (mysqli_num_rows($check_col) == 0) {
        mysqli_query($conn, "ALTER TABLE `maintenance_parts_issue` ADD `maintenance_id` INT(11) NULL DEFAULT NULL AFTER `qty`");
    }
}

// 🚀 เตรียมข้อมูลรายการ "ใบแจ้งซ่อมที่รอการแก้ไข"
$task_options = "<option value=''>-- ซ่อมทั่วไป (ไม่ได้อ้างอิงใบแจ้งซ่อม) --</option>";
// 🐛 แก้ไข: เปลี่ยน problem_detail กลับเป็น issue_description ให้ตรงกับ Database ของคุณ
$q_tasks = mysqli_query($conn, "SELECT id, machine_name, issue_description, status FROM maintenance_requests WHERE status != 'Completed' ORDER BY id DESC");
if ($q_tasks) {
    while($t = mysqli_fetch_assoc($q_tasks)) {
        $icon = ($t['status'] == 'In Progress' || $t['status'] == 'In_Progress') ? '🔧 กำลังซ่อม' : '⏳ รอรับงาน';
        $machine_clean = htmlspecialchars($t['machine_name'], ENT_QUOTES);
        $reason_clean = htmlspecialchars($t['issue_description'], ENT_QUOTES);
        $task_options .= "<option value='{$t['id']}' data-machine='{$machine_clean}' data-reason='{$reason_clean}'>[Ticket #{$t['id']}] {$icon} : {$machine_clean}</option>";
    }
}

// 🚀 เตรียม Options ของอะไหล่ทั้งหมด (เพื่อให้ JS เอาไปเพิ่มแถว)
$product_options = "<option value=''>-- เลือกอะไหล่ --</option>";
$q_items = mysqli_query($conn, "SELECT id, p_name, p_code, p_qty, p_unit FROM products WHERE p_type IN ('SPARE', 'SUPPLY') ORDER BY p_name ASC");
if ($q_items) {
    while($row = mysqli_fetch_assoc($q_items)) {
        $stock_text = number_format($row['p_qty'], 2) . " " . $row['p_unit'];
        $product_options .= "<option value='{$row['id']}'>[{$row['p_code']}] {$row['p_name']} (คลัง: {$stock_text})</option>";
    }
}

// 🚀 จัดการเมื่อช่างกด "ยืนยันการเบิกอะไหล่" (รองรับหลายรายการพร้อมกัน)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['issue_part'])) {
    
    $maintenance_id = !empty($_POST['maintenance_id']) ? (int)$_POST['maintenance_id'] : 'NULL';
    $machine_name = mysqli_real_escape_string($conn, $_POST['machine_name']);
    $reason = mysqli_real_escape_string($conn, $_POST['reason']);
    
    $can_issue = true;
    $error_msg = "";
    $items_to_process = [];

    // 1. เช็คสต็อกของทุกรายการที่ช่างกรอกเข้ามาก่อนว่า "พอเบิกไหม?"
    if (isset($_POST['items']) && is_array($_POST['items'])) {
        foreach ($_POST['items'] as $item) {
            $p_id = (int)$item['product_id'];
            $qty = (float)$item['qty'];

            if ($p_id > 0 && $qty > 0) {
                $q_stock = mysqli_query($conn, "SELECT p_name, p_qty FROM products WHERE id = $p_id");
                $stock = mysqli_fetch_assoc($q_stock);
                
                if ($stock['p_qty'] < $qty) {
                    $can_issue = false;
                    $error_msg .= "❌ อะไหล่ [{$stock['p_name']}] มีไม่พอ (เหลือแค่ {$stock['p_qty']})\\n";
                } else {
                    $items_to_process[] = ['id' => $p_id, 'qty' => $qty, 'name' => $stock['p_name']];
                }
            }
        }
    }

    if (count($items_to_process) == 0) {
        $can_issue = false;
        $error_msg = "โปรดเลือกอะไหล่อย่างน้อย 1 รายการ";
    }

    // 2. ถ้าสต็อกพอทุกชิ้น ให้ดำเนินการตัดสต็อกรวดเดียว
    if ($can_issue) {
        $part_list_for_line = ""; // เอาไว้เก็บรายชื่ออะไหล่ส่ง LINE
        
        foreach ($items_to_process as $it) {
            $p_id = $it['id'];
            $qty = $it['qty'];
            
            // ตัดสต็อกในตาราง products
            mysqli_query($conn, "UPDATE products SET p_qty = p_qty - $qty WHERE id = $p_id");
            
            // บันทึก Stock Log
            $ticket_ref = ($maintenance_id !== 'NULL') ? "อ้างอิง Ticket #$maintenance_id" : "ซ่อมทั่วไป";
            $ref_log = "เบิกซ่อม: $machine_name ($ticket_ref)";
            mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, reference, action_by) 
                                 VALUES ($p_id, 'OUT', $qty, '$ref_log', '$fullname')");
            
            // บันทึกประวัติเบิก
            mysqli_query($conn, "INSERT INTO maintenance_parts_issue (product_id, qty, maintenance_id, machine_name, reason, withdrawn_by) 
                                 VALUES ($p_id, $qty, $maintenance_id, '$machine_name', '$reason', '$fullname')");
                                 
            $part_list_for_line .= "➖ {$it['name']} จำนวน $qty\n";
        }
        
        // 🚀 บันทึก Log ศูนย์กลาง
        if(function_exists('log_event')) { 
            log_event($conn, 'UPDATE', 'products', "ช่าง $fullname เบิกอะไหล่ " . count($items_to_process) . " รายการ ไปซ่อม $machine_name"); 
        }

        // แจ้งเตือน LINE
        include_once '../line_api.php';
        $msg = "🛠️ [ฝ่ายซ่อมบำรุง] แจ้งเบิกอะไหล่/อุปกรณ์ (หลายรายการ)\n\n";
        $msg .= "⚙️ เครื่องจักรที่ซ่อม: " . $machine_name . "\n";
        if ($maintenance_id !== 'NULL') { $msg .= "🔖 อ้างอิงใบแจ้งซ่อม: Ticket #" . $maintenance_id . "\n"; }
        $msg .= "💬 สาเหตุ: " . $reason . "\n\n";
        $msg .= "📦 รายการที่เบิกตัดคลัง:\n" . $part_list_for_line . "\n";
        $msg .= "ผู้ทำรายการ: " . $fullname;
        if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

        header("Location: issue_parts.php?status=success"); exit;
        
    } else {
        header("Location: issue_parts.php?status=error&msg=" . urlencode($error_msg)); exit;
    }
}

include '../sidebar.php';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Top Feed Mills | เบิกอะไหล่ซ่อมบำรุง</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .wrapper { font-family: 'Sarabun', sans-serif; animation: fadeIn 0.5s ease-in-out; }
        .container-stacked { display: flex; flex-direction: column; gap: 25px; width: 100%; }

        .card { 
            background: #ffffff; padding: 25px 30px; border-radius: 16px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.03); border: 1px solid #f0f0f0; width: 100%; box-sizing: border-box;
        }

        h3 { color: #2c3e50; margin-top: 0; margin-bottom: 20px; font-weight: 600; border-bottom: 2px solid #f1f2f6; padding-bottom: 12px; display: flex; align-items: center; gap: 10px; }
        
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
        @media (max-width: 768px) { .form-grid-2 { grid-template-columns: 1fr; } }

        .form-group { text-align: left; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #4a5568; font-size: 0.95rem; }
        input, select, textarea { width: 100%; padding: 12px 15px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-family: 'Sarabun'; font-size: 1rem; transition: 0.3s; box-sizing: border-box; }
        input:focus, select:focus, textarea:focus { border-color: #4e73df; outline: none; box-shadow: 0 0 0 3px rgba(78, 115, 223, 0.15); }

        .select2-container--default .select2-selection--single { height: 46px; border: 1.5px solid #e2e8f0; border-radius: 10px; display: flex; align-items: center; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { padding-left: 15px; font-size: 1rem; color: #444; font-family: 'Sarabun'; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 44px; right: 10px; }

        .btn-submit { background: linear-gradient(135deg, #e74a3b 0%, #c0392b 100%); color: white; border: none; padding: 14px 20px; border-radius: 10px; cursor: pointer; font-weight: bold; font-size: 1.05rem; transition: 0.3s; width: 100%; display: flex; justify-content: center; align-items: center; gap: 8px; font-family: 'Sarabun'; margin-top: 10px; }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(231, 74, 59, 0.4); }

        /* Style สำหรับตารางเบิกของหลายชิ้น */
        .parts-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        .parts-table th { background: #f8f9fc; padding: 12px; color: #555; text-align: left; border-bottom: 2px solid #e2e8f0; font-size: 14px; }
        .parts-table td { padding: 10px; border-bottom: 1px solid #f0f0f0; }
        .btn-add-row { background: #e3f2fd; color: #4e73df; border: 1px dashed #4e73df; padding: 10px; width: 100%; border-radius: 8px; font-weight: bold; cursor: pointer; font-family: 'Sarabun'; }
        .btn-del-row { background: #fceceb; color: #e74a3b; border: none; padding: 10px 15px; border-radius: 8px; cursor: pointer; }

        /* Table ประวัติ */
        .table-responsive { overflow-x: auto; width: 100%; border-radius: 10px; -webkit-overflow-scrolling: touch; }
        table.history-table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 900px; }
        table.history-table th, table.history-table td { padding: 15px; border-bottom: 1px solid #f0f0f0; text-align: left; vertical-align: middle; }
        table.history-table th { background: #f8f9fa; color: #6c757d; font-weight: bold; font-size: 0.85rem; text-transform: uppercase; white-space: nowrap; }
        table.history-table tr:hover { background-color: #f8f9fc; }

        .out-badge { background: #fceceb; color: #e74a3b; padding: 4px 10px; border-radius: 6px; font-size: 13px; font-weight: bold; border: 1px solid #f5c6cb; }
        .ticket-badge { background: #e3f2fd; color: #4e73df; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: bold; border: 1px solid #bbdefb; margin-bottom: 5px; display: inline-block;}

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top:0; margin-bottom: 25px;"><i class="fa-solid fa-toolbox" style="color: #e74a3b;"></i> ระบบเบิกอะไหล่และอุปกรณ์ซ่อมบำรุง</h2>

    <div class="wrapper">
        <div class="container-stacked">

            <div class="card" style="border-top: 4px solid #e74a3b;">
                <h4 style="margin-top:0; color: #e74a3b;"><i class="fa-solid fa-cart-flatbed"></i> แบบฟอร์มขอเบิกอะไหล่ (ตัดสต็อกคลัง)</h4>
                
                <form method="POST" id="issueForm" onsubmit="return confirm('ยืนยันการทำรายการ?\nระบบจะตัดสต็อกคลังทันที ไม่สามารถย้อนกลับได้');">
                    
                    <div class="form-grid-2" style="background:#f8f9fc; padding: 15px; border-radius: 10px; border: 1px dashed #d1d3e2;">
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label style="color:#4e73df;"><i class="fa-solid fa-link"></i> 1. อ้างอิงงานซ่อม (เชื่อมโยงกับใบแจ้งซ่อม)</label>
                            <select name="maintenance_id" id="task_select" class="select2" onchange="autoFillTaskInfo()">
                                <?php echo $task_options; ?>
                            </select>
                            <small style="color: #888; display: block; margin-top: 5px;">* หากเลือกใบแจ้งซ่อม ระบบจะกรอกชื่อเครื่องจักรและอาการให้อัตโนมัติ</small>
                        </div>
                        <div class="form-group">
                            <label>นำไปซ่อมเครื่องจักร (Machine Name) <span style="color:red;">*</span></label>
                            <input type="text" name="machine_name" id="machine_name" required placeholder="เช่น เครื่องอัดเม็ดเบอร์ 2...">
                        </div>
                        <div class="form-group">
                            <label>สาเหตุ / อาการเสียที่ต้องเปลี่ยนอะไหล่ <span style="color:red;">*</span></label>
                            <input type="text" name="reason" id="reason" required placeholder="เช่น ลูกปืนแตก, เปลี่ยนตามวงรอบ...">
                        </div>
                    </div>

                    <div style="margin-top: 25px;">
                        <label style="font-weight:bold; color:#4a5568; font-size:16px;">2. ระบุอะไหล่ที่ต้องการเบิกใช้งาน (เลือกได้หลายชิ้น)</label>
                        <div class="table-responsive" style="border:none;">
                            <table class="parts-table" id="partsTable">
                                <thead>
                                    <tr>
                                        <th style="width: 70%;">ชื่ออะไหล่ / อุปกรณ์</th>
                                        <th style="width: 20%;">จำนวนที่เบิก</th>
                                        <th style="width: 10%; text-align:center;">ลบ</th>
                                    </tr>
                                </thead>
                                <tbody id="partsBody">
                                    <tr>
                                        <td>
                                            <select name="items[0][product_id]" class="form-control select2-item" required>
                                                <?php echo $product_options; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" name="items[0][qty]" class="form-control" placeholder="0" required>
                                        </td>
                                        <td style="text-align:center;">-</td>
                                    </tr>
                                </tbody>
                            </table>
                            <button type="button" class="btn-add-row" onclick="addRow()"><i class="fa-solid fa-plus"></i> เพิ่มรายการอะไหล่ในบิลนี้</button>
                        </div>
                    </div>

                    <button type="submit" name="issue_part" class="btn-submit" style="margin-top: 30px;">
                        <i class="fa-solid fa-right-from-bracket"></i> ยืนยันการตัดสต็อก และ เบิกใช้งาน
                    </button>
                </form>
            </div>

            <div class="card" style="border-top: 4px solid #858796;">
                <h3 style="color:#555;"><i class="fa-solid fa-clock-rotate-left"></i> ประวัติการเบิกอะไหล่ล่าสุด</h3>
                <div class="table-responsive">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th style="width: 15%;">วัน/เวลา ที่เบิก</th>
                                <th style="width: 30%;">รายการอะไหล่ที่เบิก</th>
                                <th style="width: 15%;">จำนวนที่เบิกออก</th>
                                <th style="width: 25%;">นำไปซ่อมเครื่องจักร (อ้างอิง)</th>
                                <th style="width: 15%;">ช่างผู้เบิก</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if($check_table && mysqli_num_rows($check_table) > 0) {
                                $sql_history = "SELECT mi.*, p.p_name, p.p_unit 
                                                FROM maintenance_parts_issue mi
                                                JOIN products p ON mi.product_id = p.id
                                                ORDER BY mi.id DESC LIMIT 50";
                                $res_history = mysqli_query($conn, $sql_history);
                                
                                if ($res_history && mysqli_num_rows($res_history) > 0) {
                                    while($row = mysqli_fetch_assoc($res_history)) {
                                        $unit = $row['p_unit'] ?: 'หน่วย';
                            ?>
                                <tr>
                                    <td><small style="color:#555;"><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></small></td>
                                    <td><strong style="color: #2c3e50; font-size:15px;"><?= htmlspecialchars($row['p_name']) ?></strong></td>
                                    <td><span class="out-badge">- <?= number_format($row['qty'], 2) ?> <?= htmlspecialchars($unit) ?></span></td>
                                    <td>
                                        <?php if (!empty($row['maintenance_id'])): ?>
                                            <span class="ticket-badge"><i class="fa-solid fa-ticket"></i> Ticket #<?= $row['maintenance_id'] ?></span><br>
                                        <?php endif; ?>
                                        <strong style="color:#4e73df; font-size:14px;"><?= htmlspecialchars($row['machine_name']) ?></strong><br>
                                        <small style="color:#888;">อาการ: <?= htmlspecialchars($row['reason']) ?></small>
                                    </td>
                                    <td><small><i class="fa-solid fa-user-gear"></i> <?= htmlspecialchars($row['withdrawn_by']) ?></small></td>
                                </tr>
                            <?php 
                                    }
                                } else { echo "<tr><td colspan='5' style='text-align:center; padding:40px; color:#888;'><i class='fa-solid fa-box-open fa-2x'></i><br><br>ยังไม่มีประวัติการเบิกอะไหล่</td></tr>"; }
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
        $('.select2').select2({ width: '100%' });
        $('.select2-item').select2({ width: '100%' });
    });

    let rowIdx = 1;
    const optionStr = `<?php echo $product_options; ?>`;

    // 🚀 เพิ่มแถวอะไหล่ใหม่
    function addRow() {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><select name="items[${rowIdx}][product_id]" class="form-control select2-item" required>${optionStr}</select></td>
            <td><input type="number" step="0.01" name="items[${rowIdx}][qty]" class="form-control" placeholder="0" required></td>
            <td style="text-align:center;"><button type="button" class="btn-del-row" onclick="removeRow(this)"><i class="fa-solid fa-trash"></i></button></td>
        `;
        document.getElementById('partsBody').appendChild(tr);
        $(tr).find('.select2-item').select2({ width: '100%' });
        rowIdx++;
    }

    function removeRow(btn) { btn.closest('tr').remove(); }

    // 🚀 ฟังก์ชัน Auto-fill ดึงข้อมูลแจ้งซ่อมมากรอกใส่ช่อง
    function autoFillTaskInfo() {
        var selectElement = document.getElementById('task_select');
        var selectedOption = selectElement.options[selectElement.selectedIndex];
        var machineInput = document.getElementById('machine_name');
        var reasonInput = document.getElementById('reason');

        if (selectedOption.value !== '') {
            machineInput.value = selectedOption.getAttribute('data-machine');
            reasonInput.value = "อ้างอิง Ticket #" + selectedOption.value + " : " + selectedOption.getAttribute('data-reason');
            machineInput.style.backgroundColor = '#fdf3e2';
            reasonInput.style.backgroundColor = '#fdf3e2';
        } else {
            machineInput.value = '';
            reasonInput.value = '';
            machineInput.style.backgroundColor = '#fff';
            reasonInput.style.backgroundColor = '#fff';
        }
    }

    // ระบบแจ้งเตือนหลังจากการทำงาน
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('status')) {
        const status = urlParams.get('status');
        if (status === 'success') {
            Swal.fire({ icon: 'success', title: 'ตัดสต็อกสำเร็จ!', text: 'ระบบหักยอดในคลัง และบันทึกประวัติเรียบร้อยแล้ว', timer: 3000, showConfirmButton: false });
        } else if (status === 'error') {
            const msg = urlParams.get('msg') || 'เกิดข้อผิดพลาดในการทำรายการ';
            Swal.fire({ icon: 'error', title: 'เบิกของไม่ได้!', text: decodeURIComponent(msg), confirmButtonColor: '#e74a3b' });
        }
        window.history.replaceState(null, null, window.location.pathname);
    }
</script>
</body>
</html>