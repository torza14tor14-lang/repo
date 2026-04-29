<?php
session_start();
include '../db.php';

// สเต็ปที่ 1: ตรวจสอบว่า "ล็อกอินหรือยัง?" (ถ้ายัง ให้เด้งไปหน้า login ทันที)
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

// ตรวจสอบสิทธิ์
$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
// ให้ Admin, คลังสินค้า และ ฝ่ายผลิต ดูประวัติได้
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && $user_dept !== 'แผนกคลังสินค้า 1' && $user_dept !== 'แผนกคลังสินค้า 2' && $user_dept !== 'ฝ่ายจัดซื้อ' && $user_dept !== 'ฝ่ายขาย' && $user_dept !== 'แผนกผลิต 1' && $user_dept !== 'แผนกผลิต 2') {
    echo "<script>alert('ไม่มีสิทธิ์เข้าถึง'); window.location='../index.php';</script>"; exit(); 
}

include '../sidebar.php';
?>

<title>ประวัติความเคลื่อนไหว | Top Feed Mills</title>
<style>
    .card-full { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f8f9fc; padding: 15px; text-align: left; color: #5a5c69; border-bottom: 2px solid #eaecf4; font-size: 14px; }
    td { padding: 12px 15px; border-bottom: 1px solid #eaecf4; color: #4a5568; font-size: 15px; }
    tr:hover { background: #f8f9fc; }
    
    .type-in { color: #1cc88a; font-weight: bold; background: #e3fdfd; padding: 4px 10px; border-radius: 6px; }
    .type-out { color: #e74a3b; font-weight: bold; background: #ffe5e5; padding: 4px 10px; border-radius: 6px; }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top:0;"><i class="fa-solid fa-clock-rotate-left" style="color: #858796;"></i> ประวัติความเคลื่อนไหวสต็อก (Stock Log)</h2>
    <p style="color: #888; margin-bottom: 20px;">บันทึกการ รับเข้า-เบิกออก และการตัดสต็อกจากการผลิตแบบ Real-time</p>

    <div class="card-full">
        <table>
            <thead>
                <tr>
                    <th>วัน-เวลา ที่บันทึก</th>
                    <th>รายการสินค้า/วัตถุดิบ</th>
                    <th>ประเภทรายการ</th>
                    <th style="text-align:right;">จำนวน</th>
                    <th>คำอธิบาย (อ้างอิง)</th>
                    <th>ผู้ทำรายการ</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // ดึงข้อมูลประวัติเรียงจากล่าสุดไปเก่าสุด
                $sql_log = "SELECT l.*, p.p_name, p.p_code 
                            FROM stock_log l 
                            JOIN products p ON l.product_id = p.id 
                            ORDER BY l.created_at DESC LIMIT 100"; // โชว์ 100 รายการล่าสุด
                $logs = mysqli_query($conn, $sql_log);
                
                if (mysqli_num_rows($logs) > 0) {
                    while($log = mysqli_fetch_assoc($logs)) {
                        $is_in = ($log['type'] == 'IN');
                        $type_class = $is_in ? 'type-in' : 'type-out';
                        $type_text = $is_in ? '<i class="fa-solid fa-arrow-down"></i> รับเข้า' : '<i class="fa-solid fa-arrow-up"></i> เบิกออก';
                        $sign = $is_in ? '+' : '-';
                ?>
                <tr>
                    <td><small style="color:#888;"><?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?></small></td>
                    <td>
                        <strong><?php echo $log['p_name']; ?></strong><br>
                        <span style="font-size: 12px; color: #aaa;">[<?php echo $log['p_code']; ?>]</span>
                    </td>
                    <td><span class="<?php echo $type_class; ?>"><?php echo $type_text; ?></span></td>
                    <td style="text-align:right;">
                        <strong class="<?php echo $type_class; ?>"><?php echo $sign . number_format($log['qty'], 2); ?></strong>
                    </td>
                    <td><?php echo $log['reference']; ?></td>
                    <td><i class="fa-regular fa-user" style="color:#ccc;"></i> <?php echo $log['action_by']; ?></td>
                </tr>
                <?php 
                    }
                } else {
                    echo "<tr><td colspan='6' style='text-align:center; color:#888; padding:30px;'>ยังไม่มีประวัติการทำรายการ</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>