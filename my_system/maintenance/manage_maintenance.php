<?php
session_start();
include '../db.php';

// สเต็ปที่ 1: ตรวจสอบว่า "ล็อกอินหรือยัง?" (ถ้ายัง ให้เด้งไปหน้า login ทันที)
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
$fullname = $_SESSION['fullname'] ?? '';

// ดักสิทธิ์ เฉพาะช่างซ่อมบำรุง ไฟฟ้า P&M และระดับจัดการเท่านั้น
$allowed_depts = ['แผนกซ่อมบำรุง 1', 'แผนกซ่อมบำรุง 2', 'แผนกไฟฟ้า 1', 'แผนกไฟฟ้า 2', 'แผนก P&M - 1', 'แผนก P&M - 2'];
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $allowed_depts)) { 
    echo "<script>alert('เฉพาะเจ้าหน้าที่ฝ่ายซ่อมบำรุงเท่านั้น'); window.location='../index.php';</script>"; exit(); 
}

$status_msg = '';

// อัปเดตสถานะงาน (รับงาน / ปิดงาน)
if (isset($_POST['update_maintenance'])) {
    $id = (int)$_POST['req_id'];
    $new_status = $_POST['new_status'];
    $remark = mysqli_real_escape_string($conn, $_POST['tech_remark'] ?? '');

    if ($new_status == 'In_Progress') {
        $sql = "UPDATE maintenance_requests SET status = 'In_Progress', technician_name = '$fullname' WHERE id = '$id'";
    } elseif ($new_status == 'Completed') {
        $sql = "UPDATE maintenance_requests SET status = 'Completed', remark = '$remark' WHERE id = '$id'";
    }

    // ถ้าอัปเดตสถานะลงฐานข้อมูลสำเร็จ
    if (mysqli_query($conn, $sql)) { 
        $status_msg = 'updated'; 
        
        // -----------------------------------------------------------------
        // 🚀 เพิ่มส่วนแจ้งเตือน LINE (แยกตามการกดรับงาน หรือ ปิดงาน)
        // -----------------------------------------------------------------
        include_once '../line_api.php';
        
        // ดึงข้อมูลชื่อเครื่องจักรและชื่อคนแจ้ง จากฐานข้อมูล
        $get_info = mysqli_query($conn, "SELECT machine_name, requester_name FROM maintenance_requests WHERE id = '$id'");
        $info = mysqli_fetch_assoc($get_info);

        // 1. กรณีช่าง "กดรับงาน"
        if ($new_status == 'In_Progress') {
            $msg = "รับทราบ! มีช่างกดรับงานแล้ว 🏃‍♂️💨\n\n";
            $msg .= "⚙️ เครื่องจักร: " . $info['machine_name'] . "\n";
            $msg .= "👨‍🔧 ช่างผู้รับผิดชอบ: " . $fullname . "\n";
            $msg .= "👤 ผู้แจ้ง: " . $info['requester_name'] . "\n\n";
            $msg .= "ช่างกำลังเตรียมเครื่องมือและเข้าตรวจสอบหน้างานครับ";
            
            sendLineMessage($msg);
        }
        
        // 2. กรณีช่าง "ปิดงานซ่อม"
        elseif ($new_status == 'Completed') {
            $msg = "✅ งานซ่อมเสร็จสิ้นแล้ว!\n\n";
            $msg .= "⚙️ เครื่องจักร: " . $info['machine_name'] . "\n";
            $msg .= "👨‍🔧 ซ่อมโดย: " . $fullname . "\n";
            $msg .= "📝 บันทึกช่าง: " . ($remark != '' ? $remark : 'ไม่ได้ระบุ') . "\n";
            $msg .= "👤 แจ้งโดย: " . $info['requester_name'] . "\n\n";
            $msg .= "ผู้แจ้งสามารถตรวจสอบความเรียบร้อยได้เลยครับ";

            sendLineMessage($msg);
        }
        // -----------------------------------------------------------------
    }
}

include '../sidebar.php';
?>

<title>กระดานงานซ่อม | Top Feed Mills</title>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .mnt-card { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-bottom: 25px; border-top: 5px solid #2c3e50; }
    
    .status-badge { padding: 5px 12px; border-radius: 50px; font-size: 12px; font-weight: bold; display: inline-block; }
    .status-Pending { background: #fff3cd; color: #856404; }
    .status-In_Progress { background: #cce5ff; color: #004085; }
    .status-Completed { background: #d4edda; color: #155724; }
    
    .btn-action { border: none; padding: 8px 15px; border-radius: 8px; font-weight: bold; cursor: pointer; color: white; font-size: 13px; transition: 0.3s; display: inline-flex; align-items: center; gap: 5px;}
    .btn-primary-mnt { background: #4e73df; }
    .btn-primary-mnt:hover { background: #2e59d9; }
    .btn-success-mnt { background: #1cc88a; }
    .btn-success-mnt:hover { background: #17a673; }
    
    .priority-tag { font-size: 11px; text-transform: uppercase; padding: 2px 6px; border-radius: 4px; font-weight: bold; margin-left: 5px; }
    .p-Urgent { background: #e74a3b; color: white; }

    table { width: 100%; border-collapse: collapse; min-width: 800px; }
    th { padding:12px; color: #5a5c69; background: #f8f9fc; text-align: left; border-bottom: 2px solid #eee; }
    td { padding:12px; border-bottom: 1px solid #f8f9fc; vertical-align: middle; }
</style>

<div class="content-padding">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2 style="color: #2c3e50; margin:0;"><i class="fa-solid fa-list-check" style="color: #4e73df;"></i> กระดานติดตามงานซ่อมบำรุง (Maintenance Dashboard)</h2>
        <div style="font-size: 14px; color: #666;"><i class="fa-solid fa-user-gear"></i> ผู้รับผิดชอบ: <strong><?php echo $fullname; ?> (<?php echo $user_dept; ?>)</strong></div>
    </div>

    <div class="mnt-card" style="border-top-color: #f6c23e;">
        <h4 style="margin-top:0; color:#856404;"><i class="fa-solid fa-bell"></i> งานแจ้งซ่อมเข้าใหม่ (รอรับงาน)</h4>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width:15%;">วันที่แจ้ง</th>
                        <th style="width:35%;">เครื่องจักร / อาการเสีย</th>
                        <th style="width:20%;">ผู้แจ้งเรื่อง</th>
                        <th style="width:15%;">สถานะ</th>
                        <th style="width:15%; text-align:right;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $req_pending = mysqli_query($conn, "SELECT * FROM maintenance_requests WHERE status = 'Pending' ORDER BY FIELD(priority, 'Urgent', 'High', 'Medium', 'Low'), created_at ASC");
                    if(mysqli_num_rows($req_pending) > 0) {
                        while($row = mysqli_fetch_assoc($req_pending)) {
                    ?>
                    <tr>
                        <td><small><?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?></small></td>
                        <td>
                            <strong style="color: #333; font-size:15px;"><?php echo htmlspecialchars($row['machine_name']); ?></strong>
                            <?php if($row['priority'] == 'Urgent') echo '<span class="priority-tag p-Urgent">ด่วนมาก</span>'; ?>
                            <br><small style="color:#e74a3b;"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($row['issue_description']); ?></small>
                        </td>
                        <td><i class="fa-solid fa-user" style="color:#ccc;"></i> <?php echo htmlspecialchars($row['requester_name']); ?></td>
                        <td><span class="status-badge status-<?php echo $row['status']; ?>"><?php echo $row['status']; ?></span></td>
                        <td style="text-align:right;">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="req_id" value="<?php echo $row['id']; ?>">
                                <input type="hidden" name="new_status" value="In_Progress">
                                <button type="submit" name="update_maintenance" class="btn-action btn-primary-mnt"><i class="fa-solid fa-hand"></i> กดรับงาน</button>
                            </form>
                        </td>
                    </tr>
                    <?php } } else { echo "<tr><td colspan='5' style='text-align:center; padding:20px; color:#1cc88a;'><i class='fa-solid fa-face-smile'></i> ไม่มีงานค้างในระบบ</td></tr>"; } ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="mnt-card" style="border-top-color: #4e73df;">
        <h4 style="margin-top:0; color:#004085;"><i class="fa-solid fa-person-digging"></i> งานที่กำลังซ่อม (In Progress)</h4>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width:15%;">วันที่แจ้ง</th>
                        <th style="width:35%;">เครื่องจักร / อาการเสีย</th>
                        <th style="width:20%;">ผู้แจ้ง / ช่างรับงาน</th>
                        <th style="width:15%;">สถานะ</th>
                        <th style="width:15%; text-align:right;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $req_prog = mysqli_query($conn, "SELECT * FROM maintenance_requests WHERE status = 'In_Progress' ORDER BY id DESC");
                    if(mysqli_num_rows($req_prog) > 0) {
                        while($row = mysqli_fetch_assoc($req_prog)) {
                    ?>
                    <tr>
                        <td><small><?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?></small></td>
                        <td>
                            <strong style="color: #333; font-size:15px;"><?php echo htmlspecialchars($row['machine_name']); ?></strong>
                            <br><small style="color:#555;"><?php echo htmlspecialchars($row['issue_description']); ?></small>
                        </td>
                        <td>
                            <small>ผู้แจ้ง: <?php echo htmlspecialchars($row['requester_name']); ?></small><br>
                            <small style="color:#4e73df; font-weight:bold;">ช่าง: <?php echo htmlspecialchars($row['technician_name']); ?></small>
                        </td>
                        <td><span class="status-badge status-<?php echo $row['status']; ?>">กำลังดำเนินการ</span></td>
                        <td style="text-align:right;">
                            <?php if ($row['technician_name'] == $fullname || $user_role == 'ADMIN'): ?>
                                <button onclick="showCompleteModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['machine_name']); ?>')" class="btn-action btn-success-mnt"><i class="fa-solid fa-check-double"></i> ปิดงานซ่อม</button>
                            <?php else: ?>
                                <small style="color:#888;">งานของช่างท่านอื่น</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php } } else { echo "<tr><td colspan='5' style='text-align:center; padding:20px; color:#888;'>ไม่มีงานกำลังซ่อม</td></tr>"; } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    async function showCompleteModal(id, name) {
        const result = await Swal.fire({
            title: 'ปิดงานซ่อม: ' + name,
            input: 'textarea',
            inputLabel: 'บันทึกวิธีแก้ไข / อะไหล่ที่เบิกใช้ (เว้นว่างได้)',
            inputPlaceholder: 'ระบุรายละเอียดการแก้ไขปัญหา...',
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-check"></i> ยืนยันปิดงาน',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#1cc88a'
        });

        // เช็คแค่ว่ากดปุ่ม "ยืนยัน" หรือไม่ (ไม่สนว่าจะพิมพ์ข้อความไหม)
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            // ถ้าไม่พิมพ์ข้อความ ให้ส่งค่าว่าง ('') กลับไป
            let remark_text = result.value ? result.value : ''; 
            
            form.innerHTML = `
                <input type="hidden" name="req_id" value="${id}">
                <input type="hidden" name="new_status" value="Completed">
                <input type="hidden" name="tech_remark" value="${remark_text}">
                <input type="hidden" name="update_maintenance" value="1">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }

    <?php if($status_msg == 'updated'): ?>
        Swal.fire({ icon: 'success', title: 'อัปเดตสถานะงานสำเร็จ', showConfirmButton: false, timer: 1500 });
        window.history.replaceState(null, null, 'manage_maintenance.php');
    <?php endif; ?>
</script>