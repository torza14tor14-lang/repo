<?php
session_start();
include '../db.php';

// ล็อกอินและสิทธิ์ (เฉพาะผู้บริหาร และ แผนก IT)
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';

if ($user_role !== 'ADMIN' && $user_dept !== 'แผนกคอมพิวเตอร์') { 
    echo "<script>alert('เฉพาะผู้ดูแลระบบ (Admin) หรือฝ่าย IT เท่านั้น'); window.location='../index.php';</script>"; 
    exit(); 
}

// ==========================================
// 🚀 1. ระบบจัดการฐานข้อมูล (Data Cleanup)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cleanup_logs'])) {
    $months = (int)$_POST['months_to_keep'];
    
    // ลบ Logs ที่เก่ากว่า X เดือนที่กำหนด
    $sql_clean = "DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL $months MONTH)";
    mysqli_query($conn, $sql_clean);
    
    $deleted_rows = mysqli_affected_rows($conn);
    header("Location: view_logs.php?msg=cleaned&rows=$deleted_rows");
    exit();
}

// ==========================================
// 🚀 2. ระบบค้นหา และ กรองข้อมูล (แก้ไขชื่อคอลัมน์ให้ตรง DB)
// ==========================================
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$where_arr = ["1=1"];
if ($search != '') {
    $s = mysqli_real_escape_string($conn, $search);
    $where_arr[] = "(userid LIKE '%$s%' OR username LIKE '%$s%' OR action_type LIKE '%$s%' OR affected_table LIKE '%$s%' OR details LIKE '%$s%')";
}
if ($date_from != '') {
    $where_arr[] = "DATE(created_at) >= '$date_from'";
}
if ($date_to != '') {
    $where_arr[] = "DATE(created_at) <= '$date_to'";
}
$where_sql = implode(' AND ', $where_arr);

// ==========================================
// 🚀 3. ระบบแบ่งหน้า (Pagination) ลดภาระ Server
// ==========================================
$limit = 50; // โชว์หน้าละ 50 แถว
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// นับจำนวนทั้งหมด
$q_total = mysqli_query($conn, "SELECT COUNT(*) as total FROM system_logs WHERE $where_sql");
$total_rows = mysqli_fetch_assoc($q_total)['total'] ?? 0;
$total_pages = ceil($total_rows / $limit);

// ดึงข้อมูลตามหน้า
$q_logs = mysqli_query($conn, "SELECT * FROM system_logs WHERE $where_sql ORDER BY id DESC LIMIT $offset, $limit");

include '../sidebar.php';
?>

<title>ประวัติการใช้งานระบบ (System Logs) | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .wrapper { font-family: 'Sarabun', sans-serif; animation: fadeIn 0.4s ease; }
    .card { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.03); margin-bottom: 25px; }
    
    .filter-box { background: #f8f9fc; padding: 20px; border-radius: 10px; border: 1px solid #eaecf4; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;}
    .form-group { display: flex; flex-direction: column; flex: 1; min-width: 200px; }
    .form-group label { font-size: 13px; font-weight: bold; color: #5a5c69; margin-bottom: 5px; }
    .form-control { padding: 10px; border: 1px solid #d1d3e2; border-radius: 6px; font-family: 'Sarabun'; }
    
    .btn { padding: 10px 20px; border-radius: 6px; border: none; font-weight: bold; cursor: pointer; transition: 0.3s; font-family: 'Sarabun'; display: inline-flex; align-items: center; gap: 5px;}
    .btn-primary { background: #4e73df; color: white; }
    .btn-primary:hover { background: #2e59d9; }
    .btn-danger { background: #e74a3b; color: white; }
    .btn-danger:hover { background: #be2617; }
    .btn-light { background: #eaecf4; color: #5a5c69; text-decoration: none; }
    .btn-light:hover { background: #d1d3e2; }

    table { width: 100%; border-collapse: collapse; min-width: 900px; }
    .table-responsive { overflow-x: auto; width: 100%; border-radius: 8px;}
    th { background: #4e73df; padding: 12px 15px; text-align: left; color: white; font-size: 14px; white-space: nowrap;}
    td { padding: 12px 15px; border-bottom: 1px solid #eaecf4; color: #333; font-size: 14px; vertical-align: top;}
    tr:hover { background: #f8f9fc; }
    
    /* Pagination Styles */
    .pagination { display: flex; list-style: none; padding: 0; margin: 20px 0 0 0; justify-content: center; gap: 5px; flex-wrap: wrap;}
    .pagination a { padding: 8px 15px; border: 1px solid #d1d3e2; border-radius: 6px; color: #4e73df; text-decoration: none; font-weight: bold; transition: 0.2s;}
    .pagination a:hover { background: #eaecf4; }
    .pagination a.active { background: #4e73df; color: white; border-color: #4e73df; }

    .badge-action { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; color: white; }
    .action-LOGIN { background: #1cc88a; }
    .action-UPDATE { background: #f6c23e; color: #333; }
    .action-INSERT { background: #36b9cc; }
    .action-DELETE { background: #e74a3b; }

    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>

<div class="content-padding">
    <div class="wrapper">
        <h2 style="color: #2c3e50; margin-top:0;"><i class="fa-solid fa-shield-halved" style="color: #4e73df;"></i> ประวัติการใช้งานระบบ (System Logs)</h2>
        <p style="color: #888;">ระบบบันทึกความเคลื่อนไหว และการดำเนินการทั้งหมดในระบบ ERP</p>

        <div class="card" style="border-top: 4px solid #f6c23e; padding: 20px;">
            <form method="GET" class="filter-box">
                <div class="form-group">
                    <label>ค้นหา (User ID, ตาราง, รายละเอียด)</label>
                    <input type="text" name="search" class="form-control" placeholder="พิมพ์คำค้นหา..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="form-group">
                    <label>ตั้งแต่วันที่</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="form-group">
                    <label>ถึงวันที่</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> ค้นหา</button>
                    <a href="view_logs.php" class="btn btn-light"><i class="fa-solid fa-rotate-right"></i> รีเซ็ต</a>
                </div>
            </form>
        </div>

        <div class="card" style="border-top: 4px solid #4e73df;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap:10px;">
                <h3 style="margin:0;"><i class="fa-solid fa-list"></i> ข้อมูล Logs (รวม <?= number_format($total_rows) ?> รายการ)</h3>
                
                <button type="button" class="btn btn-danger" onclick="openCleanupModal()">
                    <i class="fa-solid fa-broom"></i> บำรุงรักษาฐานข้อมูล (ลบประวัติเก่า)
                </button>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 15%;">วัน/เวลา</th>
                            <th style="width: 20%;">ผู้ใช้งาน</th>
                            <th style="width: 15%;">IP Address</th>
                            <th style="width: 15%;">การกระทำ (Action)</th>
                            <th style="width: 35%;">รายละเอียด (Details)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($q_logs && mysqli_num_rows($q_logs) > 0): ?>
                            <?php while($row = mysqli_fetch_assoc($q_logs)): 
                                $action = strtoupper($row['action_type']);
                                $action_class = 'action-' . $action;
                                // เผื่อแอคชั่นแปลกๆ ที่ไม่มี CSS กำหนดไว้
                                if(!in_array($action, ['LOGIN', 'UPDATE', 'INSERT', 'DELETE'])) {
                                    $action_class = 'action-UPDATE';
                                }
                            ?>
                            <tr>
                                <td style="white-space: nowrap;"><?= date('d/m/Y H:i:s', strtotime($row['created_at'])) ?></td>
                                <td>
                                    <strong style="color: #2c3e50;"><i class="fa-solid fa-user" style="color:#ccc;"></i> <?= htmlspecialchars($row['userid']) ?></strong><br>
                                    <small style="color: #888;"><?= htmlspecialchars($row['username']) ?></small>
                                </td>
                                <td><span style="background: #eaecf4; padding: 3px 8px; border-radius: 4px; font-family: monospace; font-size: 12px;"><i class="fa-solid fa-network-wired"></i> <?= htmlspecialchars($row['ip_address'] ?? 'Unknown') ?></span></td>
                                <td>
                                    <span class="badge-action <?= $action_class ?>"><?= htmlspecialchars($action) ?></span><br>
                                    <small style="color: #555; margin-top:5px; display:inline-block;"><i class="fa-solid fa-table"></i> <?= htmlspecialchars($row['affected_table']) ?></small>
                                </td>
                                <td style="line-height: 1.5;"><?= htmlspecialchars($row['details']) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center; padding: 40px; color:#888;"><i class="fa-solid fa-folder-open fa-2x" style="color:#ccc; margin-bottom:10px;"></i><br>ไม่มีข้อมูลที่ตรงกับเงื่อนไขการค้นหา</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if($total_pages > 1): ?>
            <div style="display: flex; justify-content: center; align-items: center; margin-top: 20px;">
                <ul class="pagination">
                    <?php if($page > 1): ?>
                        <li><a href="?page=1&search=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>">&laquo; หน้าแรก</a></li>
                        <li><a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>">‹ ก่อนหน้า</a></li>
                    <?php endif; ?>

                    <?php
                    // แสดงตัวเลขหน้า (+/- 2 หน้าจากหน้าปัจจุบัน)
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    for($i = $start_page; $i <= $end_page; $i++) {
                        $active = ($i == $page) ? 'class="active"' : '';
                        echo "<li><a href='?page=$i&search=".urlencode($search)."&date_from=".urlencode($date_from)."&date_to=".urlencode($date_to)."' $active>$i</a></li>";
                    }
                    ?>

                    <?php if($page < $total_pages): ?>
                        <li><a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>">ถัดไป ›</a></li>
                        <li><a href="?page=<?= $total_pages ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>">หน้าสุดท้าย &raquo;</a></li>
                    <?php endif; ?>
                </ul>
            </div>
            <div style="text-align: center; margin-top: 10px; font-size: 13px; color: #888;">หน้า <?= $page ?> จาก <?= $total_pages ?></div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
    // ฟังก์ชัน Popup สำหรับให้ Admin ลบฐานข้อมูลที่เก่าเกินไปทิ้ง
    function openCleanupModal() {
        Swal.fire({
            title: '🧹 บำรุงรักษาฐานข้อมูล',
            html: `
                <div style="text-align: left; font-size: 15px; color: #555;">
                    <p>การเก็บประวัติ (Logs) ไว้เยอะเกินไปจะทำให้ฐานข้อมูลทำงานช้าลง คุณสามารถเลือกลบประวัติที่เก่าเกินไปได้</p>
                    <form id="cleanupForm" method="POST">
                        <label style="font-weight: bold; color:#2c3e50;">ลบ Logs ที่เก่ากว่า :</label>
                        <select name="months_to_keep" class="form-control" style="width: 100%; margin-top: 5px;">
                            <option value="1">1 เดือน (เก็บไว้แค่เดือนล่าสุด)</option>
                            <option value="3" selected>3 เดือน (แนะนำ)</option>
                            <option value="6">6 เดือน</option>
                            <option value="12">1 ปี</option>
                        </select>
                        <input type="hidden" name="cleanup_logs" value="1">
                    </form>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e74a3b',
            cancelButtonColor: '#858796',
            confirmButtonText: '<i class="fa-solid fa-trash"></i> ยืนยันการลบ',
            cancelButtonText: 'ยกเลิก',
            preConfirm: () => {
                document.getElementById('cleanupForm').submit();
            }
        });
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('msg') === 'cleaned') {
        const rows = urlParams.get('rows');
        Swal.fire({ 
            icon: 'success', 
            title: 'ทำความสะอาดสำเร็จ!', 
            text: 'ระบบได้ทำการลบประวัติเก่าออกจำนวน ' + rows + ' รายการ เพื่อเพิ่มพื้นที่ฐานข้อมูลแล้ว', 
            confirmButtonColor: '#4e73df' 
        }).then(() => {
            window.history.replaceState(null, null, 'view_logs.php');
        });
    }
</script>
</body>
</html>