<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'ช่างซ่อมบำรุง';

// สิทธิ์เข้าถึง (ADMIN, MANAGER, ฝ่ายซ่อมบำรุง)
$group_mnt = ['แผนกซ่อมบำรุง 1', 'แผนกซ่อมบำรุง 2', 'แผนกไฟฟ้า 1', 'แผนกไฟฟ้า 2', 'แผนก P&M - 1', 'แผนก P&M - 2'];
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $group_mnt)) { 
    echo "<script>alert('เฉพาะฝ่ายซ่อมบำรุงและผู้บริหารเท่านั้น'); window.location='../index.php';</script>"; exit(); 
}

// 🚀 [Auto-Create Table] สร้างตารางเก็บแผนบำรุงรักษาเชิงป้องกัน
$check_table = mysqli_query($conn, "SHOW TABLES LIKE 'pm_schedules'");
if (mysqli_num_rows($check_table) == 0) {
    mysqli_query($conn, "CREATE TABLE pm_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        machine_name VARCHAR(255) NOT NULL,
        pm_task TEXT NOT NULL,
        frequency_days INT NOT NULL,
        last_pm_date DATE NOT NULL,
        next_pm_date DATE NOT NULL,
        created_by VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}

// 🚀 1. บันทึกแผน PM ใหม่
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_pm'])) {
    $machine_name = mysqli_real_escape_string($conn, $_POST['machine_name']);
    $pm_task = mysqli_real_escape_string($conn, $_POST['pm_task']);
    $frequency_days = (int)$_POST['frequency_days'];
    $last_pm_date = $_POST['last_pm_date'];
    
    // คำนวณวันถัดไปอัตโนมัติ
    $next_pm_date = date('Y-m-d', strtotime($last_pm_date . " + $frequency_days days"));

    mysqli_query($conn, "INSERT INTO pm_schedules (machine_name, pm_task, frequency_days, last_pm_date, next_pm_date, created_by) 
                         VALUES ('$machine_name', '$pm_task', $frequency_days, '$last_pm_date', '$next_pm_date', '$fullname')");
    
    if(function_exists('log_event')) { log_event($conn, 'INSERT', 'pm_schedules', "ตั้งแผน PM: $machine_name ($pm_task)"); }
    header("Location: manage_pm.php?status=added"); exit;
}

// 🚀 2. อัปเดตงาน PM เมื่อช่างไปทำเสร็จแล้ว (Reset รอบใหม่)
if (isset($_GET['complete_pm'])) {
    $id = (int)$_GET['complete_pm'];
    
    // ดึงความถี่วันเพื่อนำมาบวกกับวันปัจจุบัน
    $q_pm = mysqli_query($conn, "SELECT frequency_days, machine_name, pm_task FROM pm_schedules WHERE id = $id");
    if ($row = mysqli_fetch_assoc($q_pm)) {
        $freq = $row['frequency_days'];
        $machine = $row['machine_name'];
        $task = $row['pm_task'];
        
        $new_last_date = date('Y-m-d'); // ใช้วันนี้เป็นวันที่ทำล่าสุด
        $new_next_date = date('Y-m-d', strtotime("+$freq days")); // คำนวณรอบใหม่
        
        mysqli_query($conn, "UPDATE pm_schedules SET last_pm_date = '$new_last_date', next_pm_date = '$new_next_date' WHERE id = $id");
        
        // ส่ง LINE แจ้งเตือนว่าบำรุงรักษาเรียบร้อยแล้ว
        include_once '../line_api.php';
        $msg = "🔧 [อัปเดต PM] บำรุงรักษาเชิงป้องกันเรียบร้อย\n\n";
        $msg .= "⚙️ เครื่องจักร: $machine\n";
        $msg .= "📋 รายการ: $task\n";
        $msg .= "ช่างผู้ดำเนินการ: $fullname\n\n";
        $msg .= "📅 กำหนดรอบต่อไป: " . date('d/m/Y', strtotime($new_next_date));
        if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

        if(function_exists('log_event')) { log_event($conn, 'UPDATE', 'pm_schedules', "ปิดงาน PM (ทำเสร็จแล้ว): $machine"); }
    }
    header("Location: manage_pm.php?status=completed"); exit;
}

// 🚀 3. ลบแผน PM
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    mysqli_query($conn, "DELETE FROM pm_schedules WHERE id = $id");
    header("Location: manage_pm.php?status=deleted"); exit;
}

include '../sidebar.php';
?>

<title>แผนบำรุงรักษาเครื่องจักร (PM) | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    :root { 
        --primary-light: #fef3c7;
        --success: #10b981; --danger: #ef4444; --info: #3b82f6;
        --bg-color: #f8fafc; --card-bg: #ffffff; --border-color: #e2e8f0;
        --text-main: #1e293b; --text-muted: #64748b;
    }
    body { font-family: 'Sarabun', sans-serif; background-color: var(--bg-color); }
    .content-padding { padding: 24px; width: 100%; box-sizing: border-box; max-width: 1400px; margin: auto;}
    
    .card-pm { background: var(--card-bg); padding: 35px; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 30px; width: 100%; }
    
    .form-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; margin-bottom: 20px; }
    @media (max-width: 1024px) { .form-grid { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 768px) { .form-grid { grid-template-columns: 1fr; } }

    .form-control { width: 100%; padding: 12px 16px; border: 1.5px solid var(--border-color); border-radius: 10px; font-family: 'Sarabun'; font-size: 15px; color: var(--text-main); font-weight: 500; transition: 0.2s;}
    .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15); }
    .form-label { display: block; font-size: 14.5px; font-weight: 700; color: var(--text-main); margin-bottom: 8px; }

    .btn-submit { background: var(--primary); color: white; border: none; padding: 14px 24px; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 16px; transition: 0.3s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 10px rgba(245, 158, 11, 0.2);}
    .btn-submit:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 6px 15px rgba(245, 158, 11, 0.3); }

    /* ตาราง */
    .table-responsive { width: 100%; overflow-x: auto; border-radius: 12px; border: 1px solid var(--border-color);}
    table.display-table { width: 100%; border-collapse: collapse; min-width: 1000px;}
    table.display-table th { background: #f8fafc; color: var(--text-muted); font-size: 13px; text-transform: uppercase; font-weight: 700; padding: 16px 20px; border-bottom: 2px solid var(--border-color); text-align: left; }
    table.display-table td { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 15px; font-weight: 500; color: var(--text-main);}
    table.display-table tr:hover td { background-color: #fcfcfc; }

    .badge-status { padding: 6px 14px; border-radius: 50px; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
    .st-normal { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .st-warning { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; animation: pulse 2s infinite; }
    .st-danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; animation: pulse 1.5s infinite;}

    .btn-action { background: var(--success); color: white; padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 700; text-decoration: none; transition: 0.2s; display:inline-flex; align-items:center; gap:5px; border:none; cursor:pointer;}
    .btn-action:hover { background: #059669; transform: translateY(-1px); }
    
    .btn-delete { color: #94a3b8; padding: 8px; border-radius: 8px; transition: 0.2s; text-decoration:none;}
    .btn-delete:hover { color: var(--danger); background: #fee2e2; }

    @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }
</style>

<div class="content-padding">
    
    <div class="card-pm" style="border-top: 5px solid var(--primary);">
        <h3 style="margin-top:0; color:var(--text-main); font-size:22px; font-weight:800; margin-bottom: 25px;">
            <i class="fa-solid fa-calendar-plus" style="color:var(--primary); margin-right:8px;"></i> ตั้งค่าแผนบำรุงรักษาเครื่องจักร (PM Plan)
        </h3>
        
        <form method="POST">
            <div class="form-grid">
                <div style="grid-column: span 2;">
                    <label class="form-label">ชื่อเครื่องจักร / อุปกรณ์</label>
                    <input type="text" name="machine_name" class="form-control" placeholder="เช่น เครื่องโม่เบอร์ 1, ปั๊มลมหลัก..." required>
                </div>
                <div style="grid-column: span 2;">
                    <label class="form-label">รายการที่ต้องบำรุงรักษา (PM Task)</label>
                    <input type="text" name="pm_task" class="form-control" placeholder="เช่น อัดจาระบี, เปลี่ยนถ่ายน้ำมันเครื่อง, ทำความสะอาดกรอง..." required>
                </div>
                
                <div>
                    <label class="form-label">ทำรอบล่าสุดวันที่ (Last PM)</label>
                    <input type="date" name="last_pm_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div>
                    <label class="form-label">ความถี่ (ทุกๆ กี่วัน)</label>
                    <div style="position:relative;">
                        <input type="number" name="frequency_days" min="1" class="form-control" placeholder="เช่น 15, 30, 180" required>
                        <span style="position: absolute; right: 15px; top: 12px; color: var(--text-muted); font-weight: 500;">วัน</span>
                    </div>
                </div>
                <div style="grid-column: span 2; display:flex; align-items:flex-end;">
                    <button type="submit" name="add_pm" class="btn-submit" style="width:100%; margin:0; height: 48px;">
                        <i class="fa-solid fa-floppy-disk"></i> บันทึกแผนซ่อมบำรุง
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div class="card-pm">
        <h3 style="margin-top:0; margin-bottom:20px; color:var(--text-main); font-size:20px; font-weight:800;">
            <i class="fa-solid fa-screwdriver-wrench" style="color:var(--info); margin-right:8px;"></i> กำหนดการบำรุงรักษา (เรียงตามคิวที่ใกล้ถึง)
        </h3>
        
        <div class="table-responsive">
            <table class="display-table">
                <thead>
                    <tr>
                        <th width="20%">เครื่องจักร</th>
                        <th width="30%">รายการซ่อมบำรุง (Task)</th>
                        <th width="15%">ความถี่</th>
                        <th width="20%">กำหนดรอบต่อไป (Due Date)</th>
                        <th width="15%" style="text-align:right;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql = "SELECT * FROM pm_schedules ORDER BY next_pm_date ASC";
                    $res = mysqli_query($conn, $sql);
                    
                    if(mysqli_num_rows($res) > 0) {
                        $today = date('Y-m-d');
                        $warning_date = date('Y-m-d', strtotime('+7 days')); // เตือนล่วงหน้า 7 วัน

                        while($row = mysqli_fetch_assoc($res)) {
                            $next_date = $row['next_pm_date'];
                            
                            $badge = "";
                            if ($next_date < $today) {
                                $badge = "<span class='badge-status st-danger'><i class='fa-solid fa-triangle-exclamation'></i> เลยกำหนดแล้ว!</span>";
                            } elseif ($next_date <= $warning_date) {
                                $badge = "<span class='badge-status st-warning'><i class='fa-regular fa-clock'></i> ใกล้ถึงกำหนด</span>";
                            } else {
                                $badge = "<span class='badge-status st-normal'><i class='fa-solid fa-check'></i> ปกติ</span>";
                            }
                    ?>
                        <tr>
                            <td><strong style="color: var(--info); font-size:15px;"><i class="fa-solid fa-gear" style="margin-right:5px; color:#94a3b8;"></i><?= htmlspecialchars($row['machine_name']) ?></strong></td>
                            <td><?= htmlspecialchars($row['pm_task']) ?></td>
                            <td><span style="background:#e0f2fe; color:#0369a1; padding:4px 10px; border-radius:8px; font-size:13px; font-weight:bold;">ทุก <?= $row['frequency_days'] ?> วัน</span></td>
                            <td>
                                <strong style="font-size: 15px; color: var(--text-main);"><?= date('d/m/Y', strtotime($row['next_pm_date'])) ?></strong><br>
                                <?= $badge ?>
                            </td>
                            <td align="right" style="display:flex; gap:10px; justify-content:flex-end;">
                                <a href="#" class="btn-action" onclick="confirmComplete(<?= $row['id'] ?>)">
                                    <i class="fa-solid fa-check-double"></i> ทำเสร็จแล้ว
                                </a>
                                <?php if($user_role == 'ADMIN' || $user_role == 'MANAGER'): ?>
                                    <a href="#" class="btn-delete" onclick="confirmDelete(<?= $row['id'] ?>)"><i class="fa-solid fa-trash-can"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php 
                        }
                    } else { 
                        echo "<tr><td colspan='5' style='text-align:center; padding:50px; color:var(--text-muted);'><i class='fa-solid fa-calendar-check fa-3x' style='margin-bottom:15px; color:#e2e8f0;'></i><br>ยังไม่มีการตั้งค่าแผน PM</td></tr>";
                    } 
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('status')==='added') Swal.fire({ icon:'success', title:'ตั้งแผนสำเร็จ!', timer:1500, showConfirmButton:false }).then(()=>window.history.replaceState(null,null,window.location.pathname));
    if(urlParams.get('status')==='completed') Swal.fire({ icon:'success', title:'บันทึกงานเสร็จสิ้น!', text:'ระบบคำนวณวันซ่อมรอบต่อไปเรียบร้อยแล้ว', timer:2500, showConfirmButton:false }).then(()=>window.history.replaceState(null,null,window.location.pathname));
    if(urlParams.get('status')==='deleted') Swal.fire({ icon:'success', title:'ลบแผนเรียบร้อย', timer:1500, showConfirmButton:false }).then(()=>window.history.replaceState(null,null,window.location.pathname));

    function confirmComplete(id) {
        Swal.fire({
            title: 'ช่างทำงานเสร็จแล้ว?',
            text: "ระบบจะปรับรอบการบำรุงรักษา (PM) เป็นรอบถัดไปทันที",
            icon: 'info',
            showCancelButton: true,
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: '<i class="fa-solid fa-check"></i> ใช่, ปิดงานเลย',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) window.location.href = '?complete_pm=' + id;
        });
    }

    function confirmDelete(id) {
        Swal.fire({
            title: 'ลบแผน PM?',
            text: "คุณต้องการลบข้อมูลนี้ใช่หรือไม่?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: 'ลบข้อมูล'
        }).then((result) => {
            if (result.isConfirmed) window.location.href = '?delete=' + id;
        });
    }
</script>
</body>
</html>