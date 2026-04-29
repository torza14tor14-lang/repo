<?php
session_start();
ob_start();
include '../db.php';

// สเต็ปที่ 1: ตรวจสอบว่า "ล็อกอินหรือยัง?"
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';

$allowed_depts = ['ฝ่ายขาย'];
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $allowed_depts)) { 
    echo "<script>alert('เฉพาะฝ่ายขายเท่านั้น'); window.location='../index.php';</script>"; exit(); 
}

// 📦 เตรียมข้อมูลสินค้าสำเร็จรูป (FG)
$item_options = "<option value=''>-- เลือกสินค้า --</option>";
$fg_items = mysqli_query($conn, "SELECT id, p_name, p_qty FROM products WHERE p_type = 'PRODUCT' ORDER BY p_name ASC");
if ($fg_items) {
    while($row = mysqli_fetch_assoc($fg_items)) {
        $item_options .= "<option value='{$row['id']}'>📦 {$row['p_name']} (คงเหลือ: {$row['p_qty']})</option>";
    }
}

// 👤 เตรียมข้อมูลรายชื่อลูกค้า พร้อมคำนวณหนี้ค้างชำระ (Real-time Credit Check)
$cus_options = "<option value=''>-- เลือกลูกค้าในระบบ --</option>";
$cus_query = mysqli_query($conn, "SELECT id, cus_name, credit_term, credit_limit FROM customers ORDER BY cus_name ASC");
$customers_data = [];

if ($cus_query) {
    while($c = mysqli_fetch_assoc($cus_query)) {
        $c_id = $c['id'];
        
        // คำนวณยอดหนี้ค้างชำระ (Unpaid + Credit)
        $q_debt = mysqli_query($conn, "SELECT SUM(total_amount) as debt FROM sales_orders WHERE cus_id = '$c_id' AND payment_status IN ('Unpaid', 'Credit')");
        $debt = mysqli_fetch_assoc($q_debt)['debt'] ?? 0;
        $avail_credit = $c['credit_limit'] - $debt;

        $cus_options .= "<option value='{$c['id']}'>👤 {$c['cus_name']}</option>";
        
        // เก็บข้อมูลส่งไปให้ JavaScript ประมวลผลหน้าจอ
        $customers_data[$c['id']] = [
            'term' => (int)$c['credit_term'],
            'limit' => (float)$c['credit_limit'],
            'debt' => (float)$debt,
            'avail' => (float)$avail_credit
        ];
    }
}

$status = '';
$error_msg = '';

if (isset($_POST['submit_sale'])) {
    $cus_id = (int)$_POST['cus_id'];
    $date = $_POST['sale_date'];
    $payment_status = mysqli_real_escape_string($conn, $_POST['payment_status']);
    $due_date = mysqli_real_escape_string($conn, $_POST['due_date']);
    $user = $_SESSION['fullname'] ?? $_SESSION['username'];
    $total_amount = 0;
    
    // ดึงชื่อลูกค้ามาเก็บไว้
    $cus_name_query = mysqli_query($conn, "SELECT cus_name FROM customers WHERE id = '$cus_id'");
    $customer_name = mysqli_fetch_assoc($cus_name_query)['cus_name'] ?? 'ลูกค้าทั่วไป';
    
    $can_sell = true;
    $items_to_process = [];

    // 1. คำนวณยอดรวม และ ตรวจสอบสต็อกก่อนว่ามีพอขายหรือไม่
    if (isset($_POST['items'])) {
        foreach ($_POST['items'] as $item) {
            if (!empty($item['id']) && !empty($item['qty'])) {
                $i_id = (int)$item['id'];
                $qty = (float)$item['qty'];
                $price = (float)$item['price'];
                
                $check_stock = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p_qty, p_name FROM products WHERE id = '$i_id'"));
                if ($check_stock['p_qty'] < $qty) {
                    $can_sell = false;
                    $error_msg .= "❌ สินค้า <b>{$check_stock['p_name']}</b> มีไม่พอขาย (ขาดอีก ".($qty - $check_stock['p_qty'])." หน่วย)<br>";
                } else {
                    $items_to_process[] = ['id' => $i_id, 'qty' => $qty, 'price' => $price, 'name' => $check_stock['p_name']];
                    $total_amount += ($qty * $price);
                }
            }
        }
    }

    // 2. 🚀 ตรวจสอบวงเงินเครดิต (Credit Limit Check)
    if ($can_sell && count($items_to_process) > 0) {
        if ($payment_status === 'Unpaid' || $payment_status === 'Credit') {
            $q_limit = mysqli_query($conn, "SELECT credit_limit FROM customers WHERE id = '$cus_id'");
            $limit = mysqli_fetch_assoc($q_limit)['credit_limit'] ?? 0;
            
            $q_debt = mysqli_query($conn, "SELECT SUM(total_amount) as debt FROM sales_orders WHERE cus_id = '$cus_id' AND payment_status IN ('Unpaid', 'Credit')");
            $current_debt = mysqli_fetch_assoc($q_debt)['debt'] ?? 0;
            
            if (($current_debt + $total_amount) > $limit) {
                $can_sell = false;
                $status = 'error';
                $over = ($current_debt + $total_amount) - $limit;
                
                // 🐛 แก้ไขบั๊กเครื่องหมายคำพูด (Quote) ที่ทำให้ JS พัง
                $error_msg .= "🛑 <b>วงเงินเครดิตของลูกค้าไม่เพียงพอ!</b><br>";
                $error_msg .= "วงเงินที่ได้รับอนุมัติ: " . number_format($limit, 2) . " ฿<br>";
                $error_msg .= "ยอดหนี้ค้างชำระเดิม: " . number_format($current_debt, 2) . " ฿<br>";
                $error_msg .= "ยอดบิลนี้: " . number_format($total_amount, 2) . " ฿<br>";
                $error_msg .= "<span style=\"color:#e74a3b; font-weight:bold;\">ยอดรวมเกินวงเงินไป: " . number_format($over, 2) . " ฿</span><br>";
                $error_msg .= "<br><small><i>กรุณาให้ลูกค้าชำระเงินสด หรือติดต่อฝ่ายสินเชื่อเพื่อขอเพิ่มวงเงิน</i></small>";
            }
        }
    }

    // 3. ถ้าผ่านเงื่อนไขทั้งหมด ให้ทำการตัดสต็อกและบันทึกบิล
    if ($can_sell && count($items_to_process) > 0) {
        
        // สร้างหัวบิล
        mysqli_query($conn, "INSERT INTO sales_orders (cus_id, customer_name, sale_date, total_amount, created_by, payment_status, due_date) 
                             VALUES ('$cus_id', '$customer_name', '$date', '$total_amount', '$user', '$payment_status', '$due_date')");
        $sale_id = mysqli_insert_id($conn);

        // วนลูปบันทึกรายการย่อยและตัดสต็อกแบบ FEFO
        foreach ($items_to_process as $it) {
            $p_id = $it['id'];
            $qty_needed = $it['qty'];
            
            // บันทึกรายการลงบิล
            mysqli_query($conn, "INSERT INTO sales_items (sale_id, product_id, quantity, unit_price) 
                                 VALUES ('$sale_id', '$p_id', '$qty_needed', '{$it['price']}')");
            
            // 🚀 ระบบ FEFO (First-Expire, First-Out)
            // ค้นหา Lot ที่มีของ และเรียงลำดับวันหมดอายุจากใกล้สุดไปไกลสุด
            $q_lots = mysqli_query($conn, "SELECT id, lot_no, qty FROM inventory_lots WHERE product_id = '$p_id' AND qty > 0 AND status = 'Active' ORDER BY exp_date ASC");
            
            while ($lot = mysqli_fetch_assoc($q_lots)) {
                if ($qty_needed <= 0) break; // ถ้าของครบแล้วให้ออกลูป

                $lot_id = $lot['id'];
                $lot_no = $lot['lot_no'];
                $lot_qty = (float)$lot['qty'];

                if ($lot_qty >= $qty_needed) {
                    // Lot นี้มีของเพียงพอที่จะตัดจบเลย
                    mysqli_query($conn, "UPDATE inventory_lots SET qty = qty - $qty_needed WHERE id = '$lot_id'");
                    mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, reference, action_by) 
                                         VALUES ('$p_id', 'OUT', '$qty_needed', 'ขายสินค้า INV-$sale_id (Lot: $lot_no)', '$user')");
                    $qty_needed = 0; 
                } else {
                    // Lot นี้มีของไม่พอ ต้องตัดให้เกลี้ยงแล้วไปดึง Lot ถัดไป
                    mysqli_query($conn, "UPDATE inventory_lots SET qty = 0 WHERE id = '$lot_id'");
                    mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, reference, action_by) 
                                         VALUES ('$p_id', 'OUT', '$lot_qty', 'ขายสินค้า INV-$sale_id (Lot: $lot_no)', '$user')");
                    $qty_needed -= $lot_qty; // หักลบยอดที่ได้ไปแล้ว
                }
            }

            // สุดท้าย อย่าลืมตัดยอดรวมในตาราง products หลักด้วย
            mysqli_query($conn, "UPDATE products SET p_qty = p_qty - {$it['qty']} WHERE id = '$p_id'");
        }

        // -----------------------------------------------------------------
        // 🚀 บันทึกประวัติ Log ลงระบบ
        // -----------------------------------------------------------------
        if(function_exists('log_event')) {
            log_event($conn, 'INSERT', 'sales_orders', "เปิดบิลขายใหม่ INV-$sale_id (ลูกค้า: $customer_name) ยอดสุทธิ " . number_format($total_amount, 2) . " ฿");
        }

        // -----------------------------------------------------------------
        // 🚀 แจ้งเตือน LINE: แจ้งฝ่ายคลังสินค้าและบัญชี
        // -----------------------------------------------------------------
        include_once '../line_api.php';
        $msg = "🛒 [ฝ่ายขาย] เปิดบิลขายสินค้าใหม่ (INV-$sale_id)\n\n";
        $msg .= "👤 ลูกค้า: $customer_name\n";
        $msg .= "💰 ยอดรวมทั้งสิ้น: " . number_format($total_amount, 2) . " บาท\n";
        $msg .= "💳 สถานะการจ่าย: " . ($payment_status == 'Paid' ? '✅ ชำระแล้ว' : '⏳ เครดิต/ค้างชำระ') . "\n";
        $msg .= "พนักงานขาย: $user\n\n";
        $msg .= "👉 ฝ่ายคลังสินค้าโปรดเตรียมจัดเตรียมสินค้าเพื่อจัดส่งครับ (ระบบตัดสต็อก FEFO แล้ว)";

        if(function_exists('sendLineMessage')) { sendLineMessage($msg); }
        // -----------------------------------------------------------------

        $status = 'success';
    } else if (!$can_sell && $status != 'error') {
        $status = 'error'; // กรณี error_msg ถูกเซ็ตมาจากสต็อกไม่พอ
    }
}

include '../sidebar.php';
?>

<title>เปิดบิลขายสินค้า | Top Feed Mills</title>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    * { box-sizing: border-box; }
    .sales-card { background: white; padding: 30px; border-radius: 16px; box-shadow: 0 5px 20px rgba(0,0,0,0.03); margin-bottom: 20px; border-top: 5px solid #4e73df; animation: fadeIn 0.5s ease; border-left: 1px solid #f0f0f0; border-right: 1px solid #f0f0f0; border-bottom: 1px solid #f0f0f0; }
    
    .form-control { width: 100%; padding: 12px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-family: 'Sarabun'; font-size: 15px; transition: 0.3s; }
    .form-control:focus { border-color: #4e73df; outline: none; box-shadow: 0 0 0 3px rgba(78, 115, 223, 0.15); }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
    
    table { width: 100%; border-collapse: collapse; margin-top: 10px; min-width: 700px; }
    .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 8px; border: 1px solid #e2e8f0;}
    th { background: #f8f9fa; padding: 15px; color: #4e73df; border-bottom: 2px solid #e2e8f0; text-align: left; }
    td { padding: 12px 15px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
    
    .btn-add { background: #e3f2fd; color: #4e73df; border: 1px dashed #4e73df; padding: 12px 15px; border-radius: 8px; cursor: pointer; font-weight: bold; width: 100%; margin-top: 15px; transition: 0.3s; font-family: 'Sarabun'; }
    .btn-add:hover { background: #d0e7ff; }
    
    .btn-submit { background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); color: white; border: none; padding: 15px 30px; border-radius: 10px; font-size: 16px; font-weight: bold; cursor: pointer; margin-top: 20px; transition: 0.3s; float: right; box-shadow: 0 4px 15px rgba(78, 115, 223, 0.3); font-family: 'Sarabun'; }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(78, 115, 223, 0.4); }
    
    .btn-del-row { background: #fceceb; color: #e74a3b; border: none; padding: 10px 15px; border-radius: 8px; cursor: pointer; transition: 0.2s; }
    .btn-del-row:hover { background: #e74a3b; color: white; }
    
    .summary-box { background: #2c3e50; color: white; padding: 20px 30px; border-radius: 12px; display: flex; justify-content: flex-end; align-items: center; gap: 20px; margin-top: 20px; box-shadow: 0 5px 15px rgba(44,62,80,0.2); }
    .total-amount { font-size: 1.8rem; font-weight: 700; color: #f6c23e; }

    /* Select2 Custom Styling */
    .select2-container--default .select2-selection--single {
        height: 46px;
        border: 1.5px solid #e2e8f0;
        border-radius: 10px;
        display: flex;
        align-items: center;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        padding-left: 15px;
        font-size: 1rem;
        color: #444;
        font-family: 'Sarabun';
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 44px;
        right: 10px;
    }
    .select2-container--default.select2-container--focus .select2-selection--single {
        border-color: #4e73df;
        box-shadow: 0 0 0 3px rgba(78, 115, 223, 0.15);
    }
    
    /* Credit Dashboard Box */
    .credit-dashboard { display: none; margin-top: 15px; padding: 15px; background: #f8f9fc; border-radius: 10px; border-left: 5px solid #4e73df; font-size: 15px; }
    .credit-row { display: flex; justify-content: space-between; margin-bottom: 8px; }
    .credit-title { color: #5a5c69; font-weight: 600; }
    .val-limit { color: #4e73df; font-weight: bold; }
    .val-debt { color: #e74a3b; font-weight: bold; }
    .val-avail { font-size: 1.1rem; font-weight: bold; }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top:0;"><i class="fa-solid fa-cart-arrow-down" style="color: #4e73df;"></i> เปิดบิลขาย (Sales Order)</h2>
    <p style="color: #888; margin-bottom: 20px;">เลือกลูกค้าและรายการสินค้า (ระบบจะตรวจสอบวงเงิน และตัดสต็อกล็อตที่ใกล้หมดอายุก่อนอัตโนมัติ)</p>

    <form method="POST" id="salesForm" onsubmit="return confirm('ยืนยันความถูกต้อง และบันทึกการขาย?');">
        
        <div class="sales-card">
            <h4 style="margin-top:0; color: #4e73df;"><i class="fa-solid fa-user-tag"></i> ข้อมูลลูกค้าและการชำระเงิน</h4>
            <div class="grid-2">
                <div>
                    <label style="font-weight:bold; color:#555; display:block; margin-bottom:8px;">เลือกลูกค้า / ฟาร์ม <span style="color:red;">*</span></label>
                    <select name="cus_id" id="cus_id" class="form-control select2" required onchange="updateCustomerInfo()">
                        <?php echo $cus_options; ?>
                    </select>
                    
                    <div id="credit_dashboard" class="credit-dashboard">
                        <div class="credit-row">
                            <span class="credit-title">วงเงินเครดิตทั้งหมด:</span>
                            <span id="info_limit" class="val-limit">0.00 ฿</span>
                        </div>
                        <div class="credit-row">
                            <span class="credit-title">ยอดหนี้ค้างชำระเดิม:</span>
                            <span id="info_debt" class="val-debt">0.00 ฿</span>
                        </div>
                        <div class="credit-row" style="border-top: 1px dashed #d1d3e2; padding-top: 8px; margin-top: 5px;">
                            <span class="credit-title" style="color:#2c3e50;">เครดิตคงเหลือ (ซื้อได้อีก):</span>
                            <span id="info_avail" class="val-avail">0.00 ฿</span>
                        </div>
                    </div>
                </div>
                <div>
                    <label style="font-weight:bold; color:#555; display:block; margin-bottom:8px;">วันที่ทำรายการขาย <span style="color:red;">*</span></label>
                    <input type="date" name="sale_date" id="sale_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required onchange="updateCustomerInfo()">
                </div>
            </div>

            <div class="grid-2" style="background:#f8f9fc; padding:15px; border-radius:10px; border:1px dashed #d1d3e2;">
                <div>
                    <label style="font-weight:bold; color:#2c3e50; display:block; margin-bottom:8px;">สถานะการชำระเงิน <span style="color:red;">*</span></label>
                    <select name="payment_status" id="payment_status" class="form-control" required onchange="updateCustomerInfo()">
                        <option value="Unpaid">⏳ ยังไม่จ่าย / รอเก็บเงิน</option>
                        <option value="Credit">💳 ให้เครดิต (วางบิล)</option>
                        <option value="Paid">✅ จ่ายเงินเรียบร้อยแล้ว (เงินสด/โอน)</option>
                    </select>
                </div>
                <div>
                    <label style="font-weight:bold; color:#e74a3b; display:block; margin-bottom:8px;">กำหนดชำระเงิน (Due Date) <span style="color:red;">*</span></label>
                    <input type="date" name="due_date" id="due_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>
        </div>

        <div class="sales-card">
            <h4 style="margin-top:0; color: #4e73df;"><i class="fa-solid fa-list-check"></i> รายการสินค้า (ต้องมีในสต็อก)</h4>
            <div class="table-responsive">
                <table id="salesTable">
                    <thead>
                        <tr>
                            <th style="width: 40%;">สินค้าสำเร็จรูป (FG)</th>
                            <th style="width: 15%;">จำนวน (หน่วย)</th>
                            <th style="width: 20%;">ราคาขาย/หน่วย (฿)</th>
                            <th style="width: 20%;">รวม (฿)</th>
                            <th style="width: 5%; text-align:center;">ลบ</th>
                        </tr>
                    </thead>
                    <tbody id="salesBody">
                        <tr class="item-row">
                            <td><select name="items[0][id]" class="form-control select2-item" required><?php echo $item_options; ?></select></td>
                            <td><input type="number" step="0.01" name="items[0][qty]" class="form-control qty" placeholder="0" required oninput="calculateRow(this)"></td>
                            <td><input type="number" step="0.01" name="items[0][price]" class="form-control price" placeholder="0.00" required oninput="calculateRow(this)"></td>
                            <td class="row-total" style="font-weight: bold; color: #2c3e50; font-size: 16px;">0.00</td>
                            <td style="text-align:center;">-</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <button type="button" class="btn-add" onclick="addRow()"><i class="fa-solid fa-plus"></i> เพิ่มรายการสินค้า</button>
            
            <div class="summary-box">
                <span>ยอดรวมทั้งสิ้น:</span>
                <span class="total-amount" id="grandTotal">0.00</span>
                <span>บาท</span>
            </div>
            
            <div style="clear:both; overflow:hidden;">
                <button type="submit" name="submit_sale" class="btn-submit"><i class="fa-solid fa-check-circle"></i> ยืนยันการขายและตัดสต็อก</button>
            </div>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    const customersData = <?php echo json_encode($customers_data); ?>;

    $(document).ready(function() {
        $('.select2').select2({ width: '100%' });
        $('.select2-item').select2({ width: '100%' });
    });

    function updateCustomerInfo() {
        const cusId = document.getElementById('cus_id').value;
        const status = document.getElementById('payment_status').value;
        const saleDateStr = document.getElementById('sale_date').value;
        const dueDateInput = document.getElementById('due_date');
        const creditBox = document.getElementById('credit_dashboard');

        if (status === 'Paid') {
            dueDateInput.value = saleDateStr;
            dueDateInput.readOnly = true;
            dueDateInput.style.backgroundColor = '#eaecf4';
            creditBox.style.display = 'none'; 
        } else {
            dueDateInput.readOnly = false;
            dueDateInput.style.backgroundColor = '#ffffff';
            
            if (cusId && customersData[cusId]) {
                const data = customersData[cusId];
                
                // 🐛 ปรับวิธีบวกวันที่ป้องกันบั๊ก Timezone
                let creditDays = parseInt(data.term);
                if (creditDays > 0 && saleDateStr) {
                    let d = new Date(saleDateStr);
                    d.setDate(d.getDate() + creditDays);
                    let year = d.getFullYear();
                    let month = String(d.getMonth() + 1).padStart(2, '0');
                    let day = String(d.getDate()).padStart(2, '0');
                    dueDateInput.value = `${year}-${month}-${day}`;
                } else {
                    dueDateInput.value = saleDateStr;
                }

                creditBox.style.display = 'block';
                document.getElementById('info_limit').innerText = data.limit.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' ฿';
                document.getElementById('info_debt').innerText = data.debt.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' ฿';
                
                let availEl = document.getElementById('info_avail');
                availEl.innerText = data.avail.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' ฿';
                
                if(data.avail <= 0) {
                    availEl.style.color = '#e74a3b';
                    creditBox.style.borderLeftColor = '#e74a3b';
                } else {
                    availEl.style.color = '#1cc88a';
                    creditBox.style.borderLeftColor = '#4e73df';
                }

            } else {
                creditBox.style.display = 'none';
            }
        }
    }

    let rowIdx = 1;
    const itemOptions = `<?php echo $item_options; ?>`;

    function addRow() {
        const tr = document.createElement('tr');
        tr.className = 'item-row';
        tr.innerHTML = `
            <td><select name="items[${rowIdx}][id]" class="form-control select2-item" required>${itemOptions}</select></td>
            <td><input type="number" step="0.01" name="items[${rowIdx}][qty]" class="form-control qty" placeholder="0" required oninput="calculateRow(this)"></td>
            <td><input type="number" step="0.01" name="items[${rowIdx}][price]" class="form-control price" placeholder="0.00" required oninput="calculateRow(this)"></td>
            <td class="row-total" style="font-weight: bold; color: #2c3e50; font-size: 16px;">0.00</td>
            <td style="text-align:center;"><button type="button" class="btn-del-row" onclick="removeRow(this)"><i class="fa-solid fa-trash"></i></button></td>
        `;
        document.getElementById('salesBody').appendChild(tr);
        $(tr).find('.select2-item').select2({ width: '100%' });
        rowIdx++;
    }

    function removeRow(btn) { btn.closest('tr').remove(); updateGrandTotal(); }

    function calculateRow(input) {
        const row = input.closest('tr');
        const qty = parseFloat(row.querySelector('.qty').value) || 0;
        const price = parseFloat(row.querySelector('.price').value) || 0;
        row.querySelector('.row-total').innerText = (qty * price).toLocaleString('en-US', {minimumFractionDigits: 2});
        updateGrandTotal();
    }

    function updateGrandTotal() {
        let grandTotal = 0;
        document.querySelectorAll('.item-row').forEach(row => {
            const qty = parseFloat(row.querySelector('.qty').value) || 0;
            const price = parseFloat(row.querySelector('.price').value) || 0;
            grandTotal += (qty * price);
        });
        document.getElementById('grandTotal').innerText = grandTotal.toLocaleString('en-US', {minimumFractionDigits: 2});
    }

    // 🐛 แก้ไขบั๊กการใช้เครื่องหมาย Backticks (`) ครอบ PHP ทำให้ทำงานได้ปกติแม้จะมี HTML ซ้อนกัน
    <?php if($status == 'success'): ?>
        Swal.fire({ icon: 'success', title: 'บันทึกการขายสำเร็จ!', text: 'ระบบตัดสต็อกและส่งแจ้งเตือน LINE เรียบร้อย', confirmButtonColor: '#4e73df' }).then(() => { window.location = 'create_sales.php'; });
    <?php elseif($status == 'error'): ?>
        Swal.fire({ icon: 'error', title: 'ไม่สามารถบันทึกบิลได้!', html: `<?php echo $error_msg; ?>`, confirmButtonColor: '#e74a3b' });
    <?php endif; ?>
</script>