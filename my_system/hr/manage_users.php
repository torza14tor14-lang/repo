<?php
session_start();
include '../db.php';

// สเต็ปที่ 1: ตรวจสอบว่า "ล็อกอินหรือยัง?" (ถ้ายัง ให้เด้งไปหน้า login ทันที)
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

// สเต็ปที่ 2: ถ้าล็อกอินแล้ว ตรวจสอบว่า "เป็น Admin หรือ HR หรือไม่?"
$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
if ($user_role !== 'ADMIN' && $user_dept !== 'ฝ่าย HR') { 
    echo "<script>alert('ไม่มีสิทธิ์เข้าถึง'); window.location='../index.php';</script>"; 
    exit(); 
}

// --- 0. ดึงข้อมูลกะการทำงานเตรียมไว้ ---
$shifts = [];
$check_shift_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'work_shifts'");
if (mysqli_num_rows($check_shift_tbl) > 0) {
    $s_q = mysqli_query($conn, "SELECT * FROM work_shifts ORDER BY start_time ASC");
    while($s = mysqli_fetch_assoc($s_q)) { $shifts[] = $s; }
}

// --- 1. ส่วนบันทึกข้อมูลพนักงานใหม่ ---
if (isset($_POST['add_user'])) {
    $userid   = mysqli_real_escape_string($conn, $_POST['userid']);
    $fullname = mysqli_real_escape_string($conn, $_POST['fullname']);
    $pass     = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role     = $_POST['role'];
    $dept     = mysqli_real_escape_string($conn, $_POST['dept']);
    $shift_id = empty($_POST['shift_id']) ? "NULL" : (int)$_POST['shift_id'];
    $base_salary = empty($_POST['base_salary']) ? 0 : (float)$_POST['base_salary'];
    
    // 🚀 เพิ่มการรับค่าบัญชีธนาคาร
    $bank_account = mysqli_real_escape_string($conn, $_POST['bank_account']);

    $check = mysqli_query($conn, "SELECT * FROM employees WHERE userid = '$userid'");
    if (mysqli_num_rows($check) > 0) {
        echo "<script>alert('รหัสพนักงาน (ID) นี้มีอยู่ในระบบแล้ว!');</script>";
    } else {
        // 🚀 อัปเดตคำสั่ง INSERT ให้บันทึก bank_account ด้วย
        $sql = "INSERT INTO employees (userid, password, username, role, dept, shift_id, base_salary, bank_account, status) 
                VALUES ('$userid', '$pass', '$fullname', '$role', '$dept', $shift_id, $base_salary, '$bank_account', 'Active')";
        if (mysqli_query($conn, $sql)) {
            if (function_exists('log_event')) {
                log_event($conn, 'INSERT', 'employees', "เพิ่มพนักงานใหม่ รหัส $userid ($fullname)");
            }
            echo "<script>alert('บันทึกข้อมูลพนักงานสำเร็จ!'); window.location='manage_users.php';</script>";
        }
    }
}

// --- 2. ส่วนแก้ไขข้อมูลพนักงาน (อัปเดต) ---
if (isset($_POST['edit_user'])) {
    $edit_userid   = mysqli_real_escape_string($conn, $_POST['edit_userid']);
    $edit_fullname = mysqli_real_escape_string($conn, $_POST['edit_fullname']);
    $edit_role     = $_POST['edit_role'];
    $edit_dept     = mysqli_real_escape_string($conn, $_POST['edit_dept']);
    $edit_shift_id = empty($_POST['edit_shift_id']) ? "NULL" : (int)$_POST['edit_shift_id'];
    $edit_base_salary = empty($_POST['edit_base_salary']) ? 0 : (float)$_POST['edit_base_salary'];
    $edit_pass     = $_POST['edit_password']; 
    
    // 🚀 เพิ่มการรับค่าบัญชีธนาคารสำหรับอัปเดต
    $edit_bank_account = mysqli_real_escape_string($conn, $_POST['edit_bank_account']);

    // 🚀 อัปเดตคำสั่ง UPDATE ให้แก้ไข bank_account ด้วย
    if (!empty($edit_pass)) {
        $new_pass_hash = password_hash($edit_pass, PASSWORD_DEFAULT);
        $sql_update = "UPDATE employees SET username='$edit_fullname', role='$edit_role', dept='$edit_dept', shift_id=$edit_shift_id, base_salary=$edit_base_salary, bank_account='$edit_bank_account', password='$new_pass_hash' WHERE userid='$edit_userid'";
    } else {
        $sql_update = "UPDATE employees SET username='$edit_fullname', role='$edit_role', dept='$edit_dept', shift_id=$edit_shift_id, base_salary=$edit_base_salary, bank_account='$edit_bank_account' WHERE userid='$edit_userid'";
    }

    if (mysqli_query($conn, $sql_update)) {
        if (function_exists('log_event')) {
            log_event($conn, 'UPDATE', 'employees', "แก้ไขข้อมูลพนักงานรหัส $edit_userid (เงินเดือน: $edit_base_salary)");
        }
        echo "<script>alert('อัปเดตข้อมูลพนักงานสำเร็จ!'); window.location='manage_users.php';</script>";
    } else {
        echo "<script>alert('เกิดข้อผิดพลาดในการอัปเดต');</script>";
    }
}

// --- 3. ระบบจัดการสถานะพนักงาน (Soft Delete) ---
if (isset($_GET['resign'])) {
    $id = mysqli_real_escape_string($conn, $_GET['resign']);
    if ($_SESSION['userid'] != $id) { 
        if(mysqli_query($conn, "UPDATE employees SET status = 'Resigned' WHERE userid = '$id'")){
            if (function_exists('log_event')) {
                log_event($conn, 'UPDATE', 'employees', "เปลี่ยนสถานะพนักงานรหัส $id เป็น 'ลาออก'");
            }
        }
    }
    header("Location: manage_users.php");
    exit();
}
if (isset($_GET['restore'])) {
    $id = mysqli_real_escape_string($conn, $_GET['restore']);
    if(mysqli_query($conn, "UPDATE employees SET status = 'Active' WHERE userid = '$id'")){
        if (function_exists('log_event')) {
            log_event($conn, 'UPDATE', 'employees', "คืนสิทธิ์พนักงานรหัส $id เป็น 'ทำงานอยู่'");
        }
    }
    header("Location: manage_users.php");
    exit();
}

// --- 4. ระบบค้นหาพนักงาน ---
$search = $_GET['search'] ?? '';
$where_sql = "";
if (!empty($search)) {
    $search_esc = mysqli_real_escape_string($conn, $search);
    $where_sql = "WHERE e.userid LIKE '%$search_esc%' OR e.username LIKE '%$search_esc%'";
}

// --- 5. ส่วนดึงข้อมูลและจัดเรียง ---
$sql_users = "SELECT e.*, s.shift_name, s.start_time, s.end_time 
              FROM employees e 
              LEFT JOIN work_shifts s ON e.shift_id = s.id 
$where_sql
ORDER BY 
    FIELD(IFNULL(e.status, 'Active'), 'Active', 'Resigned') ASC, 
    FIELD(e.dept, 
    'แผนกผลิต 1', 'แผนกคลังสินค้า 1', 'แผนกซ่อมบำรุง 1', 'แผนกไฟฟ้า 1', 'ฝ่ายวิชาการ', 
    'แผนก QA', 'แผนก P&M - 1', 'แผนก QC', 'ฝ่ายขาย', 'ฝ่ายจัดซื้อ', 
    'ฝ่ายบัญชี', 'ฝ่ายสินเชื่อ', 'ฝ่ายการเงิน', 'ฝ่าย HR', 'ฝ่ายงานวางแผน', 
    'แผนกคอมพิวเตอร์', 'บัญชี - ท็อปธุรกิจ', 'นักศึกษาฝึกงาน', 'ผลิตอาหารสัตว์น้ำ', 
    'แผนกผลิต 2', 'แผนกคลังสินค้า 2', 'แผนกซ่อมบำรุง 2', 'แผนกไฟฟ้า 2', 'แผนก P&M - 2'
) ASC, CAST(e.userid AS UNSIGNED) ASC"; 

$users = mysqli_query($conn, $sql_users);
if (!$users) {
    $users = mysqli_query($conn, "SELECT * FROM employees ORDER BY emp_id DESC");
}

include '../sidebar.php';
?>

<title>Top Feed Mills | จัดการพนักงาน</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    .form-container { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 25px; border-top: 4px solid #4e73df; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
    .field-group { display: flex; flex-direction: column; gap: 5px; }
    .field-group label { font-size: 13px; font-weight: bold; color: #555; }
    .field-group input, .field-group select { padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-family: 'Sarabun'; outline: none; }
    .field-group input:focus, .field-group select:focus { border-color: #4e73df; }
    .btn-save { background: #4e73df; color: white; border: none; border-radius: 8px; padding: 11px; font-weight: bold; cursor: pointer; transition: 0.3s; }
    .btn-save:hover { background: #2e59d9; }

    .search-bar { display: flex; gap: 10px; margin-bottom: 20px; align-items: center; background: white; padding: 15px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
    .search-bar input { flex: 1; max-width: 400px; padding: 10px 15px; border: 1px solid #ddd; border-radius: 50px; font-family: 'Sarabun'; outline: none; }
    .search-bar input:focus { border-color: #4e73df; }
    .btn-search { background: #2c3e50; color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-weight: bold; }
    
    .table-card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; min-width: 1000px; }
    th { background: #f8f9fc; padding: 12px; text-align: left; color: #4e73df; border-bottom: 2px solid #eaecf4; font-size: 14px; }
    td { padding: 12px; border-bottom: 1px solid #f1f1f1; font-size: 14.5px; color: #555; vertical-align: middle; transition: 0.2s;}
    
    .dept-badge { background: #ebf4ff; color: #4e73df; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: bold; }
    .shift-badge { background: #e3fdfd; color: #118a9b; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: bold; border: 1px solid #c4e8e8; display: inline-block; margin-top: 4px;}

    .status-active { background: #e8f9f3; color: #1cc88a; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; border: 1px solid #c3e6cb;}
    .status-resigned { background: #f8d7da; color: #e74a3b; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; border: 1px solid #f5c6cb;}
    .row-resigned { opacity: 0.65; background-color: #fafafa; }
    .row-resigned:hover { opacity: 1; }

    .action-group { display: flex; gap: 8px; justify-content: flex-end; align-items: center; flex-wrap: wrap; }
    .btn-edit-action { background: #ffc107; color: #000; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: bold; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s; }
    .btn-edit-action:hover { background: #e0a800; transform: translateY(-1px); }
    
    .btn-delete-action { background: #e74a3b; color: white; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s; }
    .btn-delete-action:hover { background: #c0392b; transform: translateY(-1px); }
    
    .btn-restore-action { background: #1cc88a; color: white; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s; }
    .btn-restore-action:hover { background: #17a673; transform: translateY(-1px); }

    /* Modal Styling */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.4); backdrop-filter: blur(5px); z-index: 9999; justify-content: center; align-items: center; }
    .modal-content { background: white; border-radius: 15px; width: 100%; max-width: 500px; box-shadow: 0 25px 50px rgba(0,0,0,0.2); overflow: hidden; animation: modalPop 0.3s ease; }
    @keyframes modalPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .modal-header { background: #f8f9fc; padding: 15px 20px; border-bottom: 1px solid #eaecf4; display: flex; justify-content: space-between; align-items: center; }
    .modal-header h3 { margin: 0; color: #4e73df; }
    .modal-body { padding: 20px; max-height: 70vh; overflow-y: auto;}
    .modal-footer { padding: 15px 20px; background: #f8f9fc; border-top: 1px solid #eaecf4; text-align: right; display: flex; justify-content: flex-end; gap: 10px; }
    .btn-cancel { background: #e2e8f0; color: #4a5568; border: none; padding: 10px 15px; border-radius: 8px; font-weight: bold; cursor: pointer; }
    .btn-update { background: #1cc88a; color: white; border: none; padding: 10px 15px; border-radius: 8px; font-weight: bold; cursor: pointer; }
</style>

<div class="content-padding">
    <div class="form-container">
        <h3 style="margin-top:0; color:#4e73df; margin-bottom: 20px;"><i class="fa-solid fa-user-plus"></i> ลงทะเบียนพนักงานใหม่</h3>
        <form method="POST">
            <div class="form-grid">
                <div class="field-group">
                    <label>รหัสพนักงาน (userid)</label>
                    <input type="text" name="userid" placeholder="เช่น 6601" required autocomplete="off">
                </div>
                <div class="field-group">
                    <label>รหัสผ่าน</label>
                    <input type="password" name="password" placeholder="ตั้งรหัสผ่าน" required>
                </div>
                <div class="field-group">
                    <label>ชื่อ-นามสกุลจริง</label>
                    <input type="text" name="fullname" placeholder="กรอกชื่อจริง" required>
                </div>
                <div class="field-group">
                    <label>แผนก</label>
                    <select name="dept" required>
                        <option value="" disabled selected>-- เลือกแผนก --</option>
                        <?php 
                        $depts = [
                            'แผนกผลิต 1', 'แผนกคลังสินค้า 1', 'แผนกซ่อมบำรุง 1', 'แผนกไฟฟ้า 1', 'ฝ่ายวิชาการ', 
                            'แผนก QA', 'แผนก P&M - 1', 'แผนก QC', 'ฝ่ายขาย', 'ฝ่ายจัดซื้อ', 
                            'ฝ่ายบัญชี', 'ฝ่ายสินเชื่อ', 'ฝ่ายการเงิน', 'ฝ่าย HR', 'ฝ่ายงานวางแผน', 
                            'แผนกคอมพิวเตอร์', 'บัญชี - ท็อปธุรกิจ', 'นักศึกษาฝึกงาน', 'ผลิตอาหารสัตว์น้ำ', 
                            'แผนกผลิต 2', 'แผนกคลังสินค้า 2', 'แผนกซ่อมบำรุง 2', 'แผนกไฟฟ้า 2', 'แผนก P&M - 2'
                        ];
                        foreach($depts as $d) echo "<option value='$d'>$d</option>";
                        ?>
                    </select>
                </div>
                <div class="field-group">
                    <label>กะการทำงานประจำ</label>
                    <select name="shift_id">
                        <option value="">-- ไม่ระบุกะ (เวลาอิสระ) --</option>
                        <?php foreach($shifts as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['shift_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label>ฐานเงินเดือน (บาท)</label>
                    <input type="number" name="base_salary" placeholder="0.00" min="0" step="0.01">
                </div>
                
                <div class="field-group">
                    <label>เลขที่บัญชีธนาคาร</label>
                    <input type="text" name="bank_account" placeholder="เช่น 123-4-56789-0">
                </div>

                <div class="field-group">
                    <label>สิทธิ์การใช้งาน</label>
                    <select name="role">
                        <option value="USER">USER</option>
                        <option value="MANAGER">MANAGER</option>
                        <option value="ADMIN">ADMIN</option>
                    </select>
                </div>
                <button type="submit" name="add_user" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> บันทึกข้อมูล</button>
            </div>
        </form>
    </div>

    <form class="search-bar" method="GET">
        <i class="fa-solid fa-magnifying-glass" style="color: #888;"></i>
        <input type="text" name="search" placeholder="ค้นหา รหัสพนักงาน หรือ ชื่อ-นามสกุล..." value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit" class="btn-search">ค้นหา</button>
        <?php if(!empty($search)): ?>
            <a href="manage_users.php" style="color:#e74a3b; text-decoration:none; font-size: 14px; margin-left: 10px;">ล้างการค้นหา</a>
        <?php endif; ?>
    </form>

    <div class="table-card">
        <h3 style="margin-top:0; color:#2c3e50; margin-bottom: 20px;"><i class="fa-solid fa-users"></i> รายชื่อพนักงานทั้งหมด</h3>
        <table>
            <thead>
                <tr>
                    <th style="width: 8%;">รหัส</th>
                    <th style="width: 20%;">ชื่อ-นามสกุล</th>
                    <th style="width: 20%;">แผนก และ กะ</th>
                    <th style="width: 12%; text-align:right;">ฐานเงินเดือน</th>
                    <th style="width: 10%; text-align:center;">สิทธิ์</th>
                    <th style="width: 10%; text-align:center;">สถานะ</th>
                    <th style="width: 20%; text-align:right;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                if (mysqli_num_rows($users) > 0) {
                    while($row = mysqli_fetch_assoc($users)) { 
                        $emp_status = $row['status'] ?? 'Active'; // ดักจับกรณีค่าว่างให้เป็น Active
                        $row_class = ($emp_status == 'Resigned') ? 'row-resigned' : '';
                ?>
                <tr class="<?php echo $row_class; ?>">
                    <td><strong><?php echo $row['userid']; ?></strong></td>
                    <td>
                        <?php echo $row['username']; ?><br>
                        <small style="color:#888;"><i class="fa-solid fa-building-columns"></i> <?= $row['bank_account'] ?: '-' ?></small>
                    </td>
                    <td>
                        <span class="dept-badge"><?php echo $row['dept']; ?></span><br>
                        <?php if(isset($row['shift_name']) && $row['shift_name'] != ''): ?>
                            <span class="shift-badge" title="<?= date('H:i', strtotime($row['start_time'])) ?> - <?= date('H:i', strtotime($row['end_time'])) ?>"><i class="fa-regular fa-clock"></i> <?= htmlspecialchars($row['shift_name']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right; font-weight:bold; color:#1cc88a;">
                        <?= number_format($row['base_salary'] ?? 0, 2) ?> ฿
                    </td>
                    <td style="text-align:center;">
                        <span style="font-size:12px; font-weight:bold; color: <?php echo $row['role']=='ADMIN'?'#e74a3b':($row['role']=='MANAGER'?'#f6c23e':'#4e73df'); ?>;">
                            <?php echo $row['role']; ?>
                        </span>
                    </td>
                    <td style="text-align:center;">
                        <?php if($emp_status == 'Resigned'): ?>
                            <span class="status-resigned">ลาออก</span>
                        <?php else: ?>
                            <span class="status-active">ทำงานอยู่</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-group">
                            <button onclick='openEditModal(<?php echo json_encode($row); ?>)' class="btn-edit-action">
                                <i class="fa-solid fa-pen"></i> แก้ไข
                            </button>
                            
                            <?php if($row['userid'] != $_SESSION['userid']): ?>
                                <?php if($emp_status == 'Active'): ?>
                                    <a href="manage_users.php?resign=<?php echo $row['userid']; ?>" 
                                       class="btn-delete-action"
                                       onclick="return confirm('ยืนยันการตั้งสถานะ ลาออก ให้กับรหัส <?php echo $row['userid']; ?> หรือไม่? \n\n(ข้อมูลประวัติการทำงานจะยังคงอยู่)')">
                                       <i class="fa-solid fa-user-large-slash"></i> ลาออก
                                    </a>
                                <?php else: ?>
                                    <a href="manage_users.php?restore=<?php echo $row['userid']; ?>" 
                                       class="btn-restore-action"
                                       onclick="return confirm('คืนสถานะ ทำงานอยู่ ให้กับรหัส <?php echo $row['userid']; ?>?')">
                                       <i class="fa-solid fa-rotate-left"></i> คืนสิทธิ์
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <small style="color:#a0aec0; margin-left: 5px;">(บัญชีคุณ)</small>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php 
                    } 
                } else {
                    echo "<tr><td colspan='7' style='text-align:center; padding: 20px; color:#999;'>ไม่พบข้อมูลพนักงานที่ค้นหา</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<div id="editModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fa-solid fa-user-pen"></i> แก้ไขข้อมูลพนักงาน</h3>
            <span onclick="closeModal()" style="cursor:pointer; font-size:20px; color:#888;">&times;</span>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div class="field-group">
                        <label>รหัสพนักงาน (แก้ไขไม่ได้)</label>
                        <input type="text" name="edit_userid" id="edit_userid" readonly style="background: #eaecf4; cursor: not-allowed; outline:none;">
                    </div>
                    <div class="field-group">
                        <label>ชื่อ-นามสกุลจริง</label>
                        <input type="text" name="edit_fullname" id="edit_fullname" required>
                    </div>
                </div>
                
                <div class="field-group" style="margin-bottom: 15px;">
                    <label>แผนก</label>
                    <select name="edit_dept" id="edit_dept" required>
                        <?php foreach($depts as $d) echo "<option value='$d'>$d</option>"; ?>
                    </select>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div class="field-group" style="background: #fdfdfd; padding: 10px; border-radius: 8px; border: 1px solid #eee;">
                        <label style="color:#118a9b;"><i class="fa-solid fa-business-time"></i> กะการทำงานประจำ</label>
                        <select name="edit_shift_id" id="edit_shift_id" style="width: 100%;">
                            <option value="">-- ไม่ระบุกะ --</option>
                            <?php foreach($shifts as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['shift_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="field-group" style="background: #e8f9f3; padding: 10px; border-radius: 8px; border: 1px solid #c3e6cb;">
                        <label style="color:#1cc88a;"><i class="fa-solid fa-money-bill-wave"></i> ฐานเงินเดือน</label>
                        <input type="number" name="edit_base_salary" id="edit_base_salary" min="0" step="0.01" style="width: 100%; border-color:#c3e6cb;">
                    </div>
                </div>

                <div class="field-group" style="margin-bottom: 15px;">
                    <label><i class="fa-solid fa-building-columns" style="color:#f6c23e;"></i> เลขที่บัญชีธนาคาร</label>
                    <input type="text" name="edit_bank_account" id="edit_bank_account" placeholder="เช่น 123-4-56789-0">
                </div>

                <div class="field-group" style="margin-bottom: 15px;">
                    <label>สิทธิ์การใช้งาน (Role)</label>
                    <select name="edit_role" id="edit_role" required>
                        <option value="USER">USER</option>
                        <option value="MANAGER">MANAGER</option>
                        <option value="ADMIN">ADMIN</option>
                    </select>
                </div>
                <div class="field-group">
                    <label>ตั้งรหัสผ่านใหม่ <span style="color:#e74a3b; font-weight:normal;">(ปล่อยว่างไว้ถ้าไม่ต้องการเปลี่ยนรหัส)</span></label>
                    <input type="text" name="edit_password" placeholder="กรอกรหัสผ่านใหม่ที่นี่...">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal()">ยกเลิก</button>
                <button type="submit" name="edit_user" class="btn-update"><i class="fa-solid fa-check"></i> บันทึกการแก้ไข</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal(userData) {
        document.getElementById('edit_userid').value = userData.userid;
        document.getElementById('edit_fullname').value = userData.username;
        document.getElementById('edit_dept').value = userData.dept;
        document.getElementById('edit_role').value = userData.role;
        
        let shiftSelect = document.getElementById('edit_shift_id');
        if(userData.shift_id) { shiftSelect.value = userData.shift_id; } 
        else { shiftSelect.value = ""; }
        
        document.getElementById('edit_base_salary').value = userData.base_salary ? userData.base_salary : 0;
        
        // 🚀 ดึงข้อมูลเลขบัญชีมาแสดงในช่องแก้ไข
        document.getElementById('edit_bank_account').value = userData.bank_account ? userData.bank_account : "";
        
        document.getElementById('editModal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    window.onclick = function(event) {
        let modal = document.getElementById('editModal');
        if (event.target == modal) {
            closeModal();
        }
    }
</script>