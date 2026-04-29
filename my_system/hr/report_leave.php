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

// สร้างเงื่อนไขการกรอง (Dynamic WHERE)
$where_clauses = ["e.role != 'ADMIN'"];
if ($sel_dept != '') { $where_clauses[] = "e.dept = '$sel_dept'"; }
if ($sel_user != '') { $where_clauses[] = "(e.username LIKE '%$sel_user%' OR e.userid = '$sel_user')"; }
$where_sql = implode(" AND ", $where_clauses);

// ดึงข้อมูลวันลา เฉพาะที่อนุมัติแล้ว (AND l.status = 'Approved')
$sql = "SELECT e.userid, e.username, e.dept,
    SUM(CASE WHEN l.leave_type = 'ลาป่วย' THEN l.d ELSE 0 END) as sick_d,
    SUM(CASE WHEN l.leave_type = 'ลาป่วย' THEN l.h ELSE 0 END) as sick_h,
    SUM(CASE WHEN l.leave_type = 'ลาป่วย' THEN l.m ELSE 0 END) as sick_m,
    SUM(CASE WHEN l.leave_type = 'ลากิจ' THEN l.d ELSE 0 END) as biz_d,
    SUM(CASE WHEN l.leave_type = 'ลากิจ' THEN l.h ELSE 0 END) as biz_h,
    SUM(CASE WHEN l.leave_type = 'ลากิจ' THEN l.m ELSE 0 END) as biz_m,
    SUM(CASE WHEN l.leave_type = 'ลาอื่นๆ' THEN l.d ELSE 0 END) as oth_d,
    SUM(CASE WHEN l.leave_type = 'ลาอื่นๆ' THEN l.h ELSE 0 END) as oth_h,
    SUM(CASE WHEN l.leave_type = 'ลาอื่นๆ' THEN l.m ELSE 0 END) as oth_m,
    SUM(CASE WHEN l.leave_type = 'พักร้อน' THEN l.d ELSE 0 END) as vac_d,
    SUM(CASE WHEN l.leave_type = 'พักร้อน' THEN l.h ELSE 0 END) as vac_h,
    SUM(CASE WHEN l.leave_type = 'มาสาย' THEN l.t ELSE 0 END) as late_t,
    SUM(CASE WHEN l.leave_type = 'มาสาย' THEN l.m ELSE 0 END) as late_m,
    SUM(CASE WHEN l.leave_type = 'ขาดงาน' THEN l.d ELSE 0 END) as abs_d,
    SUM(CASE WHEN l.leave_type = 'ขาดงาน' THEN l.h ELSE 0 END) as abs_h,
    SUM(CASE WHEN l.leave_type = 'ขาดงาน' THEN l.m ELSE 0 END) as abs_m,
    SUM(CASE WHEN l.leave_type = 'พักงาน' THEN l.d ELSE 0 END) as susp_d,
    SUM(CASE WHEN l.leave_type = 'ลาคลอด' THEN l.d ELSE 0 END) as mat_d
FROM employees e
LEFT JOIN leave_records l ON e.userid = l.userid AND l.start_date BETWEEN '$start_date' AND '$end_date' AND l.status = 'Approved'
WHERE $where_sql
GROUP BY e.userid
ORDER BY FIELD(e.dept, 'แผนกผลิต 1', 'แผนกคลังสินค้า 1', 'แผนกซ่อมบำรุง 1', 'แผนกไฟฟ้า 1', 'ฝ่ายวิชาการ', 'แผนก QA', 'แผนก P&M - 1', 'แผนก QC', 'ฝ่ายขาย', 'ฝ่ายจัดซื้อ', 'ฝ่ายบัญชี', 'ฝ่ายสินเชื่อ', 'ฝ่ายการเงิน', 'ฝ่าย HR', 'ฝ่ายงานวางแผน', 'แผนกคอมพิวเตอร์', 'บัญชี - ท็อปธุรกิจ', 'นักศึกษาฝึกงาน', 'ผลิตอาหารสัตว์น้ำ', 'แผนกผลิต 2', 'แผนกคลังสินค้า 2', 'แผนกซ่อมบำรุง 2', 'แผนกไฟฟ้า 2', 'แผนก P&M - 2' ) ASC, e.userid ASC";

$result = mysqli_query($conn, $sql);
$dept_query = mysqli_query($conn, "SELECT DISTINCT dept FROM employees WHERE role != 'ADMIN' ORDER BY dept ASC");

include '../sidebar.php';
?>

<title>Top Feed Mills | พิมพ์ใบลา</title>
<style>
    .print-area { width: 100%; padding: 20px; background: white; font-family: 'Sarabun', sans-serif; color: #000; }
    
    .report-table { width: 100%; border-collapse: collapse; margin-top: 10px; color: #000; }
    .report-table th, .report-table td { border: 1px solid #000; padding: 5px; font-size: 12px; text-align: center; color: #000; }
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
        @page { size: landscape; margin: 10mm; }
        body { background: white; }
        .no-print-box, .sidebar, .topbar { display: none !important; }
        .main-content { margin: 0 !important; padding: 0 !important; }
        .print-area { padding: 0; }
        
        /* สั่งให้ thead แสดงทุกหน้า */
        thead { display: table-header-group; }
        /* สั่งให้ tfoot แสดงทุกหน้า (ถ้าต้องการ) */
        tfoot { display: table-footer-group; }
        
        tr { page-break-inside: avoid; }
    }
</style>

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
            <a href="report_leave.php" style="font-size:12px; color:gray; text-decoration:none;">ล้างค่า</a>
        </form>
    </div>

    <table class="report-table">
        <thead>
            <tr>
                <th colspan="23" style="border: none; background: white; padding-bottom: 10px;">
                    <div style="font-size: 20px; font-weight: bold; text-align: center; margin-bottom: 10px;">บริษัท ท็อป ฟีด มิลส์ จำกัด</div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-end; font-size: 13px; border-bottom: 2px solid #000; padding-bottom: 5px;">
                        <span>ประวัติการลาตั้งแต่วันที่ <?php echo formatThaiDate($start_date); ?> ถึงวันที่ <?php echo formatThaiDate($end_date); ?></span>
                        <span>วันที่พิมพ์: <?php echo thai_date(date('Y-m-d')); ?></span>
                    </div>
                </th>
            </tr>
            <tr>
                <th rowspan="2" style="width:30px;">ลำดับ</th>
                <th rowspan="2" style="width:55px;">รหัส</th>
                <th rowspan="2" style="width:160px;">ชื่อ-นามสกุล</th>
                <th rowspan="2" style="width:100px;">แผนก</th>
                <th colspan="3">ลาป่วย</th>
                <th colspan="3">ลากิจ</th>
                <th colspan="3">ลาอื่นๆ</th>
                <th colspan="2">พักร้อน</th>
                <th colspan="2">มาสาย</th>
                <th colspan="3">ขาดงาน</th>
                <th rowspan="2">พักงาน<br><span style="font-size:10px; font-weight:normal;">(วัน)</span></th>
                <th rowspan="2">ลาคลอด<br><span style="font-size:10px; font-weight:normal;">(วัน)</span></th>
            </tr>
            <tr style="font-size:10px;">
                <th>วัน</th><th>ชม.</th><th>น.</th>
                <th>วัน</th><th>ชม.</th><th>น.</th>
                <th>วัน</th><th>ชม.</th><th>น.</th>
                <th>วัน</th><th>ชม.</th>
                <th>ครั้ง</th><th>น.</th>
                <th>วัน</th><th>ชม.</th><th>น.</th>
            </tr>
        </thead>
        
        <tbody>
            <?php $i = 1; while($r = mysqli_fetch_assoc($result)): ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td><?php echo $r['userid']; ?></td>
                <td style="text-align:left; padding-left:5px;"><?php echo $r['username']; ?></td>
                <td><?php echo $r['dept']; ?></td>
                <td><?php echo $r['sick_d'] ?: ''; ?></td><td><?php echo $r['sick_h'] ?: ''; ?></td><td><?php echo $r['sick_m'] ?: ''; ?></td>
                <td><?php echo $r['biz_d'] ?: ''; ?></td><td><?php echo $r['biz_h'] ?: ''; ?></td><td><?php echo $r['biz_m'] ?: ''; ?></td>
                <td><?php echo $r['oth_d'] ?: ''; ?></td><td><?php echo $r['oth_h'] ?: ''; ?></td><td><?php echo $r['oth_m'] ?: ''; ?></td>
                <td><?php echo $r['vac_d'] ?: ''; ?></td><td><?php echo $r['vac_h'] ?: ''; ?></td>
                <td><?php echo $r['late_t'] ?: ''; ?></td><td><?php echo $r['late_m'] ?: ''; ?></td>
                <td><?php echo $r['abs_d'] ?: ''; ?></td><td><?php echo $r['abs_h'] ?: ''; ?></td><td><?php echo $r['abs_m'] ?: ''; ?></td>
                <td><?php echo $r['susp_d'] ?: ''; ?></td>
                <td><?php echo $r['mat_d'] ?: ''; ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
        
            <tr>
                <td colspan="23" style="border:none; text-align:left; padding-top:30px;">
                    <div style="display:flex; justify-content: space-around; font-size:13px; margin-bottom: 20px;">
                        <span>ผู้รายงาน ........................................................................</span>
                        <span>ผู้ตรวจสอบ ........................................................................</span>
                    </div>
                    <div style="font-size:11px; font-weight:bold; border-top: 1px dashed #ccc; padding-top: 10px;">
                        หมายเหตุ: ลาป่วย 1 ปี ไม่เกิน 30 วัน &nbsp;|&nbsp; ลากิจรวมลาอื่นๆ 1 ปี ไม่เกิน 7 วัน &nbsp;|&nbsp; ลาพักร้อน 1 ปีไม่เกิน 6 วัน &nbsp;|&nbsp; ลาคลอด 1 ปี ไม่เกิน 120 วัน
                    </div>
                </td>
            </tr>
    </table>
</div>