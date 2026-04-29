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
    $year = date('Y', strtotime($date)) + 543;
    return date('d/m/', strtotime($date)) . $year;
}

function thai_date($date) {
    if(empty($date)) return "-";
    $th_months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    $d = date("d", strtotime($date));
    $m = $th_months[date("n", strtotime($date))];
    $y = date("Y", strtotime($date)) + 543;
    return (int)$d . " " . $m . " " . $y;
}

// รับค่าจาก Form ค้นหา
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-01-01');
$end_date   = isset($_GET['end_date'])   ? $_GET['end_date']   : date('Y-12-31');
$sel_dept   = isset($_GET['sel_dept'])   ? $_GET['sel_dept']   : '';
$sel_user   = isset($_GET['sel_user'])   ? $_GET['sel_user']   : '';

// สร้างเงื่อนไขการกรอง
$where_clauses = ["e.role != 'ADMIN'"];
if ($sel_dept != '') { $where_clauses[] = "e.dept = '$sel_dept'"; }
if ($sel_user != '') { $where_clauses[] = "(e.username LIKE '%$sel_user%' OR e.userid = '$sel_user')"; }
$where_sql = implode(" AND ", $where_clauses);

// SQL ดึงข้อมูล OT และสรุปยอด (เพิ่ม AND o.status = 'Approved' ตรง LEFT JOIN)
$sql = "SELECT e.userid, e.username, e.dept,
    SUM(o.ot_1_h) as h1, SUM(o.ot_1_m) as m1,
    SUM(o.ot_15_h) as h15, SUM(o.ot_15_m) as m15,
    SUM(o.ot_3_h) as h3, SUM(o.ot_3_m) as m3,
    SUM(o.shift_fee) as total_shift,
    SUM(o.diligence_fee) as total_dil
FROM employees e
LEFT JOIN ot_records o ON e.userid = o.userid 
    AND o.ot_date BETWEEN '$start_date' AND '$end_date' 
    AND o.status = 'Approved'
WHERE $where_sql
GROUP BY e.userid
ORDER BY FIELD(e.dept, 'แผนกผลิต 1', 'แผนกคลังสินค้า 1', 'แผนกซ่อมบำรุง 1', 'แผนกไฟฟ้า 1', 'ฝ่ายวิชาการ', 'แผนก QA', 'แผนก P&M - 1', 'แผนก QC', 'ฝ่ายขาย', 'ฝ่ายจัดซื้อ', 'ฝ่ายบัญชี', 'ฝ่ายสินเชื่อ', 'ฝ่ายการเงิน', 'ฝ่าย HR', 'ฝ่ายงานวางแผน', 'แผนกคอมพิวเตอร์', 'บัญชี - ท็อปธุรกิจ', 'นักศึกษาฝึกงาน', 'ผลิตอาหารสัตว์น้ำ', 'แผนกผลิต 2', 'แผนกคลังสินค้า 2', 'แผนกซ่อมบำรุง 2', 'แผนกไฟฟ้า 2', 'แผนก P&M - 2' ) ASC, e.userid ASC";

$result = mysqli_query($conn, $sql);
$dept_query = mysqli_query($conn, "SELECT DISTINCT dept FROM employees WHERE role != 'ADMIN' ORDER BY dept ASC");

include '../sidebar.php';
?>

<style>
    .print-area { width: 100%; padding: 20px; background: white; font-family: 'Sarabun', sans-serif; color: #000; }
    
    .report-table { width: 100%; border-collapse: collapse; margin-top: 10px; color: #000; }
    .report-table th, .report-table td { border: 1px solid #000; padding: 4px 2px; font-size: 11px; text-align: center; color: #000; word-wrap: break-word; }
    .report-table th { background: #ffffff; vertical-align: middle; font-weight: bold; }
    
    /* Search Box */
    .no-print-box { 
        background: #e4fffea1; padding: 20px; border-radius: 12px; margin-bottom: 25px; 
        border: 1px solid #e0e0e0; display: flex; flex-wrap: wrap; gap: 15px; justify-content: center;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    .filter-group { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: bold; }
    .filter-group input, .filter-group select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 20px; outline: none; }
    .btn-action { padding: 8px 20px; border-radius: 20px; border: none; cursor: pointer; font-weight: bold; transition: 0.3s; }
    .btn-search { background: #4e73df; color: white; } 
    .btn-print { background: #000; color: white; }

    @media print {
        @page { size: portrait; margin: 10mm; }
        body { background: white; -webkit-print-color-adjust: exact; }
        .no-print-box, .sidebar, .topbar { display: none !important; }
        .main-content { margin: 0 !important; padding: 0 !important; }
        .print-area { padding: 0; width: 100%; }
        
        /* บังคับให้หัวตาราง (thead) แสดงทุกหน้า */
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
        
        .report-table { width: 100% !important; }
        tr { page-break-inside: avoid; }
    }
</style>

<title>Top Feed Mills | พิมพ์ใบ OT</title>
<div class="print-area">
    <div class="no-print-box">
        <form method="GET" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: center;">
            <div class="filter-group">
                <span>📅 ช่วงวันที่:</span>
                <input type="date" name="start_date" value="<?php echo $start_date; ?>">
                <span>ถึง</span>
                <input type="date" name="end_date" value="<?php echo $end_date; ?>">
            </div>
            <div class="filter-group">
                <span>🏢 แผนก:</span>
                <select name="sel_dept">
                    <option value="">-- ทั้งหมด --</option>
                    <?php while($d = mysqli_fetch_assoc($dept_query)): ?>
                        <option value="<?php echo $d['dept']; ?>" <?php echo ($sel_dept == $d['dept']) ? 'selected' : ''; ?>>
                            <?php echo $d['dept']; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="filter-group">
                <span>👤 ชื่อ/รหัส:</span>
                <input type="text" name="sel_user" placeholder="ระบุเพื่อพิมพ์รายคน" value="<?php echo $sel_user; ?>">
            </div>
            <button type="submit" class="btn-action btn-search">🔍 ค้นหา</button>
            <button type="button" onclick="window.print()" class="btn-action btn-print">🖨️ พิมพ์รายงาน</button>
            <a href="report_ot.php" style="font-size:12px; color:gray; text-decoration:none;">ล้างค่า</a>
        </form>
    </div>
    
    <table class="report-table">
        <thead>
            <tr>
                <th colspan="12" style="border: none; background: white; padding-bottom: 10px;">
                    <div style="font-size: 18px; font-weight: bold; text-align: center; margin-bottom: 10px;">บริษัท ท็อป ฟีด มิลส์ จำกัด</div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-end; font-size: 12px; border-bottom: 2px solid #000; padding-bottom: 5px;">
                        <span>ประวัติการทำ OT ตั้งแต่วันที่ <?php echo formatThaiDate($start_date); ?> ถึงวันที่ <?php echo formatThaiDate($end_date); ?></span>
                        <span>วันที่พิมพ์: <?php echo thai_date(date('Y-m-d')); ?></span>
                    </div>
                </th>
            </tr>
            <tr>
                <th rowspan="2" style="width:15px;">ลำดับ</th>
                <th rowspan="2" style="width:50px;">รหัส</th>
                <th rowspan="2" style="width:120px;">ชื่อ-นามสกุล</th>
                <th rowspan="2" style="width:80px;">แผนก</th>
                <th colspan="2">OT 1.0</th>
                <th colspan="2">OT 1.5</th>
                <th colspan="2">OT 3.0</th>
                <th rowspan="2" style="width:30px;">ค่ากะ<br><span style="font-size:9px;">(บาท)</span></th>
                <th rowspan="2" style="width:30px;">เบี้ยขยัน<br><span style="font-size:9px;">(บาท)</span></th>
            </tr>
            <tr style="font-size:10px;">
                <th style="width:15px;">ชม.</th><th style="width:15px;">น.</th>
                <th style="width:15px;">ชม.</th><th style="width:15px;">น.</th>
                <th style="width:15px;">ชม.</th><th style="width:15px;">น.</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $i = 1; 
            $total_h1 = 0; $total_m1 = 0;
            $total_h15 = 0; $total_m15 = 0;
            $total_h3 = 0; $total_m3 = 0;
            $total_all_shift = 0;
            $total_all_dil = 0;

            while($r = mysqli_fetch_assoc($result)): 
                // คำนวณยอดรวมแต่ละแถว (จัดการนาทีให้เป็นชั่วโมง)
                $h1 = $r['h1'] + floor($r['m1']/60); $m1 = $r['m1'] % 60;
                $h15 = $r['h15'] + floor($r['m15']/60); $m15 = $r['m15'] % 60;
                $h3 = $r['h3'] + floor($r['m3']/60); $m3 = $r['m3'] % 60;

                // สะสมยอดรวม
                $total_h1 += $h1; $total_m1 += $m1;
                $total_h15 += $h15; $total_m15 += $m15;
                $total_h3 += $h3; $total_m3 += $m3;
                $total_all_shift += $r['total_shift'];
                $total_all_dil += $r['total_dil'];
            ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td><?php echo $r['userid']; ?></td>
                <td style="text-align:left; padding-left:5px;"><?php echo $r['username']; ?></td>
                <td><?php echo $r['dept']; ?></td>
                <td><?php echo $h1 ?: ''; ?></td><td><?php echo $m1 ?: ''; ?></td>
                <td><?php echo $h15 ?: ''; ?></td><td><?php echo $m15 ?: ''; ?></td>
                <td><?php echo $h3 ?: ''; ?></td><td><?php echo $m3 ?: ''; ?></td>
                <td><?php echo $r['total_shift'] ? number_format($r['total_shift']) : ''; ?></td>
                <td><?php echo $r['total_dil'] ? number_format($r['total_dil']) : ''; ?></td>
            </tr>
            <?php endwhile; 

            // สรุปยอดรวมท้ายตาราง
            $total_h1 += floor($total_m1/60); $total_m1 = $total_m1 % 60;
            $total_h15 += floor($total_m15/60); $total_m15 = $total_m15 % 60;
            $total_h3 += floor($total_m3/60); $total_m3 = $total_m3 % 60;
            ?>
            <tr style="background-color: #ffffff; font-weight: bold;">
                <td colspan="4">รวมทั้งสิ้น</td>
                <td><?php echo $total_h1; ?></td><td><?php echo $total_m1; ?></td>
                <td><?php echo $total_h15; ?></td><td><?php echo $total_m15; ?></td>
                <td><?php echo $total_h3; ?></td><td><?php echo $total_m3; ?></td>
                <td><?php echo number_format($total_all_shift); ?></td>
                <td><?php echo number_format($total_all_dil); ?></td>
            </tr>
        </tbody>
            <tr>
                <td colspan="12" style="border:none; text-align:left; padding-top:40px;">
                    <div style="display:flex; justify-content: space-around; font-size:13px; font-weight: normal;">
                        <span>ผู้รายงาน .........................................</span>
                        <span>ผู้ตรวจสอบ .........................................</span>
                    </div>
                </td>
            </tr>
    </table>
</div>