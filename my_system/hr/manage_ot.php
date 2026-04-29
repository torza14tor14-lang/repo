<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='/my_system/login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';

$is_hr = ($user_role === 'ADMIN' || $user_dept === 'ฝ่าย HR');

// ส่วนบันทึกข้อมูล OT
if (isset($_POST['save_ot'])) {
    $userid = mysqli_real_escape_string($conn, $_POST['userid']);
    $ot_date = $_POST['ot_date'];
    
    // รับค่าชั่วโมง OT
    $ot_1_h = intval($_POST['ot_1_h'] ?: 0);
    $ot_1_m = intval($_POST['ot_1_m'] ?: 0);
    $ot_15_h = intval($_POST['ot_15_h'] ?: 0);
    $ot_15_m = intval($_POST['ot_15_m'] ?: 0);
    $ot_3_h = intval($_POST['ot_3_h'] ?: 0);
    $ot_3_m = intval($_POST['ot_3_m'] ?: 0);
    
    // รับค่าเงิน (ถ้าไม่มีส่งมาเนื่องจากโดนซ่อนไว้ ให้มีค่าเป็น 0)
    $shift_fee = isset($_POST['shift_fee']) ? intval($_POST['shift_fee']) : 0;
    $diligence_fee = isset($_POST['diligence_fee']) ? intval($_POST['diligence_fee']) : 0;
    
    $recorder = $_SESSION['fullname'] ?? $_SESSION['username'];

    // 🛑 ตรวจสอบสถานะและดึงข้อมูลพนักงาน
    $check_emp = mysqli_query($conn, "SELECT * FROM employees WHERE userid = '$userid'");
    if(mysqli_num_rows($check_emp) > 0) {
        $emp_row = mysqli_fetch_assoc($check_emp);
        if(($emp_row['status'] ?? 'Active') == 'Resigned') {
            echo "<script>alert('❌ ไม่สามารถบันทึกได้! รหัสพนักงานนี้ถูกตั้งสถานะว่าลาออกแล้ว'); window.history.back();</script>";
            exit();
        }
        
        $emp_name = $emp_row['fullname'] ?? $emp_row['username'] ?? $emp_row['name'] ?? 'ไม่ทราบชื่อ';
        $emp_dept = $emp_row['dept'] ?? 'ไม่ระบุแผนก';
        
    } else {
        echo "<script>alert('❌ ไม่พบรหัสพนักงานนี้ในระบบ!'); window.history.back();</script>";
        exit();
    }

    $sql = "INSERT INTO ot_records (userid, ot_date, ot_1_h, ot_1_m, ot_15_h, ot_15_m, ot_3_h, ot_3_m, shift_fee, diligence_fee, recorder) 
            VALUES ('$userid', '$ot_date', '$ot_1_h', '$ot_1_m', '$ot_15_h', '$ot_15_m', '$ot_3_h', '$ot_3_m', '$shift_fee', '$diligence_fee', '$recorder')";
    
    if(mysqli_query($conn, $sql)){
        // -----------------------------------------------------------------
        // 🚀 แจ้งเตือน LINE: พนักงานขอทำ OT
        // -----------------------------------------------------------------
        include_once '../line_api.php';

        $ot_date = $_POST['ot_date'] ?? date('Y-m-d');
        $reason = $_POST['reason'] ?? 'ไม่ได้ระบุ';

        $msg = "⏱️ มีคำร้องขอทำล่วงเวลา (OT) ใหม่!\n\n";
        $msg .= "👤 พนักงาน: " . $emp_name . " (" . $emp_dept . ")\n";
        $msg .= "🗓️ วันที่ทำ OT: " . date('d/m/Y', strtotime($ot_date)) . "\n";
        
        $ot_details = "";
        if($ot_1_h > 0 || $ot_1_m > 0) $ot_details .= "- OT 1 เท่า: {$ot_1_h} ชม. {$ot_1_m} นาที\n";
        if($ot_15_h > 0 || $ot_15_m > 0) $ot_details .= "- OT 1.5 เท่า: {$ot_15_h} ชม. {$ot_15_m} นาที\n";
        if($ot_3_h > 0 || $ot_3_m > 0) $ot_details .= "- OT 3 เท่า: {$ot_3_h} ชม. {$ot_3_m} นาที\n";
        
        $msg .= "⏳ จำนวนเวลา:\n" . ($ot_details != "" ? $ot_details : "- ไม่ได้ระบุ -\n");
        $msg .= "💬 งานที่ทำ: " . ($reason != '' ? $reason : 'ไม่ได้ระบุ') . "\n\n";
        $msg .= "หัวหน้าแผนกโปรดตรวจสอบและอนุมัติในระบบครับ";

        sendLineMessage($msg);
        // -----------------------------------------------------------------
        
        if(function_exists('log_event')) {
            log_event($conn, 'INSERT', 'ot_records', "บันทึก OT ให้พนักงานรหัส $userid วันที่ $ot_date");
        }
        echo "<script>alert('บันทึกขอทำ OT สำเร็จ!'); window.location='manage_ot.php';</script>";
    }
}

include '../sidebar.php';
?>

<title>Top Feed Mills | บันทึก OT</title>
<style>
    /* 🚀 หัวใจสำคัญ ป้องกันกล่องล้นขอบ */
    * { box-sizing: border-box; }

    .ot-card { max-width: auto; margin: auto; background: white; padding: 30px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); border-top: 5px solid #4e73df; } 
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
    @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
    
    .input-group { display: flex; flex-direction: column; gap: 8px; width: 100%; }
    .input-group label { font-weight: bold; font-size: 14px; color: #4e73df; }
    
    /* บังคับ input ให้ 100% เสมอ */
    .input-group input[type="text"], 
    .input-group input[type="date"], 
    .input-group input[type="number"]:not([name^="ot_"]), 
    .input-group select, 
    .input-group textarea { 
        width: 100%; 
        padding: 12px; 
        border: 1px solid #d1d3e2; 
        border-radius: 8px; 
        outline: none; 
        transition: 0.3s; 
        font-family: 'Sarabun'; 
    }
    
    /* สไตล์เฉพาะสำหรับกล่องใส่เวลา OT (ให้แบ่ง 50/50 สวยๆ) */
    .time-input-wrap {
        display: flex; 
        gap: 5px; 
        align-items: center;
        width: 100%;
    }
    .time-input-wrap input {
        width: 100%;
        padding: 12px; 
        border: 1px solid #d1d3e2; 
        border-radius: 8px; 
        outline: none; 
        font-family: 'Sarabun';
    }
    
    .input-group input:focus, .input-group textarea:focus { border-color: #4e73df; box-shadow: 0 0 0 3px rgba(28,200,138,0.1); }
    .display-info { background: #f8f9fc; padding: 15px; border-radius: 8px; border: 1px dashed #4e73df; margin-bottom: 20px; display: flex; justify-content: space-around; }
    .btn-save { width: 100%; padding: 15px; background: #4e73df; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 16px; transition: 0.3s; font-family: 'Sarabun'; }
    .btn-save:hover { background: #2e59d9; transform: translateY(-2px); }
    .section-title { font-size: 14px; font-weight: bold; color: #5a5c69; margin-bottom: 10px; border-left: 4px solid #4e73df; padding-left: 10px; }
    
    /* กล่องรวมชั่วโมง OT */
    .ot-time-grid {
        display: grid; 
        grid-template-columns: repeat(3, 1fr); 
        gap: 15px; 
        margin-bottom: 20px; 
        background: #fdfdfd; 
        padding: 15px; 
        border-radius: 10px; 
        border: 1px solid #eee;
        width: 100%;
    }
    @media (max-width: 768px) {
        .ot-time-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="content-padding">
    <div class="ot-card">
        <h3 style="margin-top:0; color:#2c3e50; text-align:center;"><i class="fa-solid fa-stopwatch" style="color:#4e73df;"></i> บันทึกขอทำล่วงเวลา (OT)</h3>
        <hr style="opacity: 0.2; margin-bottom: 25px;">

        <form method="POST">
            <div class="form-row">
                <div class="input-group">
                    <label>ระบุรหัสพนักงาน (ID)</label>
                    <?php if ($is_hr): ?>
                        <input type="text" name="userid" id="userid" placeholder="ตัวอย่าง: 66041193" required autocomplete="off">
                    <?php else: ?>
                        <input type="text" name="userid" id="userid" value="<?php echo htmlspecialchars($_SESSION['userid']); ?>" readonly style="background-color: #eaecf4; cursor: not-allowed; color:#888;">
                    <?php endif; ?>
                </div>
                <div class="input-group">
                    <label>วันที่ทำ OT</label>
                    <input type="date" name="ot_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>

            <div class="display-info" id="info_box">
                <?php if ($is_hr): ?>
                    <div><strong>ชื่อ:</strong> <span id="show_name">-</span></div>
                    <div><strong>แผนก:</strong> <span id="show_dept">-</span></div>
                <?php else: ?>
                    <div><strong>ชื่อ:</strong> <span id="show_name" style="color: #4e73df; font-weight:bold;"><?php echo htmlspecialchars($_SESSION['fullname']); ?></span></div>
                    <div><strong>แผนก:</strong> <span id="show_dept" style="color: #4e73df; font-weight:bold;"><?php echo htmlspecialchars($_SESSION['dept']); ?></span></div>
                <?php endif; ?>
            </div>

            <div class="section-title">ระบุชั่วโมงทำงานล่วงเวลา</div>
            <div class="ot-time-grid">
                <div class="input-group">
                    <label>OT 1 เท่า (ชม. : น.)</label>
                    <div class="time-input-wrap">
                        <input type="number" name="ot_1_h" value="0" min="0"> :
                        <input type="number" name="ot_1_m" value="0" min="0" max="59">
                    </div>
                </div>
                <div class="input-group">
                    <label>OT 1.5 เท่า (ชม. : น.)</label>
                    <div class="time-input-wrap">
                        <input type="number" name="ot_15_h" value="0" min="0"> :
                        <input type="number" name="ot_15_m" value="0" min="0" max="59">
                    </div>
                </div>
                <div class="input-group">
                    <label>OT 3 เท่า (ชม. : น.)</label>
                    <div class="time-input-wrap">
                        <input type="number" name="ot_3_h" value="0" min="0"> :
                        <input type="number" name="ot_3_m" value="0" min="0" max="59">
                    </div>
                </div>
            </div>

            <div class="form-row" style="margin-bottom: 20px;">
                <div class="input-group" style="grid-column: 1 / -1;">
                    <label>รายละเอียด / งานที่ทำ</label>
                    <textarea name="reason" rows="2" placeholder="ระบุงานที่ทำในช่วงทำ OT..." required></textarea>
                </div>
            </div>

            <?php if ($is_hr): ?>
            <div class="section-title" style="color:#e74a3b; border-color:#e74a3b;">ค่าตอบแทนอื่นๆ (เฉพาะ HR บันทึก)</div>
            <div class="form-row" style="margin-bottom:25px; background: #fff5f5; padding: 15px; border-radius: 10px; width: 100%;">
                <div class="input-group">
                    <label style="color:#e74a3b;">ค่ากะ (บาท)</label>
                    <input type="number" name="shift_fee" value="0" min="0">
                </div>
                <div class="input-group">
                    <label style="color:#e74a3b;">เบี้ยขยัน (บาท)</label>
                    <input type="number" name="diligence_fee" value="0" min="0">
                </div>
            </div>
            <?php endif; ?>

            <button type="submit" name="save_ot" class="btn-save"><i class="fa-solid fa-paper-plane"></i> ส่งคำร้องขอทำ OT</button>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function(){
    $('#userid').not('[readonly]').on('input', function(){
        var uid = $(this).val();
        if(uid.length >= 2){ 
            $.ajax({
                url: 'get_employee.php', 
                method: 'POST',
                data: {userid: uid},
                dataType: 'json',
                success: function(data){
                    if(data.username){
                        $('#show_name').text(data.username).css('color', '#1cc88a').css('font-weight', 'bold');
                        $('#show_dept').text(data.dept).css('color', '#4e73df').css('font-weight', 'bold');
                    } else {
                        $('#show_name').text(data.error).css('color', 'red');
                        $('#show_dept').text('-');
                    }
                }
            });
        } else {
            $('#show_name').text('-');
            $('#show_dept').text('-');
        }
    });
});
</script>