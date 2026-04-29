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

$status = '';
if (isset($_POST['add_holiday'])) {
    $date = $_POST['h_date'];
    $name = mysqli_real_escape_string($conn, $_POST['h_name']);
    
    $sql = "INSERT INTO company_holidays (holiday_date, holiday_name) VALUES ('$date', '$name')";
    if (mysqli_query($conn, $sql)) { $status = 'success'; }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    mysqli_query($conn, "DELETE FROM company_holidays WHERE id = '$id'");
    header("Location: manage_holidays.php"); exit();
}

include '../sidebar.php';
?>

<title>จัดการวันหยุด | Top Feed Mills</title>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .h-card { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-bottom: 25px; }
    .form-control { width: 100%; padding: 12px; border: 1.5px solid #e2e8f0; border-radius: 10px; margin-bottom: 15px; font-family: 'Sarabun'; box-sizing: border-box; }
    .btn-add { background: #e74a3b; color: white; border: none; padding: 12px; width: 100%; border-radius: 10px; font-weight: bold; cursor: pointer; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f8f9fc; padding: 12px; text-align: left; color: #4e73df; border-bottom: 2px solid #eaecf4; }
    td { padding: 12px; border-bottom: 1px solid #f1f1f1; }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50;"><i class="fa-solid fa-calendar-day" style="color: #e74a3b;"></i> จัดการวันหยุดบริษัท</h2>
    
    <div style="display: grid; grid-template-columns: 350px 1fr; gap: 25px;">
        <div class="h-card" style="border-top: 5px solid #e74a3b;">
            <h4>📌 เพิ่มวันหยุดพิเศษ</h4>
            <form method="POST">
                <label style="font-size:14px; font-weight:bold;">วันที่หยุด</label>
                <input type="date" name="h_date" class="form-control" required>
                
                <label style="font-size:14px; font-weight:bold;">ชื่อวันหยุด / หมายเหตุ</label>
                <input type="text" name="h_name" class="form-control" placeholder="เช่น วันสงกรานต์" required>
                
                <button type="submit" name="add_holiday" class="btn-add"><i class="fa-solid fa-plus"></i> บันทึกวันหยุด</button>
            </form>
        </div>

        <div class="h-card">
            <h4>📋 รายการวันหยุดนักขัตฤกษ์ที่บันทึกไว้</h4>
            <table>
                <thead>
                    <tr>
                        <th>ว/ด/ย</th>
                        <th>ชื่อวันหยุด</th>
                        <th style="text-align:right;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $res = mysqli_query($conn, "SELECT * FROM company_holidays ORDER BY holiday_date ASC");
                    while($row = mysqli_fetch_assoc($res)) {
                    ?>
                    <tr>
                        <td><strong><?php echo date('d/m/Y', strtotime($row['holiday_date'])); ?></strong></td>
                        <td><?php echo $row['holiday_name']; ?></td>
                        <td style="text-align:right;">
                            <a href="?delete=<?php echo $row['id']; ?>" onclick="return confirm('ลบวันหยุดนี้?')" style="color:#e74a3b;"><i class="fa-solid fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if($status == 'success'): ?>
<script>Swal.fire({ icon: 'success', title: 'บันทึกสำเร็จ', showConfirmButton: false, timer: 1500 });</script>
<?php endif; ?>