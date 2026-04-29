<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
$fullname = $_SESSION['fullname'] ?? $_SESSION['username'];

if ($user_role !== 'ADMIN' && $user_dept !== 'ฝ่ายจัดซื้อ') { 
    echo "<script>alert('เฉพาะฝ่ายจัดซื้อเท่านั้น'); window.location='../index.php';</script>"; exit(); 
}

// สร้างตาราง purchase_requests หากยังไม่มี
$check_table = mysqli_query($conn, "SHOW TABLES LIKE 'purchase_requests'");
if (mysqli_num_rows($check_table) == 0) {
    mysqli_query($conn, "CREATE TABLE purchase_requests (
        id INT(11) AUTO_INCREMENT PRIMARY KEY, pr_no VARCHAR(50) NOT NULL, item_name VARCHAR(255) NOT NULL,
        qty DECIMAL(10,2) NOT NULL, unit VARCHAR(50) NOT NULL, reason TEXT, request_by VARCHAR(100) NOT NULL,
        request_dept VARCHAR(100) NOT NULL, status VARCHAR(50) DEFAULT 'Pending', approved_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}

if (isset($_POST['submit_po_from_pr'])) {
    $pr_id = (int)$_POST['pr_id'];
    $supplier = mysqli_real_escape_string($conn, $_POST['supplier_name']);
    $date = $_POST['delivery_date'];
    $product_id = (int)$_POST['product_id'];
    $qty = (float)$_POST['qty'];
    $price = (float)$_POST['price'];

    // สร้างใบ PO สถานะ Pending เพื่อให้ผู้บริหารเคาะอนุมัติอีกที (ตามมาตรฐาน)
    mysqli_query($conn, "INSERT INTO purchase_orders (supplier_name, expected_delivery_date, status) VALUES ('$supplier', '$date', 'Pending')");
    $po_id = mysqli_insert_id($conn);

    mysqli_query($conn, "INSERT INTO po_items (po_id, item_id, quantity, unit_price) VALUES ($po_id, $product_id, $qty, $price)");
    $total_amount = $qty * $price;

    mysqli_query($conn, "UPDATE purchase_requests SET status = 'Ordered' WHERE id = $pr_id");

    if(function_exists('log_event')) {
        log_event($conn, 'INSERT', 'purchase_orders', "จัดซื้อเปิดใบสั่งซื้อด่วน PO-$po_id จากใบขอเบิก PR-$pr_id (ยอด: " . number_format($total_amount, 2) . " ฿)");
    }

    include_once '../line_api.php';
    $msg = "🛒 [จัดซื้อ] สร้างใบสั่งซื้อจากใบขอเบิกเรียบร้อย\n\n";
    $msg .= "🧾 อ้างอิง: PO-" . str_pad($po_id, 5, '0', STR_PAD_LEFT) . " (จาก PR-$pr_id)\n🏢 ผู้ขาย: $supplier\n💰 ยอดรวม: " . number_format($total_amount, 2) . " บาท\nพนักงานจัดซื้อ: $fullname\n\n👉 ระบบรอผู้บริหารอนุมัติการสั่งซื้อ PO ใบนี้ครับ";
    if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

    header("Location: process_pr.php?status=success"); exit();
}

include '../sidebar.php';
?>

<title>จัดการใบขอซื้อ (PR) | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .wrapper { font-family: 'Sarabun', sans-serif; animation: fadeIn 0.4s ease; }
    .pr-card { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.03); border-top: 5px solid #f6c23e; margin-bottom: 25px;}
    table { width: 100%; border-collapse: collapse; min-width: 900px; }
    .table-responsive { overflow-x: auto; width: 100%; border-radius: 8px; border: 1px solid #eaecf4;}
    th { background: #f8f9fc; padding: 15px; text-align: left; color: #5a5c69; border-bottom: 2px solid #eaecf4; font-size: 14px;}
    td { padding: 15px; border-bottom: 1px solid #eaecf4; color: #333; font-size: 15px; vertical-align: middle;}
    tr:hover { background: #fcfcfc; }
    .badge-dept { background: #e3f2fd; color: #4e73df; padding: 4px 10px; border-radius: 50px; font-size: 12px; font-weight: bold; }
    .btn-create-po { background: #36b9cc; color: white; border: none; padding: 8px 15px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.3s; font-family: 'Sarabun'; display: inline-flex; align-items: center; gap: 5px;}
    .btn-create-po:hover { background: #2c9faf; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(54,185,204,0.3); }
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(2px);}
    .modal-content { background: white; border-radius: 16px; width: 95%; max-width: 700px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); animation: pop 0.3s ease; }
    @keyframes pop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .modal-header { padding: 20px 25px; border-bottom: 1px solid #eaecf4; display: flex; justify-content: space-between; align-items: center; background: #f8f9fc; border-radius: 16px 16px 0 0;}
    .modal-close { cursor: pointer; font-size: 24px; color: #858796; transition: 0.2s;}
    .modal-close:hover { color: #e74a3b; }
    .modal-body { padding: 25px; }
    .form-group { margin-bottom: 15px; }
    .form-control { width: 100%; padding: 10px 15px; border: 1.5px solid #d1d3e2; border-radius: 8px; font-family: 'Sarabun'; font-size: 15px; box-sizing: border-box;}
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px;}
    .select2-container--default .select2-selection--single { height: 44px; border: 1.5px solid #d1d3e2; border-radius: 8px; display: flex; align-items: center; font-family: 'Sarabun'; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { padding-left: 15px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 42px; right: 10px; }
</style>

<div class="content-padding">
    <div class="wrapper">
        <h2 style="color: #2c3e50; margin-top:0;"><i class="fa-solid fa-clipboard-list" style="color: #f6c23e;"></i> จัดการใบขอซื้อ/ขอเบิก (Process PR)</h2>
        <p style="color:#888;">หน้านี้สำหรับฝ่ายจัดซื้อ ใช้เพื่อนำใบ PR ที่ได้รับอนุมัติจากผู้จัดการแล้ว มาแปลงเป็นใบสั่งซื้อ (PO) ส่งร้านค้าครับ</p>

        <div class="pr-card">
            <h3 style="margin-top:0; color:#555; border-bottom: 1px solid #f0f0f0; padding-bottom: 15px;"><i class="fa-regular fa-clock"></i> ใบขอซื้อ (PR) ที่รอสั่งของ</h3>
            
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>เลขที่ PR</th><th>รายการที่ขอ</th><th>จำนวน</th><th>เหตุผล / การใช้งาน</th><th>ผู้ขอเบิก (แผนก)</th><th style="text-align:right;">ดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $q_pr = mysqli_query($conn, "SELECT * FROM purchase_requests WHERE status = 'Approved' ORDER BY created_at ASC");
                        if ($q_pr && mysqli_num_rows($q_pr) > 0) {
                            while($r = mysqli_fetch_assoc($q_pr)) {
                                $pr_no_disp = $r['pr_no'] ? $r['pr_no'] : "PR-".str_pad($r['id'], 5, '0', STR_PAD_LEFT);
                        ?>
                        <tr>
                            <td><strong style="color:#f6c23e;"><?= $pr_no_disp ?></strong><br><small style="color:#888;"><?= date('d/m/Y', strtotime($r['created_at'])) ?></small></td>
                            <td><strong style="color:#2c3e50;"><?= htmlspecialchars($r['item_name']) ?></strong></td>
                            <td><strong style="color:#e74a3b;"><?= number_format($r['qty'], 2) ?></strong> <small><?= htmlspecialchars($r['unit']) ?></small></td>
                            <td><span style="color:#555; font-size: 13px;"><?= htmlspecialchars($r['reason']) ?></span></td>
                            <td><div><i class="fa-solid fa-user" style="color:#ccc;"></i> <?= htmlspecialchars($r['request_by']) ?></div><div class="badge-dept" style="margin-top:5px; display:inline-block;"><?= htmlspecialchars($r['request_dept']) ?></div></td>
                            <td style="text-align:right;">
                                <button type="button" class="btn-create-po" onclick="openPOModal('<?= $r['id'] ?>', '<?= $pr_no_disp ?>', '<?= htmlspecialchars($r['item_name']) ?>', '<?= $r['qty'] ?>')">
                                    <i class="fa-solid fa-file-export"></i> เปิดใบสั่งซื้อ (PO)
                                </button>
                            </td>
                        </tr>
                        <?php } } else { echo "<tr><td colspan='6' style='text-align:center; padding:40px; color:#888;'><i class='fa-solid fa-check-circle fa-2x' style='color:#1cc88a; margin-bottom:15px;'></i><br>ไม่มีใบขอซื้อที่ค้างสั่งครับ</td></tr>"; } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="poModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3 style="margin:0; color:#36b9cc;"><i class="fa-solid fa-cart-shopping"></i> ออกใบสั่งซื้ออ้างอิง <span id="mod_pr_no" style="color:#f6c23e;"></span></h3>
            <div class="modal-close" onclick="document.getElementById('poModal').style.display='none'"><i class="fa-solid fa-xmark"></i></div>
        </div>
        <form method="POST" onsubmit="return confirm('ยืนยันการเปิด PO?\nระบบจะปรับสถานะใบ PR นี้เป็น สั่งของแล้ว ทันที');">
            <div class="modal-body">
                <input type="hidden" name="pr_id" id="mod_pr_id">
                <div style="background:#fffcf5; padding:15px; border-radius:8px; border:1px dashed #f6c23e; margin-bottom: 20px;">
                    <i class="fa-solid fa-circle-info" style="color:#d48a00;"></i> <b>รายการที่พนักงานขอมา:</b> <span id="mod_item_name" style="color:#2c3e50; font-weight:bold;"></span> (จำนวน <span id="mod_qty_disp" style="color:#e74a3b; font-weight:bold;"></span>)
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label style="font-weight:bold; color:#555;"><i class="fa-regular fa-building"></i> เลือกร้านค้า / Supplier <span style="color:red;">*</span></label>
                        <select name="supplier_name" class="form-control select2" required style="width:100%;">
                            <option value="">-- เลือกร้านค้า --</option>
                            <?php 
                            $s_list = mysqli_query($conn, "SELECT s_name FROM suppliers ORDER BY s_name ASC");
                            while($s = mysqli_fetch_assoc($s_list)) { echo "<option value='{$s['s_name']}'>{$s['s_name']}</option>"; }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="font-weight:bold; color:#555;"><i class="fa-regular fa-calendar"></i> กำหนดวันของมาส่ง <span style="color:red;">*</span></label>
                        <input type="date" name="delivery_date" class="form-control" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" required>
                    </div>
                </div>
                <hr style="border:0; border-bottom:1px solid #eaecf4; margin: 15px 0;">
                
                <div class="form-group">
                    <label style="font-weight:bold; color:#36b9cc;">จับคู่กับรหัสสินค้า (เฉพาะของใช้/อะไหล่ในคลัง) <span style="color:red;">*</span></label>
                    <select name="product_id" class="form-control select2" required style="width:100%;">
                        <option value="">-- พิมพ์ค้นหาอะไหล่หรือวัสดุสิ้นเปลือง --</option>
                        <?php 
                        // 🚀 กรองให้ดึงเฉพาะของที่ไม่ใช่ "วัตถุดิบ (RAW)" และไม่ใช่ "สินค้าสำเร็จรูป (PRODUCT)" มาแสดง
                        $all_items = mysqli_query($conn, "SELECT id, p_name, p_type FROM products WHERE p_type NOT IN ('RAW', 'PRODUCT') ORDER BY p_name ASC");
                        while($row = mysqli_fetch_assoc($all_items)) {
                            echo "<option value='{$row['id']}'>🔧 {$row['p_name']}</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label style="font-weight:bold; color:#555;">จำนวนสั่งซื้อจริง <span style="color:red;">*</span></label>
                        <input type="number" step="0.01" name="qty" id="mod_qty_input" class="form-control" required oninput="calcTotal()">
                    </div>
                    <div class="form-group">
                        <label style="font-weight:bold; color:#555;">ราคาต่อหน่วย (บาท) <span style="color:red;">*</span></label>
                        <input type="number" step="0.01" name="price" id="mod_price_input" class="form-control" placeholder="0.00" required oninput="calcTotal()">
                    </div>
                </div>
                <div style="background:#2c3e50; color:white; padding:15px 20px; border-radius:8px; display:flex; justify-content:space-between; align-items:center; margin-top:10px;">
                    <span style="font-size:14px;">ยอดรวมสุทธิ (บาท):</span>
                    <span id="mod_total" style="font-size:24px; font-weight:bold; color:#1cc88a;">0.00</span>
                </div>
                <div style="margin-top: 20px; text-align:right;">
                    <button type="submit" name="submit_po_from_pr" style="background:#36b9cc; color:white; border:none; padding:12px 25px; border-radius:8px; font-weight:bold; font-size:16px; cursor:pointer; font-family:'Sarabun';">
                        <i class="fa-solid fa-check-circle"></i> ออกใบสั่งซื้อ (PO)
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() { $('.select2').select2(); });
    function openPOModal(id, pr_no, item_name, qty) {
        document.getElementById('mod_pr_id').value = id;
        document.getElementById('mod_pr_no').innerText = pr_no;
        document.getElementById('mod_item_name').innerText = item_name;
        document.getElementById('mod_qty_disp').innerText = qty;
        document.getElementById('mod_qty_input').value = qty;
        document.getElementById('mod_price_input').value = '';
        document.getElementById('mod_total').innerText = '0.00';
        document.getElementById('poModal').style.display = 'flex';
    }
    function calcTotal() {
        let q = parseFloat(document.getElementById('mod_qty_input').value) || 0;
        let p = parseFloat(document.getElementById('mod_price_input').value) || 0;
        document.getElementById('mod_total').innerText = (q * p).toLocaleString('en-US', {minimumFractionDigits: 2});
    }
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('status') === 'success') {
        Swal.fire({ icon: 'success', title: 'สร้าง PO สำเร็จ!', text: 'ส่งข้อมูลให้ผู้บริหารพิจารณาอนุมัติสั่งซื้อแล้ว', confirmButtonColor: '#36b9cc' })
        .then(() => window.history.replaceState(null, null, window.location.pathname));
    }
</script>
</body>
</html>