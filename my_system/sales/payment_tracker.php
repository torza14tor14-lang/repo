<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    header("Location: ../login.php"); exit(); 
}

// ดึงข้อมูลสิทธิ์และแผนกของผู้ใช้งาน
$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';

// กำหนดสิทธิ์ผู้ที่มีสิทธิ์กดยืนยันชำระเงิน
$can_approve_payment = ($user_role === 'ADMIN' || $user_role === 'MANAGER' || $user_dept === 'ฝ่ายบัญชี' || $user_dept === 'ฝ่ายการเงิน' || $user_dept === 'บัญชี - ท็อปธุรกิจ');

// 🚀 ส่วนอัปเดตสถานะการจ่ายเงิน
if (isset($_GET['action']) && $_GET['action'] == 'mark_paid') {
    if (!$can_approve_payment) {
        echo "<script>alert('❌ คุณไม่มีสิทธิ์กดยืนยันรับเงิน (ต้องเป็นฝ่ายบัญชีหรือผู้บริหารเท่านั้น)'); window.location='payment_tracker.php';</script>";
        exit();
    }

    $order_id = intval($_GET['id']);
    $user_name = $_SESSION['fullname'] ?? $_SESSION['username'];
    
    // ดึงข้อมูลบิลและลูกค้าก่อน เพื่อใช้คำนวณคืนวงเงินเครดิตและส่ง LINE
    $q_order = mysqli_query($conn, "SELECT s.total_amount, s.cus_id, c.cus_name 
                                    FROM sales_orders s 
                                    LEFT JOIN customers c ON s.cus_id = c.id 
                                    WHERE s.sale_id = '$order_id'");
                                    
    if ($q_order && mysqli_num_rows($q_order) > 0) {
        $order_data = mysqli_fetch_assoc($q_order);
        $total_paid = $order_data['total_amount'];
        $cus_name = $order_data['cus_name'];
        
        // 1. อัปเดตสถานะบิลเป็น "จ่ายแล้ว"
        $sql = "UPDATE sales_orders SET payment_status = 'Paid' WHERE sale_id = '$order_id'";
        
        if (mysqli_query($conn, $sql)) {
            
            // -----------------------------------------------------------------
            // 🚀 บันทึกประวัติ Log ลงระบบ
            // -----------------------------------------------------------------
            if(function_exists('log_event')) {
                log_event($conn, 'UPDATE', 'sales_orders', "รับชำระเงินจาก $cus_name (บิล INV-$order_id) ยอด " . number_format($total_paid, 2) . " ฿");
            }
            // -----------------------------------------------------------------

            // แจ้งเตือน LINE (ส่งให้รับทราบว่าบัญชีรับเงินแล้ว วงเงินลูกค้ากลับมาแล้ว)
            include_once '../line_api.php';
            $msg = "💰 [ฝ่ายบัญชี] ยืนยันการรับชำระเงินสำเร็จ\n\n";
            $msg .= "🧾 เลขที่บิล: INV-" . str_pad($order_id, 5, '0', STR_PAD_LEFT) . "\n";
            $msg .= "👤 ลูกค้า: $cus_name\n";
            $msg .= "💵 ยอดเงิน: " . number_format($total_paid, 2) . " บาท\n";
            $msg .= "ผู้ทำรายการ: $user_name\n\n";
            $msg .= "👉 วงเงินเครดิตของลูกค้ารายนี้ถูกคืนกลับสู่ระบบเรียบร้อย ฝ่ายขายสามารถเปิดบิลใหม่ได้เลยครับ";
            
            if (function_exists('sendLineMessage')) { sendLineMessage($msg); }
            
            header("Location: payment_tracker.php?updated=1"); exit();
        }
    }
}

include '../sidebar.php';

// กรองข้อมูลตามสถานะที่เลือก
$filter = $_GET['status'] ?? 'All';
$where_clause = "";
if ($filter == 'Unpaid') $where_clause = "WHERE s.payment_status != 'Paid'";
// 🐛 แก้ไขเงื่อนไข Overdue ให้แม่นยำ (นับเฉพาะบิลที่ยังไม่จ่าย และ วันหมดอายุน้อยกว่าวันนี้)
if ($filter == 'Overdue') $where_clause = "WHERE s.payment_status != 'Paid' AND s.due_date < CURDATE()";
if ($filter == 'Paid') $where_clause = "WHERE s.payment_status = 'Paid'";

$query = "SELECT s.*, c.cus_name 
          FROM sales_orders s 
          LEFT JOIN customers c ON s.cus_id = c.id 
          $where_clause 
          ORDER BY s.payment_status DESC, s.due_date ASC";
$result = mysqli_query($conn, $query);
?>

<title>Top Feed Mills | ติดตามการชำระเงิน</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .status-card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.03); margin-bottom: 25px; font-family: 'Sarabun'; animation: fadeIn 0.4s ease; border-top: 4px solid #1cc88a; }
    .filter-group { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
    .filter-btn { padding: 10px 20px; border-radius: 50px; text-decoration: none; font-size: 14px; font-weight: bold; border: 1.5px solid #e2e8f0; color: #5a5c69; transition: 0.3s; background: white; }
    .filter-btn:hover { background: #f8f9fc; }
    .filter-btn.active { background: #1cc88a; color: white; border-color: #1cc88a; box-shadow: 0 4px 10px rgba(28, 200, 138, 0.2); }
    .filter-btn.overdue { border-color: #e74a3b; color: #e74a3b; }
    .filter-btn.overdue.active { background: #e74a3b; color: white; border-color: #e74a3b; box-shadow: 0 4px 10px rgba(231, 74, 59, 0.2); }
    
    .table-responsive { overflow-x: auto; border-radius: 10px; border: 1px solid #e2e8f0; }
    table { width: 100%; border-collapse: collapse; min-width: 800px; }
    th { background: #f8f9fc; padding: 15px; text-align: left; color: #4e73df; border-bottom: 2px solid #eaecf4; font-size: 14px; white-space: nowrap; }
    td { padding: 15px; border-bottom: 1px solid #f1f1f1; font-size: 14px; vertical-align: middle; }
    tr:hover { background: #f8f9fc; }
    
    .badge { padding: 6px 12px; border-radius: 50px; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
    .bg-unpaid { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
    .bg-paid { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .bg-overdue { background: #fceceb; color: #e74a3b; border: 1px solid #f5c6cb; animation: pulse 2s infinite; }
    
    @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(231, 74, 59, 0.4); } 70% { box-shadow: 0 0 0 8px rgba(231, 74, 59, 0); } 100% { box-shadow: 0 0 0 0 rgba(231, 74, 59, 0); } }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    
    .btn-pay { background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%); color: white; border: none; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; font-weight: bold; transition: 0.3s; }
    .btn-pay:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(28,200,138,0.3); }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top: 0; margin-bottom: 20px;"><i class="fa-solid fa-money-bill-trend-up" style="color: #1cc88a;"></i> ติดตามสถานะการเงินและการชำระเงิน</h2>

    <div class="filter-group">
        <a href="?status=All" class="filter-btn <?= $filter == 'All' ? 'active' : '' ?>"><i class="fa-solid fa-layer-group"></i> ทั้งหมด</a>
        <a href="?status=Unpaid" class="filter-btn <?= $filter == 'Unpaid' ? 'active' : '' ?>"><i class="fa-solid fa-hourglass-half"></i> รอเก็บเงิน / ค้างชำระ</a>
        <a href="?status=Overdue" class="filter-btn overdue <?= $filter == 'Overdue' ? 'active' : '' ?>"><i class="fa-solid fa-triangle-exclamation"></i> เกินกำหนดจ่าย</a>
        <a href="?status=Paid" class="filter-btn <?= $filter == 'Paid' ? 'active' : '' ?>"><i class="fa-solid fa-check-double"></i> จ่ายแล้ว (ประวัติ)</a>
    </div>

    <div class="status-card">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="width: 15%;">เลขที่บิล</th>
                        <th style="width: 30%;">ลูกค้า / บริษัท</th>
                        <th style="width: 15%;">ยอดสุทธิ (บาท)</th>
                        <th style="width: 15%;">กำหนดชำระ</th>
                        <th style="width: 10%;">สถานะ</th>
                        <th style="width: 15%; text-align:right;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if(mysqli_num_rows($result) > 0) {
                        while($row = mysqli_fetch_assoc($result)) { 
                            $is_overdue = ($row['payment_status'] != 'Paid' && strtotime($row['due_date']) < strtotime(date('Y-m-d')));
                            
                            if ($row['payment_status'] == 'Paid') {
                                $status_class = "bg-paid";
                                $status_text = "<i class='fa-solid fa-check-circle'></i> ชำระแล้ว";
                            } elseif ($is_overdue) {
                                $status_class = "bg-overdue";
                                $status_text = "<i class='fa-solid fa-circle-exclamation'></i> เกินกำหนด";
                            } else {
                                $status_class = "bg-unpaid";
                                $status_text = "<i class='fa-solid fa-clock'></i> รอเก็บเงิน";
                            }
                    ?>
                    <tr>
                        <td><strong style="color: #4e73df; font-size: 16px;">INV-<?= str_pad($row['sale_id'], 5, '0', STR_PAD_LEFT) ?></strong></td>
                        <td><strong style="color: #2c3e50;"><i class="fa-solid fa-user-tag" style="color:#ccc; margin-right:5px;"></i><?= htmlspecialchars($row['cus_name'] ?: 'ลูกค้าทั่วไป') ?></strong></td>
                        <td><strong style="color:#e74a3b; font-size: 16px;"><?= number_format($row['total_amount'], 2) ?></strong></td>
                        <td>
                            <span style="<?= $is_overdue ? 'color:#e74a3b; font-weight:bold;' : 'color:#555;' ?>">
                                <?= date('d/m/Y', strtotime($row['due_date'])) ?>
                            </span>
                        </td>
                        <td><span class="badge <?= $status_class ?>"><?= $status_text ?></span></td>
                        <td style="text-align:right;">
                            <?php if($row['payment_status'] != 'Paid'): ?>
                                
                                <?php if ($can_approve_payment): ?>
                                    <a href="?action=mark_paid&id=<?= $row['sale_id'] ?>" class="btn-pay" onclick="return confirm('ยืนยันว่าได้รับเงินจำนวน <?= number_format($row['total_amount'], 2) ?> บาท เข้าบัญชีบริษัทเรียบร้อยแล้ว?\n\n*วงเงินเครดิตของลูกค้าจะถูกคืนกลับเข้าระบบอัตโนมัติ')">
                                        <i class="fa-solid fa-hand-holding-dollar"></i> ยืนยันรับเงิน
                                    </a>
                                <?php else: ?>
                                    <span style="color:#f6c23e; font-size: 13px; font-weight: bold;">
                                        <i class="fa-solid fa-user-shield"></i> รอฝ่ายบัญชีตรวจสอบ
                                    </span>
                                <?php endif; ?>

                            <?php else: ?>
                                <span style="color:#1cc88a; font-weight: bold; font-size: 13px;">
                                    <i class="fa-solid fa-lock"></i> ปิดบิลแล้ว
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php } } else { echo "<tr><td colspan='6' style='text-align:center; padding:40px; color:#888;'><i class='fa-solid fa-file-invoice-dollar fa-2x'></i><br><br>ไม่มีรายการบิลในหมวดหมู่นี้</td></tr>"; } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    // สคริปต์สำหรับแจ้งเตือนเมื่อการอัปเดตสถานะสำเร็จ
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('updated') === '1') {
        Swal.fire({
            icon: 'success',
            title: 'บันทึกรับเงินสำเร็จ!',
            text: 'ระบบอัปเดตสถานะบิล และส่ง LINE แจ้งเตือนฝ่ายขายแล้ว',
            confirmButtonColor: '#1cc88a',
            timer: 3000
        }).then(() => {
            window.history.replaceState(null, null, window.location.pathname);
        });
    }
</script>