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
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $allowed_depts)) { 
    echo "<script>alert('เฉพาะฝ่ายผลิตเท่านั้น'); window.location='../index.php';</script>"; exit(); 
}

// 🚀 [Auto-Create & Update Table] 
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS product_costs (
    id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, lot_no VARCHAR(100) NOT NULL, production_order_id INT NOT NULL, total_cost DECIMAL(15,2) NOT NULL DEFAULT 0, cost_per_unit DECIMAL(15,2) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

// เพิ่มคอลัมน์ wh_id ให้ inventory_lots เพื่อให้รู้ว่าตอนรอ QA ของอยู่คลังไหน
$check_wh_col = mysqli_query($conn, "SHOW COLUMNS FROM `inventory_lots` LIKE 'wh_id'");
if(mysqli_num_rows($check_wh_col) == 0) {
    mysqli_query($conn, "ALTER TABLE `inventory_lots` ADD `wh_id` INT(11) NOT NULL DEFAULT 0 AFTER `product_id`");
}

$status = '';
$message = '';

// 🚀 API คำนวณต้นทุน และ ค้นหาโกดังวัตถุดิบ
if (isset($_GET['api_cost']) && isset($_GET['order_id'])) {
    $oid = (int)$_GET['order_id'];
    $q_ord = mysqli_query($conn, "SELECT product_id, formula_name, target_qty FROM production_orders WHERE id = $oid");
    
    if ($q_ord && mysqli_num_rows($q_ord) > 0) {
        $ord = mysqli_fetch_assoc($q_ord);
        $amount = (float)$ord['target_qty'];
        $p_id = (int)$ord['product_id'];
        $fname = mysqli_real_escape_string($conn, $ord['formula_name']);
        
        $formulas = mysqli_query($conn, "SELECT f.ingredient_id, f.quantity_required, p.p_name, p.p_unit FROM formulas f JOIN products p ON f.ingredient_id = p.id WHERE f.product_id = '$p_id' AND f.formula_name = '$fname'");
        
        $total_cost = 0; $materials = [];
        while ($f = mysqli_fetch_assoc($formulas)) {
            $need = $f['quantity_required'] * $amount;
            $ing_id = $f['ingredient_id'];
            
            $q_price = mysqli_query($conn, "SELECT unit_price FROM po_items JOIN purchase_orders po ON po_items.po_id = po.po_id WHERE item_id = '$ing_id' AND po.status IN ('Delivered', 'Completed') ORDER BY po.created_at DESC LIMIT 1");
            $unit_price = (mysqli_num_rows($q_price) > 0) ? (float)mysqli_fetch_assoc($q_price)['unit_price'] : 0;
            
            $item_cost = $need * $unit_price;
            $total_cost += $item_cost;
            
            $q_stock = mysqli_query($conn, "SELECT sb.wh_id, sb.qty, w.wh_code, w.wh_name, w.plant FROM stock_balances sb JOIN warehouses w ON sb.wh_id = w.wh_id WHERE sb.product_id = '$ing_id' AND sb.qty > 0 ORDER BY w.plant ASC");
            $stocks = [];
            while($st = mysqli_fetch_assoc($q_stock)){ $stocks[] = $st; }

            $materials[] = [ 'id' => $ing_id, 'name' => $f['p_name'], 'unit' => $f['p_unit'], 'need' => $need, 'price' => $unit_price, 'cost' => $item_cost, 'stocks' => $stocks ];
        }
        echo json_encode([ 'status' => 'ok', 'amount' => $amount, 'total_cost' => $total_cost, 'cost_per_unit' => ($amount > 0) ? ($total_cost / $amount) : 0, 'materials' => $materials ]); exit;
    }
}

// 🚀 ประมวลผลการผลิต
if (isset($_POST['start_production'])) {
    $order_id = (int)$_POST['order_id'];
    $amount = (float)$_POST['amount'];
    $to_wh_id = (int)$_POST['to_wh_id']; // 🚀 รับคลัง Hold ที่เลือกลงของ
    $from_wh = $_POST['from_wh'] ?? [];
    $user = $_SESSION['fullname'];

    $q_order = mysqli_query($conn, "SELECT order_no, product_id, formula_name FROM production_orders WHERE id = $order_id");
    if(mysqli_num_rows($q_order) > 0) {
        $order_data = mysqli_fetch_assoc($q_order);
        $p_id = (int)$order_data['product_id'];
        $formula_name = mysqli_real_escape_string($conn, $order_data['formula_name']);
        $order_no = $order_data['order_no'];

        $q_prod = mysqli_query($conn, "SELECT shelf_life_days FROM products WHERE id = '$p_id'");
        $shelf_life = (mysqli_num_rows($q_prod) > 0) ? (int)mysqli_fetch_assoc($q_prod)['shelf_life_days'] : 0;

        $formulas = mysqli_query($conn, "SELECT f.ingredient_id, f.quantity_required, p.p_name FROM formulas f JOIN products p ON f.ingredient_id = p.id WHERE f.product_id = '$p_id' AND f.formula_name = '$formula_name'");
        
        $can_produce = true; $items_to_deduct = []; $total_production_cost = 0; 
        
        while ($f = mysqli_fetch_assoc($formulas)) {
            $need = $f['quantity_required'] * $amount;
            $ing_id = $f['ingredient_id'];
            $wh_id_selected = (int)($from_wh[$ing_id] ?? 0);

            $q_check = mysqli_query($conn, "SELECT qty FROM stock_balances WHERE product_id = $ing_id AND wh_id = $wh_id_selected");
            $wh_qty = ($q_check && mysqli_num_rows($q_check) > 0) ? (float)mysqli_fetch_assoc($q_check)['qty'] : 0;

            if ($wh_qty < $need || $wh_id_selected == 0) {
                $can_produce = false; $message = "❌ สต็อกวัตถุดิบ '{$f['p_name']}' มีไม่เพียงพอ!"; break;
            }

            $q_price = mysqli_query($conn, "SELECT unit_price FROM po_items JOIN purchase_orders po ON po_items.po_id = po.po_id WHERE item_id = '$ing_id' AND po.status IN ('Delivered', 'Completed') ORDER BY po.created_at DESC LIMIT 1");
            $unit_price = ($q_price && mysqli_num_rows($q_price) > 0) ? (float)mysqli_fetch_assoc($q_price)['unit_price'] : 0;
            $total_production_cost += ($need * $unit_price);

            $items_to_deduct[] = [ 'id' => $ing_id, 'qty' => $need, 'wh_id' => $wh_id_selected ];
        }

        if ($can_produce && count($items_to_deduct) > 0 && $to_wh_id > 0) {
            // หักวัตถุดิบ
            foreach ($items_to_deduct as $item) {
                mysqli_query($conn, "UPDATE stock_balances SET qty = qty - {$item['qty']} WHERE product_id = {$item['id']} AND wh_id = {$item['wh_id']}");
                mysqli_query($conn, "UPDATE products SET p_qty = p_qty - {$item['qty']} WHERE id = {$item['id']}"); 
                mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, from_wh_id, reference, action_by) VALUES ('{$item['id']}', 'OUT', '{$item['qty']}', '{$item['wh_id']}', 'เบิกผลิต FG Lot สำหรับ Order: $order_no', '$user')");
            }
            
            // 🚀 1. สร้าง LOT FG
            $lot_no = "LOT-" . date('Ymd') . "-" . str_pad($order_id, 4, "0", STR_PAD_LEFT);
            $mfg_date = date('Y-m-d'); 
            $exp_date = ($shelf_life > 0) ? date('Y-m-d', strtotime($mfg_date. " + {$shelf_life} days")) : '2099-12-31'; 

            mysqli_query($conn, "INSERT INTO inventory_lots (product_id, wh_id, lot_no, mfg_date, exp_date, qty, status) VALUES ('$p_id', '$to_wh_id', '$lot_no', '$mfg_date', '$exp_date', '$amount', 'Pending_QA')");

            // 🚀 2. เอา FG ยัดลง คลัง Hold ให้บัญชีเห็นว่าของมีตัวตนแล้ว!
            mysqli_query($conn, "INSERT INTO stock_balances (product_id, wh_id, qty) VALUES ('$p_id', '$to_wh_id', '$amount') ON DUPLICATE KEY UPDATE qty = qty + '$amount'");
            mysqli_query($conn, "UPDATE products SET p_qty = p_qty + '$amount' WHERE id = '$p_id'"); // อัปเดตตารางหลักเผื่อไว้
            
            // 🚀 3. บันทึก Stock Log การรับเข้าคลัง Hold
            mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, to_wh_id, reference, action_by) VALUES ('$p_id', 'IN', '$amount', '$to_wh_id', 'ผลิตสำเร็จรอตรวจ (Lot: $lot_no)', '$user')");

            mysqli_query($conn, "UPDATE production_orders SET status = 'Completed' WHERE id = $order_id");

            $cost_per_unit = ($amount > 0) ? ($total_production_cost / $amount) : 0;
            mysqli_query($conn, "INSERT INTO product_costs (product_id, lot_no, production_order_id, total_cost, cost_per_unit) VALUES ('$p_id', '$lot_no', '$order_id', '$total_production_cost', '$cost_per_unit')");

            if(function_exists('log_event')) { log_event($conn, 'INSERT', 'inventory_lots', "บันทึกผลิต $formula_name สำเร็จ (เอาลงคลัง Hold ID: $to_wh_id)"); }

            include_once '../line_api.php';
            $msg = "🏭 [ฝ่ายผลิต] บันทึกการผลิตสำเร็จ\n\n🔖 ใบสั่งผลิต: $order_no\n📦 สินค้า: $formula_name\n✅ จำนวน: " . number_format($amount, 2) . " หน่วย\n\n⚠️ สินค้านำไปเก็บไว้ที่ คลัง Hold รอกักกัน (Pending QA) เรียบร้อยแล้วครับ";
            if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

            $status = 'success';
        } else if (!$can_produce) {
            $status = 'error';
        } else {
            $status = 'warning'; $message = 'กรุณาตรวจสอบการตั้งค่าสูตร และเลือกคลังกักกันให้ครบถ้วน';
        }
    }
}

$res_hold_wh = mysqli_query($conn, "SELECT * FROM warehouses WHERE wh_type = 'Hold' ORDER BY plant ASC, wh_name ASC");

include '../sidebar.php';
?>

<title>บันทึกการผลิต & ตัดสต็อกไซโล | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    :root { --bg-color: #f8fafc; --card-bg: #ffffff; --text-main: #1e293b; --text-muted: #64748b; --border-color: #e2e8f0; --primary: #4f46e5; --primary-hover: #4338ca;}
    body { background-color: var(--bg-color); color: var(--text-main); font-family: 'Sarabun', sans-serif;}
    .content-padding { padding: 40px 20px; min-height: 80vh; display: flex; align-items: flex-start; justify-content: center; }
    .prod-card { background: var(--card-bg); padding: 40px; border-radius: 24px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05); max-width: 800px; width: 100%; border: 1px solid var(--border-color); border-top: 5px solid var(--primary); }
    .header-section { margin-bottom: 25px; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px;}
    .header-section h2 { margin: 0 0 8px 0; color: var(--text-main); font-weight: 800; font-size: 24px; display: flex; align-items: center; gap: 10px;}
    
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-weight: 700; color: var(--text-main); margin-bottom: 8px; font-size: 14.5px; }
    .form-control { width: 100%; padding: 12px 16px; border: 1.5px solid var(--border-color); border-radius: 10px; font-size: 15px; font-family: 'Sarabun'; transition: 0.2s; box-sizing: border-box;}
    .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15); }
    
    .select2-container--default .select2-selection--single { height: 48px; border: 1.5px solid var(--border-color); border-radius: 10px; display: flex; align-items: center; font-family: 'Sarabun';}
    .select2-container--default .select2-selection--single .select2-selection__rendered { padding-left: 16px; font-size: 15px; font-weight:bold; color: var(--text-main); }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 46px; right: 12px; }
    
    .btn-run { background: var(--primary); color: white; width: 100%; padding: 16px; border: none; border-radius: 12px; font-size: 18px; font-weight: 800; cursor: pointer; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 10px; font-family: 'Sarabun'; margin-top:20px;}
    .btn-run:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 8px 20px rgba(79, 70, 229, 0.3);}
    
    .issue-box { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 12px; padding: 25px; margin-bottom: 20px; display: none; animation: fadeIn 0.4s; }
    .ing-row { display: flex; align-items: center; justify-content: space-between; gap: 15px; padding: 15px 0; border-bottom: 1px dashed #cbd5e1; }
    .ing-row:last-child { border-bottom: none; padding-bottom: 0; }
    .ing-info { flex: 1.2; }
    .ing-select { flex: 2; }
    
    .cost-summary { background: #ecfdf5; border: 1px solid #a7f3d0; padding: 15px; border-radius: 10px; margin-top: 15px; display:flex; justify-content: space-between; align-items:center; }
    
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
</style>

<div class="content-padding">
    <div class="prod-card">
        <div class="header-section">
            <h2><i class="fa-solid fa-gears" style="color:var(--primary);"></i> บันทึกการผลิต (Issue & Receipt)</h2>
            <p style="color:var(--text-muted); margin:0;">ตัดวัตถุดิบออกจากไซโล และนำสินค้า (FG) ไปเก็บไว้ที่คลังกักกัน (Hold) รอ QA</p>
        </div>

        <?php
        $dept_filter = ($user_role === 'ADMIN' || $user_role === 'MANAGER' || $user_dept === 'ฝ่ายงานวางแผน') ? "" : "AND production_line = '$user_dept'";
        $q_orders = mysqli_query($conn, "SELECT po.*, p.p_name FROM production_orders po JOIN products p ON po.product_id = p.id WHERE po.status = 'Pending' $dept_filter ORDER BY po.due_date ASC");
        
        if(mysqli_num_rows($q_orders) > 0):
        ?>
        <form method="POST" id="productionForm">
            <div style="display:flex; gap:20px; margin-bottom: 15px; flex-wrap:wrap;">
                <div class="form-group" style="flex:2; min-width:300px;">
                    <label class="form-label">1. เลือกใบสั่งผลิต (รอดำเนินการ)</label>
                    <select name="order_id" id="orderSelect" class="select2 form-control" required onchange="fetchBOMData()">
                        <option value="">-- เลือกใบสั่งผลิต --</option>
                        <?php 
                        while($row = mysqli_fetch_assoc($q_orders)) { 
                            echo "<option value='{$row['id']}'>📦 [{$row['order_no']}] {$row['p_name']} (สูตร: {$row['formula_name']})</option>"; 
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group" style="flex:1; min-width:150px;">
                    <label class="form-label">2. จำนวนที่ผลิตสำเร็จ</label>
                    <div style="position: relative;">
                        <input type="number" step="0.01" name="amount" id="targetAmount" class="form-control" style="background:#f1f5f9; font-weight:bold; color:var(--primary);" required readonly>
                        <span style="position: absolute; right: 16px; top: 14px; color: var(--text-muted); font-weight: 800;">หน่วย</span>
                    </div>
                </div>
            </div>
            
            <div id="issueBox" class="issue-box">
                <div style="color: #1e293b; font-weight: 800; font-size:18px; margin-bottom: 10px;">
                    <i class="fa-solid fa-truck-moving" style="color:#f59e0b;"></i> เลือกไซโล/โกดัง ตัดวัตถุดิบ (Issue From)
                </div>
                
                <div id="materialsList"></div>

                <!-- 🚀 Dropdown ใหม่ให้เลือกคลัง Hold เอาของไปลง -->
                <div class="form-group" style="margin-top: 25px; padding-top: 20px; border-top: 2px dashed #cbd5e1;">
                    <label class="form-label" style="color:#d97706; font-size:16px;">
                        <i class="fa-solid fa-warehouse"></i> เลือกคลังกักกันรับเข้า FG (รอ QA ตรวจ) <span style="color:red;">*</span>
                    </label>
                    <select name="to_wh_id" class="form-control select2" required style="border-color:#fcd34d;">
                        <option value="">-- เลือกคลัง Hold สำหรับโรงงานนี้ --</option>
                        <?php 
                        if($res_hold_wh) {
                            mysqli_data_seek($res_hold_wh, 0);
                            while($h = mysqli_fetch_assoc($res_hold_wh)) {
                                echo "<option value='{$h['wh_id']}'>[{$h['plant']}] {$h['wh_name']}</option>";
                            }
                        }
                        ?>
                    </select>
                    <small style="color:#d97706; font-weight:bold; margin-top:5px; display:block;">* สินค้าที่ผลิตเสร็จจะถูกเก็บรวมไว้ที่คลังนี้ (สถานะ Pending QA)</small>
                </div>

                <div class="cost-summary">
                    <div>
                        <span style="color:#047857; font-weight:bold;"><i class="fa-solid fa-calculator"></i> ประเมินต้นทุนต่อหน่วย:</span>
                        <div id="costPerUnit" style="font-size:20px; font-weight:900; color:#166534; margin-top:5px;">0.00 ฿</div>
                    </div>
                    <div style="text-align:right;">
                        <span style="color:#64748b; font-size:13px;">รวมต้นทุนการผลิตล็อตนี้</span>
                        <div id="totalCost" style="font-size:16px; font-weight:bold; color:#1e293b;">0.00 ฿</div>
                    </div>
                </div>
            </div>
            
            <button type="submit" name="start_production" id="btn_submit" class="btn-run" onclick="return confirm('ยืนยันการผลิตและย้ายสินค้าเข้าคลัง Hold?');">
                <i class="fa-solid fa-check-double"></i> ยืนยันการผลิต และนำเก็บเข้าคลัง Hold
            </button>
        </form>
        <?php else: ?>
            <div style="text-align:center; padding:30px; background:#f8fafc; border-radius:12px; border:1px dashed #cbd5e1;">
                <i class="fa-solid fa-check-circle" style="font-size:40px; color:#10b981; margin-bottom:15px;"></i>
                <h3>ไม่มีออเดอร์ค้าง</h3>
                <p style="color:#64748b;">แผนกของคุณผลิตตามเป้าหมายเสร็จสิ้นหมดแล้ว</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() { $('.select2').select2({width: '100%'}); });

    function fetchBOMData() {
        const orderId = document.getElementById('orderSelect').value;
        if (!orderId) {
            document.getElementById('issueBox').style.display = 'none';
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
                    let canProduce = true;

                    res.materials.forEach(m => {
                        let options = '';
                        let hasEnough = false;
                        
                        m.stocks.forEach(st => {
                            if (parseFloat(st.qty) >= parseFloat(m.need)) {
                                options += `<option value="${st.wh_id}">[${st.plant}] ${st.wh_name} (มี ${parseFloat(st.qty).toFixed(2)})</option>`;
                                hasEnough = true;
                            }
                        });

                        let selectBox = '';
                        if (hasEnough) {
                            selectBox = `<select name="from_wh[${m.id}]" class="form-control" style="background:#fff;" required>${options}</select>`;
                        } else {
                            selectBox = `<select class="form-control" style="background:#fef2f2; color:#ef4444; border-color:#fecaca;" disabled>
                                            <option>❌ สต็อกทุกคลังไม่พอ (ต้องการ ${m.need.toFixed(2)})</option>
                                         </select>`;
                            canProduce = false;
                        }

                        html += `<div class="ing-row">
                                    <div class="ing-info">
                                        <strong style="color:#334155; font-size:15.5px;">${m.name}</strong><br>
                                        <span style="color:#ef4444; font-weight:bold; font-size:14px;"><i class="fa-solid fa-minus"></i> ${m.need.toLocaleString('en-US', {minimumFractionDigits: 2})} ${m.unit}</span>
                                    </div>
                                    <div class="ing-select">
                                        ${selectBox}
                                    </div>
                                 </div>`;
                    });

                    document.getElementById('materialsList').innerHTML = html;
                    document.getElementById('issueBox').style.display = 'block';

                    if (!canProduce) {
                        $('#btn_submit').prop('disabled', true).css({'background':'#cbd5e1', 'cursor':'not-allowed'}).html('<i class="fa-solid fa-ban"></i> วัตถุดิบในคลังไม่พอผลิต');
                    } else {
                        $('#btn_submit').prop('disabled', false).css({'background':'var(--primary)', 'cursor':'pointer'}).html('<i class="fa-solid fa-check-double"></i> ยืนยันการผลิต และนำเก็บเข้าคลัง Hold');
                    }
                }
            }
        });
    }

    <?php if($status == 'success'): ?>
        Swal.fire({ icon: 'success', title: 'บันทึกสำเร็จ!', text: 'สินค้านำไปเก็บรอที่คลัง Hold เรียบร้อยแล้ว', confirmButtonColor: '#4f46e5'}).then(() => { window.location.href = window.location.pathname; });
    <?php elseif($status == 'error'): ?>
        Swal.fire({ icon: 'error', title: 'ผิดพลาด!', text: "<?php echo $message; ?>" });
    <?php endif; ?>
</script>
</body>
</html>