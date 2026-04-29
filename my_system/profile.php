<?php
session_start();
include 'db.php'; 

if (empty($_SESSION['userid'])) { 
    header("Location: login.php"); exit(); 
}

$my_userid = $_SESSION['userid'];

// ดึงข้อมูลพนักงาน
$query = mysqli_query($conn, "SELECT * FROM employees WHERE userid = '$my_userid'");
$emp = mysqli_fetch_assoc($query);

$success_msg = "";
$error_msg = "";

// 🚀 จัดการการเปลี่ยนรหัสผ่าน (อัปเกรดรองรับระบบ Hash)
if (isset($_POST['change_password'])) {
    $old_pw = $_POST['old_pw'];
    $new_pw = $_POST['new_pw'];
    $confirm_pw = $_POST['confirm_pw'];

    // ดึงรหัสผ่านเดิมจากฐานข้อมูล
    $current_pw = $emp['password'];

    // 1. ตรวจสอบรหัสผ่านเก่า (รองรับทั้งแบบ Hash และแบบข้อความธรรมดา)
    $is_password_correct = false;
    if (password_verify($old_pw, $current_pw)) {
        $is_password_correct = true; // กรณีเป็น Hash
    } elseif ($old_pw === $current_pw) {
        $is_password_correct = true; // กรณีเป็น Text ธรรมดา (พนักงานเก่า)
    }

    if (!$is_password_correct) {
        $error_msg = "รหัสผ่านปัจจุบันไม่ถูกต้อง!";
    } else if ($new_pw !== $confirm_pw) {
        $error_msg = "รหัสผ่านใหม่ และ ยืนยันรหัสผ่าน ไม่ตรงกัน!";
    } else if (strlen($new_pw) < 6) {
        $error_msg = "รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 6 ตัวอักษร!";
    } else {
        // 2. เข้ารหัส (Hash) รหัสผ่านใหม่ก่อนบันทึก
        $hashed_new_pw = password_hash($new_pw, PASSWORD_DEFAULT);
        
        // อัปเดตรหัสผ่านใหม่ลงฐานข้อมูล
        mysqli_query($conn, "UPDATE employees SET password = '$hashed_new_pw' WHERE userid = '$my_userid'");
        
        $success_msg = "เปลี่ยนรหัสผ่านเรียบร้อยแล้ว! กรุณาใช้รหัสผ่านใหม่ในการเข้าสู่ระบบครั้งถัดไป";
        $emp['password'] = $hashed_new_pw; 
    }
}

include 'sidebar.php'; 
?>

<title>ตั้งค่าโปรไฟล์ | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    .profile-container { max-width: 900px; margin: 0 auto; display: grid; grid-template-columns: 1fr 2fr; gap: 25px; }
    @media (max-width: 768px) { .profile-container { grid-template-columns: 1fr; } }
    
    .card { background: white; border-radius: 15px; padding: 30px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
    .card-header { border-bottom: 2px solid #f1f1f1; padding-bottom: 15px; margin-bottom: 20px; font-size: 18px; font-weight: bold; color: #2c3e50; }
    
    .avatar-section { text-align: center; }
    .avatar-circle { width: 120px; height: 120px; background: #eaecf4; border-radius: 50%; margin: 0 auto 15px auto; display: flex; align-items: center; justify-content: center; font-size: 50px; color: #4e73df; border: 4px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    .user-id-badge { display: inline-block; background: #4e73df; color: white; padding: 5px 15px; border-radius: 50px; font-size: 14px; font-weight: bold; margin-bottom: 10px; }
    
    .info-list { text-align: left; margin-top: 20px; }
    .info-item { margin-bottom: 15px; font-size: 14px; }
    .info-item span { display: block; color: #858796; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 3px; }
    .info-item strong { color: #333; font-size: 15px; }

    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 8px; color: #4a5568; font-size: 14px; }
    .form-control { width: 100%; padding: 12px 15px; border: 1px solid #d1d3e2; border-radius: 8px; font-family: 'Sarabun'; font-size: 15px; transition: 0.3s; background: #f8f9fc; box-sizing: border-box; }
    .form-control:focus { border-color: #4e73df; background: #fff; outline: none; box-shadow: 0 0 0 3px rgba(78,115,223,0.1); }
    
    .btn-save { background: #1cc88a; color: white; border: none; padding: 12px 25px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.3s; width: 100%; font-size: 16px; display: flex; justify-content: center; align-items: center; gap: 8px; }
    .btn-save:hover { background: #17a673; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(28,200,138,0.3); }

    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; font-size: 14px; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: #e3fdfd; border: 1px solid #1cc88a; color: #0f8b5e; }
    .alert-danger { background: #fff5f5; border: 1px solid #e74a3b; color: #be2617; }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top:0; margin-bottom:25px;"><i class="fa-solid fa-user-gear" style="color:#4e73df;"></i> ตั้งค่าโปรไฟล์และความปลอดภัย</h2>

    <?php if($success_msg != ""): ?>
        <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if($error_msg != ""): ?>
        <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo $error_msg; ?></div>
    <?php endif; ?>

    <div class="profile-container">
        
        <div class="card avatar-section">
            <div class="avatar-circle">
                <i class="fa-solid fa-user-tie"></i>
            </div>
            <div class="user-id-badge">ID: <?php echo $emp['userid']; ?></div>
            <h3 style="margin: 0 0 5px 0; color: #2c3e50;"><?php echo $emp['username']; ?></h3>
            <p style="margin: 0; color: #1cc88a; font-weight: bold; font-size: 14px;"><i class="fa-solid fa-circle"></i> สถานะ: พนักงาน (Active)</p>
            
            <hr style="border: 1px dashed #eaecf4; margin: 20px 0;">
            
            <div class="info-list">
                <div class="info-item"><span>แผนกสังกัด</span><strong><i class="fa-solid fa-users" style="color:#ccc;"></i> <?php echo $emp['dept'] ?: '-'; ?></strong></div>
                <div class="info-item"><span>บทบาทในระบบ (Role)</span><strong><i class="fa-solid fa-shield-halved" style="color:#ccc;"></i> <?php echo $emp['role'] ?: 'USER'; ?></strong></div>
                <div class="info-item"><span>เลขที่บัญชีธนาคาร</span><strong><i class="fa-solid fa-building-columns" style="color:#ccc;"></i> <?php echo $emp['bank_account'] ?: 'ยังไม่ระบุ'; ?></strong></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="fa-solid fa-lock" style="color:#f6c23e;"></i> เปลี่ยนรหัสผ่าน (Change Password)</div>
            
            <form method="POST">
                <div class="form-group">
                    <label>รหัสผ่านปัจจุบัน</label>
                    <input type="password" name="old_pw" class="form-control" placeholder="กรอกรหัสผ่านที่ใช้ล็อกอินปัจจุบัน" required>
                </div>
                
                <div class="form-group">
                    <label>รหัสผ่านใหม่</label>
                    <input type="password" name="new_pw" class="form-control" placeholder="ตั้งรหัสผ่านใหม่อย่างน้อย 6 ตัวอักษร" required minlength="6">
                </div>
                
                <div class="form-group">
                    <label>ยืนยันรหัสผ่านใหม่</label>
                    <input type="password" name="confirm_pw" class="form-control" placeholder="กรอกรหัสผ่านใหม่อีกครั้งให้ตรงกัน" required minlength="6">
                </div>
                
                <button type="submit" name="change_password" class="btn-save">
                    <i class="fa-solid fa-floppy-disk"></i> บันทึกรหัสผ่านใหม่
                </button>
            </form>
        </div>

    </div>
</div>