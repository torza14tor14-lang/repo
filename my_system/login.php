<?php
session_start();
include 'db.php';

// ถ้า Login แล้วให้ไปหน้าแรก
if(isset($_SESSION['userid'])) { header("Location: index.php"); exit(); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $userid = mysqli_real_escape_string($conn, $_POST['userid']);
    $password = $_POST['password'];

    // ค้นหาพนักงานที่ยังไม่ลาออก (Active)
    $sql = "SELECT * FROM employees WHERE userid = '$userid' AND status = 'Active'";
    $result = mysqli_query($conn, $sql);

    if ($row = mysqli_fetch_assoc($result)) {
        // ตรวจสอบรหัสผ่าน (รองรับทั้ง Hash และ Plain text ในช่วงย้ายระบบ)
        if (password_verify($password, $row['password']) || $password === $row['password']) {
            
            // อัปเกรดรหัสเป็น Hash อัตโนมัติ
            if ($password === $row['password']) {
                $new_hash = password_hash($password, PASSWORD_DEFAULT);
                mysqli_query($conn, "UPDATE employees SET password = '$new_hash' WHERE userid = '$userid'");
            }

            // สร้าง Session
            $_SESSION['userid']   = $row['userid'];
            $_SESSION['fullname'] = $row['username'];
            $_SESSION['role']     = $row['role'];
            $_SESSION['dept']     = $row['dept'];

            log_event($conn, 'LOGIN', 'employees', "เข้าสู่ระบบสำเร็จ");
            header("Location: index.php");
            exit();
        } else {
            $error = 'รหัสผ่านไม่ถูกต้อง';
        }
    } else {
        $error = 'ไม่พบรหัสพนักงาน หรือบัญชีถูกระงับ';
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ | Top Feed Mills</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { margin: 0; font-family: 'Sarabun', sans-serif; background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-wrapper { display: flex; background: white; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.2); overflow: hidden; width: 900px; max-width: 95%; height: 500px; animation: slideUp 0.6s ease; }
        
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

        /* ฝั่งซ้าย รูปภาพ/แบนเนอร์ */
        .login-image { flex: 1; background: url('http://www.panuspoultry.co.th/images/feedmill.jpg') center/cover; position: relative; }
        .login-image::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(to bottom, rgba(78, 115, 223, 0.4), rgba(44, 62, 80, 0.8)); }
        .image-text { position: absolute; bottom: 40px; left: 40px; color: white; z-index: 1; }
        .image-text h2 { margin: 0 0 10px 0; font-size: 28px; }
        .image-text p { margin: 0; font-size: 15px; opacity: 0.8; }

        /* ฝั่งขวา ฟอร์มล็อคอิน */
        .login-form-container { flex: 1; padding: 50px 40px; display: flex; flex-direction: column; justify-content: center; }
        .login-form-container h3 { margin: 0 0 5px 0; color: #2c3e50; font-size: 24px; text-align: center; }
        .login-form-container p.subtitle { text-align: center; color: #858796; font-size: 14px; margin-bottom: 30px; }
        
        .form-group { margin-bottom: 20px; position: relative; }
        .form-group i { position: absolute; top: 15px; left: 15px; color: #b7b9cc; font-size: 18px; }
        .form-control { width: 100%; padding: 14px 15px 14px 45px; border: 2px solid #eaecf4; border-radius: 10px; font-family: 'Sarabun'; font-size: 15px; box-sizing: border-box; transition: 0.3s; }
        .form-control:focus { border-color: #4e73df; outline: none; box-shadow: 0 0 0 3px rgba(78, 115, 223, 0.1); }
        
        .btn-login { background: #4e73df; color: white; width: 100%; padding: 14px; border: none; border-radius: 10px; font-size: 16px; font-weight: bold; cursor: pointer; transition: 0.3s; margin-top: 10px; }
        .btn-login:hover { background: #2e59d9; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(78, 115, 223, 0.3); }

        .error-msg { background: #ffe5e5; color: #e74a3b; padding: 10px 15px; border-radius: 8px; font-size: 14px; margin-bottom: 20px; text-align: center; border: 1px solid #f5c6cb; }

        @media (max-width: 768px) { .login-image { display: none; } .login-wrapper { height: auto; padding: 20px 0; } }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-image">
        <div class="image-text">
            <h2>Top Feed Mills</h2>
            <p>ERP System & Management Portal</p>
        </div>
    </div>
    <div class="login-form-container">
        <div style="text-align: center; margin-bottom: 20px;">
            <i class="fa-solid fa-industry" style="font-size: 40px; color: #4e73df;"></i>
        </div>
        <h3>ยินดีต้อนรับกลับมา</h3>
        <p class="subtitle">กรุณาเข้าสู่ระบบเพื่อดำเนินการต่อ</p>

        <?php if($error): ?>
            <div class="error-msg"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <i class="fa-solid fa-user"></i>
                <input type="text" name="userid" class="form-control" placeholder="รหัสพนักงาน (ID)" required autocomplete="off">
            </div>
            <div class="form-group">
                <i class="fa-solid fa-lock"></i>
                <input type="password" name="password" class="form-control" placeholder="รหัสผ่าน" required>
            </div>
            <button type="submit" name="login" class="btn-login">เข้าสู่ระบบ</button>
        </form>
    </div>
</div>

</body>
</html>