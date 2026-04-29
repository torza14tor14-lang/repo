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

// จัดการการเพิ่มกะใหม่
if (isset($_POST['add_shift'])) {
    $name = mysqli_real_escape_string($conn, $_POST['shift_name']);
    $start = $_POST['start_time'];
    $end = $_POST['end_time'];
    $grace = (int)$_POST['grace_period'];
    
    $sql = "INSERT INTO work_shifts (shift_name, start_time, end_time, grace_period) 
            VALUES ('$name', '$start', '$end', '$grace')";
    if (mysqli_query($conn, $sql)) { $status_msg = 'added'; }
}

// จัดการการลบกะ
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    mysqli_query($conn, "DELETE FROM work_shifts WHERE id = '$id'");
    header("Location: manage_shifts.php?msg=deleted"); exit();
}
$msg = $_GET['msg'] ?? '';

include '../sidebar.php';
?>

<title>จัดการกะการทำงาน | Top Feed Mills</title>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .shift-card { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-bottom: 25px; border-top: 5px solid #36b9cc; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 5px; color: #5a5c69; font-size: 14px; }
    .form-control { width: 100%; padding: 10px 15px; border: 1.5px solid #eaecf4; border-radius: 8px; font-family: 'Sarabun'; outline: none; transition: 0.3s; box-sizing: border-box; }
    .form-control:focus { border-color: #36b9cc; }
    .btn-submit { background: #36b9cc; color: white; border: none; padding: 12px; border-radius: 8px; width: 100%; font-weight: bold; cursor: pointer; transition: 0.3s; font-family: 'Sarabun'; }
    .btn-submit:hover { background: #2c9faf; }
    
    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    th { background: #f8f9fc; padding: 12px; text-align: left; color: #4e73df; border-bottom: 2px solid #eaecf4; font-size: 14px; }
    td { padding: 12px; border-bottom: 1px solid #f1f1f1; font-size: 14px; vertical-align: middle; }
    .time-badge { background: #e3fdfd; color: #118a9b; padding: 4px 10px; border-radius: 6px; font-weight: bold; font-size: 13px; }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top:0;"><i class="fa-solid fa-business-time" style="color: #36b9cc;"></i> ตั้งค่ากะการทำงาน (Shift Settings)</h2>

    <div style="display: grid; grid-template-columns: 350px 1fr; gap: 25px;">
        
        <div class="shift-card">
            <h4 style="margin-top:0; color:#2c3e50;">📌 สร้างกะใหม่</h4>
            <form method="POST">
                <div class="form-group">
                    <label>ชื่อกะการทำงาน</label>
                    <input type="text" name="shift_name" class="form-control" placeholder="เช่น กะดึก (คลังสินค้า)" required>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label>เวลาเข้างาน</label>
                        <input type="time" name="start_time" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>เวลาเลิกงาน</label>
                        <input type="time" name="end_time" class="form-control" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>สายได้ไม่เกิน (นาที)</label>
                    <input type="number" name="grace_period" class="form-control" value="0" min="0" placeholder="ระบุนานทีที่อนุโลม">
                    <small style="color: #888;">*หากระบุ 15 หมายถึง สแกนเข้า 08:15 ไม่ถือว่าสาย</small>
                </div>
                <button type="submit" name="add_shift" class="btn-submit"><i class="fa-solid fa-plus"></i> บันทึกกะการทำงาน</button>
            </form>
        </div>

        <div class="shift-card" style="border-top-color: #4e73df;">
            <h4 style="margin-top:0; color:#2c3e50;">📋 รายการกะในระบบ</h4>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ชื่อกะการทำงาน</th>
                            <th>เวลาเข้า - ออก</th>
                            <th>อนุโลมสาย (นาที)</th>
                            <th style="text-align:center;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $res = mysqli_query($conn, "SELECT * FROM work_shifts ORDER BY start_time ASC");
                        if(mysqli_num_rows($res) > 0) {
                            while($row = mysqli_fetch_assoc($res)) {
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['shift_name']) ?></strong></td>
                            <td>
                                <span class="time-badge"><i class="fa-regular fa-clock"></i> <?= date('H:i', strtotime($row['start_time'])) ?> - <?= date('H:i', strtotime($row['end_time'])) ?></span>
                            </td>
                            <td><?= $row['grace_period'] ?> นาที</td>
                            <td style="text-align:center;">
                                <a href="?delete=<?= $row['id'] ?>" onclick="return confirm('ต้องการลบกะ <?= $row['shift_name'] ?> ใช่หรือไม่?')" style="color:#e74a3b; padding: 5px;"><i class="fa-solid fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php } } else { echo "<tr><td colspan='4' style='text-align:center; padding: 20px;'>ยังไม่มีข้อมูลกะการทำงาน</td></tr>"; } ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
    <?php if($status_msg == 'added'): ?>
        Swal.fire({ icon: 'success', title: 'เพิ่มกะการทำงานสำเร็จ', showConfirmButton: false, timer: 1500 });
        window.history.replaceState(null, null, 'manage_shifts.php');
    <?php elseif($msg == 'deleted'): ?>
        Swal.fire({ icon: 'success', title: 'ลบข้อมูลสำเร็จ', showConfirmButton: false, timer: 1500 });
        window.history.replaceState(null, null, 'manage_shifts.php');
    <?php endif; ?>
</script>