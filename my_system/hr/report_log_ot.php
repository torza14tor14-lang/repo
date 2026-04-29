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

// ฟังก์ชันแปลงวันที่ ค.ศ. เป็น พ.ศ. (d/m/Y + 543)
function formatThaiDate($date) {
    if (!$date) return '';
    $year = date('Y', strtotime($date)) + 543; // บวก 543 ปี
    return date('d/m/', strtotime($date)) . $year;
}

// รับค่าจาก Form ค้นหา (ถ้าไม่มีให้เป็นค่าว่าง)
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // ค่าเริ่มต้นคือต้นเดือน
$end_date = $_GET['end_date'] ?? date('Y-m-t');      // ค่าเริ่มต้นคือสิ้นเดือน

// คำสั่ง SQL ดึงข้อมูลและรวมยอด เฉพาะรายการที่ "Approved" แล้วเท่านั้น
$sql = "SELECT 
            e.userid, 
            e.username, 
            e.dept,
            SUM(o.ot_1_h * 60 + o.ot_1_m) AS total_1_mins,
            SUM(o.ot_15_h * 60 + o.ot_15_m) AS total_15_mins,
            SUM(o.ot_3_h * 60 + o.ot_3_m) AS total_3_mins,
            SUM(o.shift_fee) AS total_shift,
            SUM(o.diligence_fee) AS total_dil
        FROM employees e
        JOIN ot_records o ON e.userid = o.userid
        WHERE o.ot_date BETWEEN '$start_date' AND '$end_date'
        AND o.status = 'Approved' 
        GROUP BY e.userid, e.username, e.dept
        HAVING (total_1_mins + total_15_mins + total_3_mins + total_shift + total_dil) > 0
        ORDER BY e.dept ASC, e.userid ASC";

$result = mysqli_query($conn, $sql);

// ฟังก์ชันซ่อนเลข 0 และแปลงนาทีเป็นชั่วโมง
function formatTime($total_mins, $type) {
    if ($total_mins <= 0) return ''; // ถ้าเป็น 0 ให้ปล่อยว่าง
    $h = floor($total_mins / 60);
    $m = $total_mins % 60;
    
    if ($type == 'H') return $h > 0 ? $h : '';
    if ($type == 'M') return $m > 0 ? $m : '';
    return '';
}

function formatMoney($val) {
    return $val > 0 ? number_format($val) : '';
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Top Feed Mills | รายงานสรุปการทำ OT</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f4f7f6;
            margin: 0;
            padding: 20px;
        }

        /* ส่วนควบคุมด้านบน (ซ่อนตอน Print) */
        .control-panel {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
        }
        .control-panel input[type="date"] {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-family: 'Sarabun', sans-serif;
            font-size: 16px;
        }
        .btn-search { background: #4e73df; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
        .btn-print { background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }

        /* การตั้งค่าหน้ากระดาษ A4 */
        .a4-page {
            background: white;
            width: 210mm; /* ปรับความกว้างเป็นแนวตั้ง */
            min-height: 297mm; /* ปรับความสูงเป็นแนวตั้ง */
            margin: 0 auto;
            padding: 10mm;
            box-shadow: 0 0 15px rgba(0,0,0,0.2);
            box-sizing: border-box;
        }

        /* หัวกระดาษ */
        .report-header { text-align: center; margin-bottom: 20px; }
        .report-header h2 { margin: 0 0 5px 0; font-size: 20px; }
        .report-header p { margin: 0; font-size: 14px; }
        .print-date { text-align: right; font-size: 12px; margin-bottom: 10px; }

        /* ตารางรายงาน */
        table.report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px; /* ลดฟอนต์ลงเพื่อให้พอดีกับกระดาษแนวตั้ง */
        }
        table.report-table th, table.report-table td {
            border: 1px solid #000;
            padding: 5px 3px;
            text-align: center;
            vertical-align: middle;
        }
        table.report-table th { background-color: #ffffff; font-weight: 600; font-size: 13px; }
        .text-left { text-align: left !important; padding-left: 8px !important; }

        /* ซ่อน Control Panel และจัดหน้าตอนสั่ง Print */
        @media print {
            body { background: white; padding: 0; margin: 0; }
            .control-panel { display: none; }
            .a4-page { 
                box-shadow: none; 
                width: 100%; 
                padding: 0; 
                margin: 0; 
                page-break-after: always;
            }
            /* เปลี่ยนเป็น A4 portrait */
            @page { size: A4 portrait; margin: 10mm; }
        }
    </style>
</head>
<body>

    <div class="control-panel">
        <form method="GET" style="display: flex; gap: 10px; align-items: center;">
            <strong>เรียกข้อมูลตั้งแต่วันที่:</strong>
            <input type="date" name="start_date" value="<?php echo $start_date; ?>" required>
            <strong>ถึง:</strong>
            <input type="date" name="end_date" value="<?php echo $end_date; ?>" required>
            <button type="submit" class="btn-search">🔍 ดึงข้อมูล</button>
            <button type="button" class="btn-print" onclick="window.print()">🖨️ Print</button>
            <a href="manage_ot.php" style="margin-left:20px; text-decoration:none; color:#666;">กลับหน้าหลัก</a>
        </form>
    </div>

    <div class="a4-page">        
        <div class="report-header">
            <p>ประวัติการทำ OT ตั้งแต่วันที่ <?php echo formatThaiDate($start_date); ?> ถึงวันที่ <?php echo formatThaiDate($end_date); ?></p>
        </div>

        <table class="report-table">
            <thead>
                <tr>
                    <th rowspan="2" style="width:30px;">ลำดับ</th>
                    <th rowspan="2" style="width:55px;">รหัส</th>
                    <th rowspan="2" style="width:160px;">ชื่อ-นามสกุล</th>
                    <th rowspan="2" style="width:100px;">แผนก</th>
                    <th colspan="2">OT 1.0</th>
                    <th colspan="2">OT 1.5</th>
                    <th colspan="2">OT 3.0</th>
                    <th rowspan="2" style="width:60px;">ค่ากะ<br><span style="font-size:9px;">(บาท)</span></th>
                    <th rowspan="2" style="width:60px;">เบี้ยขยัน<br><span style="font-size:9px;">(บาท)</span></th>
                </tr>
                <tr style="font-size:10px;">
                    <th style="width:20px;">ชม.</th><th style="width:20px;">น.</th>
                    <th style="width:20px;">ชม.</th><th style="width:20px;">น.</th>
                    <th style="width:20px;">ชม.</th><th style="width:20px;">น.</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $i = 1;
                $sum_all_1_mins = 0; $sum_all_15_mins = 0; $sum_all_3_mins = 0;
                $sum_all_shift = 0; $sum_all_dil = 0;

                if (mysqli_num_rows($result) > 0) {
                    while($row = mysqli_fetch_assoc($result)) { 
                        // เก็บยอดรวมไว้ทำสรุปท้ายตาราง
                        $sum_all_1_mins += $row['total_1_mins'];
                        $sum_all_15_mins += $row['total_15_mins'];
                        $sum_all_3_mins += $row['total_3_mins'];
                        $sum_all_shift += $row['total_shift'];
                        $sum_all_dil += $row['total_dil'];
                ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td><?php echo $row['userid']; ?></td>
                    <td class="text-left"><?php echo $row['username']; ?></td>
                    <td><?php echo $row['dept']; ?></td>
                    
                    <td><?php echo formatTime($row['total_1_mins'], 'H'); ?></td>
                    <td><?php echo formatTime($row['total_1_mins'], 'M'); ?></td>
                    
                    <td><?php echo formatTime($row['total_15_mins'], 'H'); ?></td>
                    <td><?php echo formatTime($row['total_15_mins'], 'M'); ?></td>
                    
                    <td><?php echo formatTime($row['total_3_mins'], 'H'); ?></td>
                    <td><?php echo formatTime($row['total_3_mins'], 'M'); ?></td>
                    
                    <td><?php echo formatMoney($row['total_shift']); ?></td>
                    <td><?php echo formatMoney($row['total_dil']); ?></td>
                </tr>
                <?php 
                    }
                } else {
                    echo "<tr><td colspan='12' style='padding: 20px; color: red;'>ไม่พบข้อมูลอนุมัติ OT ในช่วงเวลาที่ระบุ</td></tr>";
                }
                ?>
            </tbody>
            <tfoot>
                <tr style="font-weight: bold; background-color: #ffffff;">
                    <td colspan="4" style="text-align: right; padding-right: 20px;">รวมทั้งหมด</td>
                    <td><?php echo formatTime($sum_all_1_mins, 'H'); ?></td>
                    <td><?php echo formatTime($sum_all_1_mins, 'M'); ?></td>
                    <td><?php echo formatTime($sum_all_15_mins, 'H'); ?></td>
                    <td><?php echo formatTime($sum_all_15_mins, 'M'); ?></td>
                    <td><?php echo formatTime($sum_all_3_mins, 'H'); ?></td>
                    <td><?php echo formatTime($sum_all_3_mins, 'M'); ?></td>
                    <td><?php echo formatMoney($sum_all_shift); ?></td>
                    <td><?php echo formatMoney($sum_all_dil); ?></td>
                </tr>
            </tfoot>
        </table>

        <div style="margin-top: 50px; display: flex; justify-content: space-between; padding: 0 50px;">
            <div>ผู้รายงาน ...................................</div>
            <div>ผู้อนุมัติ ....................................</div>
        </div>
    </div>

</body>
</html>