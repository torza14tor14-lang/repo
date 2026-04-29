<?php
session_start();
include '../db.php';

// สเต็ปที่ 1: ตรวจสอบว่า "ล็อกอินหรือยัง?"
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$my_userid = $_SESSION['userid'];
$fullname = $_SESSION['fullname'] ?? '';

// รับค่าเดือนและปี (ค่าเริ่มต้นคือเดือนปัจจุบัน)
$m = isset($_GET['m']) ? sprintf("%02d", $_GET['m']) : date('m');
$y = isset($_GET['y']) ? $_GET['y'] : date('Y');
$months_th = ["01"=>"มกราคม", "02"=>"กุมภาพันธ์", "03"=>"มีนาคม", "04"=>"เมษายน", "05"=>"พฤษภาคม", "06"=>"มิถุนายน", "07"=>"กรกฎาคม", "08"=>"สิงหาคม", "09"=>"กันยายน", "10"=>"ตุลาคม", "11"=>"พฤศจิกายน", "12"=>"ธันวาคม"];

// ==========================================
// 1. ดึงข้อมูลพนักงานของตัวเอง
// ==========================================
$emp_q = mysqli_query($conn, "SELECT * FROM employees WHERE userid = '$my_userid'");
$emp = mysqli_fetch_assoc($emp_q);
// 🚀 แก้ไขชื่อคอลัมน์ให้ตรงกับ DB คือ salary
$base_salary = floatval($emp['salary'] ?? 0); 
$bank_account = $emp['bank_account'] ?? '-';
$dept = $emp['dept'] ?? '-';
$username = $emp['username'] ?? $fullname;

// ==========================================
// 2. ตรวจสอบว่า HR บันทึกข้อมูลของเดือนนี้เข้าระบบ (Official) หรือยัง?
// ==========================================
$sql_pr = "SELECT * FROM payroll_records WHERE userid = '$my_userid' AND pay_month = '$m' AND pay_year = '$y'";
$res_pr = mysqli_query($conn, $sql_pr);
$is_official = false;

$ot_money = 0; $allowance = 0; $late_deduction = 0; 
$ot_1_sum = 0; $ot_15_sum = 0; $ot_3_sum = 0; $total_late_mins = 0;

if(mysqli_num_rows($res_pr) > 0) {
    // 🟢 กรณีที่ 1: บัญชีล็อคข้อมูลแล้ว (ดึงจาก payroll_records โดยตรง)
    $pr = mysqli_fetch_assoc($res_pr);
    $base_salary = $pr['base_salary'];
    $ot_money = $pr['ot_pay']; // รวม OT และเบี้ยขยันแล้วจากฝั่ง HR
    $late_deduction = $pr['leave_deduction'];
    $net_salary = $pr['net_salary'];
    $gross_pay = $base_salary + $ot_money;
    $is_official = true;

} else {
    // 🟡 กรณีที่ 2: บัญชียังไม่ล็อค (คำนวณสดให้ดูเป็น Preview)
    $daily_rate = ($base_salary > 0) ? ($base_salary / 30) : 0;
    $hourly_rate = ($daily_rate > 0) ? ($daily_rate / 8) : 0;
    $minute_rate = $hourly_rate / 60;

    // ดึง OT และค่ากะ
    $sql_ot = "SELECT * FROM ot_records WHERE userid = '$my_userid' AND status = 'Approved' AND MONTH(ot_date) = '$m' AND YEAR(ot_date) = '$y'";
    $res_ot = mysqli_query($conn, $sql_ot);
    while($ot = mysqli_fetch_assoc($res_ot)) {
        $ot1_mins = ($ot['ot_1_h'] * 60) + $ot['ot_1_m'];
        $ot15_mins = ($ot['ot_15_h'] * 60) + $ot['ot_15_m'];
        $ot3_mins = ($ot['ot_3_h'] * 60) + $ot['ot_3_m'];
        
        $ot_1_sum += $ot1_mins;
        $ot_15_sum += $ot15_mins;
        $ot_3_sum += $ot3_mins;

        $ot_money += ($ot1_mins * $minute_rate * 1) + ($ot15_mins * $minute_rate * 1.5) + ($ot3_mins * $minute_rate * 3);
        $allowance += $ot['shift_fee'] + $ot['diligence_fee'];
    }

    // 🚀 ปรับการคำนวณวันลา/มาสาย ให้ตรงกับหน้า Dashboard ของ HR (ใช้ leave_records)
    $sql_leave = "SELECT leave_type, SUM(d) as total_days, SUM(t) as total_times FROM leave_records WHERE userid='$my_userid' AND MONTH(start_date)='$m' AND YEAR(start_date)='$y' AND status='Approved' GROUP BY leave_type";
    $res_leave = mysqli_query($conn, $sql_leave);
    if ($res_leave) {
        while($row_l = mysqli_fetch_assoc($res_leave)) {
            if ($row_l['leave_type'] == 'ขาดงาน') {
                $late_deduction += ($row_l['total_days'] * $daily_rate); 
            } elseif ($row_l['leave_type'] == 'มาสาย') {
                $total_late_mins += $row_l['total_times'];
                $late_deduction += ($row_l['total_times'] * 50); // หักครั้งละ 50 บาท (ตามฝั่ง HR)
            }
        }
    }

    // ยอดสุทธิ
    $gross_pay = $base_salary + $ot_money + $allowance;
    $net_salary = $gross_pay - $late_deduction;
}

include '../sidebar.php';
?>

<title>สลิปเงินเดือนของฉัน | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    .payslip-container { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); max-width: 800px; margin: 0 auto; }
    
    /* ตัวกรองด้านบน */
    .filter-box { display: flex; gap: 15px; align-items: center; background: #f8f9fc; padding: 15px 20px; border-radius: 10px; border: 1px solid #eaecf4; margin-bottom: 25px; flex-wrap: wrap; }
    .form-control { padding: 8px 12px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: 'Sarabun'; outline: none; }
    .btn-primary { background: #4e73df; color: white; border: none; padding: 9px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s;}
    .btn-primary:hover { background: #2e59d9; }
    .btn-print { background: #2c3e50; color: white; border: none; padding: 9px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s;}
    .btn-print:hover { background: #1a252f; }

    /* โครงสร้างสลิป */
    .slip-wrapper { border: 2px solid #2c3e50; padding: 25px; border-radius: 10px; background: #fff; position: relative; overflow: hidden;}
    .slip-header { text-align: center; border-bottom: 2px solid #2c3e50; padding-bottom: 15px; margin-bottom: 20px; position: relative;}
    .slip-header h2 { margin: 0 0 5px 0; color: #2c3e50; font-size: 22px; }
    .slip-header p { margin: 0; color: #555; }
    
    /* ป้ายบอกสถานะ (Official/Preview) */
    .status-badge { position: absolute; top: 0; right: 0; padding: 5px 15px; border-radius: 50px; font-size: 12px; font-weight: bold; }
    .status-official { background: #e3fdfd; color: #1cc88a; border: 1px solid #1cc88a; }
    .status-preview { background: #fff3cd; color: #f6c23e; border: 1px solid #f6c23e; }
    
    .emp-info { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; font-size: 14px; }
    .emp-info div { display: flex; border-bottom: 1px dashed #eee; padding-bottom: 5px; }
    .emp-info strong { width: 120px; color: #555; }

    .calc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .calc-box { border: 1px solid #ccc; border-radius: 8px; overflow: hidden; }
    .calc-title { background: #f8f9fc; padding: 10px; font-weight: bold; text-align: center; border-bottom: 1px solid #ccc; color: #2c3e50; }
    .calc-row { display: flex; justify-content: space-between; padding: 8px 15px; border-bottom: 1px solid #eee; font-size: 14px; }
    .calc-row:last-child { border-bottom: none; }
    .calc-row.total { background: #eaecf4; font-weight: bold; font-size: 15px; border-top: 2px solid #ccc; }
    
    .net-box { margin-top: 20px; background: #2c3e50; color: white; padding: 15px; border-radius: 8px; text-align: center; }
    .net-box h3 { margin: 0 0 5px 0; font-size: 16px; font-weight: normal; }
    .net-box h1 { margin: 0; font-size: 28px; color: #1cc88a; }

    .watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 100px; color: rgba(0,0,0,0.03); font-weight: bold; z-index: 0; pointer-events: none; white-space: nowrap;}
    .content-z { position: relative; z-index: 1; }

    /* สำหรับการพิมพ์ */
    @media print {
        @page { size: A4 portrait; margin: 15mm; }
        body { background: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .sidebar, .topbar, .filter-box, .print-hide { display: none !important; }
        .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        .payslip-container { box-shadow: none; padding: 0; max-width: 100%; border: none; }
        .net-box { color: #000 !important; background: #f1f1f1 !important; border: 2px solid #000; }
        .net-box h1 { color: #000 !important; }
    }
</style>

<div class="content-padding">
    <div class="payslip-container">
        
        <h2 style="color: #2c3e50; margin-top:0;" class="print-hide"><i class="fa-solid fa-file-invoice-dollar" style="color: #4e73df;"></i> สลิปเงินเดือนของฉัน (My E-Slip)</h2>

        <form method="GET" class="filter-box print-hide">
            <label style="font-weight:bold; color:#2c3e50;">เลือกงวดเดือน/ปี:</label>
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
            <button type="submit" class="btn-primary">ดูสลิปเงินเดือน</button>
            <button type="button" class="btn-print" style="margin-left: auto;" onclick="window.print()"><i class="fa-solid fa-print"></i> พิมพ์ / Save PDF</button>
        </form>

        <?php if($base_salary > 0): ?>
        <div class="slip-wrapper">
            <div class="watermark">CONFIDENTIAL</div>
            
            <div class="content-z">
                <div class="slip-header">
                    <h2>บริษัท ท็อป ฟีด มิลล์ จำกัด</h2>
                    <p>ใบแจ้งรายได้ประจำงวดเดือน <strong><?= $months_th[$m] ?> <?= $y+543 ?></strong></p>
                    
                    <?php if($is_official): ?>
                        <div class="status-badge status-official"><i class="fa-solid fa-circle-check"></i> ฉบับสมบูรณ์</div>
                    <?php else: ?>
                        <div class="status-badge status-preview"><i class="fa-solid fa-clock"></i> ฉบับร่าง (รอตรวจสอบ)</div>
                    <?php endif; ?>
                </div>

                <div class="emp-info">
                    <div><strong>รหัสพนักงาน:</strong> <span><?= $my_userid ?></span></div>
                    <div><strong>ชื่อ-นามสกุล:</strong> <span><?= $username ?></span></div>
                    <div><strong>แผนก/ตำแหน่ง:</strong> <span><?= $dept ?></span></div>
                    <div><strong>เลขที่บัญชี:</strong> <span><?= $bank_account ?></span></div>
                </div>

                <div class="calc-grid">
                    <div class="calc-box">
                        <div class="calc-title">รายได้ (EARNINGS)</div>
                        <div class="calc-row"><span>เงินเดือน (Base Salary)</span> <span><?= number_format($base_salary, 2) ?></span></div>
                        
                        <div class="calc-row">
                            <span>รายได้พิเศษ/ล่วงเวลา (OT) 
                                <?php if(!$is_official && $ot_money > 0) echo "<small style='color:#888;'>(".floor(($ot_1_sum+$ot_15_sum+$ot_3_sum)/60)." ชม.)</small>"; ?>
                            </span> 
                            <span><?= number_format($ot_money + $allowance, 2) ?></span>
                        </div>
                        
                        <div class="calc-row" style="color:transparent; user-select:none;"><span>-</span> <span>-</span></div> 
                        <div class="calc-row total"><span>รวมรายได้ (Total Earnings)</span> <span style="color:#1cc88a;"><?= number_format($gross_pay, 2) ?></span></div>
                    </div>

                    <div class="calc-box">
                        <div class="calc-title">รายการหัก (DEDUCTIONS)</div>
                        <div class="calc-row">
                            <span>หักขาดงาน/มาสาย 
                                <?php if(!$is_official && $total_late_mins > 0) echo "<small style='color:#e74a3b;'>({$total_late_mins} ครั้ง)</small>"; ?>
                            </span> 
                            <span style="color:#e74a3b;"><?= $late_deduction > 0 ? "-".number_format($late_deduction, 2) : "0.00" ?></span>
                        </div>
                        <div class="calc-row"><span>ภาษีหัก ณ ที่จ่าย</span> <span>0.00</span></div>
                        <div class="calc-row"><span>ประกันสังคม</span> <span>0.00</span></div>
                        <div class="calc-row total"><span>รวมรายการหัก (Total Deductions)</span> <span style="color:#e74a3b;"><?= number_format($late_deduction, 2) ?></span></div>
                    </div>
                </div>

                <div class="net-box">
                    <h3>รายได้สุทธิ (NET PAY)</h3>
                    <h1><?= number_format($net_salary, 2) ?> ฿</h1>
                </div>
                
                <div style="margin-top: 30px; font-size: 12px; color: #888; text-align: center;">
                    * เอกสารฉบับนี้จัดทำขึ้นโดยระบบคอมพิวเตอร์ ถือเป็นเอกสารทางอิเล็กทรอนิกส์ที่สมบูรณ์โดยไม่ต้องมีลายเซ็น
                </div>
            </div>
        </div>
        
        <?php else: ?>
            <div style="text-align: center; padding: 50px; background: #fff5f5; border-radius: 10px; color: #e74a3b; border: 1px dashed #e74a3b;">
                <i class="fa-solid fa-triangle-exclamation fa-3x" style="margin-bottom: 15px;"></i>
                <h3 style="margin:0;">ยังไม่มีข้อมูลฐานเงินเดือน</h3>
                <p style="margin:5px 0 0 0; color:#555;">กรุณาติดต่อฝ่ายทรัพยากรบุคคล (HR) เพื่อตั้งค่าฐานเงินเดือนเข้าสู่ระบบก่อนดูสลิปเงินเดือน</p>
            </div>
        <?php endif; ?>

    </div>
</div>