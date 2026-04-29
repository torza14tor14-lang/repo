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
if(isset($_POST['userid'])){
    $userid = mysqli_real_escape_string($conn, $_POST['userid']);
    
    // ค้นหาพนักงาน (ไม่รวม ADMIN)
    $sql = "SELECT username, dept, status FROM employees WHERE userid = '$userid' AND role != 'ADMIN' LIMIT 1";
    $result = mysqli_query($conn, $sql);
    
    if($row = mysqli_fetch_assoc($result)){
        // 🛑 ตรวจสอบว่าพนักงานลาออกไปหรือยัง (Soft Delete)
        $status = $row['status'] ?? 'Active'; // ดักเผื่อบางเรคคอร์ดยังไม่มีค่า
        
        if ($status === 'Resigned') {
            echo json_encode(['error' => '❌ พนักงานคนนี้ลาออกไปแล้ว']);
        } else {
            // ถ้ายังทำงานอยู่ (Active) ก็ส่งข้อมูลกลับไปแสดงปกติ
            echo json_encode([
                'username' => $row['username'],
                'dept' => $row['dept']
            ]);
        }
    } else {
        echo json_encode(['error' => '❌ ไม่พบข้อมูลพนักงานรหัสนี้']);
    }
}
?>