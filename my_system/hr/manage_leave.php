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

// ส่วนบันทึกข้อมูล
if (isset($_POST['save_leave'])) {
    $userid = mysqli_real_escape_string($conn, $_POST['userid']);
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $leave_type = $_POST['leave_type'];
    
    // รับค่าตัวเลข (ถ้าไม่มีช่องให้กรอก จะได้ค่าเป็น 0)
    $d = isset($_POST['d']) ? intval($_POST['d']) : 0;
    $h = isset($_POST['h']) ? intval($_POST['h']) : 0;
    $m = isset($_POST['m']) ? intval($_POST['m']) : 0;
    $t = isset($_POST['t']) ? intval($_POST['t']) : 0;
    
    // 🧠 ระบบคำนวณวันอัตโนมัติ (สำหรับพนักงานทั่วไป)
    if (!$is_hr) {
        $date1 = new DateTime($start_date);
        $date2 = new DateTime($end_date);
        $diff = $date1->diff($date2);
        $d = $diff->days + 1; // บวก 1 เพื่อให้นับรวมวันเริ่มต้นด้วย
    }

    $reason = mysqli_real_escape_string($conn, $_POST['reason'] ?? '');
    $recorder = $_SESSION['fullname'] ?? $_SESSION['userid'];

    // 🛑 ตรวจสอบพนักงาน
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

    // 📷 จัดการอัปโหลดไฟล์รูปภาพ 
    $attachment = '';
    if (isset($_FILES['leave_image']) && $_FILES['leave_image']['error'] == 0) {
        $dir = '../uploads/leaves/';
        if (!is_dir($dir)) { mkdir($dir, 0777, true); }
        $ext = pathinfo($_FILES['leave_image']['name'], PATHINFO_EXTENSION);
        $attachment = 'leave_' . $userid . '_' . time() . '.' . $ext; 
        move_uploaded_file($_FILES['leave_image']['tmp_name'], $dir . $attachment);
    }

    // บันทึกลงฐานข้อมูล
    $sql = "INSERT INTO leave_records (userid, start_date, end_date, leave_type, d, h, m, t, reason, attachment, recorder) 
            VALUES ('$userid', '$start_date', '$end_date', '$leave_type', '$d', '$h', '$m', '$t', '$reason', '$attachment', '$recorder')";
            
    if(mysqli_query($conn, $sql)){
        
        // -----------------------------------------------------------------
        // 🚀 แจ้งเตือน LINE
        // -----------------------------------------------------------------
        include_once '../line_api.php';

        $msg = "📅 มีใบแจ้งวันลา / ขาด / ลากิจ!\n\n";
        $msg .= "👤 พนักงาน: " . $emp_name . " (" . $emp_dept . ")\n";
        $msg .= "📌 ประเภท: " . $leave_type . "\n";
        
        if ($start_date == $end_date) {
            $msg .= "🗓️ วันที่: " . date('d/m/Y', strtotime($start_date)) . "\n";
        } else {
            $msg .= "🗓️ วันที่: " . date('d/m/Y', strtotime($start_date)) . " ถึง " . date('d/m/Y', strtotime($end_date)) . "\n";
        }
        
        $time_str = "";
        if($d > 0) $time_str .= "$d วัน ";
        if($h > 0) $time_str .= "$h ชม. ";
        if($m > 0) $time_str .= "$m นาที ";
        if($t > 0) $time_str .= "$t ครั้ง";
        
        $msg .= "⏳ จำนวน: " . ($time_str != "" ? $time_str : "-") . "\n";
        $msg .= "💬 เหตุผล: " . ($reason != '' ? $reason : 'ไม่ได้ระบุ') . "\n";
        
        if ($attachment != '') {
            $msg .= "📎 (มีไฟล์รูปภาพแนบในระบบ)\n";
        }
        
        $msg .= "\nหัวหน้าแผนกโปรดตรวจสอบและอนุมัติในระบบครับ";

        sendLineMessage($msg);
        // -----------------------------------------------------------------

        echo "<script>alert('บันทึกข้อมูลและส่งแจ้งเตือนสำเร็จ!'); window.location='manage_leave.php';</script>";
    } else {
        echo "<script>alert('เกิดข้อผิดพลาดในการบันทึกข้อมูล'); window.history.back();</script>";
    }
}

include '../sidebar.php';
?>

<title>Top Feed Mills | บันทึกการลา</title>
<style>
    /* 🚀 เติม box-sizing ให้กล่องทุกใบในหน้านี้ เพื่อป้องกันกล่องล้นทะลุกรอบ */
    * { box-sizing: border-box; }

    .leave-card { max-width: auto; margin: auto; background: white; padding: 30px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); border-top: 5px solid #4e73df; } 
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
    @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
    .input-group { display: flex; flex-direction: column; gap: 8px; width: 100%; } /* บังคับ width: 100% */
    .input-group label { font-weight: bold; font-size: 14px; color: #4e73df; }
    
    /* 🚀 ปรับ CSS ช่องกรอกให้หดตัวพอดี */
    .input-group input, .input-group select, .input-group textarea { 
        width: 100%; /* ให้กว้างเต็มที่ที่ทำได้ตามกล่องแม่ */
        padding: 12px; 
        border: 1px solid #d1d3e2; 
        border-radius: 8px; 
        outline: none; 
        transition: 0.3s; 
        font-family: 'Sarabun'; 
    }
    
    .input-group input:focus, .input-group select:focus, .input-group textarea:focus { border-color: #4e73df; box-shadow: 0 0 0 3px rgba(78,115,223,0.1); }
    .display-info { background: #f8f9fc; padding: 15px; border-radius: 8px; border: 1px dashed #4e73df; margin-bottom: 20px; display: flex; justify-content: space-around; }
    .btn-save { width: 100%; padding: 15px; background: #4e73df; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 16px; transition: 0.3s; font-family: 'Sarabun'; margin-top: 15px; }
    .btn-save:hover { background: #2e59d9; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(78,115,223,0.3); }
    
    /* สไตล์สำหรับกล่อง HR */
    .hr-box {
        background: #fff3cd; 
        padding: 20px; 
        border-radius: 8px; 
        border: 1px solid #ffeeba;
        margin-bottom: 20px;
        width: 100%; /* กันล้น */
    }
    .hr-box-title {
        font-size: 13px; 
        color: #856404; 
        font-weight: bold; 
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .hr-grid {
        display: grid; 
        grid-template-columns: repeat(4, 1fr); 
        gap: 15px;
        width: 100%;
    }
    @media (max-width: 600px) {
        .hr-grid { grid-template-columns: repeat(2, 1fr); }
    }
</style>

<div class="content-padding">
    <div class="leave-card">
        <h3 style="margin-top:0; color:#2c3e50; text-align:center;"><i class="fa-solid fa-calendar-plus" style="color:#4e73df;"></i> บันทึกประวัติขออนุญาตลา</h3>
        <hr style="opacity: 0.2; margin-bottom: 25px;">

        <form method="POST" enctype="multipart/form-data">
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
                    <label>ประเภทการลา / เหตุการณ์</label>
                    <select name="leave_type" required>
                        <option value="ลาป่วย">ลาป่วย</option>
                        <option value="ลากิจ">ลากิจ</option>
                        <option value="พักร้อน">ลาพักร้อน</option>
                        <option value="ลาคลอด">ลาคลอด</option>
                        <option value="ลาอื่นๆ">ลาอื่นๆ</option>
                        
                        <?php if ($is_hr): ?>
                        <optgroup label="🔒 สำหรับ HR บันทึกความผิด">
                            <option value="มาสาย">มาสาย</option>
                            <option value="ขาดงาน">ขาดงาน</option>
                            <option value="พักงาน">พักงาน (บทลงโทษ)</option>
                        </optgroup>
                        <?php endif; ?>
                    </select>
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

            <div class="form-row">
                <div class="input-group">
                    <label>ตั้งแต่วันที่</label>
                    <input type="date" name="start_date" id="start_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="input-group">
                    <label>ถึงวันที่</label>
                    <input type="date" name="end_date" id="end_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>

            <?php if ($is_hr): ?>
            <div class="hr-box">
                <div class="hr-box-title">
                    <i class="fa-solid fa-triangle-exclamation"></i> 
                    ส่วนนี้สำหรับ HR แก้ไขเวลาแบบละเอียด (พนักงานจะไม่เห็น)
                </div>
                <div class="hr-grid">
                    <div class="input-group">
                        <label>จำนวน (วัน)</label>
                        <input type="number" name="d" value="0" min="0" style="background:white;">
                    </div>
                    <div class="input-group">
                        <label>จำนวน (ชม.)</label>
                        <input type="number" name="h" value="0" min="0" style="background:white;">
                    </div>
                    <div class="input-group">
                        <label>จำนวน (นาที)</label>
                        <input type="number" name="m" value="0" min="0" style="background:white;">
                    </div>
                    <div class="input-group">
                        <label>จำนวน (ครั้ง)</label>
                        <input type="number" name="t" value="0" min="0" style="background:white;">
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-row">
                <div class="input-group" style="grid-column: 1 / -1;">
                    <label>รายละเอียด / เหตุผล</label>
                    <textarea name="reason" rows="2" placeholder="ระบุเหตุผลให้ชัดเจน..."></textarea>
                </div>
                <div class="input-group" style="grid-column: 1 / -1;">
                    <label><i class="fa-solid fa-paperclip"></i> แนบรูปภาพ / ใบรับรองแพทย์ (ถ้ามี)</label>
                    <input type="file" name="leave_image" accept="image/*, application/pdf" style="background: #f8f9fc;">
                </div>
            </div>

            <button type="submit" name="save_leave" class="btn-save"><i class="fa-solid fa-paper-plane"></i> ยืนยันการบันทึกข้อมูล</button>
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
                        $('#show_name').text(data.username).css('color', '#4e73df').css('font-weight', 'bold');
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

    $('#start_date').on('change', function() {
        var startDate = $(this).val();
        $('#end_date').attr('min', startDate); 
        if($('#end_date').val() < startDate) {
            $('#end_date').val(startDate);
        }
    });
});
</script>