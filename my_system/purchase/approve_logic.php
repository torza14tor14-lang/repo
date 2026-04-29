<?php
session_start();
include '../db.php';

// สเต็ปที่ 1: ตรวจสอบว่า "ล็อกอินหรือยัง?" (ถ้ายัง ให้เด้งไปหน้า login ทันที)
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

// รับค่าพารามิเตอร์จาก URL
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';
$type = isset($_GET['type']) ? $_GET['type'] : ''; // รับค่าว่าเป็น 'PO' หรือ 'OT'

// ป้องกันการเข้าถึงโดยตรงแบบไม่มีข้อมูล
if ($id === 0 || $action === '' || $type === '') {
    die("ข้อมูลไม่ถูกต้อง หรือไม่ครบถ้วน");
}

// 1. แปลงค่า Action เป็น Status ที่จะบันทึกลงฐานข้อมูล
$new_status = '';
$msg_type = ''; // ส่งข้อความกลับไปแจ้งเตือน

if ($action === 'step1') {
    $new_status = 'Manager_Approved';
    $msg_type = 'approved';
} elseif ($action === 'step2') {
    $new_status = 'Approved';
    $msg_type = 'approved';
} elseif ($action === 'reject') {
    $new_status = 'Rejected';
    $msg_type = 'rejected'; // ถ้าปฏิเสธ ให้ส่งคำว่า rejected กลับไป
} else {
    die("รูปแบบคำสั่ง (Action) ไม่ถูกต้อง");
}

// ------------------------------------------------------------------
// ส่วนที่ 1: จัดการอนุมัติสำหรับ "ใบสั่งซื้อ (PO)"
// ------------------------------------------------------------------
if ($type === 'PO') {
    $query = "UPDATE purchase_orders SET status = '$new_status' WHERE po_id = '$id'";
    
    if (mysqli_query($conn, $query)) {
        // สำเร็จ ให้เด้งกลับไปหน้า ติดตามใบสั่งซื้อ
        header("Location: view_pos.php?msg=" . $msg_type);
        exit();
    } else {
        echo "เกิดข้อผิดพลาดในการอัปเดต PO: " . mysqli_error($conn);
    }
}

// ------------------------------------------------------------------
// ส่วนที่ 2: จัดการอนุมัติสำหรับ "บันทึกล่วงเวลา (OT)"
// ------------------------------------------------------------------
elseif ($type === 'OT') {
    // อัปเดตตาราง ot_records โดยใช้คอลัมน์ id เป็นเงื่อนไข
    $query = "UPDATE ot_records SET status = '$new_status' WHERE id = '$id'";
    
    if (mysqli_query($conn, $query)) {
        // สำเร็จ ให้เด้งกลับไปหน้า ประวัติ OT (สามารถแก้ชื่อไฟล์ได้ถ้าคุณใช้ชื่ออื่น)
        header("Location: history_ot.php?msg=" . $msg_type);
        exit();
    } else {
        echo "เกิดข้อผิดพลาดในการอัปเดต OT: " . mysqli_error($conn);
    }
}

// ------------------------------------------------------------------
// ป้องกันกรณีแอบส่ง Type อื่นมา
// ------------------------------------------------------------------
else {
    die("ประเภทเอกสาร (Type) ไม่ถูกต้อง");
}
?>