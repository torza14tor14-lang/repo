<?php
session_start();
include '../db.php';

// สเต็ปที่ 1: ตรวจสอบว่า "ล็อกอินหรือยัง?"
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

// สเต็ปที่ 2: ตรวจสอบสิทธิ์
$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
if ($user_role !== 'ADMIN' && $user_dept !== 'ฝ่าย HR' && $user_dept !== 'ฝ่ายบัญชี' && $user_dept !== 'ฝ่ายการเงิน' && $user_dept !== 'ฝ่ายสินเชื่อ' && $user_dept !== 'บัญชี - ท็อปธุรกิจ') { 
    echo "<script>alert('ไม่มีสิทธิ์เข้าถึง'); window.location='../index.php';</script>"; 
    exit(); 
}

// รับค่าเดือนและปี
$m = isset($_GET['m']) ? sprintf("%02d", $_GET['m']) : date('m');
$y = isset($_GET['y']) ? $_GET['y'] : date('Y');
$months_th = ["01"=>"มกราคม", "02"=>"กุมภาพันธ์", "03"=>"มีนาคม", "04"=>"เมษายน", "05"=>"พฤษภาคม", "06"=>"มิถุนายน", "07"=>"กรกฎาคม", "08"=>"สิงหาคม", "09"=>"กันยายน", "10"=>"ตุลาคม", "11"=>"พฤศจิกายน", "12"=>"ธันวาคม"];

// ดึงข้อมูลจาก payroll_records
$sql_pr = "SELECT pr.*, e.username, e.dept, e.bank_account
           FROM payroll_records pr 
           LEFT JOIN employees e ON pr.userid = e.userid
           WHERE pr.pay_month = '$m' AND pr.pay_year = '$y'
           ORDER BY FIELD(e.dept, 
                'แผนกผลิต 1', 'แผนกคลังสินค้า 1', 'แผนกซ่อมบำรุง 1', 'แผนกไฟฟ้า 1', 'ฝ่ายวิชาการ', 
                'แผนก QA', 'แผนก P&M - 1', 'แผนก QC', 'ฝ่ายขาย', 'ฝ่ายจัดซื้อ', 
                'ฝ่ายบัญชี', 'ฝ่ายสินเชื่อ', 'ฝ่ายการเงิน', 'ฝ่าย HR', 'ฝ่ายงานวางแผน', 
                'แผนกคอมพิวเตอร์', 'บัญชี - ท็อปธุรกิจ', 'นักศึกษาฝึกงาน', 'ผลิตอาหารสัตว์น้ำ', 
                'แผนกผลิต 2', 'แผนกคลังสินค้า 2', 'แผนกซ่อมบำรุง 2', 'แผนกไฟฟ้า 2', 'แผนก P&M - 2'
            ) ASC, CAST(pr.userid AS UNSIGNED) ASC";

$res_pr = mysqli_query($conn, $sql_pr);

$payroll_data = [];
$sum_base = 0; $sum_extra = 0; $sum_deduct = 0; $sum_net = 0;

if ($res_pr && mysqli_num_rows($res_pr) > 0) {
    while($pr = mysqli_fetch_assoc($res_pr)) {
        $sum_base += $pr['base_salary'];
        $sum_extra += $pr['ot_pay'];
        $sum_deduct += $pr['leave_deduction'];
        $sum_net += $pr['net_salary'];

        $payroll_data[] = [
            'userid' => $pr['userid'], 
            'username' => $pr['username'] ?: 'พนักงานลาออก ('.$pr['userid'].')',
            'dept' => $pr['dept'] ?: '-',
            'bank_account' => $pr['bank_account'] ?: 'ยังไม่ระบุเลขบัญชี',
            'base_salary' => $pr['base_salary'], 
            'extra_income' => $pr['ot_pay'],
            'deduction' => $pr['leave_deduction'], 
            'net_salary' => $pr['net_salary'],
            'status' => $pr['status']
        ];
    }
}

// =========================================================================================
// 🚀 1. โหมด Export ส่งธนาคาร (ไฟล์ CSV/Excel)
// =========================================================================================
if (isset($_GET['action']) && $_GET['action'] == 'export_bank') {
    if(count($payroll_data) > 0) {
        $filename = "Bank_Transfer_TopFeedMills_".$m."_".$y.".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        
        // ใส่ BOM ให้ Excel อ่านภาษาไทยได้ไม่เพี้ยน
        fputs($output, $bom =(chr(0xEF) . chr(0xBB) . chr(0xBF)));
        
        // หัวตาราง
        fputcsv($output, array('ลำดับ', 'รหัสพนักงาน', 'ชื่อบัญชี (พนักงาน)', 'เลขที่บัญชีธนาคาร', 'ยอดเงินที่ต้องโอน (บาท)', 'หมายเหตุ'));
        
        $row_num = 1;
        foreach ($payroll_data as $row) {
            $note = ($row['bank_account'] == 'ยังไม่ระบุเลขบัญชี' || empty($row['bank_account'])) ? 'กรุณาตรวจสอบเลขบัญชี' : 'โอนเงินเดือน';
            fputcsv($output, array(
                $row_num++,
                $row['userid'],
                $row['username'],
                "'" . $row['bank_account'], // ใส่ Single Quote นำหน้า ป้องกัน Excel ตัดเลข 0 ข้างหน้าทิ้ง
                $row['net_salary'],
                $note
            ));
        }
        fclose($output);
        exit();
    }
}

// =========================================================================================
// 🚀 2. โหมดพิมพ์สลิปคาร์บอนแบบเหมา (Batch Print)
// =========================================================================================
if (isset($_GET['action']) && $_GET['action'] == 'print_slips') {
    if(count($payroll_data) == 0) {
        echo "<h2 style='text-align:center; margin-top:50px; font-family:sans-serif;'>ไม่มีข้อมูลสลิปเงินเดือนให้พิมพ์ในงวดนี้</h2>"; 
        exit;
    }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>พิมพ์สลิปเงินเดือนทั้งหมด งวด <?= $m ?>/<?= $y ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@400;700&display=swap');
        body { font-family: 'Sarabun', sans-serif; background: #525659; margin: 0; padding: 20px; }
        .slip-page { 
            background: white; width: 210mm; height: 140mm; margin: 0 auto 20px auto; padding: 12mm; 
            box-sizing: border-box; position: relative; page-break-after: always; overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3); border-radius: 5px;
        }
        .watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 80px; color: rgba(0,0,0,0.03); font-weight: bold; z-index: 0; pointer-events: none;}
        .content { position: relative; z-index: 1; }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 12px; }
        .header h2 { margin: 0 0 5px 0; font-size: 20px; }
        .header p { margin: 0; font-size: 14px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; font-size: 13px; }
        .info-row { display: flex; border-bottom: 1px dotted #ccc; padding-bottom: 2px; }
        .info-row strong { width: 100px; }
        .calc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .calc-box { border: 1px solid #000; border-radius: 4px; overflow: hidden;}
        .calc-title { background: #f0f0f0; padding: 5px; font-weight: bold; text-align: center; border-bottom: 1px solid #000; font-size: 13px; -webkit-print-color-adjust: exact; print-color-adjust: exact;}
        .calc-row { display: flex; justify-content: space-between; padding: 5px 10px; font-size: 13px; }
        .calc-row.total { font-weight: bold; border-top: 1px solid #000; background: #f9f9f9; -webkit-print-color-adjust: exact; print-color-adjust: exact;}
        .net-box { margin-top: 15px; border: 2px solid #000; text-align: center; padding: 8px; background: #f0f0f0; border-radius: 4px; -webkit-print-color-adjust: exact; print-color-adjust: exact;}
        .net-box h3 { margin: 0 0 2px 0; font-size: 14px; }
        .net-box h1 { margin: 0; font-size: 22px; }
        .footer { margin-top: 15px; font-size: 11px; text-align: center; color: #555; }
        
        @media print {
            body { background: white; padding: 0; }
            .slip-page { margin: 0; border: none; box-shadow: none; border-radius: 0; }
            @page { size: A4 portrait; margin: 0; } 
        }
    </style>
</head>
<body onload="window.print()">
    <?php foreach($payroll_data as $row): ?>
    <div class="slip-page">
        <div class="watermark">CONFIDENTIAL</div>
        <div class="content">
            <div class="header">
                <h2>บริษัท ท็อป ฟีด มิลล์ จำกัด</h2>
                <p>ใบแจ้งเงินเดือน (Payslip)</p>
                <p>ประจำงวด: <?= $months_th[$m] ?> <?= $y+543 ?></p>
            </div>
            <div class="info-grid">
                <div class="info-row"><strong>รหัสพนักงาน:</strong> <span><?= $row['userid'] ?></span></div>
                <div class="info-row"><strong>ชื่อ-นามสกุล:</strong> <span><?= $row['username'] ?></span></div>
                <div class="info-row"><strong>แผนก:</strong> <span><?= $row['dept'] ?></span></div>
                <div class="info-row"><strong>เลขที่บัญชี:</strong> <span><?= $row['bank_account'] ?></span></div>
            </div>
            <div class="calc-grid">
                <div class="calc-box">
                    <div class="calc-title">รายได้ (EARNINGS)</div>
                    <div class="calc-row"><span>เงินเดือนฐาน</span> <span><?= number_format($row['base_salary'], 2) ?></span></div>
                    <div class="calc-row"><span>ล่วงเวลา/สวัสดิการ</span> <span><?= number_format($row['extra_income'], 2) ?></span></div>
                    <div class="calc-row" style="color:transparent;"><span>-</span> <span>-</span></div>
                    <div class="calc-row total"><span>รวมรายได้</span> <span><?= number_format($row['base_salary'] + $row['extra_income'], 2) ?></span></div>
                </div>
                <div class="calc-box">
                    <div class="calc-title">รายการหัก (DEDUCTIONS)</div>
                    <div class="calc-row"><span>หักขาดงาน/มาสาย</span> <span style="color:red;"><?= $row['deduction'] > 0 ? "-".number_format($row['deduction'], 2) : "0.00" ?></span></div>
                    <div class="calc-row"><span>ประกันสังคม</span> <span>0.00</span></div>
                    <div class="calc-row"><span>ภาษีหัก ณ ที่จ่าย</span> <span>0.00</span></div>
                    <div class="calc-row total"><span>รวมรายการหัก</span> <span style="color:red;"><?= number_format($row['deduction'], 2) ?></span></div>
                </div>
            </div>
            <div class="net-box">
                <h3>ยอดเงินได้สุทธิ (NET PAY)</h3>
                <h1><?= number_format($row['net_salary'], 2) ?> บาท</h1>
            </div>
            <div class="footer">
                * เอกสารฉบับนี้จัดทำขึ้นโดยระบบคอมพิวเตอร์ ถือเป็นเอกสารทางอิเล็กทรอนิกส์ที่สมบูรณ์โดยไม่ต้องมีลายเซ็น
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</body>
</html>
<?php
    exit();
}
// =========================================================================================

include '../sidebar.php';
?>

<title>ประวัติการจ่ายเงินเดือน | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    .pr-card { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-bottom: 25px; border-top: 4px solid #1cc88a; }
    .filter-box { display: flex; gap: 15px; align-items: center; background: #f8f9fc; padding: 15px 20px; border-radius: 10px; border: 1px solid #eaecf4; flex-wrap: wrap;}
    .form-control { padding: 8px 12px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: 'Sarabun'; outline: none; }
    .btn-primary { background: #4e73df; color: white; border: none; padding: 9px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; }
    .btn-success { background: #1cc88a; color: white; border: none; padding: 9px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; text-decoration: none; display: flex; align-items: center; gap: 8px;}
    .btn-warning { background: #f6c23e; color: #333; border: none; padding: 9px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; text-decoration: none; display: flex; align-items: center; gap: 8px;}
    .btn-warning:hover { background: #dda20a; }
    .btn-excel { background: #107c41; color: white; border: none; padding: 9px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; text-decoration: none; display: flex; align-items: center; gap: 8px;}
    .btn-excel:hover { background: #0c5e31; }
    
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 25px; }
    .sum-box { background: white; padding: 20px; border-radius: 12px; border-left: 5px solid #4e73df; box-shadow: 0 4px 10px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center;}
    .sum-box h5 { margin: 0 0 5px 0; color: #858796; font-size: 13px; text-transform: uppercase; }
    .sum-box h2 { margin: 0; color: #333; font-size: 24px; }
    
    .payroll-table { width: 100%; border-collapse: collapse; min-width: 1000px; }
    .payroll-table th { background: #f8f9fc; padding: 12px; text-align: right; color: #4e73df; border-bottom: 2px solid #eaecf4; font-size: 13px; }
    .payroll-table th.text-left { text-align: left; }
    .payroll-table td { padding: 12px; border-bottom: 1px solid #f1f1f1; font-size: 14px; text-align: right; vertical-align: middle; }
    .payroll-table td.text-left { text-align: left; }
    .payroll-table tr:hover { background: #f8f9fc; }
    
    .dept-badge { background: #ebf4ff; color: #4e73df; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: bold; }
    .text-danger { color: #e74a3b; }
    .print-only { display: none; } 
    
    .btn-view { background: #f8f9fc; border: 1px solid #d1d3e2; padding: 4px 10px; border-radius: 6px; cursor: pointer; color: #4e73df; font-weight: bold; transition: 0.2s; }
    .btn-view:hover { background: #4e73df; color: white; border-color: #4e73df; }

    .slip-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(3px); }
    .slip-content { background: white; margin: 3% auto; width: 90%; max-width: 500px; border-radius: 15px; overflow: hidden; box-shadow: 0 15px 35px rgba(0,0,0,0.2); animation: slipPop 0.3s ease; }
    @keyframes slipPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .slip-header { background: #2c3e50; color: white; padding: 20px; text-align: center; position: relative; }
    .slip-header h2 { margin: 0 0 5px 0; color: #1cc88a; font-size: 22px; }
    .slip-close { position: absolute; right: 20px; top: 15px; color: white; font-size: 24px; cursor: pointer; opacity: 0.7; }
    .slip-close:hover { opacity: 1; }
    .slip-body { padding: 25px; }
    .slip-row { display: flex; justify-content: space-between; border-bottom: 1px dashed #eee; padding: 10px 0; font-size: 15px; }
    .slip-row.total { border-top: 2px solid #2c3e50; border-bottom: none; margin-top: 10px; padding-top: 15px; font-weight: bold; font-size: 18px; color: #e74a3b; }

    @media print {
        @page { size: A4 landscape; margin: 12mm; }
        body { background: white !important; font-size: 11pt !important; color: #000 !important; font-family: 'Sarabun', sans-serif; }
        
        .sidebar, .topbar, .filter-box, .summary-grid, .web-only, .btn-success, .btn-view { display: none !important; }
        .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        .content-padding { padding: 0 !important; }
        .pr-card { box-shadow: none !important; border: none !important; padding: 0 !important; }

        .print-only { display: block; }
        .print-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .print-header h2 { margin: 0 0 5px 0; font-size: 18pt; font-weight: bold; }
        .print-header h3 { margin: 0 0 5px 0; font-size: 14pt; }
        .print-header p { margin: 0; font-size: 11pt; }
        .print-date { position: absolute; right: 0; top: 0; font-size: 10pt; }

        .payroll-table { width: 100% !important; border: 1px solid #000 !important; }
        .payroll-table thead { display: table-header-group; }
        .payroll-table tr { page-break-inside: avoid; }
        .payroll-table th, .payroll-table td { border: 1px solid #000 !important; padding: 6px 8px !important; color: #000 !important; font-size: 10.5pt !important; }
        .payroll-table th { background-color: #f0f0f0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; text-align: center !important;}
        .payroll-table td.text-left { text-align: left !important; }
        .payroll-table td { text-align: right !important; }
        
        .signature-block { display: flex; justify-content: space-around; margin-top: 50px; page-break-inside: avoid; }
        .sig-box { text-align: center; width: 200px; }
        .sig-line { border-bottom: 1px dashed #000; height: 30px; margin-bottom: 5px; }
        
        .dept-badge { background: none !important; border: none !important; padding: 0 !important; color: #000 !important; font-weight: normal !important; }
    }
</style>

<div class="content-padding">
    
    <div class="print-only print-header">
        <div class="print-date">พิมพ์เมื่อ: <?= date('d/m/Y H:i') ?></div>
        <h2>บริษัท ท็อป ฟีด มิลล์ จำกัด</h2>
        <h3>รายงานสรุปเงินเดือนพนักงาน (Payroll Register)</h3>
        <p>ประจำงวดเดือน: <?= $months_th[$m] ?> <?= $y+543 ?></p>
    </div>

    <h2 class="web-only" style="color: #2c3e50; margin-top:0;"><i class="fa-solid fa-file-invoice-dollar" style="color: #1cc88a;"></i> ประวัติการจ่ายเงินเดือน (Payroll Records)</h2>

    <div class="pr-card web-only">
        <form method="GET" class="filter-box">
            <label style="font-weight:bold; color:#2c3e50;">เลือกดูประวัติงวดเดือน/ปี:</label>
            <select name="m" class="form-control">
                <?php foreach($months_th as $num => $name): ?>
                    <option value="<?= $num ?>" <?= ($num == $m) ? 'selected' : '' ?>><?= $name ?></option>
                <?php endforeach; ?>
            </select>
            <select name="y" class="form-control">
                <?php for($i = date('Y')-1; $i <= date('Y')+1; $i++): ?>
                    <option value="<?= $i ?>" <?= ($i == $y) ? 'selected' : '' ?>><?= $i+543 ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn-primary">ค้นหาประวัติ</button>
            
            <div style="margin-left: auto; display:flex; gap:10px; flex-wrap: wrap; justify-content: flex-end;">
                <?php if(count($payroll_data) > 0): ?>
                    <a href="?action=export_bank&m=<?= $m ?>&y=<?= $y ?>" class="btn-excel"><i class="fa-solid fa-file-excel"></i> Export ข้อมูลส่งธนาคาร</a>
                    <a href="?action=print_slips&m=<?= $m ?>&y=<?= $y ?>" target="_blank" class="btn-warning"><i class="fa-solid fa-receipt"></i> พิมพ์สลิปคาร์บอนทั้งหมด</a>
                <?php endif; ?>
                
                <button type="button" class="btn-success" onclick="window.print()"><i class="fa-solid fa-list"></i> พิมพ์รายงานตารางบัญชี</button>
            </div>
        </form>
    </div>

    <div class="summary-grid web-only">
        <div class="sum-box" style="border-left-color: #4e73df;">
            <div><h5>ยอดจ่ายสุทธิรวม (Net Pay)</h5><h2><?= number_format($sum_net, 2) ?> ฿</h2></div>
            <i class="fa-solid fa-vault" style="color: #4e73df;"></i>
        </div>
        <div class="sum-box" style="border-left-color: #1cc88a;">
            <div><h5>จำนวนพนักงาน</h5><h2><?= count($payroll_data) ?> <small>คน</small></h2></div>
            <i class="fa-solid fa-users" style="color: #1cc88a;"></i>
        </div>
        <div class="sum-box" style="border-left-color: #e74a3b;">
            <div><h5>รายการหักรวม (Deductions)</h5><h2><?= number_format($sum_deduct, 2) ?> ฿</h2></div>
            <i class="fa-solid fa-calculator" style="color: #e74a3b;"></i>
        </div>
    </div>

    <div class="pr-card" style="padding-top: 0;">
        <?php if(count($payroll_data) == 0): ?>
            <div style="text-align:center; padding:50px 20px; color:#888;">
                <i class="fa-solid fa-folder-open" style="font-size: 50px; color:#ddd; margin-bottom:15px;"></i>
                <h3 style="margin:0;">ยังไม่มีประวัติการจ่ายเงินเดือนในงวด <?= $months_th[$m] ?> <?= $y+543 ?></h3>
                <p>กรุณาไปที่เมนู <strong>"ประมวลผลเงินเดือน"</strong> เพื่อทำการคำนวณและบันทึกข้อมูลเข้าระบบบัญชีก่อนครับ</p>
                <a href="payroll_summary.php?m=<?= $m ?>&y=<?= $y ?>" class="btn-primary" style="text-decoration:none; display:inline-block; margin-top:10px;"><i class="fa-solid fa-calculator"></i> ไปที่หน้าประมวลผล</a>
            </div>
        <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="payroll-table">
                <thead>
                    <tr>
                        <th class="text-left" style="width: 8%;">รหัส</th>
                        <th class="text-left" style="width: 18%;">ชื่อ - นามสกุล</th>
                        <th class="text-left" style="width: 15%;">แผนก</th>
                        <th style="width: 12%;">เงินเดือนฐาน</th>
                        <th style="width: 10%;">รายได้พิเศษ</th>
                        <th style="width: 10%;">รายการหัก</th>
                        <th style="width: 15%; background:#e8f9f3;">รายรับสุทธิ (Net)</th>
                        <th class="web-only" style="width: 5%; text-align:center;">สลิป</th>
                    </tr>
                </thead>
                <tbody>
                        <?php foreach($payroll_data as $row): 
                            $slip_json = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr>
                            <td class="text-left"><?= $row['userid'] ?></td>
                            <td class="text-left">
                                <?= $row['username'] ?>
                                <br><small style="color:#aaa;"><i class="fa-solid fa-building-columns"></i> <?= $row['bank_account'] ?></small>
                            </td>
                            <td class="text-left"><span class="dept-badge"><?= $row['dept'] ?></span></td>
                            <td><?= number_format($row['base_salary'], 2) ?></td>
                            <td><?= number_format($row['extra_income'], 2) ?></td>
                            <td><span class="text-danger"><?= $row['deduction'] > 0 ? "-".number_format($row['deduction'], 2) : "0.00" ?></span></td>
                            <td style="font-weight:bold; color: #1cc88a;"><?= number_format($row['net_salary'], 2) ?></td>
                            <td class="web-only" style="text-align:center;">
                                <button class="btn-view" onclick="openSlipModal('<?= $slip_json ?>')"><i class="fa-solid fa-eye"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <tr style="font-weight: bold; background: #f8f9fc;">
                            <td colspan="3" class="text-left" style="text-align: right !important; padding-right: 15px !important;">ยอดรวมทั้งสิ้น (Grand Total)</td>
                            <td><?= number_format($sum_base, 2) ?></td>
                            <td><?= number_format($sum_extra, 2) ?></td>
                            <td class="text-danger">-<?= number_format($sum_deduct, 2) ?></td>
                            <td style="font-size: 16px; color:#1cc88a;"><?= number_format($sum_net, 2) ?></td>
                            <td class="web-only"></td>
                        </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="print-only signature-block">
        <div class="sig-box">
            <div class="sig-line"></div><br>
            <div>( .......................................... )</div>
            <div style="margin-top: 5px;">ผู้จัดทำ <br> (ฝ่ายทรัพยากรบุคคล)</div><br>
            <div style="font-size: 10pt; color: #555;">วันที่ _______/_______/_______</div>
        </div>
        <div class="sig-box">
            <div class="sig-line"></div><br>
            <div>( .......................................... )</div>
            <div style="margin-top: 5px;">ผู้ตรวจสอบ <br> (ฝ่ายบัญชีและการเงิน)</div><br>
            <div style="font-size: 10pt; color: #555;">วันที่ _______/_______/_______</div>
        </div>
        <div class="sig-box">
            <div class="sig-line"></div><br>
            <div>( .......................................... )</div>
            <div style="margin-top: 5px;">ผู้อนุมัติ <br> (ผู้จัดการโรงงาน)</div><br>
            <div style="font-size: 10pt; color: #555;">วันที่ _______/_______/_______</div>
        </div>
    </div>
</div>

<div id="slipModal" class="slip-modal">
    <div class="slip-content">
        <div class="slip-header">
            <span class="slip-close" onclick="closeSlipModal()"><i class="fa-solid fa-xmark"></i></span>
            <h2>บริษัท ท็อป ฟีด มิลล์ จำกัด</h2>
            <p style="margin:0; opacity:0.8; font-size:14px;">ใบแจ้งเงินเดือน (Payslip)</p>
            <p style="margin:5px 0 0 0; font-weight:bold;">ประจำงวด: <?= $months_th[$m] ?> <?= $y+543 ?></p>
        </div>
        <div class="slip-body">
            <div style="background:#f8f9fc; padding:15px; border-radius:8px; margin-bottom:20px; font-size:14px; border:1px solid #eaecf4;">
                <strong style="color:#4e73df;">รหัสพนักงาน:</strong> <span id="m_userid"></span><br>
                <strong style="color:#4e73df;">ชื่อ-นามสกุล:</strong> <span id="m_name"></span><br>
                <strong style="color:#4e73df;">แผนก:</strong> <span id="m_dept"></span><br>
                <strong style="color:#4e73df;">เลขที่บัญชี:</strong> <span id="m_bank"></span>
            </div>
            
            <div class="slip-row">
                <span>เงินเดือนพื้นฐาน (Base Salary)</span>
                <span id="m_base" style="font-weight:bold; color:#555;"></span>
            </div>
            <div class="slip-row">
                <span>รายได้พิเศษ (OT/ค่ากะ/เบี้ยขยัน)</span>
                <span id="m_extra" style="font-weight:bold; color:#1cc88a;"></span>
            </div>
            <div class="slip-row">
                <span>รายการหัก (ขาดงาน/มาสาย)</span>
                <span id="m_deduct" style="font-weight:bold; color:#e74a3b;"></span>
            </div>
            
            <div class="slip-row total">
                <span>ยอดเงินได้สุทธิ (Net Pay)</span>
                <span id="m_net"></span>
            </div>
        </div>
    </div>
</div>

<script>
    function openSlipModal(jsonData) {
        const data = JSON.parse(jsonData);
        document.getElementById('m_userid').innerText = data.userid;
        document.getElementById('m_name').innerText = data.username;
        document.getElementById('m_dept').innerText = data.dept;
        document.getElementById('m_bank').innerText = data.bank_account;
        
        document.getElementById('m_base').innerText = parseFloat(data.base_salary).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' บาท';
        document.getElementById('m_extra').innerText = '+ ' + parseFloat(data.extra_income).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' บาท';
        document.getElementById('m_deduct').innerText = '- ' + parseFloat(data.deduction).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' บาท';
        document.getElementById('m_net').innerText = parseFloat(data.net_salary).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' บาท';
        
        document.getElementById('slipModal').style.display = "block";
    }
    function closeSlipModal() { document.getElementById('slipModal').style.display = "none"; }
    window.onclick = function(event) { if (event.target == document.getElementById('slipModal')) closeSlipModal(); }
</script>