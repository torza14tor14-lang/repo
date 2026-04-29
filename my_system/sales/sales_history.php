<?php
session_start();
include '../db.php';

// สเต็ปที่ 1: ตรวจสอบว่า "ล็อกอินหรือยัง?"
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

// ตรวจสอบสิทธิ์ (Admin, ฝ่ายขาย, ฝ่ายบัญชี/บริหาร สามารถดูได้)
$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && $user_dept !== 'ฝ่ายขาย' && $user_dept !== 'ฝ่ายบัญชี') { 
    echo "<script>alert('ไม่มีสิทธิ์เข้าถึง'); window.location='../index.php';</script>"; exit(); 
}

include '../sidebar.php';
?>

<title>ประวัติการขาย | Top Feed Mills</title>
<style>
    .card-full { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
    table { width: 100%; border-collapse: collapse; min-width: 900px; }
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    th { background: #fff4e6; padding: 15px; text-align: left; color: #fd7e14; border-bottom: 2px solid #ffe8cc; font-size: 14px; white-space: nowrap; }
    td { padding: 15px; border-bottom: 1px solid #eaecf4; color: #4a5568; font-size: 14px; }
    tr:hover { background: #f8f9fc; }
    
    .badge-inv { background: #2c3e50; color: white; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: bold; }
    
    /* 🚀 สไตล์ป้ายสถานะเงิน */
    .badge-status { padding: 5px 10px; border-radius: 50px; font-size: 11px; font-weight: bold; display: inline-block; }
    .bg-unpaid { background: #fff3cd; color: #856404; }
    .bg-paid { background: #d4edda; color: #155724; }
    .bg-credit { background: #e3f2fd; color: #1976d2; }
    
    .btn-print-inv {
        display: inline-block; background: #fd7e14; color: white; padding: 8px 15px; border-radius: 8px; text-decoration: none; font-size: 12px; font-weight: bold; transition: 0.3s;
    }
    .btn-print-inv:hover { background: #e8590c; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(253,126,20,0.4); }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top:0;"><i class="fa-solid fa-file-invoice" style="color: #fd7e14;"></i> ประวัติการขายสินค้า (Sales History)</h2>
    <p style="color: #888; margin-bottom: 20px;">รายการขายทั้งหมดที่ถูกบันทึกและตัดสต็อกออกจากระบบแล้ว</p>

    <div class="card-full">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>เลขที่บิล (INV)</th>
                        <th>วันที่ขาย</th>
                        <th>ชื่อลูกค้า / ฟาร์ม</th>
                        <th>สถานะการชำระเงิน</th>
                        <th style="text-align:right;">ยอดรวม (บาท)</th>
                        <th style="text-align:center;">จำนวนรายการ</th>
                        <th>พนักงานขาย</th>
                        <th style="text-align:center;">จัดการ (พิมพ์)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // 🚀 แก้ไข: เรียงตาม s.sale_id
                    $query = "SELECT s.*, c.cus_name 
                              FROM sales_orders s 
                              LEFT JOIN customers c ON s.cus_id = c.id 
                              ORDER BY s.sale_id DESC LIMIT 100";
                    $sales = mysqli_query($conn, $query);

                    if (mysqli_num_rows($sales) > 0) {
                        while($s = mysqli_fetch_assoc($sales)) {
                            // ดึงจำนวนรายการย่อย
                            $item_count_query = mysqli_query($conn, "SELECT COUNT(*) as c FROM sales_items WHERE sale_id = '{$s['sale_id']}'");
                            $item_count = $item_count_query ? mysqli_fetch_assoc($item_count_query)['c'] : 0;
                            
                            // จัดการป้ายสถานะเงิน (รองรับบิลเก่าที่อาจจะยังไม่มีสถานะ)
                            $payment_status = $s['payment_status'] ?? 'Unpaid';
                            $status_class = "bg-unpaid"; $status_text = "ค้างชำระ";
                            if ($payment_status == 'Paid') { $status_class = "bg-paid"; $status_text = "จ่ายแล้ว"; }
                            elseif ($payment_status == 'Credit') { $status_class = "bg-credit"; $status_text = "รอวางบิล"; }
                            
                            // ชื่อลูกค้า (ถ้าลบลูกค้าไปแล้ว ให้ใช้ชื่อที่พิมพ์ไว้เดิม)
                            $customer_display = !empty($s['cus_name']) ? $s['cus_name'] : ($s['customer_name'] ?? 'ลูกค้าทั่วไป');
                    ?>
                    <tr>
                        <td><span class="badge-inv">INV-<?php echo str_pad($s['sale_id'], 5, '0', STR_PAD_LEFT); ?></span></td>
                        <td><?php echo date('d/m/Y', strtotime($s['sale_date'])); ?></td>
                        <td><strong><?php echo $customer_display; ?></strong></td>
                        <td><span class="badge-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                        <td style="text-align:right; font-weight:bold; color:#fd7e14;">฿<?php echo number_format($s['total_amount'], 2); ?></td>
                        <td style="text-align:center;"><small style="background:#eee; padding:3px 8px; border-radius:10px;"><?php echo $item_count; ?> รายการ</small></td>
                        <td><i class="fa-regular fa-user" style="color:#ccc;"></i> <?php echo $s['created_by']; ?></td>
                        <td style="text-align:center;">
                            <a href="print_invoice.php?id=<?php echo $s['sale_id']; ?>" target="_blank" class="btn-print-inv">
                                <i class="fa-solid fa-print"></i> พิมพ์ใบเสร็จ
                            </a>
                        </td>
                    </tr>
                    <?php 
                        }
                    } else {
                        echo "<tr><td colspan='8' style='text-align:center; padding:30px; color:#999;'>ยังไม่มีประวัติการขาย</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>