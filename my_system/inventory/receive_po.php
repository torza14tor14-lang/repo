<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
$fullname = $_SESSION['fullname'] ?? 'พนักงานคลัง';

$group_wh = ['แผนกคลังสินค้า 1', 'แผนกคลังสินค้า 2'];
$group_pur = ['ฝ่ายจัดซื้อ'];
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $group_wh) && !in_array($user_dept, $group_pur)) { 
    echo "<script>alert('เฉพาะแผนกคลังสินค้าหรือฝ่ายจัดซื้อเท่านั้น'); window.location='../index.php';</script>"; 
    exit(); 
}

// 🚀 [Auto-Update DB] เพิ่มคอลัมน์ status ให้รายการย่อย (po_items) เพื่อแยกรับทีละรายการได้
$check_pi_status = mysqli_query($conn, "SHOW COLUMNS FROM `po_items` LIKE 'status'");
if(mysqli_num_rows($check_pi_status) == 0) {
    mysqli_query($conn, "ALTER TABLE `po_items` ADD `status` VARCHAR(50) DEFAULT 'Pending' AFTER `unit_price`");
    mysqli_query($conn, "UPDATE `po_items` SET `status` = 'Pending'"); // อัปเดตของเก่าให้เป็น Pending
}

// 🚀 [Auto-Update DB] เพิ่มคอลัมน์ wh_id ให้ inventory_lots (ถ้ายังไม่มี)
$check_wh_col = mysqli_query($conn, "SHOW COLUMNS FROM `inventory_lots` LIKE 'wh_id'");
if(mysqli_num_rows($check_wh_col) == 0) {
    mysqli_query($conn, "ALTER TABLE `inventory_lots` ADD `wh_id` INT(11) NOT NULL DEFAULT 0 AFTER `product_id`");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_receive'])) {
    $po_item_id = (int)$_POST['po_item_id']; // 🚀 ไอดีของรายการย่อย
    $po_id = (int)$_POST['po_id'];
    $product_id = (int)$_POST['product_id'];
    $receive_qty = (float)$_POST['receive_qty'];
    $wh_id = (int)$_POST['wh_id']; 
    $scale_no = mysqli_real_escape_string($conn, $_POST['scale_no']);
    $remark = mysqli_real_escape_string($conn, $_POST['remark']);

    if ($receive_qty > 0 && $wh_id > 0) {
        
        // 1. 🚀 อัปเดตสถานะ "เฉพาะรายการนี้" ว่ารับแล้ว
        mysqli_query($conn, "UPDATE po_items SET status = 'Received' WHERE id = $po_item_id");

        // 2. 🚀 ตรวจสอบว่าใน PO นี้ ยังมีรายการอื่นที่รอรับอยู่อีกไหม?
        $q_check_remaining = mysqli_query($conn, "SELECT COUNT(*) as c FROM po_items WHERE po_id = $po_id AND status = 'Pending'");
        $pending_count = mysqli_fetch_assoc($q_check_remaining)['c'];
        
        if ($pending_count == 0) {
            // ถ้าไม่มีของรอรับแล้ว (รับครบทุกรายการ) ค่อยเปลี่ยนสถานะ PO ทั้งใบเป็น Delivered
            mysqli_query($conn, "UPDATE purchase_orders SET status = 'Delivered' WHERE po_id = $po_id");
        }

        // เพิ่มสต็อกเข้า "คลัง Hold"
        mysqli_query($conn, "INSERT INTO stock_balances (product_id, wh_id, qty) 
                             VALUES ($product_id, $wh_id, $receive_qty) 
                             ON DUPLICATE KEY UPDATE qty = qty + $receive_qty");

        $lot_no = "REC-" . date('Ymd') . "-" . $po_id . "-" . $po_item_id; // เติมไอดีให้ LOT ไม่ซ้ำกัน
        
        // สร้าง LOT เก็บลงคลัง Hold
        mysqli_query($conn, "INSERT INTO inventory_lots (product_id, wh_id, lot_no, qty, mfg_date, status) 
                             VALUES ($product_id, $wh_id, '$lot_no', $receive_qty, CURDATE(), 'Pending_QA')");

        $ref_msg = "รับของจาก PO #$po_id (ตาชั่ง: $scale_no) รอกักกัน QA";
        mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, to_wh_id, reference, action_by) 
                             VALUES ($product_id, 'IN', $receive_qty, $wh_id, '$ref_msg', '$fullname')");

        $p_name = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p_name FROM products WHERE id = $product_id"))['p_name'];
        $wh_name = mysqli_fetch_assoc(mysqli_query($conn, "SELECT wh_name FROM warehouses WHERE wh_id = $wh_id"))['wh_name'];
        
        include_once '../line_api.php';
        $msg = "🚚 [คลังรับของเข้า] เรียบร้อยแล้ว\n\n📦 สินค้า: $p_name\n⚖️ ปริมาณ: " . number_format($receive_qty, 2) . "\n📥 เก็บไว้ที่: $wh_name\n🎫 ใบตาชั่ง: $scale_no\n\n👉 ฝ่าย QA โปรดเข้าตรวจสอบคุณภาพด้วยครับ";
        if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

        header("Location: receive_po.php?status=success"); exit();
    }
}

// 🚀 ดึงเฉพาะ "รายการย่อย" (po_items) ที่มีสถานะ Pending
$sql_po = "SELECT po.po_id, po.supplier_name, po.created_at, 
                  pi.id as po_item_id, pi.quantity as qty, 
                  p.p_name, p.p_unit, p.id as p_id 
           FROM purchase_orders po 
           JOIN po_items pi ON po.po_id = pi.po_id 
           JOIN products p ON pi.item_id = p.id
           WHERE po.status IN ('Manager_Approved', 'Approved') AND pi.status = 'Pending' 
           ORDER BY po.po_id DESC, pi.id ASC";
$res_po = mysqli_query($conn, $sql_po);

$res_wh = mysqli_query($conn, "SELECT * FROM warehouses WHERE wh_type = 'Hold' ORDER BY plant ASC, wh_name ASC");

include '../sidebar.php';
?>

<title>รับสินค้าเข้าคลัง (GRN) | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    :root { --primary: #3b82f6; --success: #10b981; --warning: #f59e0b; --bg: #f8fafc; }
    body { font-family: 'Sarabun', sans-serif; background: var(--bg); }
    .content-padding { padding: 30px; max-width: 1400px; margin: auto; }
    .po-card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 20px; border-left: 5px solid var(--primary); display: flex; justify-content: space-between; align-items: center; transition: 0.3s; flex-wrap: wrap; gap:15px;}
    .po-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
    .btn-receive { background: var(--success); color: white; border: none; padding: 12px 20px; border-radius: 10px; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content:center; gap: 8px; font-family:'Sarabun';}
    
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    .modal-content { background: white; border-radius: 20px; width: 90%; max-width: 550px; padding: 35px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); animation: pop 0.3s ease; }
    @keyframes pop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    
    .form-label { display: block; font-weight: 800; margin-bottom: 8px; color: #334155; font-size: 14px; }
    .form-control { width: 100%; padding: 12px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-family: 'Sarabun'; margin-bottom: 20px; box-sizing: border-box; }
    .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
</style>

<div class="content-padding">
    <div style="margin-bottom: 30px;">
        <h2 style="margin:0; color:#1e293b; font-weight:800;"><i class="fa-solid fa-truck-ramp-box" style="color:var(--primary);"></i> รับวัตถุดิบเข้าคลัง (Goods Receipt)</h2>
        <p style="color:#64748b;">รับวัตถุดิบลงคลังกักกัน (Hold) เพื่อรอให้ QA มาตรวจสอบ (สามารถทยอยรับทีละรายการได้)</p>
    </div>

    <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(600px, 1fr)); gap:20px;">
        <?php if ($res_po && mysqli_num_rows($res_po) > 0): ?>
            <?php while($row = mysqli_fetch_assoc($res_po)): ?>
                <div class="po-card">
                    <div>
                        <span style="background:#dbeafe; color:#1e40af; padding:4px 12px; border-radius:50px; font-size:12px; font-weight:bold;">PO #<?= str_pad($row['po_id'], 5, "0", STR_PAD_LEFT) ?></span>
                        <h3 style="margin: 10px 0 5px 0; color:#1e293b;"><?= htmlspecialchars($row['p_name']) ?></h3>
                        <p style="margin:0; color:#64748b; font-size:14px;">
                            <i class="fa-solid fa-store"></i> ผู้ขาย: <?= htmlspecialchars($row['supplier_name']) ?> | 
                            <i class="fa-solid fa-calendar"></i> สั่งเมื่อ: <?= date('d/m/Y', strtotime($row['created_at'])) ?>
                        </p>
                    </div>
                    <div style="text-align: right; min-width: 150px;">
                        <div style="font-size: 22px; font-weight: 800; color: var(--primary); margin-bottom: 10px;">
                            <?= number_format($row['qty'], 2) ?> <small style="font-size: 14px; color:#64748b;"><?= $row['p_unit'] ?></small>
                        </div>
                        <button class="btn-receive" onclick="openReceiveModal(<?= $row['po_item_id'] ?>, <?= $row['po_id'] ?>, <?= $row['p_id'] ?>, '<?= htmlspecialchars($row['p_name']) ?>', <?= $row['qty'] ?>, '<?= $row['p_unit'] ?>')">
                            <i class="fa-solid fa-file-import"></i> บันทึกรับลงคลังกักกัน
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div style="grid-column: 1 / -1; text-align:center; padding:100px; background:white; border-radius:20px; color:#94a3b8; border: 1px dashed #cbd5e1;">
                <i class="fa-solid fa-truck-clock fa-4x" style="margin-bottom:20px; opacity:0.3;"></i><br>
                <h3 style="margin:0;">ไม่มีรายการที่รอรับเข้าในขณะนี้</h3>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="receiveModal" class="modal-overlay">
    <div class="modal-content">
        <h3 style="margin-top:0; color:#1e293b; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 25px;">
            <i class="fa-solid fa-shield-halved" style="color:var(--warning);"></i> ยืนยันรับของลงคลังกักกัน (Hold)
        </h3>
        <form method="POST" onsubmit="return confirm('ยืนยันรับสินค้านี้เข้าคลัง Hold?');">
            <input type="hidden" name="po_item_id" id="m_po_item_id">
            <input type="hidden" name="po_id" id="m_po_id">
            <input type="hidden" name="product_id" id="m_p_id">

            <div style="background: #f8fafc; padding: 15px; border-radius: 12px; margin-bottom: 20px; border: 1px dashed #cbd5e1;">
                <span style="font-size: 13px; color:#64748b;">วัตถุดิบที่จะรับเข้า:</span><br>
                <strong id="m_p_name" style="font-size: 18px; color: #1e293b;">-</strong>
            </div>

            <div style="display:flex; gap:15px; flex-wrap:wrap;">
                <div style="flex:1; min-width:200px;">
                    <label class="form-label">เลขที่ใบตาชั่ง (Scale Ticket) <span style="color:red;">*</span></label>
                    <input type="text" name="scale_no" class="form-control" placeholder="เช่น TK-64001" required>
                </div>
                <div style="flex:1; min-width:200px;">
                    <label class="form-label">น้ำหนักรับจริง (สุทธิ) <span style="color:red;">*</span></label>
                    <input type="number" step="0.001" name="receive_qty" id="m_qty" class="form-control" required style="font-weight:bold; color:var(--primary);">
                </div>
            </div>

            <label class="form-label" style="color:var(--warning);"><i class="fa-solid fa-warehouse"></i> เลือกคลังกักกันรับของ (Hold) <span style="color:red;">*</span></label>
            <select name="wh_id" class="form-control" required style="font-weight:bold; border-color:#fcd34d;">
                <option value="">-- เลือกคลัง Hold --</option>
                <?php 
                if ($res_wh) {
                    mysqli_data_seek($res_wh, 0);
                    while($wh = mysqli_fetch_assoc($res_wh)): 
                ?>
                    <option value="<?= $wh['wh_id'] ?>">
                        [<?= $wh['plant'] ?>] <?= $wh['wh_name'] ?>
                    </option>
                <?php 
                    endwhile; 
                }
                ?>
            </select>
            <small style="color:#d97706; display:block; margin-top:-15px; margin-bottom:20px; font-weight:bold;">* ระบบจำกัดให้รับลงได้เฉพาะคลังประเภท Hold เท่านั้น</small>

            <label class="form-label">หมายเหตุเพิ่มเติม</label>
            <textarea name="remark" class="form-control" rows="2" placeholder="เช่น กระสอบมีรอยฉีกขาด 2 ใบ..."></textarea>

            <div style="display:flex; gap:10px; margin-top:10px;">
                <button type="button" class="btn-receive" style="background:#e2e8f0; color:#475569; flex:1; justify-content:center;" onclick="closeModal()">ยกเลิก</button>
                <button type="submit" name="confirm_receive" class="btn-receive" style="flex:2; justify-content:center;">
                    <i class="fa-solid fa-check-circle"></i> บันทึกรับของรอกักกัน
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // ส่ง po_item_id เข้าไปด้วย เพื่ออัปเดตสถานะทีละรายการ
    function openReceiveModal(po_item_id, po_id, p_id, p_name, qty, unit) {
        document.getElementById('m_po_item_id').value = po_item_id;
        document.getElementById('m_po_id').value = po_id;
        document.getElementById('m_p_id').value = p_id;
        document.getElementById('m_p_name').innerText = p_name;
        document.getElementById('m_qty').value = qty;
        document.getElementById('receiveModal').style.display = 'flex';
    }
    function closeModal() { document.getElementById('receiveModal').style.display = 'none'; }

    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('status') === 'success') {
        Swal.fire({ icon: 'success', title: 'รับของสำเร็จ!', text: 'วัตถุดิบเข้าไปรอกักกันให้ QA ตรวจแล้ว', timer: 2500, showConfirmButton: false })
        .then(() => { window.history.replaceState(null, null, window.location.pathname); });
    }
</script>