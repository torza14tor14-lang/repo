<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    header("Location: ../login.php"); exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';

if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && $user_dept !== 'ฝ่าย HR' && $user_dept !== 'ฝ่ายบัญชี' && $user_dept !== 'ฝ่ายการเงิน') { 
    echo "<script>alert('ไม่มีสิทธิ์เข้าถึงหน้านี้'); window.location='../index.php';</script>"; exit(); 
}

$m = isset($_GET['m']) ? $_GET['m'] : date('m');
$y = isset($_GET['y']) ? $_GET['y'] : date('Y');

// 🚀 ฟังก์ชันคำนวณเงินเดือน
function calculatePayroll($conn, $userid, $month, $year, $base_salary) {
    $daily_rate = $base_salary > 0 ? ($base_salary / 30) : 0; 
    $hourly_rate = $daily_rate > 0 ? ($daily_rate / 8) : 0;  
    
    $ot_pay = 0; $allowance = 0; $deduction = 0;

    $sql_ot = "SELECT 
                SUM(ot_1_h) as h1, SUM(ot_1_m) as m1, SUM(ot_15_h) as h15, SUM(ot_15_m) as m15, SUM(ot_3_h) as h3, SUM(ot_3_m) as m3,
                SUM(shift_fee) as sum_shift, SUM(diligence_fee) as sum_diligence
               FROM ot_records 
               WHERE userid='$userid' AND MONTH(ot_date)='$month' AND YEAR(ot_date)='$year' AND status='Approved'";
    $res_ot = mysqli_query($conn, $sql_ot);
    if ($res_ot && $row_ot = mysqli_fetch_assoc($res_ot)) {
        $total_h1 = ($row_ot['h1'] ?? 0) + (($row_ot['m1'] ?? 0) / 60);
        $total_h15 = ($row_ot['h15'] ?? 0) + (($row_ot['m15'] ?? 0) / 60);
        $total_h3 = ($row_ot['h3'] ?? 0) + (($row_ot['m3'] ?? 0) / 60);
        
        $pay_h1 = $total_h1 * ($hourly_rate * 1);
        $pay_h15 = $total_h15 * ($hourly_rate * 1.5);
        $pay_h3 = $total_h3 * ($hourly_rate * 3);
        
        $ot_pay = $pay_h1 + $pay_h15 + $pay_h3;
        $allowance = ($row_ot['sum_shift'] ?? 0) + ($row_ot['sum_diligence'] ?? 0);
    }

    $sql_leave = "SELECT leave_type, SUM(d) as total_days, SUM(t) as total_times FROM leave_records WHERE userid='$userid' AND MONTH(start_date)='$month' AND YEAR(start_date)='$year' AND status='Approved' GROUP BY leave_type";
    $res_leave = mysqli_query($conn, $sql_leave);
    if ($res_leave) {
        while($row_l = mysqli_fetch_assoc($res_leave)) {
            if ($row_l['leave_type'] == 'ขาดงาน') { $deduction += ($row_l['total_days'] * $daily_rate); } 
            elseif ($row_l['leave_type'] == 'มาสาย') { $deduction += ($row_l['total_times'] * 50); }
        }
    }

    $net_salary = $base_salary + $ot_pay + $allowance - $deduction;
    return [ 'extra_income' => $ot_pay + $allowance, 'deduction' => $deduction, 'net_salary' => $net_salary ];
}

// 🚀 ระบบบันทึกข้อมูลเข้าระบบบัญชี (เมื่อกดปุ่มสีเขียว)
if (isset($_POST['save_payroll'])) {
    $save_m = $_POST['save_m'];
    $save_y = $_POST['save_y'];
    
    // เช็คก่อนว่าเดือนนี้เคยบันทึกไปหรือยัง เพื่อป้องกันการบันทึกซ้ำ
    $check_dup = mysqli_query($conn, "SELECT id FROM payroll_records WHERE pay_month='$save_m' AND pay_year='$save_y'");
    if (mysqli_num_rows($check_dup) > 0) {
        echo "<script>alert('❌ ข้อมูลเดือน $save_m/$save_y ถูกบันทึกเข้าระบบไปแล้ว!'); window.location.href='payroll_summary.php?m=$save_m&y=$save_y';</script>";
        exit();
    }

    // 💡 แก้ไข: ดึงจากคอลัมน์ base_salary
    $sql_emp_save = "SELECT userid, base_salary FROM employees WHERE (status = 'Active' OR status IS NULL) AND role != 'ADMIN'";
    $res_emp_save = mysqli_query($conn, $sql_emp_save);
    $saved_count = 0;
    $processor = $_SESSION['fullname'];

    while($e = mysqli_fetch_assoc($res_emp_save)) {
        // 💡 แก้ไข: ใช้คีย์ base_salary
        $base = floatval($e['base_salary']);
        if($base > 0) { // บันทึกเฉพาะคนที่ถูกตั้งค่าเงินเดือนแล้วเท่านั้น
            $calc = calculatePayroll($conn, $e['userid'], $save_m, $save_y, $base);
            $u_id = $e['userid'];
            $ot_pay = $calc['extra_income'];
            $deduct = $calc['deduction'];
            $net = $calc['net_salary'];
            
            mysqli_query($conn, "INSERT INTO payroll_records (userid, pay_month, pay_year, base_salary, ot_pay, leave_deduction, net_salary, status, processed_by) 
                                 VALUES ('$u_id', '$save_m', '$save_y', '$base', '$ot_pay', '$deduct', '$net', 'Paid', '$processor')");
            $saved_count++;
        }
    }
    echo "<script>alert('✅ บันทึกข้อมูลเงินเดือน $saved_count รายการ สำเร็จเรียบร้อย!'); window.location.href='manage_payroll.php';</script>";
    exit();
}

include '../sidebar.php';
?>

<title>ประมวลผลเงินเดือน | Top Feed Mills</title>
<style>
    .payroll-card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-bottom: 20px; border-top: 4px solid #4e73df;}
    .filter-box { background: #f8f9fc; padding: 15px 20px; border-radius: 10px; display: flex; gap: 15px; align-items: flex-end; margin-bottom: 25px; border: 1px solid #eaecf4; flex-wrap: wrap; }
    .form-control { padding: 10px; border: 1px solid #d1d3e2; border-radius: 8px; font-family: 'Sarabun'; min-width: 150px; }
    .btn-primary { background: #4e73df; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: bold; transition: 0.3s; }
    .btn-primary:hover { background: #2e59d9; }
    .btn-success { background: #1cc88a; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: bold; transition: 0.3s; display: inline-flex; align-items: center; gap: 8px; }
    .btn-success:hover { background: #17a673; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(28,200,138,0.3); }

    table { width: 100%; border-collapse: collapse; min-width: 1100px; }
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 10px; }
    th { background: #f8f9fc; padding: 18px 15px; text-align: left; color: #4e73df; font-size: 14px; white-space: nowrap; border-bottom: 2px solid #eaecf4; font-weight: bold; }
    td { padding: 16px 15px; border-bottom: 1px solid #f1f1f1; font-size: 15px; color: #4a5568; vertical-align: middle; white-space: nowrap; } 
    tr:hover { background: #f8f9fc; }

    .text-green { color: #1cc88a; font-weight: bold; }
    .text-red { color: #e74a3b; font-weight: bold; }
    .text-blue { color: #4e73df; font-weight: bold; font-size: 16px; }
    
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .summary-box { padding: 25px 20px; border-radius: 12px; color: white; display: flex; flex-direction: column; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,0.1); transition: transform 0.3s; }
    .summary-box:hover { transform: translateY(-5px); }
    .sum-base { background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); }
    .sum-ot { background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%); }
    .sum-deduct { background: linear-gradient(135deg, #e74a3b 0%, #be2617 100%); }
    .sum-net { background: linear-gradient(135deg, #f6c23e 0%, #dda20a 100%); color: #333 !important; }
    .summary-box h4 { margin: 0 0 8px 0; font-size: 14px; opacity: 0.9; }
    .summary-box h2 { margin: 0; font-size: 26px; font-weight: bold; }
    
    .badge-dept { background: #e3f2fd; color: #1976d2; padding: 6px 12px; border-radius: 50px; font-size: 13px; font-weight: bold; border: 1px solid #bbdefb; }
    .btn-view { background: #f8f9fc; border: 1px solid #d1d3e2; padding: 6px 15px; border-radius: 8px; cursor: pointer; color: #4e73df; font-weight: bold; transition: 0.2s; }
    .btn-view:hover { background: #4e73df; color: white; border-color: #4e73df; }

    .slip-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(3px); }
    .slip-content { background: white; margin: 3% auto; width: 90%; max-width: 500px; border-radius: 15px; overflow: hidden; box-shadow: 0 15px 35px rgba(0,0,0,0.2); animation: slipPop 0.3s ease; }
    @keyframes slipPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .slip-header { background: #2c3e50; color: white; padding: 20px; text-align: center; position: relative; }
    .slip-header h2 { margin: 0 0 5px 0; color: #f6c23e; font-size: 22px; }
    .slip-close { position: absolute; right: 20px; top: 15px; color: white; font-size: 24px; cursor: pointer; opacity: 0.7; }
    .slip-close:hover { opacity: 1; }
    .slip-body { padding: 25px; }
    .slip-row { display: flex; justify-content: space-between; border-bottom: 1px dashed #eee; padding: 10px 0; font-size: 15px; }
    .slip-row.total { border-top: 2px solid #2c3e50; border-bottom: none; margin-top: 10px; padding-top: 15px; font-weight: bold; font-size: 18px; color: #e74a3b; }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top:0; margin-bottom: 25px;"><i class="fa-solid fa-calculator" style="color: #4e73df;"></i> ระบบประมวลผลเงินเดือน (Payroll Summary)</h2>

    <form method="GET" class="filter-box">
        <div>
            <label style="font-size: 13px; font-weight:bold; color:#555; display:block; margin-bottom:5px;">เลือกเดือน</label>
            <select name="m" class="form-control">
                <?php 
                for($i=1; $i<=12; $i++) {
                    $m_val = str_pad($i, 2, '0', STR_PAD_LEFT);
                    $selected = ($m_val == $m) ? 'selected' : '';
                    echo "<option value='$m_val' $selected>เดือน $m_val</option>";
                }
                ?>
            </select>
        </div>
        <div>
            <label style="font-size: 13px; font-weight:bold; color:#555; display:block; margin-bottom:5px;">เลือกปี</label>
            <select name="y" class="form-control">
                <?php for($i = date('Y')-1; $i <= date('Y')+1; $i++): ?>
                    <option value="<?= $i ?>" <?= ($i == $y) ? 'selected' : '' ?>><?= $i+543 ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div>
            <button type="submit" class="btn-primary"><i class="fa-solid fa-magnifying-glass"></i> คำนวณข้อมูล</button>
        </div>
    </form>

    <?php
    // 💡 แก้ไข: ดึงจากคอลัมน์ base_salary
    $sql_emp = "SELECT userid, username, dept, base_salary, bank_account 
                FROM employees 
                WHERE (status = 'Active' OR status IS NULL) 
                AND role != 'ADMIN' 
                ORDER BY FIELD(dept, 
                    'แผนกผลิต 1', 'แผนกคลังสินค้า 1', 'แผนกซ่อมบำรุง 1', 'แผนกไฟฟ้า 1', 'ฝ่ายวิชาการ', 
                    'แผนก QA', 'แผนก P&M - 1', 'แผนก QC', 'ฝ่ายขาย', 'ฝ่ายจัดซื้อ', 
                    'ฝ่ายบัญชี', 'ฝ่ายสินเชื่อ', 'ฝ่ายการเงิน', 'ฝ่าย HR', 'ฝ่ายงานวางแผน', 
                    'แผนกคอมพิวเตอร์', 'บัญชี - ท็อปธุรกิจ', 'นักศึกษาฝึกงาน', 'ผลิตอาหารสัตว์น้ำ', 
                    'แผนกผลิต 2', 'แผนกคลังสินค้า 2', 'แผนกซ่อมบำรุง 2', 'แผนกไฟฟ้า 2', 'แผนก P&M - 2'
                ) ASC, CAST(userid AS UNSIGNED) ASC";
                
    $res_emp = mysqli_query($conn, $sql_emp);

    $grand_base = 0; $grand_extra = 0; $grand_deduct = 0; $grand_net = 0;
    $payroll_data = [];

    if ($res_emp && mysqli_num_rows($res_emp) > 0) {
        while($emp = mysqli_fetch_assoc($res_emp)) {
            // 💡 แก้ไข: ใช้คีย์ base_salary
            $base = floatval($emp['base_salary']); 
            $calc = calculatePayroll($conn, $emp['userid'], $m, $y, $base);
            
            $grand_base += $base;
            $grand_extra += $calc['extra_income'];
            $grand_deduct += $calc['deduction'];
            $grand_net += $calc['net_salary'];

            $payroll_data[] = [
                'userid' => $emp['userid'],
                'name' => $emp['username'],
                'dept' => $emp['dept'],
                'base' => $base,
                'extra' => $calc['extra_income'],
                'deduct' => $calc['deduction'],
                'net' => $calc['net_salary']
            ];
        }
    }
    ?>

    <div class="summary-grid">
        <div class="summary-box sum-base">
            <h4><i class="fa-solid fa-wallet"></i> รวมฐานเงินเดือน</h4>
            <h2>฿<?php echo number_format($grand_base, 2); ?></h2>
        </div>
        <div class="summary-box sum-ot">
            <h4><i class="fa-solid fa-money-bill-trend-up"></i> รวมรายได้พิเศษ (OT/กะ/ขยัน)</h4>
            <h2>+ ฿<?php echo number_format($grand_extra, 2); ?></h2>
        </div>
        <div class="summary-box sum-deduct">
            <h4><i class="fa-solid fa-file-invoice"></i> รวมรายการหัก (ขาด/สาย)</h4>
            <h2>- ฿<?php echo number_format($grand_deduct, 2); ?></h2>
        </div>
        <div class="summary-box sum-net">
            <h4><i class="fa-solid fa-piggy-bank"></i> ยอดจ่ายสุทธิ (Net Pay)</h4>
            <h2>฿<?php echo number_format($grand_net, 2); ?></h2>
        </div>
    </div>

    <div class="payroll-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h4 style="margin:0; color:#2c3e50;"><i class="fa-solid fa-users"></i> รายละเอียดรายบุคคล (งวดเดือน <?php echo "$m/$y"; ?>)</h4>
            
            <form method="POST" style="margin:0;">
                <input type="hidden" name="save_m" value="<?php echo $m; ?>">
                <input type="hidden" name="save_y" value="<?php echo $y; ?>">
                <button type="submit" name="save_payroll" class="btn-success" onclick="return confirm('ยืนยันการบันทึกข้อมูลเงินเดือนงวด <?php echo $m.'/'.$y; ?> เข้าระบบบัญชีอย่างเป็นทางการ?\n(หากบันทึกแล้วจะไม่สามารถแก้ไขฐานข้อมูลย้อนหลังได้)')">
                    <i class="fa-solid fa-floppy-disk"></i> บันทึกเข้าระบบบัญชี
                </button>
            </form>
        </div>
        
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="width: 10%;">รหัสพนักงาน</th>
                        <th style="width: 20%;">ชื่อ-นามสกุล</th>
                        <th style="width: 15%;">แผนก</th>
                        <th style="width: 12%; text-align:right;">เงินเดือน (Base)</th>
                        <th style="width: 12%; text-align:right;">รายได้พิเศษ (+)</th>
                        <th style="width: 12%; text-align:right;">รายการหัก (-)</th>
                        <th style="width: 12%; text-align:right;">รับสุทธิ (Net Pay)</th>
                        <th style="width: 7%; text-align:center;">สลิป</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($payroll_data)): ?>
                        <?php foreach($payroll_data as $row): 
                            // เตรียม JSON สำหรับส่งไปที่ Modal
                            $slip_json = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr>
                            <td><strong><?php echo $row['userid']; ?></strong></td>
                            <td><?php echo $row['name']; ?></td>
                            <td><span class="badge-dept"><?php echo $row['dept']; ?></span></td>
                            
                            <td style="text-align:right;">
                                <?php if($row['base'] > 0): ?>
                                    <?php echo number_format($row['base'], 2); ?>
                                <?php else: ?>
                                    <a href="manage_users.php" style="color:#e74a3b; font-size:13px; font-weight:bold; text-decoration:underline;"><i class="fa-solid fa-triangle-exclamation"></i> ตั้งค่าฐานเงินเดือน</a>
                                <?php endif; ?>
                            </td>
                            
                            <td style="text-align:right;" class="text-green"><?php echo $row['extra'] > 0 ? '+'.number_format($row['extra'], 2) : '-'; ?></td>
                            <td style="text-align:right;" class="text-red"><?php echo $row['deduct'] > 0 ? '-'.number_format($row['deduct'], 2) : '-'; ?></td>
                            <td style="text-align:right;" class="text-blue">
                                <?php echo ($row['base'] > 0 || $row['extra'] > 0) ? '฿'.number_format($row['net'], 2) : '-'; ?>
                            </td>
                            <td style="text-align:center;">
                                <button class="btn-view" title="ดูสลิปชั่วคราว" onclick="openSlipModal('<?php echo $slip_json; ?>')"><i class="fa-solid fa-eye"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" style="text-align:center; padding:40px; color:#888;">ไม่มีข้อมูลพนักงานในระบบ</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="slipModal" class="slip-modal">
    <div class="slip-content">
        <div class="slip-header">
            <span class="slip-close" onclick="closeSlipModal()"><i class="fa-solid fa-xmark"></i></span>
            <h2>บริษัท ท็อป ฟีด มิลล์ จำกัด</h2>
            <p style="margin:0; opacity:0.8; font-size:14px;">ใบแจ้งเงินเดือนชั่วคราว (Payslip Preview)</p>
            <p style="margin:5px 0 0 0; font-weight:bold; color:#1cc88a;">ประจำงวด: <?php echo "$m/$y"; ?></p>
        </div>
        <div class="slip-body">
            <div style="background:#f8f9fc; padding:15px; border-radius:8px; margin-bottom:20px; font-size:14px; border:1px solid #eaecf4;">
                <strong style="color:#4e73df;">รหัสพนักงาน:</strong> <span id="m_userid"></span><br>
                <strong style="color:#4e73df;">ชื่อ-นามสกุล:</strong> <span id="m_name"></span><br>
                <strong style="color:#4e73df;">แผนก:</strong> <span id="m_dept"></span>
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
    // ฟังก์ชันจัดการ Modal สลิปเงินเดือน
    function openSlipModal(jsonData) {
        const data = JSON.parse(jsonData);
        
        document.getElementById('m_userid').innerText = data.userid;
        document.getElementById('m_name').innerText = data.name;
        document.getElementById('m_dept').innerText = data.dept;
        
        // ใส่ฟอร์แมตตัวเลขให้สวยงาม
        document.getElementById('m_base').innerText = parseFloat(data.base).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' บาท';
        document.getElementById('m_extra').innerText = '+ ' + parseFloat(data.extra).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' บาท';
        document.getElementById('m_deduct').innerText = '- ' + parseFloat(data.deduct).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' บาท';
        document.getElementById('m_net').innerText = parseFloat(data.net).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' บาท';
        
        document.getElementById('slipModal').style.display = "block";
    }

    function closeSlipModal() {
        document.getElementById('slipModal').style.display = "none";
    }

    // ปิด Modal เมื่อคลิกพื้นหลังสีดำ
    window.onclick = function(event) {
        if (event.target == document.getElementById('slipModal')) {
            closeSlipModal();
        }
    }
</script>