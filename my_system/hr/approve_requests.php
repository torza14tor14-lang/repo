<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    header("Location: ../login.php"); exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
$my_userid = $_SESSION['userid'];
$fullname  = $_SESSION['fullname'];

// เช็คสิทธิ์ (ต้องเป็น ADMIN, MANAGER หรือ HR เท่านั้น)
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && $user_dept !== 'ฝ่าย HR') { 
    echo "<script>alert('ไม่มีสิทธิ์เข้าถึงหน้านี้'); window.location='../index.php';</script>"; exit(); 
}

// ==========================================
// 🚀 จัดการการกดอนุมัติ / ไม่อนุมัติ พร้อมแจ้งเตือน LINE Notify
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $req_id   = mysqli_real_escape_string($conn, $_POST['req_id']);
    $req_type = $_POST['req_type']; // 'OT' หรือ 'LEAVE'
    $action   = $_POST['action'];   // 'APPROVE' หรือ 'REJECT'
    
    include_once '../line_api.php'; 
    
    $new_status = 'Rejected';
    if ($action == 'APPROVE') {
        if ($user_role == 'ADMIN' || $user_dept == 'ฝ่าย HR') {
            $new_status = 'Approved'; 
        } else {
            $new_status = 'Manager_Approved'; 
        }
    }

    // 📌 กรณีเป็นคำขอทำ OT
    if ($req_type == 'OT') {
        $info_query = mysqli_query($conn, "SELECT o.*, e.username, e.dept FROM ot_records o JOIN employees e ON o.userid = e.userid WHERE o.id = '$req_id'");
        $info = mysqli_fetch_assoc($info_query);
        $emp_name = $info['username'];
        $ot_date_str = date('d/m/Y', strtotime($info['ot_date']));

        // 💡 แก้ไข: ลบ approver_id ออก เพื่อไม่ให้ Error กับฐานข้อมูล
        mysqli_query($conn, "UPDATE ot_records SET status = '$new_status' WHERE id = '$req_id'");

        if ($new_status == 'Manager_Approved') {
            $msg = "✅ หัวหน้าแผนก 'อนุมัติ' การทำ OT แล้ว!\n\n👤 พนักงาน: $emp_name ({$info['dept']})\n🗓️ วันที่ทำ OT: $ot_date_str\n\nHR โปรดเข้าสู่ระบบเพื่อรับทราบและบันทึกข้อมูลครับ";
            sendLineMessage($msg);
        } elseif ($new_status == 'Approved') {
            $msg = "🎉 คำร้องขอทำ OT ของคุณได้รับการ 'ยืนยัน' แล้ว!\n\n👤 พนักงาน: $emp_name\n🗓️ วันที่ทำ OT: $ot_date_str\n\nHR ได้บันทึกข้อมูลเข้าระบบเรียบร้อยแล้ว ลุยงานได้เลยครับ!";
            sendLineMessage($msg);
        } elseif ($new_status == 'Rejected') {
            $msg = "❌ คำร้องขอทำ OT ของคุณ 'ถูกปฏิเสธ'\n\n👤 พนักงาน: $emp_name\n🗓️ วันที่: $ot_date_str\n\nกรุณาติดต่อหัวหน้าแผนก หรือ HR เพื่อสอบถามรายละเอียดครับ";
            sendLineMessage($msg);
        }
    } 
    // 📌 กรณีเป็นคำขอลาหยุด
    else if ($req_type == 'LEAVE') {
        $info_query = mysqli_query($conn, "SELECT l.*, e.username, e.dept FROM leave_records l JOIN employees e ON l.userid = e.userid WHERE l.id = '$req_id'");
        $info = mysqli_fetch_assoc($info_query);
        $emp_name = $info['username'];
        
        $leave_date_str = date('d/m/Y', strtotime($info['start_date']));
        if ($info['start_date'] != $info['end_date']) {
            $leave_date_str .= " - " . date('d/m/Y', strtotime($info['end_date']));
        }

        // 💡 แก้ไข: ลบ approver_id ออก เพื่อไม่ให้ Error กับฐานข้อมูล
        mysqli_query($conn, "UPDATE leave_records SET status = '$new_status' WHERE id = '$req_id'");

        if ($new_status == 'Manager_Approved') {
            $msg = "✅ หัวหน้าแผนกอนุมัติการลาแล้ว!\n\n👤 พนักงาน: $emp_name ({$info['dept']})\n📌 ประเภท: {$info['leave_type']}\n🗓️ วันที่: $leave_date_str\n\nHR โปรดเข้าสู่ระบบเพื่อยืนยันการหักวันลาครับ";
            sendLineMessage($msg);
        } elseif ($new_status == 'Approved') {
            $msg = "🎉 คำร้องขอลาของคุณ 'อนุมัติ' สำเร็จ!\n\n👤 พนักงาน: $emp_name\n📌 ประเภท: {$info['leave_type']}\n🗓️ วันที่: $leave_date_str\n\nHR ทำการบันทึกข้อมูลเรียบร้อยแล้ว พักผ่อนให้เต็มที่นะครับ!";
            sendLineMessage($msg);
        } elseif ($new_status == 'Rejected') {
            $msg = "❌ คำร้องขอลาของคุณ 'ถูกปฏิเสธ'\n\n👤 พนักงาน: $emp_name\n📌 ประเภท: {$info['leave_type']}\n🗓️ วันที่: $leave_date_str\n\nกรุณาติดต่อหัวหน้าแผนก หรือ HR เพื่อสอบถามรายละเอียดเพิ่มเติมครับ";
            sendLineMessage($msg);
        }
    }
    
    echo "<script>window.location.href='approve_requests.php?success=1';</script>";
    exit();
}

// ==========================================
// 🚀 ดึงข้อมูลคำขอ (แยกตามสิทธิ์)
// ==========================================
if ($user_role == 'ADMIN' || $user_dept == 'ฝ่าย HR') {
    $where_cond = "IN ('Pending', 'Manager_Approved')";
    $join_cond  = ""; 
} else {
    $where_cond = "= 'Pending'";
    $join_cond  = "AND e.dept = '$user_dept'"; 
}

$sql_ot = "SELECT o.*, e.username, e.dept FROM ot_records o JOIN employees e ON o.userid = e.userid WHERE o.status $where_cond $join_cond ORDER BY o.ot_date ASC";
$res_ot = mysqli_query($conn, $sql_ot);

$sql_leave = "SELECT l.*, e.username, e.dept FROM leave_records l JOIN employees e ON l.userid = e.userid WHERE l.status $where_cond $join_cond ORDER BY l.created_at ASC";
$res_leave = mysqli_query($conn, $sql_leave);

include '../sidebar.php';
?>

<title>อนุมัติคำขอ | Top Feed Mills</title>
<style>
    /* 🎨 UI Refinement */
    .app-card { background: white; border-radius: 15px; padding: 30px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); margin-bottom: 20px; border-top: 4px solid #f6c23e;}
    
    .tabs-container { background: #f8f9fc; padding: 10px; border-radius: 12px; display: flex; gap: 10px; margin-bottom: 25px; flex-wrap: wrap; border: 1px solid #eaecf4; }
    .tab-btn { flex: 1; min-width: 150px; background: transparent; border: none; padding: 12px 20px; border-radius: 8px; font-family: 'Sarabun'; font-size: 15px; font-weight: bold; color: #858796; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px; }
    .tab-btn:hover { color: #2c3e50; background: rgba(255,255,255,0.6); }
    .tab-btn.active { background: white; color: #4e73df; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .tab-content { display: none; animation: fadeIn 0.4s ease; }
    .tab-content.active { display: block; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

    /* 📊 Table Modernization */
    table { width: 100%; border-collapse: collapse; min-width: 1000px; }
    .table-responsive { overflow-x: auto; padding-bottom: 10px; }
    th { background: #f8f9fc; padding: 15px 20px; text-align: left; color: #4e73df; font-size: 14px; white-space: nowrap; border-bottom: 2px solid #eaecf4; }
    td { padding: 15px 20px; border-bottom: 1px solid #f1f1f1; font-size: 14px; color: #4a5568; vertical-align: middle; }
    tr:hover { background: #fafbfc; }
    
    /* 🏷️ Badges & Buttons */
    .badge-dept { background: #e3f2fd; color: #1976d2; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: bold; border: 1px solid #bbdefb; }
    .badge-status { padding: 5px 12px; border-radius: 50px; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; }
    .st-pending { background: #fff3cd; color: #f6c23e; border: 1px solid #ffeeba; }
    .st-mng-app { background: #cce5ff; color: #28a745; border: 1px solid #b8daff; }

    .action-cell { display: flex; gap: 8px; flex-wrap: wrap; }
    .btn-approve { background: #1cc88a; color: white; border: none; padding: 8px 15px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s; font-family: 'Sarabun'; display: flex; align-items: center; gap: 5px; font-size: 13px;}
    .btn-approve:hover { background: #17a673; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(28,200,138,0.3);}
    .btn-reject { background: #e74a3b; color: white; border: none; padding: 8px 15px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s; font-family: 'Sarabun'; display: flex; align-items: center; gap: 5px; font-size: 13px;}
    .btn-reject:hover { background: #c0392b; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(231,74,59,0.3);}
    
    /* 📄 Attachment Button */
    .btn-doc { background: #f8f9fc; color: #36b9cc; border: 1px solid #bce8f1; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: bold; cursor: pointer; transition: 0.2s; margin-top: 5px; display: inline-flex; align-items: center; gap: 5px;}
    .btn-doc:hover { background: #36b9cc; color: white; }

    /* 🖼️ Image Viewer Modal */
    .img-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(5px); z-index: 9999; justify-content: center; align-items: center; }
    .img-modal-content { background: white; border-radius: 12px; padding: 15px; max-width: 90%; max-height: 90vh; position: relative; animation: zoomIn 0.3s ease; display: flex; flex-direction: column; align-items: center;}
    .img-modal-content img { max-width: 100%; max-height: 75vh; border-radius: 8px; object-fit: contain; }
    .img-close { position: absolute; top: -15px; right: -15px; background: #e74a3b; color: white; width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; cursor: pointer; border: 3px solid white; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
    @keyframes zoomIn { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top:0; margin-bottom: 25px;"><i class="fa-solid fa-clipboard-check" style="color: #f6c23e;"></i> อนุมัติคำขอ (Approval Center)</h2>

    <?php if(isset($_GET['success'])): ?>
        <div style="background: #e8f9f3; color: #1cc88a; padding: 15px 20px; border-radius: 8px; font-weight: bold; margin-bottom: 20px; border: 1px solid #c3e6cb; display: flex; align-items: center; gap: 10px;">
            <i class="fa-solid fa-circle-check" style="font-size: 20px;"></i> ทำรายการและส่งแจ้งเตือนเข้า LINE เรียบร้อยแล้ว!
        </div>
    <?php endif; ?>

    <div class="app-card">
        <div class="tabs-container">
            <button class="tab-btn active" onclick="openTab(event, 'tab-ot')">
                <i class="fa-solid fa-stopwatch"></i> คำขอทำ OT 
                <span style="background:#e74a3b; color:white; padding:2px 8px; border-radius:50px; font-size:12px;"><?= mysqli_num_rows($res_ot) ?></span>
            </button>
            <button class="tab-btn" onclick="openTab(event, 'tab-leave')">
                <i class="fa-solid fa-calendar-plus"></i> คำขอลาหยุด 
                <span style="background:#e74a3b; color:white; padding:2px 8px; border-radius:50px; font-size:12px;"><?= mysqli_num_rows($res_leave) ?></span>
            </button>
        </div>

        <div id="tab-ot" class="tab-content active">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 12%;">วันที่ทำ OT</th>
                            <th style="width: 20%;">ชื่อพนักงาน</th>
                            <th style="width: 15%;">แผนก</th>
                            <th style="width: 20%;">เวลาที่ทำ & ชั่วโมง</th>
                            <th style="width: 13%; text-align:center;">สถานะ</th>
                            <th style="width: 20%;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(mysqli_num_rows($res_ot) > 0): while($row = mysqli_fetch_assoc($res_ot)): ?>
                            <tr>
                                <td><i class="fa-regular fa-calendar"></i> <strong style="color: #2c3e50;"><?= date('d/m/Y', strtotime($row['ot_date'])) ?></strong></td>
                                <td><strong><?= $row['username'] ?></strong><br><small style="color:#858796;">ID: <?= $row['userid'] ?></small></td>
                                <td><span class="badge-dept"><?= $row['dept'] ?></span></td>
                                <td>
                                    <?php if(!empty($row['start_time']) && !empty($row['end_time'])): ?>
                                        <span style="color:#4e73df; font-weight:bold;"><?= date('H:i', strtotime($row['start_time'])) ?> - <?= date('H:i', strtotime($row['end_time'])) ?></span><br>
                                    <?php endif; ?>
                                    <small style="color:#555;">(เรท 1: <?= $row['ot_1_h'] ?> ชม. <?= $row['ot_1_m'] ?> น.)</small>
                                </td>
                                <td style="text-align:center;">
                                    <?php if($row['status'] == 'Pending'): ?>
                                        <span class="badge-status st-pending"><i class="fa-solid fa-hourglass-half"></i> รอหัวหน้า</span>
                                    <?php else: ?>
                                        <span class="badge-status st-mng-app"><i class="fa-solid fa-user-check"></i> รอ HR ยืนยัน</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" class="action-cell" onsubmit="return confirm('ยืนยันการทำรายการนี้หรือไม่? (ระบบจะส่งแจ้งเตือนเข้า LINE อัตโนมัติ)');">
                                        <input type="hidden" name="req_id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="req_type" value="OT">
                                        <button type="submit" name="action" value="APPROVE" class="btn-approve"><i class="fa-solid fa-check"></i> อนุมัติ</button>
                                        <button type="submit" name="action" value="REJECT" class="btn-reject"><i class="fa-solid fa-xmark"></i> ปฏิเสธ</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="6" style="text-align:center; padding: 50px; color:#a0aec0;"><i class="fa-solid fa-box-open fa-2x" style="margin-bottom: 10px;"></i><br>ไม่มีคำขอทำ OT ที่ต้องตรวจสอบ</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="tab-leave" class="tab-content">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 15%;">วันที่ลา</th>
                            <th style="width: 20%;">ชื่อพนักงาน</th>
                            <th style="width: 15%;">ประเภทการลา</th>
                            <th style="width: 20%;">เหตุผล และ เอกสาร</th>
                            <th style="width: 12%; text-align:center;">สถานะ</th>
                            <th style="width: 18%;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(mysqli_num_rows($res_leave) > 0): while($row = mysqli_fetch_assoc($res_leave)): ?>
                            <tr>
                                <td>
                                    <strong style="color: #2c3e50;"><i class="fa-regular fa-calendar"></i> <?= date('d/m/Y', strtotime($row['start_date'])) ?></strong><br>
                                    <small style="color:#858796;">ถึง <?= date('d/m/Y', strtotime($row['end_date'])) ?></small>
                                </td>
                                <td><strong><?= $row['username'] ?></strong><br><small style="color:#858796;">ID: <?= $row['userid'] ?></small></td>
                                <td>
                                    <strong style="color:#e74a3b;"><?= $row['leave_type'] ?></strong> <br>
                                    <small>(<?= $row['d'] ?> วัน <?= $row['h'] ?> ชม.)</small>
                                </td>
                                <td>
                                    <span style="font-size: 13px; color:#555;"><?= htmlspecialchars($row['reason']) ?></span><br>
                                    
                                    <?php 
                                    $doc_file = $row['document'] ?? ($row['attachment'] ?? ''); 
                                    if (!empty($doc_file)): 
                                    ?>
                                        <button type="button" class="btn-doc" onclick="viewImage('../uploads/leaves/<?= htmlspecialchars($doc_file) ?>')">
                                            <i class="fa-solid fa-paperclip"></i> ดูเอกสารแนบ
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if($row['status'] == 'Pending'): ?>
                                        <span class="badge-status st-pending"><i class="fa-solid fa-hourglass-half"></i> รอหัวหน้า</span>
                                    <?php else: ?>
                                        <span class="badge-status st-mng-app"><i class="fa-solid fa-user-check"></i> รอ HR ยืนยัน</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" class="action-cell" onsubmit="return confirm('ยืนยันการทำรายการนี้หรือไม่? (ระบบจะส่งแจ้งเตือนเข้า LINE อัตโนมัติ)');">
                                        <input type="hidden" name="req_id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="req_type" value="LEAVE">
                                        <button type="submit" name="action" value="APPROVE" class="btn-approve"><i class="fa-solid fa-check"></i> อนุมัติ</button>
                                        <button type="submit" name="action" value="REJECT" class="btn-reject"><i class="fa-solid fa-xmark"></i> ปฏิเสธ</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="6" style="text-align:center; padding: 50px; color:#a0aec0;"><i class="fa-solid fa-box-open fa-2x" style="margin-bottom: 10px;"></i><br>ไม่มีคำขอลาหยุดที่ต้องตรวจสอบ</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<div id="imageViewerModal" class="img-modal-overlay">
    <div class="img-modal-content">
        <div class="img-close" onclick="closeImageViewer()"><i class="fa-solid fa-xmark"></i></div>
        <img id="documentImage" src="" alt="เอกสารแนบ">
        <div style="margin-top: 15px; font-weight: bold; color: #2c3e50;">เอกสารแนบ / ใบรับรองแพทย์</div>
    </div>
</div>

<script>
    function openTab(evt, tabId) {
        let tabContents = document.getElementsByClassName("tab-content");
        for (let i = 0; i < tabContents.length; i++) tabContents[i].classList.remove("active");
        
        let tabBtns = document.getElementsByClassName("tab-btn");
        for (let i = 0; i < tabBtns.length; i++) tabBtns[i].classList.remove("active");

        document.getElementById(tabId).classList.add("active");
        evt.currentTarget.classList.add("active");
    }

    // 🚀 ฟังก์ชันเปิดรูปเอกสารแนบ
    function viewImage(imagePath) {
        document.getElementById('documentImage').src = imagePath;
        document.getElementById('imageViewerModal').style.display = 'flex';
    }

    // ฟังก์ชันปิดรูป
    function closeImageViewer() {
        document.getElementById('imageViewerModal').style.display = 'none';
        document.getElementById('documentImage').src = ''; // เคลียร์รูป
    }

    // ปิด Modal ถ้าคลิกพื้นที่สีดำ
    window.onclick = function(event) {
        let modal = document.getElementById('imageViewerModal');
        if (event.target == modal) {
            closeImageViewer();
        }
    }
</script>