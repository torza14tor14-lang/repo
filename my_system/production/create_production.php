<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';

$allowed_depts = ['แผนกผลิต 1', 'แผนกผลิต 2', 'ผลิตอาหารสัตว์น้ำ'];
// 🚀 เพิ่มสิทธิ์ MANAGER และ ADMIN ให้เข้ามาดูและสั่งการได้
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $allowed_depts)) { 
    echo "<script>alert('เฉพาะฝ่ายผลิตเท่านั้น'); window.location='../index.php';</script>"; exit(); 
}

// 🚀 [Auto-Create Table] สร้างตารางเก็บต้นทุนการผลิตของแต่ละ LOT (สำหรับผู้บริหารดู)
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS product_costs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    lot_no VARCHAR(100) NOT NULL,
    production_order_id INT NOT NULL,
    total_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
    cost_per_unit DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

$status = '';
$message = '';

if (isset($_POST['start_production'])) {
    $order_id = (int)$_POST['order_id'];
    $amount = (float)$_POST['amount'];
    $user = $_SESSION['fullname'];

    $q_order = mysqli_query($conn, "SELECT order_no, formula_name FROM production_orders WHERE id = $order_id");
    if(mysqli_num_rows($q_order) > 0) {
        $order_data = mysqli_fetch_assoc($q_order);
        $formula_name = mysqli_real_escape_string($conn, $order_data['formula_name']);
        $order_no = $order_data['order_no'];

        $q_prod = mysqli_query($conn, "SELECT id, shelf_life_days FROM products WHERE p_name = '$formula_name' LIMIT 1");
        
        if (mysqli_num_rows($q_prod) > 0) {
            $prod_data = mysqli_fetch_assoc($q_prod);
            $p_id = $prod_data['id'];
            $shelf_life = (int)$prod_data['shelf_life_days'];

            $formulas = mysqli_query($conn, "SELECT f.ingredient_id, f.quantity_required, p.p_name, p.p_qty, p.p_code 
                                             FROM formulas f 
                                             JOIN products p ON f.ingredient_id = p.id 
                                             WHERE f.product_id = '$p_id'");
            
            $can_produce = true;
            $items_to_deduct = [];
            $total_production_cost = 0; // 🚀 ตัวแปรเก็บต้นทุนรวม
            
            $missing_msg = "<ul style='text-align: left; padding-left: 20px; margin-top: 10px; color: #475569; font-size: 15px;'>";

            while ($f = mysqli_fetch_assoc($formulas)) {
                $need = $f['quantity_required'] * $amount;
                
                if ($f['p_qty'] < $need) {
                    $can_produce = false;
                    $missing = $need - $f['p_qty'];
                    $missing_msg .= "<li style='margin-bottom: 8px;'><b>{$f['p_name']}</b> <br>ขาดอีก <span style='color: #ef4444; font-weight: bold;'>" . number_format($missing, 3) . "</span> กก.</li>";
                } else {
                    // 🚀 ค้นหาราคาวัตถุดิบล่าสุด (Last Purchase Price) จากการสั่งซื้อที่เคยรับเข้า
                    $ing_id = $f['ingredient_id'];
                    $q_price = mysqli_query($conn, "SELECT unit_price FROM po_items 
                                                    JOIN purchase_orders po ON po_items.po_id = po.po_id 
                                                    WHERE item_id = '$ing_id' AND po.status IN ('Delivered', 'Completed') 
                                                    ORDER BY po.created_at DESC LIMIT 1");
                    
                    if ($q_price && mysqli_num_rows($q_price) > 0) {
                        $unit_price = (float)mysqli_fetch_assoc($q_price)['unit_price'];
                    } else {
                        $unit_price = 0; // ถ้าไม่เคยสั่งซื้อเลย ให้เป็น 0 ไปก่อน (อาจจะต้องปรับให้ดึงราคามาตรฐานจากตาราง Products แทนในอนาคต)
                    }
                    
                    $item_cost = $need * $unit_price;
                    $total_production_cost += $item_cost;

                    $items_to_deduct[] = [
                        'id' => $ing_id, 
                        'qty' => $need, 
                        'code' => $f['p_code']
                    ];
                }
            }
            $missing_msg .= "</ul>";

            if ($can_produce && count($items_to_deduct) > 0) {
                // 1. หักสต็อกวัตถุดิบ
                foreach ($items_to_deduct as $item) {
                    mysqli_query($conn, "UPDATE products SET p_qty = p_qty - {$item['qty']} WHERE id = {$item['id']}");
                    mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, reference, action_by) 
                                         VALUES ('{$item['id']}', 'OUT', '{$item['qty']}', 'ผลิตสินค้า FG-#$p_id', '$user')");
                }
                
                // 2. 🚀 สร้าง LOT เข้าสถานะกักกันตรวจสอบ (Pending_QA)
                $lot_no = "LOT-" . date('Ymd') . "-" . str_pad($order_id, 4, "0", STR_PAD_LEFT);
                $mfg_date = date('Y-m-d'); 
                $exp_date = ($shelf_life > 0) ? date('Y-m-d', strtotime($mfg_date. " + {$shelf_life} days")) : '2099-12-31'; 

                mysqli_query($conn, "INSERT INTO inventory_lots (product_id, lot_no, mfg_date, exp_date, qty, status) 
                                     VALUES ('$p_id', '$lot_no', '$mfg_date', '$exp_date', '$amount', 'Pending_QA')");

                mysqli_query($conn, "UPDATE production_orders SET status = 'Completed' WHERE id = $order_id");

                // 3. บันทึกต้นทุนลงตาราง product_costs
                $cost_per_unit = ($amount > 0) ? ($total_production_cost / $amount) : 0;
                mysqli_query($conn, "INSERT INTO product_costs (product_id, lot_no, production_order_id, total_cost, cost_per_unit) 
                                     VALUES ('$p_id', '$lot_no', '$order_id', '$total_production_cost', '$cost_per_unit')");

                // 🚀 บันทึกประวัติ Log ลงระบบ (ใส่เพิ่มตรงนี้ครับ)
                if(function_exists('log_event')) {
                    log_event($conn, 'INSERT', 'inventory_lots', "บันทึกผลิต $formula_name สำเร็จ $amount หน่วย (Lot: $lot_no) ต้นทุนรวม " . number_format($total_production_cost, 2) . " ฿");
                }

                // แจ้งเตือน LINE ไปหา QA
                include_once '../line_api.php';
                $msg = "🏭 [ฝ่ายผลิต] บันทึกการผลิตสำเร็จเรียบร้อย\n\n";
                $msg .= "🔖 ใบสั่งผลิต: $order_no\n";
                $msg .= "📦 สินค้า: $formula_name\n";
                $msg .= "✅ จำนวนที่ได้: " . number_format($amount, 2) . " หน่วย\n\n";
                $msg .= "⚠️ สถานะ: รอกักกันตรวจสอบคุณภาพ (Pending QA) ก่อนนำเข้าสต็อกหลัก\n";
                $msg .= "👉 ฝ่าย QA โปรดตรวจสอบคุณภาพ Lot: $lot_no";
                
                if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

                $status = 'success';
            } else if (!$can_produce) {
                $status = 'error';
                $message = $missing_msg;
            } else {
                $status = 'warning';
                $message = 'สินค้านี้ยังไม่ได้ตั้งค่าสูตรการผลิต (BOM) ในระบบ ทำให้ไม่สามารถคำนวณการตัดสต็อกได้';
            }
        }
    }
}

// 🚀 API สำหรับดึงข้อมูลคำนวณต้นทุน (เมื่อเลือก Dropdown จะโหลดข้อมูลราคาวัตถุดิบ)
if (isset($_GET['api_cost']) && isset($_GET['order_id'])) {
    $oid = (int)$_GET['order_id'];
    $q_ord = mysqli_query($conn, "SELECT target_qty, formula_name FROM production_orders WHERE id = $oid");
    if ($q_ord && mysqli_num_rows($q_ord) > 0) {
        $ord = mysqli_fetch_assoc($q_ord);
        $amount = (float)$ord['target_qty'];
        $fname = mysqli_real_escape_string($conn, $ord['formula_name']);
        
        $q_p = mysqli_query($conn, "SELECT id FROM products WHERE p_name = '$fname' LIMIT 1");
        $pid = mysqli_fetch_assoc($q_p)['id'] ?? 0;
        
        $formulas = mysqli_query($conn, "SELECT f.ingredient_id, f.quantity_required, p.p_name FROM formulas f JOIN products p ON f.ingredient_id = p.id WHERE f.product_id = '$pid'");
        
        $total_cost = 0;
        $materials = [];
        
        while ($f = mysqli_fetch_assoc($formulas)) {
            $need = $f['quantity_required'] * $amount;
            $ing_id = $f['ingredient_id'];
            
            $q_price = mysqli_query($conn, "SELECT unit_price FROM po_items JOIN purchase_orders po ON po_items.po_id = po.po_id WHERE item_id = '$ing_id' AND po.status IN ('Delivered', 'Completed') ORDER BY po.created_at DESC LIMIT 1");
            $unit_price = (mysqli_num_rows($q_price) > 0) ? (float)mysqli_fetch_assoc($q_price)['unit_price'] : 0;
            
            $item_cost = $need * $unit_price;
            $total_cost += $item_cost;
            
            $materials[] = [
                'name' => $f['p_name'],
                'need' => $need,
                'price' => $unit_price,
                'cost' => $item_cost
            ];
        }
        
        echo json_encode([
            'status' => 'ok',
            'amount' => $amount,
            'total_cost' => $total_cost,
            'cost_per_unit' => ($amount > 0) ? ($total_cost / $amount) : 0,
            'materials' => $materials
        ]);
        exit;
    }
}

include '../sidebar.php';
?>

<title>สั่งผลิตสินค้า | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.12/dist/sweetalert2.min.css" rel="stylesheet">
<style>
    :root { --bg-color: #f8fafc; --card-bg: #ffffff; --text-main: #1e293b; --text-muted: #64748b; --border-color: #e2e8f0; }
    body { background-color: var(--bg-color); color: var(--text-main); font-family: 'Sarabun', sans-serif;}
    .content-padding { padding: 40px 20px; min-height: 80vh; display: flex; align-items: flex-start; justify-content: center; }
    .prod-card { background: var(--card-bg); padding: 40px; border-radius: 24px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05); max-width: 600px; width: 100%; border: 1px solid var(--border-color); }
    .header-section { text-align: center; margin-bottom: 35px; }
    .icon-circle { width: 70px; height: 70px; background: #e0e7ff; color: var(--primary); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 30px; margin-bottom: 16px; }
    .header-section h2 { margin: 0 0 8px 0; color: var(--text-main); font-weight: 700; font-size: 26px; }
    .header-section p { color: var(--text-muted); margin: 0; font-size: 15px; }
    .form-group { margin-bottom: 24px; text-align: left; }
    .form-label { display: block; font-weight: 600; color: var(--text-main); margin-bottom: 10px; font-size: 15px; }
    .form-control { width: 100%; padding: 14px 16px; border: 1.5px solid var(--border-color); border-radius: 10px; font-size: 16px; box-sizing: border-box; font-family: 'Sarabun';}
    .select2-container--default .select2-selection--single { height: 52px; border: 1.5px solid var(--border-color); border-radius: 10px; display: flex; align-items: center; font-family: 'Sarabun';}
    .select2-container--default .select2-selection--single .select2-selection__rendered { padding-left: 16px; font-size: 16px; color: var(--text-main); }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 50px; right: 12px; }
    .btn-run { background: var(--primary); color: white; width: 100%; padding: 16px; border: none; border-radius: 12px; font-size: 18px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 10px; font-family: 'Sarabun';}
    .btn-run:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.4);}
    
    /* 🚀 สไตล์สำหรับกล่องคำนวณต้นทุน */
    .cost-box { background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 20px; margin-bottom: 25px; display: none; animation: fadeIn 0.3s; }
    .cost-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 15px; }
    .cost-title { color: #64748b; font-weight: bold; }
    .cost-val { color: #1e293b; font-weight: bold; }
    .cost-divider { height: 1px; background: #e2e8f0; margin: 15px 0; }
    .cost-total { font-size: 18px; color: #ef4444; font-weight: 900; }
    
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
</style>

<div class="content-padding">
    <div class="prod-card">
        <div class="header-section">
            <div class="icon-circle"><i class="fa-solid fa-industry"></i></div>
            <h2>บันทึกการผลิต (Execution)</h2>
            <p>ดึงข้อมูลจากใบสั่งผลิต ตัดสต็อกวัตถุดิบอัตโนมัติ และ <b style="color:var(--primary);">คำนวณต้นทุนการผลิต</b></p>
        </div>

        <?php
        $dept_filter = ($user_role === 'ADMIN') ? "" : "AND production_line = '$user_dept'";
        $q_orders = mysqli_query($conn, "SELECT * FROM production_orders WHERE status = 'Pending' $dept_filter ORDER BY due_date ASC");
        
        if(mysqli_num_rows($q_orders) > 0):
        ?>
        <form method="POST">
            <div class="form-group">
                <label class="form-label">1. เลือกใบสั่งผลิต (รอดำเนินการ)</label>
                <select name="order_id" id="orderSelect" class="select2 form-control" required onchange="fetchCostData()">
                    <option value="">-- เลือกใบสั่งผลิต --</option>
                    <?php 
                    while($row = mysqli_fetch_assoc($q_orders)) { 
                        echo "<option value='{$row['id']}'>📦 [{$row['order_no']}] {$row['formula_name']}</option>"; 
                    }
                    ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">2. จำนวนเป้าหมายที่ผลิตสำเร็จ</label>
                <div style="position: relative;">
                    <input type="number" step="0.01" min="0.01" name="amount" id="targetAmount" class="form-control" required readonly>
                    <span style="position: absolute; right: 16px; top: 14px; color: var(--text-muted); font-weight: 500;">หน่วย</span>
                </div>
            </div>
            
            <div id="costBox" class="cost-box">
                <div style="color: #4f46e5; font-weight: bold; margin-bottom: 15px;"><i class="fa-solid fa-calculator"></i> ประเมินต้นทุนวัตถุดิบ (Raw Material Cost)</div>
                <div id="materialsList"></div>
                <div class="cost-divider"></div>
                <div class="cost-row">
                    <span class="cost-title">ต้นทุนรวมทั้งหมด (Total Cost) :</span>
                    <span class="cost-total" id="totalCost">0.00 ฿</span>
                </div>
                <div class="cost-row" style="margin-bottom:0;">
                    <span class="cost-title">ต้นทุนต่อหน่วย (Cost per Unit) :</span>
                    <span class="cost-val" id="costPerUnit">0.00 ฿</span>
                </div>
            </div>
            
            <button type="submit" name="start_production" class="btn-run" onclick="return confirm('ยืนยันการผลิต?\nระบบจะตัดสต็อกวัตถุดิบ และบันทึกต้นทุนเข้าสู่ฐานข้อมูลครับ');">
                <i class="fa-solid fa-play"></i> ยืนยันการผลิตและส่งตรวจ QA
            </button>
        </form>
        <?php else: ?>
            <div style="text-align:center; padding:30px; background:#f8fafc; border-radius:12px; border:1px dashed #cbd5e1;"><i class="fa-solid fa-check-circle" style="font-size:40px; color:#10b981; margin-bottom:15px;"></i><h3>ไม่มีงานค้าง</h3></div>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.12/dist/sweetalert2.all.min.js"></script>
<script>
    $(document).ready(function() { $('.select2').select2({width: '100%'}); });

    // 🚀 ฟังก์ชันดึงราคาและคำนวณต้นทุนผ่าน AJAX
    function fetchCostData() {
        const orderId = document.getElementById('orderSelect').value;
        if (!orderId) {
            document.getElementById('costBox').style.display = 'none';
            document.getElementById('targetAmount').value = '';
            return;
        }

        $.ajax({
            url: 'create_production.php',
            type: 'GET',
            dataType: 'json',
            data: { api_cost: 1, order_id: orderId },
            success: function(res) {
                if (res.status === 'ok') {
                    document.getElementById('targetAmount').value = res.amount;
                    document.getElementById('totalCost').innerText = res.total_cost.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' ฿';
                    document.getElementById('costPerUnit').innerText = res.cost_per_unit.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' ฿';
                    
                    let html = '';
                    res.materials.forEach(m => {
                        let priceText = (m.price === 0) ? "<span style='color:#f59e0b; font-size:12px;'>(ประเมิน 0 ฿)</span>" : `(${m.price} ฿/กก.)`;
                        html += `<div class="cost-row" style="color:#64748b;">
                                    <span>- ${m.name} ${priceText}</span>
                                    <span>${m.need.toLocaleString('en-US', {minimumFractionDigits: 2})} กก.</span>
                                 </div>`;
                    });
                    document.getElementById('materialsList').innerHTML = html;
                    document.getElementById('costBox').style.display = 'block';
                }
            }
        });
    }

    <?php if($status == 'success'): ?>
        Swal.fire({ icon: 'success', title: 'บันทึกสำเร็จ!', text: 'ระบบคำนวณต้นทุนและส่งสินค้าไปกักกัน QA แล้ว', confirmButtonColor: '#4f46e5'}).then(() => { window.location.href = window.location.pathname; });
    <?php elseif($status == 'error'): ?>
        Swal.fire({ icon: 'error', title: 'วัตถุดิบไม่เพียงพอ!', html: "<?php echo $message; ?>" });
    <?php endif; ?>
</script>