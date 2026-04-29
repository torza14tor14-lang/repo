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

$status_msg = '';
$view_date = $_GET['date'] ?? date('Y-m-d'); // วันที่ต้องการดูข้อมูล

// ==========================================
// 1. จำลองการรับข้อมูลจากเครื่องสแกนนิ้ว (หรือ HR คีย์มือ)
// ==========================================
if (isset($_POST['save_time'])) {
    $userid = mysqli_real_escape_string($conn, $_POST['userid']);
    $work_date = $_POST['work_date'];
    $clock_in = !empty($_POST['clock_in']) ? "'".$_POST['clock_in']."'" : "NULL";
    $clock_out = !empty($_POST['clock_out']) ? "'".$_POST['clock_out']."'" : "NULL";
    
    $late_minutes = 0;

    // --- ตรรกะความฉลาด: คำนวณมาสายอัตโนมัติ ---
    if (!empty($_POST['clock_in'])) {
        // ดึงข้อมูลกะของพนักงานคนนี้
        $emp_q = mysqli_query($conn, "SELECT e.shift_id, s.start_time, s.grace_period FROM employees e LEFT JOIN work_shifts s ON e.shift_id = s.id WHERE e.userid = '$userid'");
        if ($emp = mysqli_fetch_assoc($emp_q)) {
            if ($emp['start_time']) { // ถ้ามีกะ
                $scan_time = strtotime($_POST['clock_in']);
                $shift_start = strtotime($emp['start_time']);
                $grace = (int)$emp['grace_period'] * 60; // แปลงนาทีเป็นวินาที
                
                // ถ้าสแกนเข้า ช้ากว่า (เวลาเริ่มกะ + เวลาอนุโลม)
                if ($scan_time > ($shift_start + $grace)) {
                    // คำนวณนาทีที่สาย (เอาเวลาที่สแกน ลบ เวลาเริ่มกะจริงๆ ไม่ใช่เวลาอนุโลม)
                    $late_seconds = $scan_time - $shift_start;
                    $late_minutes = floor($late_seconds / 60);
                }
            }
        }
    }

    // บันทึกหรืออัปเดตข้อมูล (ถ้ารหัสนี้สแกนวันนี้ไปแล้ว ให้ใช้วิธีอัปเดต)
    $sql = "INSERT INTO time_attendance (userid, work_date, clock_in, clock_out, late_minutes) 
            VALUES ('$userid', '$work_date', $clock_in, $clock_out, $late_minutes)
            ON DUPLICATE KEY UPDATE clock_in=VALUES(clock_in), clock_out=VALUES(clock_out), late_minutes=VALUES(late_minutes)";
    
    if (mysqli_query($conn, $sql)) { $status_msg = 'saved'; }
}

include '../sidebar.php';
?>

<title>ตรวจสอบเวลาเข้า-ออกงาน | Top Feed Mills</title>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .att-card { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-bottom: 25px; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 5px; color: #5a5c69; font-size: 13px; }
    .form-control { width: 100%; padding: 10px 12px; border: 1px solid #eaecf4; border-radius: 8px; font-family: 'Sarabun'; outline: none; transition: 0.3s; box-sizing: border-box; }
    .form-control:focus { border-color: #4e73df; }
    .btn-submit { background: #4e73df; color: white; border: none; padding: 12px; border-radius: 8px; width: 100%; font-weight: bold; cursor: pointer; transition: 0.3s; font-family: 'Sarabun'; }
    .btn-submit:hover { background: #2e59d9; }
    
    table { width: 100%; border-collapse: collapse; min-width: 700px; }
    th { background: #f8f9fc; padding: 12px; text-align: left; color: #4e73df; border-bottom: 2px solid #eaecf4; font-size: 14px; }
    td { padding: 12px; border-bottom: 1px solid #f1f1f1; font-size: 14px; vertical-align: middle; }
    
    .late-badge { background: #ffe5e5; color: #e74a3b; padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: bold; border: 1px solid #f5c2c7; display: inline-block; }
    .ontime-badge { background: #e8f9f3; color: #1cc88a; padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: bold; border: 1px solid #c3e6cb; display: inline-block; }
    
    .shift-info { font-size: 12px; color: #888; display: block; margin-top: 3px; }
    
    .filter-box { display: flex; gap: 10px; align-items: center; background: #f8f9fc; padding: 15px; border-radius: 10px; border: 1px solid #eaecf4; margin-bottom: 20px; }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top:0;"><i class="fa-solid fa-fingerprint" style="color: #4e73df;"></i> ตรวจสอบเวลาเข้า-ออกงาน (Time Attendance)</h2>

    <div style="display: grid; grid-template-columns: 300px 1fr; gap: 25px;">
        
        <div class="att-card" style="border-top: 4px solid #f6c23e; height: fit-content;">
            <h4 style="margin-top:0; color:#2c3e50;"><i class="fa-solid fa-keyboard"></i> ป้อนเวลาแบบ Manual</h4>
            <p style="font-size:13px; color:#888; margin-top:-5px;">ใช้ทดสอบระบบ หรือกรณีพนักงานลืมสแกนนิ้ว</p>
            
            <form method="POST">
                <div class="form-group">
                    <label>รหัสพนักงาน</label>
                    <input type="text" name="userid" class="form-control" placeholder="เช่น 6601" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label>วันที่ทำงาน</label>
                    <input type="date" name="work_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>เวลาเข้างาน (Clock In)</label>
                    <input type="time" name="clock_in" class="form-control">
                </div>
                <div class="form-group">
                    <label>เวลาเลิกงาน (Clock Out)</label>
                    <input type="time" name="clock_out" class="form-control">
                </div>
                <button type="submit" name="save_time" class="btn-submit"><i class="fa-solid fa-save"></i> บันทึกเวลาเข้า-ออก</button>
            </form>
        </div>

        <div class="att-card" style="border-top: 4px solid #4e73df;">
            
            <form class="filter-box" method="GET">
                <label style="font-weight:bold; color:#2c3e50;">ดูข้อมูลประจำวันที่:</label>
                <input type="date" name="date" class="form-control" style="width: auto;" value="<?= $view_date ?>">
                <button type="submit" class="btn-submit" style="width: auto; padding: 10px 20px;">แสดงข้อมูล</button>
            </form>

            <h4 style="margin-top:0; color:#2c3e50;">ตารางเวลาทำงาน วันที่ <?= date('d/m/Y', strtotime($view_date)) ?></h4>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>พนักงาน</th>
                            <th>กะการทำงาน (เริ่ม)</th>
                            <th style="text-align:center;">เวลาเข้า (In)</th>
                            <th style="text-align:center;">เวลาออก (Out)</th>
                            <th style="text-align:center;">สถานะเข้างาน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sql_log = "SELECT t.*, e.username, s.shift_name, s.start_time 
                                    FROM time_attendance t 
                                    JOIN employees e ON t.userid = e.userid 
                                    LEFT JOIN work_shifts s ON e.shift_id = s.id
                                    WHERE t.work_date = '$view_date' 
                                    ORDER BY t.clock_in ASC";
                        $res_log = mysqli_query($conn, $sql_log);

                        if(mysqli_num_rows($res_log) > 0) {
                            while($row = mysqli_fetch_assoc($res_log)) {
                                $clock_in = $row['clock_in'] ? date('H:i', strtotime($row['clock_in'])) : '-';
                                $clock_out = $row['clock_out'] ? date('H:i', strtotime($row['clock_out'])) : '-';
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($row['username']) ?></strong><br>
                                <small style="color:#888;">รหัส: <?= htmlspecialchars($row['userid']) ?></small>
                            </td>
                            <td>
                                <span style="color:#4e73df; font-weight:bold; font-size:13px;"><?= $row['shift_name'] ?? 'ไม่ระบุกะ' ?></span>
                                <?php if($row['start_time']): ?>
                                    <span class="shift-info">ต้องเข้า: <?= date('H:i', strtotime($row['start_time'])) ?> น.</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center; font-weight:bold;"><?= $clock_in ?></td>
                            <td style="text-align:center; font-weight:bold;"><?= $clock_out ?></td>
                            <td style="text-align:center;">
                                <?php if($row['late_minutes'] > 0): ?>
                                    <span class="late-badge"><i class="fa-solid fa-triangle-exclamation"></i> สาย <?= $row['late_minutes'] ?> นาที</span>
                                <?php elseif($row['clock_in']): ?>
                                    <span class="ontime-badge"><i class="fa-solid fa-check"></i> ตรงเวลา</span>
                                <?php else: ?>
                                    <span style="color:#ccc; font-size:12px;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php } } else { echo "<tr><td colspan='5' style='text-align:center; padding: 20px; color:#888;'>ไม่มีข้อมูลสแกนนิ้วในวันนี้</td></tr>"; } ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
    <?php if($status_msg == 'saved'): ?>
        Swal.fire({ icon: 'success', title: 'บันทึกเวลาสำเร็จ', text: 'ระบบคำนวณการมาสายอัตโนมัติแล้ว', showConfirmButton: false, timer: 1500 });
        window.history.replaceState(null, null, window.location.pathname + '?date=<?= $view_date ?>');
    <?php endif; ?>
</script>