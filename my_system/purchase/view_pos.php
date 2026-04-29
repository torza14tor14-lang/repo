<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && $user_dept !== 'แผนกคลังสินค้า 1' && $user_dept !== 'แผนกคลังสินค้า 2' && $user_dept !== 'ฝ่ายจัดซื้อ' && $user_dept !== 'แผนก QA' && $user_dept !== 'แผนก QC') { 
    echo "<script>alert('ไม่มีสิทธิ์เข้าถึง'); window.location='../index.php';</script>"; exit(); 
}

// 🚀 ปลดล็อคฐานข้อมูลหน้าดูสถานะ
mysqli_query($conn, "ALTER TABLE `purchase_orders` MODIFY `status` VARCHAR(50) DEFAULT 'Pending'");
mysqli_query($conn, "UPDATE `purchase_orders` SET `status` = 'Received_Pending_QA' WHERE `status` = ''");

include '../sidebar.php'; 
?>

<title>ติดตามใบสั่งซื้อ (PO Tracking) | Top Feed Mills</title>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    .card-full { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
    table { width: 100%; border-collapse: collapse; min-width: 950px; }
    .table-responsive { overflow-x: auto; }
    th { background: #f8f9fc; padding: 15px; text-align: left; color: #5a5c69; border-bottom: 2px solid #eaecf4; font-size: 14px; text-transform: uppercase; white-space: nowrap; }
    td { padding: 15px; border-bottom: 1px solid #eaecf4; color: #333; vertical-align: middle; }
    tr:hover { background: #f8f9fc; }
    
    .badge { padding: 6px 12px; border-radius: 50px; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
    .badge-pending { background: #fff3cd; color: #856404; } 
    .badge-manager { background: #cce5ff; color: #004085; } 
    .badge-approved { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; } 
    .badge-qa { background: #fceceb; color: #e74a3b; border: 1px solid #f5c6cb; } 
    .badge-completed { background: #1cc88a; color: white; } 
    .badge-rejected { background: #f8d7da; color: #721c24; } 
    
    .btn-action { padding: 6px 12px; border-radius: 8px; font-weight: bold; text-decoration: none; display: inline-block; transition: 0.3s; font-size: 13px; border: none; cursor: pointer; margin-right: 5px; }
    .btn-approve1 { background: #4e73df; color: white; } 
    .btn-approve2 { background: #f6c23e; color: #333; } 
    .btn-reject { background: #e74a3b; color: white; } 
    .btn-print { background: #858796; color: white; } 
    .btn-print.active { background: #4e73df; color: white; } 
    
    .btn-action:hover:not(.btn-disabled) { transform: translateY(-2px); filter: brightness(1.1); box-shadow: 0 3px 8px rgba(0,0,0,0.15); }
    .btn-disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top:0;"><i class="fa-solid fa-list-check" style="color: #4e73df;"></i> ติดตามสถานะใบสั่งซื้อ (PO Tracking)</h2>
    <p style="color: #888; margin-bottom: 20px;">* หากผู้จัดการอนุมัติ (Approved) แล้ว ให้ฝ่ายคลังสินค้าไปกดยืนยันรับของที่เมนู <b>"รับของจาก PO (GRN)"</b></p>

    <div class="card-full">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="width:15%">เลขที่ PO</th>
                        <th style="width:25%">Supplier (ผู้ขาย)</th>
                        <th style="width:15%">วันที่สั่ง / กำหนดส่ง</th>
                        <th style="width:25%">สถานะล่าสุด</th>
                        <th style="width:20%; text-align:center;">การจัดการ (Action)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $pos = mysqli_query($conn, "SELECT * FROM purchase_orders ORDER BY po_id DESC");
                    while($po = mysqli_fetch_assoc($pos)) {
                        $po_id = $po['po_id'];
                        $status = $po['status'];
                        $po_ref_string = "PO-" . str_pad($po_id, 5, '0', STR_PAD_LEFT);
                        
                        $badge_class = '';
                        $status_text = '';
                        
                        // 🚀 เช็คสถานะให้ครอบคลุมทั้งข้อมูลใหม่และข้อมูลเก่า (Legacy Data)
                        if ($status == 'Pending') { 
                            $badge_class = 'badge-pending'; $status_text = '<i class="fa-solid fa-clock"></i> รอจัดซื้ออนุมัติ'; 
                        }
                        elseif ($status == 'Manager_Approved' || $status == 'Approved') { 
                            $badge_class = 'badge-approved'; $status_text = '<i class="fa-solid fa-truck"></i> อนุมัติแล้ว (รอคลังรับของ)'; 
                        }
                        elseif ($status == 'Received_Pending_QA') { 
                            $badge_class = 'badge-qa'; $status_text = '<i class="fa-solid fa-microscope"></i> ของถึงแล้ว (รอตรวจ QA)'; 
                        }
                        elseif ($status == 'Completed' || $status == 'Delivered') { 
                            $badge_class = 'badge-completed'; $status_text = '<i class="fa-solid fa-check-double"></i> ตรวจผ่าน / เข้าคลังสำเร็จ'; 
                        }
                        elseif ($status == 'QA_Rejected') { 
                            $badge_class = 'badge-rejected'; $status_text = '<i class="fa-solid fa-ban"></i> QA ตีกลับ (ของเสีย)'; 
                        }
                        elseif ($status == 'Rejected') { 
                            $badge_class = 'badge-rejected'; $status_text = '<i class="fa-solid fa-ban"></i> ไม่อนุมัติ / ยกเลิก'; 
                        } else {
                            $badge_class = 'badge-pending'; $status_text = '<i class="fa-solid fa-circle-question"></i> รอตรวจสอบ';
                        }
                    ?>
                    <tr>
                        <td><strong style="color:#4e73df; font-size:16px;">#<?php echo $po_ref_string; ?></strong></td>
                        <td><strong style="color:#2c3e50;"><i class="fa-solid fa-building"></i> <?php echo $po['supplier_name']; ?></strong></td>
                        <td>
                            <small style="color:#888;">สั่ง: <?php echo date('d/m/Y', strtotime($po['created_at'])); ?></small><br>
                            ส่ง: <strong style="color:#e74a3b;"><?php echo date('d/m/Y', strtotime($po['expected_delivery_date'])); ?></strong>
                        </td>
                        <td><span class="badge <?php echo $badge_class; ?>"><?php echo $status_text; ?></span></td>
                        <td style="text-align:center;">
                            
                            <?php if ($status == 'Pending' && ($user_dept == 'ฝ่ายจัดซื้อ' || $user_role == 'ADMIN')): ?>
                                <button onclick="actionPO(<?php echo $po_id; ?>, 'step1')" class="btn-action btn-approve1"><i class="fa-solid fa-check"></i> อนุมัติ</button>
                                <button onclick="actionPO(<?php echo $po_id; ?>, 'reject')" class="btn-action btn-reject"><i class="fa-solid fa-xmark"></i></button>
                            <?php endif; ?>

                            <?php if ($user_dept == 'ฝ่ายจัดซื้อ' || $user_role == 'ADMIN' || $user_role == 'MANAGER'): ?>
                                <?php if ($status != 'Pending' && $status != 'Rejected'): ?>
                                    <a href="print_po.php?id=<?php echo $po_id; ?>" target="_blank" class="btn-action btn-print active">
                                        <i class="fa-solid fa-print"></i> พิมพ์ PO
                                    </a>
                                <?php else: ?>
                                    <button class="btn-action btn-print btn-disabled" title="ต้องรออนุมัติก่อนถึงจะพิมพ์ได้"><i class="fa-solid fa-print"></i> พิมพ์ PO</button>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($user_dept == 'แผนกคลังสินค้า 1' || $user_dept == 'แผนกคลังสินค้า 2' || $user_role == 'ADMIN'): ?>
                                <?php if ($status == 'Manager_Approved' || $status == 'Approved'): ?>
                                    <br><small style="color:#888; display:block; margin-top:5px;"><i class="fa-solid fa-share-node"></i> ไปรับของที่เมนู GRN</small>
                                <?php endif; ?>
                            <?php endif; ?>

                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function actionPO(id, action) {
        let titleText = '';
        let confirmText = '';
        let btnColor = '';

        if(action === 'step1') { 
            titleText = 'อนุมัติการสั่งซื้อ?'; 
            confirmText = 'ใช่, อนุมัติ'; 
            btnColor = '#4e73df'; 
            action = 'step2'; // ข้ามสเต็ป 2 ให้คลังรับของได้เลย
        }
        if(action === 'reject') { titleText = 'ยกเลิกการสั่งซื้อนี้?'; confirmText = 'ใช่, ยกเลิก'; btnColor = '#e74a3b'; }

        Swal.fire({
            title: titleText, icon: 'warning', showCancelButton: true, confirmButtonColor: btnColor,
            cancelButtonColor: '#858796', confirmButtonText: confirmText, cancelButtonText: 'ปิด'
        }).then((result) => {
            if (result.isConfirmed) { window.location.href = `approve_logic.php?id=${id}&action=${action}&type=PO`; }
        });
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('msg') === 'approved') {
        Swal.fire({ icon: 'success', title: 'อัปเดตสถานะสำเร็จ', showConfirmButton: false, timer: 1500 });
        window.history.replaceState(null, null, 'view_pos.php');
    } else if (urlParams.get('msg') === 'rejected') {
        Swal.fire({ icon: 'error', title: 'ยกเลิกรายการสั่งซื้อแล้ว', showConfirmButton: false, timer: 1500 });
        window.history.replaceState(null, null, 'view_pos.php');
    }
</script>