<?php
session_start();
include '../db.php';

// สเต็ปที่ 1: ตรวจสอบว่า "ล็อกอินหรือยัง?" (ถ้ายัง ให้เด้งไปหน้า login ทันที)
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

// ตรวจสอบสิทธิ์
$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
if ($user_role !== 'ADMIN' && $user_dept !== 'ฝ่ายจัดซื้อ') { 
    echo "<script>alert('ไม่มีสิทธิ์เข้าถึง'); window.location='../index.php';</script>"; exit(); 
}

// ---------------------------------------------------------
// ระบบ Quick Add เพิ่มผู้ขายด่วนผ่าน AJAX (แบบเต็มฟอร์ม 4 ช่อง)
// ---------------------------------------------------------
if (isset($_POST['ajax_add_supplier'])) {
    $s_name = mysqli_real_escape_string($conn, $_POST['s_name']);
    $contact = mysqli_real_escape_string($conn, $_POST['contact_person']); // เพิ่มช่องผู้ติดต่อ
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $address = mysqli_real_escape_string($conn, $_POST['address']); // เพิ่มช่องที่อยู่

    // เช็คซ้ำ
    $check = mysqli_query($conn, "SELECT id FROM suppliers WHERE s_name = '$s_name'");
    if (mysqli_num_rows($check) > 0) {
        echo json_encode(['status' => 'error', 'msg' => 'มีรายชื่อบริษัทนี้ในระบบแล้ว!']);
        exit();
    }

    // บันทึกครบ 4 ช่อง
    $sql = "INSERT INTO suppliers (s_name, contact_person, phone, address) VALUES ('$s_name', '$contact', '$phone', '$address')";
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['status' => 'success', 's_name' => $s_name]);
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'เกิดข้อผิดพลาดในการบันทึก']);
    }
    exit(); 
}
// ---------------------------------------------------------

// เตรียม Options สำหรับ JavaScript
$options = "<option value=''>-- พิมพ์ค้นหา หรือเลือกวัตถุดิบ --</option>";
$raw_items = mysqli_query($conn, "SELECT id, p_name FROM products WHERE p_type = 'RAW' ORDER BY p_name ASC");
while($row = mysqli_fetch_assoc($raw_items)) {
    $options .= "<option value='{$row['id']}'>🌾 {$row['p_name']}</option>";
}

$status = '';
if (isset($_POST['submit_po'])) {
    $supplier = mysqli_real_escape_string($conn, $_POST['supplier_name']);
    $date = $_POST['delivery_date'];
    $user = $_SESSION['fullname'] ?? $_SESSION['username'];

    mysqli_query($conn, "INSERT INTO purchase_orders (supplier_name, expected_delivery_date) VALUES ('$supplier', '$date')");
    $po_id = mysqli_insert_id($conn);
    $grand_total = 0;

    if (isset($_POST['items'])) {
        foreach ($_POST['items'] as $it) {
            $i_id = (int)$it['id'];
            $qty = (float)$it['qty'];
            $price = (float)$it['price'];
            
            if ($i_id > 0 && $qty > 0) {
                mysqli_query($conn, "INSERT INTO po_items (po_id, item_id, quantity, unit_price) VALUES ('$po_id', '$i_id', '$qty', '$price')");
                $grand_total += ($qty * $price); 
            }
        }
    }
    
    // 🚀 บันทึกประวัติ Log ลงระบบ
    if(function_exists('log_event')) {
        log_event($conn, 'INSERT', 'purchase_orders', "สร้างใบสั่งซื้อใหม่ PO-$po_id (ซัพพลายเออร์: $supplier) ยอดประเมิน " . number_format($grand_total, 2) . " ฿");
    }
    
    // แจ้งเตือน LINE หาหัวหน้าจัดซื้อและผู้จัดการ
    include_once '../line_api.php';
    $msg = "🛒 [จัดซื้อ] สร้างใบสั่งซื้อใหม่ (PO-" . str_pad($po_id, 5, '0', STR_PAD_LEFT) . ")\n\n";
    $msg .= "🏢 ผู้ขาย: $supplier\n";
    $msg .= "💰 ยอดรวมประเมิน: " . number_format($grand_total, 2) . " บาท\n";
    $msg .= "📅 กำหนดรับของ: " . date('d/m/Y', strtotime($date)) . "\n";
    $msg .= "พนักงานจัดซื้อ: $user\n\n";
    $msg .= "👉 โปรดเข้าตรวจสอบและอนุมัติในระบบครับ";
    if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

    $status = 'success';
}

include '../sidebar.php';
?>

<title>สร้างใบสั่งซื้อ (PO) | Top Feed Mills</title>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    .po-card { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-bottom: 20px; border-top: 5px solid #36b9cc; }
    .form-control { width: 100%; padding: 12px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-family: 'Sarabun'; box-sizing: border-box; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; min-width: 800px;}
    .table-responsive { width: 100%; overflow-x: auto; border-radius: 8px;}
    th { background: #f8f9fc; padding: 12px; color: #5a5c69; border-bottom: 2px solid #eaecf4; text-align: left; white-space: nowrap;}
    td { padding: 10px; border-bottom: 1px solid #eaecf4; vertical-align: middle;}
    
    .btn-add { background: #e3fdfd; color: #36b9cc; border: 1px dashed #36b9cc; padding: 10px 15px; border-radius: 8px; cursor: pointer; font-weight: bold; width: 100%; margin-top: 10px; transition: 0.3s; }
    .btn-add:hover { background: #cff6f6; }
    .btn-submit { background: linear-gradient(135deg, #36b9cc 0%, #1e8796 100%); color: white; border: none; padding: 15px 30px; border-radius: 10px; font-size: 16px; font-weight: bold; cursor: pointer; margin-top: 20px; transition: 0.3s; float: right; box-shadow: 0 4px 15px rgba(54,185,204,0.3);}
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(54,185,204,0.4); }
    .btn-del-row { background: #fceceb; color: #e74a3b; border: none; padding: 10px; border-radius: 8px; cursor: pointer; transition: 0.2s;}
    .btn-del-row:hover { background: #e74a3b; color: white;}

    /* 🚀 สไตล์สำหรับกล่องยอดรวมสุทธิ */
    .summary-box { background: #2c3e50; color: white; padding: 20px 30px; border-radius: 12px; display: flex; justify-content: flex-end; align-items: center; gap: 20px; margin-top: 20px; box-shadow: 0 5px 15px rgba(44,62,80,0.2); }
    .total-amount { font-size: 1.8rem; font-weight: 700; color: #f6c23e; }

    /* ปุ่ม Quick Add Supplier */
    .btn-quick-add { background: #4e73df; color: white; border: none; padding: 0 15px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.3s; display: flex; align-items: center; gap: 5px; white-space: nowrap; height: 46px;}
    .btn-quick-add:hover { background: #2e59d9; transform: translateY(-1px); box-shadow: 0 4px 8px rgba(78, 115, 223, 0.2); }

    /* ปรับแต่งหน้าตา Select2 */
    .select2-container--default .select2-selection--single {
        height: 46px; border: 1.5px solid #e2e8f0; border-radius: 8px; display: flex; align-items: center; font-family: 'Sarabun', sans-serif; outline: none; transition: 0.3s;
    }
    .select2-container--default .select2-selection--single:focus, .select2-container--open .select2-selection--single {
        border-color: #36b9cc; box-shadow: 0 0 0 3px rgba(54, 185, 204, 0.15);
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered { color: #4a5568; padding-left: 12px; font-size: 15px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 44px; right: 10px; }
    .select2-dropdown { border: 1.5px solid #36b9cc; border-radius: 8px; font-family: 'Sarabun', sans-serif; box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-top: none; }
    .select2-search__field { border-radius: 5px !important; border: 1px solid #ccc !important; padding: 8px !important; }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top:0;"><i class="fa-solid fa-file-invoice-dollar" style="color: #36b9cc;"></i> สร้างใบสั่งซื้อ (Purchase Order)</h2>
    
    <form method="POST" id="poForm" onsubmit="return confirm('ตรวจสอบความถูกต้องและยืนยันการสร้างใบสั่งซื้อ?');">
        <div class="po-card">
            <h4><i class="fa-regular fa-building"></i> ข้อมูลผู้ขาย (Supplier Info)</h4>
            <div class="grid-2">
                <div>
                    <label style="font-weight:bold; color:#555; display:block; margin-bottom:8px;">ชื่อบริษัทผู้ขาย / Supplier <span style="color:red;">*</span></label>
                    <div style="display: flex; gap: 10px;">
                        <div style="flex: 1;">
                            <select name="supplier_name" id="supplier_select" class="form-control select2-search" required>
                                <option value="">-- เลือกผู้ขายจากฐานข้อมูล --</option>
                                <?php 
                                $s_list = mysqli_query($conn, "SELECT s_name FROM suppliers ORDER BY s_name ASC");
                                while($s = mysqli_fetch_assoc($s_list)) {
                                    echo "<option value='{$s['s_name']}'>🏢 {$s['s_name']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <button type="button" class="btn-quick-add" onclick="quickAddSupplier()">
                            <i class="fa-solid fa-plus-circle"></i> เพิ่มใหม่
                        </button>
                    </div>
                </div>
                <div>
                    <label style="font-weight:bold; color:#555; display:block; margin-bottom:8px;">วันที่คาดว่าจะได้รับของ (Delivery Date) <span style="color:red;">*</span></label>
                    <input type="date" name="delivery_date" class="form-control" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" required>
                </div>
            </div>
        </div>

        <div class="po-card">
            <h4 style="margin-top:0; color:#36b9cc;"><i class="fa-solid fa-list-check"></i> รายการสินค้า</h4>
            <div class="table-responsive">
                <table id="poTable">
                    <thead>
                        <tr>
                            <th style="width: 40%;">วัตถุดิบ (RAW) / สินค้า</th>
                            <th style="width: 15%;">จำนวน (กก./หน่วย)</th>
                            <th style="width: 20%;">ราคาต่อหน่วย (บาท)</th>
                            <th style="width: 20%;">ยอดรวมประเมิน (บาท)</th>
                            <th style="width: 5%; text-align:center;">ลบ</th>
                        </tr>
                    </thead>
                    <tbody id="poBody">
                        <tr class="item-row">
                            <td><select name="items[0][id]" class="form-control select2-search select2-item" required><?php echo $options; ?></select></td>
                            <td><input type="number" step="0.01" name="items[0][qty]" class="form-control qty" placeholder="0" required oninput="calculateRow(this)"></td>
                            <td><input type="number" step="0.01" name="items[0][price]" class="form-control price" placeholder="0.00" required oninput="calculateRow(this)"></td>
                            <td class="row-total" style="font-weight:bold; color:#2c3e50; font-size:16px;">0.00</td>
                            <td style="text-align:center;">-</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <button type="button" class="btn-add" onclick="addRow()"><i class="fa-solid fa-plus"></i> เพิ่มรายการสินค้าใหม่</button>
            
            <div class="summary-box">
                <span>รวมยอดสั่งซื้อสุทธิประเมิน:</span>
                <span class="total-amount" id="grandTotal">0.00</span>
                <span>บาท</span>
            </div>

            <div style="clear:both; overflow:hidden;">
                <button type="submit" name="submit_po" class="btn-submit"><i class="fa-solid fa-paper-plane"></i> บันทึกและส่งขออนุมัติ</button>
            </div>
        </div>
    </form>
</div>

<script>
    $(document).ready(function() {
        $('.select2-search').select2({ width: '100%' });
    });

    let rowIdx = 1;
    const optionStr = `<?php echo $options; ?>`;

    // 🚀 เพิ่มแถวใหม่ พร้อมระบบดัก Event การคำนวณ
    function addRow() {
        const tr = document.createElement('tr');
        tr.className = 'item-row';
        tr.innerHTML = `
            <td><select name="items[${rowIdx}][id]" class="form-control select2-search select2-item" required>${optionStr}</select></td>
            <td><input type="number" step="0.01" name="items[${rowIdx}][qty]" class="form-control qty" placeholder="0" required oninput="calculateRow(this)"></td>
            <td><input type="number" step="0.01" name="items[${rowIdx}][price]" class="form-control price" placeholder="0.00" required oninput="calculateRow(this)"></td>
            <td class="row-total" style="font-weight:bold; color:#2c3e50; font-size:16px;">0.00</td>
            <td style="text-align:center;"><button type="button" class="btn-del-row" onclick="removeRow(this)"><i class="fa-solid fa-trash"></i></button></td>
        `;
        document.getElementById('poBody').appendChild(tr);
        $(tr).find('.select2-item').select2({ width: '100%' });
        rowIdx++;
    }

    // 🚀 ลบแถว และอัปเดตยอดรวม
    function removeRow(btn) {
        btn.closest('tr').remove();
        updateGrandTotal();
    }

    // 🚀 คำนวณราคารายบรรทัด (Quantity x Price)
    function calculateRow(input) {
        const row = input.closest('tr');
        const qty = parseFloat(row.querySelector('.qty').value) || 0;
        const price = parseFloat(row.querySelector('.price').value) || 0;
        const total = qty * price;
        
        row.querySelector('.row-total').innerText = total.toLocaleString('en-US', {minimumFractionDigits: 2});
        updateGrandTotal();
    }

    // 🚀 คำนวณราคารวมทั้งหมด (Grand Total)
    function updateGrandTotal() {
        let grandTotal = 0;
        document.querySelectorAll('.item-row').forEach(row => {
            const qty = parseFloat(row.querySelector('.qty').value) || 0;
            const price = parseFloat(row.querySelector('.price').value) || 0;
            grandTotal += (qty * price);
        });
        document.getElementById('grandTotal').innerText = grandTotal.toLocaleString('en-US', {minimumFractionDigits: 2});
    }

    // ฟังก์ชัน Popup สำหรับเพิ่มผู้ขายด่วน (ปรับให้มี 4 ช่อง)
    function quickAddSupplier() {
        Swal.fire({
            title: '➕ เพิ่มผู้ขายรายใหม่',
            width: '500px', 
            html: `
                <div style="text-align: left; padding: 0 10px;">
                    <label style="font-size:13px; font-weight:bold; color:#888;">ชื่อบริษัท/ร้านค้า <span style="color:#e74a3b;">*</span></label>
                    <input type="text" id="swal-s_name" class="swal2-input" placeholder="เช่น บจก. พืชผล" style="font-family: 'Sarabun'; margin: 5px 0 15px 0; width: 100%; box-sizing: border-box;" required>
                    
                    <label style="font-size:13px; font-weight:bold; color:#888;">ชื่อผู้ติดต่อ</label>
                    <input type="text" id="swal-contact" class="swal2-input" placeholder="ชื่อเล่น/ชื่อจริง" style="font-family: 'Sarabun'; margin: 5px 0 15px 0; width: 100%; box-sizing: border-box;">
                    
                    <label style="font-size:13px; font-weight:bold; color:#888;">เบอร์โทรศัพท์</label>
                    <input type="text" id="swal-phone" class="swal2-input" placeholder="0xx-xxx-xxxx" style="font-family: 'Sarabun'; margin: 5px 0 15px 0; width: 100%; box-sizing: border-box;">
                    
                    <label style="font-size:13px; font-weight:bold; color:#888;">ที่อยู่ / ข้อมูลเพิ่มเติม</label>
                    <textarea id="swal-address" class="swal2-textarea" placeholder="ระบุที่อยู่..." style="font-family: 'Sarabun'; margin: 5px 0 0 0; width: 100%; box-sizing: border-box; height: 80px;"></textarea>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-save"></i> บันทึกข้อมูล',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#4e73df',
            preConfirm: () => {
                const s_name = document.getElementById('swal-s_name').value;
                const contact = document.getElementById('swal-contact').value;
                const phone = document.getElementById('swal-phone').value;
                const address = document.getElementById('swal-address').value;
                
                if (!s_name) {
                    Swal.showValidationMessage('กรุณากรอกชื่อบริษัท/ร้านค้า');
                    return false;
                }
                return { s_name: s_name, contact_person: contact, phone: phone, address: address };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'create_po.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        ajax_add_supplier: 1,
                        s_name: result.value.s_name,
                        contact_person: result.value.contact_person,
                        phone: result.value.phone,
                        address: result.value.address
                    },
                    success: function(res) {
                        if (res.status === 'success') {
                            var newOption = new Option("🏢 " + res.s_name, res.s_name, true, true);
                            $('#supplier_select').append(newOption).trigger('change');
                            
                            Swal.fire({
                                icon: 'success', title: 'เพิ่มผู้ขายสำเร็จ!', 
                                text: 'ข้อมูลถูกบันทึกเข้าระบบแล้ว',
                                showConfirmButton: false, timer: 2000
                            });
                        } else {
                            Swal.fire({ icon: 'error', title: 'ไม่สามารถบันทึกได้', text: res.msg });
                        }
                    }
                });
            }
        });
    }

    <?php if($status == 'success'): ?>
    Swal.fire({
        icon: 'success',
        title: 'ออกใบสั่งซื้อสำเร็จ!',
        text: 'ระบบได้ส่งใบสั่งซื้อไปให้ผู้จัดการอนุมัติแล้ว',
        confirmButtonColor: '#36b9cc'
    }).then(() => { window.location = 'view_pos.php'; });
    <?php endif; ?>
</script>