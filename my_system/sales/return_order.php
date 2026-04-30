<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';

// ฝ่ายขาย, บัญชี และผู้บริหารเข้าได้
$allowed_depts = ['ฝ่ายขาย', 'ฝ่ายบัญชี', 'ฝ่ายการเงิน', 'บัญชี - ท็อปธุรกิจ'];
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $allowed_depts)) { 
    echo "<script>alert('ไม่มีสิทธิ์เข้าถึงหน้านี้'); window.location='../index.php';</script>"; exit(); 
}

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'];

// 🚀 [Auto-Create Table] สร้างตารางเก็บประวัติการรับคืนสินค้า (RMA)
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sales_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    cus_id INT NOT NULL,
    return_date DATE NOT NULL,
    total_refund DECIMAL(15,2) NOT NULL DEFAULT 0,
    reason TEXT,
    created_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

// 🚀 API สำหรับดึงรายการสินค้าในบิลที่เลือกผ่าน AJAX
if (isset($_GET['get_sale_items'])) {
    $sid = (int)$_GET['get_sale_items'];
    $q = mysqli_query($conn, "SELECT si.product_id, si.quantity, si.unit_price, p.p_name 
                              FROM sales_items si 
                              JOIN products p ON si.product_id = p.id 
                              WHERE si.sale_id = $sid");
    $items = [];
    while($r = mysqli_fetch_assoc($q)) { $items[] = $r; }
    echo json_encode($items);
    exit;
}

// 🚀 จัดการเมื่อกดปุ่ม "ยืนยันการรับคืนสินค้า"
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_return'])) {
    $sale_id = (int)$_POST['sale_id'];
    $wh_id = (int)$_POST['wh_id']; // 🚀 คลังที่เซลส์เลือกโยนของเข้า
    $reason = mysqli_real_escape_string($conn, $_POST['reason']);
    $total_refund = 0;
    
    // ดึงข้อมูลบิลเดิม
    $q_sale = mysqli_query($conn, "SELECT cus_id, customer_name, total_amount FROM sales_orders WHERE sale_id = $sale_id");
    if ($q_sale && mysqli_num_rows($q_sale) > 0) {
        $sale_data = mysqli_fetch_assoc($q_sale);
        $cus_id = $sale_data['cus_id'];
        $cus_name = $sale_data['customer_name'];

        // ประมวลผลสินค้าที่ถูกส่งคืน
        if (isset($_POST['return_qty']) && is_array($_POST['return_qty'])) {
            foreach ($_POST['return_qty'] as $p_id => $ret_qty) {
                $ret_qty = (float)$ret_qty;
                
                if ($ret_qty > 0) {
                    $price = (float)$_POST['unit_price'][$p_id];
                    $refund = $ret_qty * $price;
                    $total_refund += $refund;

                    // 1. นำสินค้ากลับเข้าคลัง โดยตั้งสถานะเป็น Pending_QA (รอกักกันให้ QA ตรวจสอบว่าเสียจริงไหม)
                    $lot_no = "RMA-" . date('Ymd') . "-S" . $sale_id . "-" . $p_id;
                    $exp = date('Y-m-d', strtotime('+30 days')); 
                    mysqli_query($conn, "INSERT INTO inventory_lots (product_id, lot_no, mfg_date, exp_date, qty, status) 
                                         VALUES ($p_id, '$lot_no', CURDATE(), '$exp', $ret_qty, 'Pending_QA')");
                    
                    // 2. 🚀 เพิ่มยอดเข้าคลัง (Multi-Warehouse) ที่เลือกลงกักกัน
                    mysqli_query($conn, "INSERT INTO stock_balances (product_id, wh_id, qty) 
                                         VALUES ($p_id, $wh_id, $ret_qty) 
                                         ON DUPLICATE KEY UPDATE qty = qty + $ret_qty");

                    // 3. บันทึก Log การคืนของ
                    mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, to_wh_id, reference, action_by) 
                                         VALUES ($p_id, 'IN', $ret_qty, $wh_id, 'ลูกค้ารับคืน (RMA จากบิล INV-$sale_id)', '$fullname')");
                }
            }
        }

        if ($total_refund > 0) {
            mysqli_query($conn, "INSERT INTO sales_returns (sale_id, cus_id, return_date, total_refund, reason, created_by) 
                                 VALUES ($sale_id, $cus_id, CURDATE(), $total_refund, '$reason', '$fullname')");
            
            mysqli_query($conn, "UPDATE sales_orders SET total_amount = total_amount - $total_refund WHERE sale_id = $sale_id");

            if(function_exists('log_event')) {
                log_event($conn, 'INSERT', 'sales_returns', "รับคืนสินค้า/ลดหนี้ บิล INV-$sale_id ลูกค้า: $cus_name ยอดคืน " . number_format($total_refund, 2) . " ฿ | เหตุผล: $reason");
            }

            include_once '../line_api.php';
            $msg = "🔄 [ฝ่ายขาย] แจ้งรับคืนสินค้าจากลูกค้า (RMA)\n\n";
            $msg .= "🧾 อ้างอิงบิลเดิม: INV-" . str_pad($sale_id, 5, '0', STR_PAD_LEFT) . "\n";
            $msg .= "👤 ลูกค้า: $cus_name\n";
            $msg .= "💰 ยอดลดหนี้: " . number_format($total_refund, 2) . " บาท\n";
            $msg .= "💬 สาเหตุ: $reason\nผู้รับเรื่อง: $fullname\n\n";
            $msg .= "👉 บัญชี: ระบบทำการลดยอดหนี้ในบิลอัตโนมัติแล้ว\n";
            $msg .= "👉 คลัง&QA: สินค้าตีกลับถูกส่งเข้าโซนกักกัน (Pending QA) โปรดตรวจสอบด้วยครับ";
            if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

            header("Location: return_order.php?status=success"); exit;
        } else {
            header("Location: return_order.php?status=error"); exit;
        }
    }
}

// เตรียมรายชื่อคลังไว้สำหรับเลือกโยนกักกัน
$res_wh = mysqli_query($conn, "SELECT * FROM warehouses ORDER BY plant ASC, wh_name ASC");

include '../sidebar.php';
?>

<title>รับคืนสินค้า / ลดหนี้ | Top Feed Mills</title>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .rma-card { background: white; padding: 30px; border-radius: 16px; box-shadow: 0 5px 20px rgba(0,0,0,0.03); margin-bottom: 20px; border-top: 5px solid #e74a3b; }
    .form-control { width: 100%; padding: 12px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-family: 'Sarabun'; font-size: 15px; }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; min-width: 600px; }
    .table-responsive { width: 100%; overflow-x: auto; border-radius: 8px; border: 1px solid #e2e8f0;}
    th { background: #fceceb; padding: 15px; color: #e74a3b; border-bottom: 2px solid #f5c6cb; text-align: left; }
    td { padding: 12px 15px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
    
    .btn-submit { background: linear-gradient(135deg, #e74a3b 0%, #be2617 100%); color: white; border: none; padding: 15px 30px; border-radius: 10px; font-size: 16px; font-weight: bold; cursor: pointer; margin-top: 20px; float: right; box-shadow: 0 4px 15px rgba(231,74,59,0.3); font-family: 'Sarabun'; transition: 0.3s;}
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(231,74,59,0.4); }
    
    .select2-container--default .select2-selection--single { height: 46px; border: 1.5px solid #e2e8f0; border-radius: 10px; display: flex; align-items: center; font-family: 'Sarabun'; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { padding-left: 15px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 44px; right: 10px; }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top:0;"><i class="fa-solid fa-arrow-rotate-left" style="color: #e74a3b;"></i> รับคืนสินค้า และลดหนี้ลูกหนี้ (RMA / Credit Note)</h2>
    <p style="color: #888; margin-bottom: 20px;">ออกใบลดหนี้ให้ลูกค้า ยอดหนี้จะถูกหักลบอัตโนมัติ และสินค้าจะถูกตีกลับไปกักกันให้ QA ตรวจสอบครับ</p>

    <div class="rma-card">
        <form method="POST" onsubmit="return confirm('ยืนยันการรับคืนสินค้า?\nระบบจะทำการลดยอดหนี้ในบิลเดิม และนำของเข้าโซนกักกันอัตโนมัติ');">
            
            <div style="margin-bottom: 20px;">
                <label style="font-weight:bold; color:#555; display:block; margin-bottom:8px;">1. เลือกบิลขาย (Invoice) ที่ต้องการทำเรื่องคืน <span style="color:red;">*</span></label>
                <select name="sale_id" id="sale_select" class="form-control select2" required onchange="loadSaleItems()">
                    <option value="">-- พิมพ์ค้นหาเลขบิล หรือ ชื่อลูกค้า --</option>
                    <?php 
                    $q_sales = mysqli_query($conn, "SELECT sale_id, customer_name, sale_date, total_amount FROM sales_orders ORDER BY sale_id DESC LIMIT 100");
                    while($s = mysqli_fetch_assoc($q_sales)) {
                        $inv = "INV-" . str_pad($s['sale_id'], 5, '0', STR_PAD_LEFT);
                        echo "<option value='{$s['sale_id']}'>📄 $inv | {$s['customer_name']} | ขายเมื่อ ".date('d/m/y', strtotime($s['sale_date']))."</option>";
                    }
                    ?>
                </select>
            </div>

            <div id="items_area" style="display: none;">
                <label style="font-weight:bold; color:#555; display:block; margin-bottom:8px;">2. ระบุจำนวนสินค้าที่ถูกตีกลับคืน</label>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ชื่อสินค้า (FG)</th>
                                <th style="width: 20%;">จำนวนที่ซื้อไป</th>
                                <th style="width: 20%;">ราคาต่อหน่วย</th>
                                <th style="width: 25%;">ระบุจำนวนที่ส่งคืน (หน่วย)</th>
                            </tr>
                        </thead>
                        <tbody id="items_body"></tbody>
                    </table>
                </div>

                <div style="display:flex; gap:20px; margin-top:20px; flex-wrap:wrap;">
                    <div style="flex:1; min-width:300px;">
                        <label style="font-weight:bold; color:#555; display:block; margin-bottom:8px;">3. เลือกคลังกักกันสำหรับเก็บของคืน <span style="color:red;">*</span></label>
                        <select name="wh_id" class="form-control select2" required style="border-color:#e74a3b;">
                            <option value="">-- เลือกคลัง/โกดัง --</option>
                            <?php 
                            if ($res_wh) {
                                mysqli_data_seek($res_wh, 0);
                                while($wh = mysqli_fetch_assoc($res_wh)) {
                                    $sel = ($wh['wh_type'] == 'Hold' || $wh['wh_type'] == 'Scrap') ? 'selected' : '';
                                    echo "<option value='{$wh['wh_id']}' $sel>[{$wh['plant']}] {$wh['wh_name']}</option>";
                                }
                            }
                            ?>
                        </select>
                        <small style="color:#e74a3b; font-weight:bold; margin-top:5px; display:block;"><i class="fa-solid fa-triangle-exclamation"></i> สินค้าจะถูกกักกัน (Hold) ทันทีจนกว่า QA จะตรวจสอบ</small>
                    </div>

                    <div style="flex:1; min-width:300px;">
                        <label style="font-weight:bold; color:#555; display:block; margin-bottom:8px;">4. สาเหตุการคืนของ <span style="color:red;">*</span></label>
                        <textarea name="reason" class="form-control" rows="2" placeholder="เช่น สินค้าชื้น, ถุงฉีกขาดระหว่างขนส่ง, ส่งผิดสูตร..." required></textarea>
                    </div>
                </div>

                <button type="submit" name="submit_return" class="btn-submit"><i class="fa-solid fa-file-shield"></i> ออกใบลดหนี้ และคืนของเข้าคลัง</button>
                <div style="clear:both;"></div>
            </div>
        </form>
    </div>
    
    <div class="rma-card" style="border-top: 4px solid #858796;">
        <h4 style="margin-top:0; color:#555;"><i class="fa-solid fa-clock-rotate-left"></i> ประวัติการรับคืนสินค้าล่าสุด</h4>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr style="background:#f8f9fc;">
                        <th style="color:#555; border-bottom:2px solid #eaecf4;">วันที่ทำคืน</th>
                        <th style="color:#555; border-bottom:2px solid #eaecf4;">อ้างอิงบิล</th>
                        <th style="color:#555; border-bottom:2px solid #eaecf4;">ยอดลดหนี้ (฿)</th>
                        <th style="color:#555; border-bottom:2px solid #eaecf4;">สาเหตุ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $q_history = mysqli_query($conn, "SELECT * FROM sales_returns ORDER BY id DESC LIMIT 10");
                    if ($q_history && mysqli_num_rows($q_history) > 0) {
                        while($h = mysqli_fetch_assoc($q_history)) {
                            echo "<tr>
                                    <td>".date('d/m/Y H:i', strtotime($h['created_at']))."</td>
                                    <td><strong style='color:#4e73df;'>INV-".str_pad($h['sale_id'], 5, '0', STR_PAD_LEFT)."</strong></td>
                                    <td><strong style='color:#e74a3b;'>".number_format($h['total_refund'], 2)."</strong></td>
                                    <td>{$h['reason']}</td>
                                  </tr>";
                        }
                    } else { echo "<tr><td colspan='4' style='text-align:center; padding:20px; color:#888;'>ยังไม่มีประวัติการทำคืนสินค้า</td></tr>"; }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() { $('.select2').select2({ width: '100%' }); });

    function loadSaleItems() {
        const saleId = document.getElementById('sale_select').value;
        const itemsArea = document.getElementById('items_area');
        const itemsBody = document.getElementById('items_body');
        
        if (!saleId) { itemsArea.style.display = 'none'; return; }

        $.ajax({
            url: 'return_order.php',
            type: 'GET',
            dataType: 'json',
            data: { get_sale_items: saleId },
            success: function(data) {
                itemsBody.innerHTML = '';
                if (data.length > 0) {
                    data.forEach(item => {
                        itemsBody.innerHTML += `
                            <tr>
                                <td><strong>${item.p_name}</strong></td>
                                <td>${item.quantity}</td>
                                <td>${parseFloat(item.unit_price).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                                <td>
                                    <input type="hidden" name="unit_price[${item.product_id}]" value="${item.unit_price}">
                                    <input type="number" step="0.01" min="0" max="${item.quantity}" name="return_qty[${item.product_id}]" class="form-control" placeholder="0" style="border-color:#e74a3b;">
                                </td>
                            </tr>
                        `;
                    });
                    itemsArea.style.display = 'block';
                } else {
                    itemsBody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:red;">ไม่พบรายการสินค้าในบิลนี้</td></tr>`;
                    itemsArea.style.display = 'block';
                }
            }
        });
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('status') === 'success') {
        Swal.fire({ icon: 'success', title: 'รับคืนสำเร็จ!', text: 'ระบบออกใบลดหนี้และแจ้งเตือนผ่าน LINE เรียบร้อยแล้ว', confirmButtonColor: '#e74a3b' })
        .then(() => window.history.replaceState(null, null, window.location.pathname));
    } else if (urlParams.get('status') === 'error') {
        Swal.fire({ icon: 'error', title: 'ผิดพลาด!', text: 'กรุณาระบุจำนวนสินค้าที่ต้องการคืนอย่างน้อย 1 รายการ' })
        .then(() => window.history.replaceState(null, null, window.location.pathname));
    }
</script>