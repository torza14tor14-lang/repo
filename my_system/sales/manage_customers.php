<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    header("Location: ../login.php"); exit(); 
}

// จัดการการเพิ่ม/แก้ไขข้อมูล
if (isset($_POST['save_customer'])) {
    $id = $_POST['cus_id'];
    $name = mysqli_real_escape_string($conn, $_POST['cus_name']);
    $address = mysqli_real_escape_string($conn, $_POST['cus_address']);
    $tel = mysqli_real_escape_string($conn, $_POST['cus_tel']);
    $tax_id = mysqli_real_escape_string($conn, $_POST['cus_tax_id']);
    $credit = intval($_POST['credit_term']);

    if ($id == "") {
        $sql = "INSERT INTO customers (cus_name, cus_address, cus_tel, cus_tax_id, credit_term) VALUES ('$name', '$address', '$tel', '$tax_id', '$credit')";
    } else {
        $sql = "UPDATE customers SET cus_name='$name', cus_address='$address', cus_tel='$tel', cus_tax_id='$tax_id', credit_term='$credit' WHERE id='$id'";
    }
    mysqli_query($conn, $sql);
    header("Location: manage_customers.php?success=1"); exit();
}

// จัดการการลบ
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    mysqli_query($conn, "DELETE FROM customers WHERE id='$id'");
    header("Location: manage_customers.php?deleted=1"); exit();
}

include '../sidebar.php';
?>

<title>Top Feed Mills | จัดการข้อมูลลูกค้า</title>
<style>
    .card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-bottom: 25px; border-top: 4px solid #4e73df; }
    .btn-add-main { background: #4e73df; color: white; padding: 12px 25px; border-radius: 10px; text-decoration: none; font-weight: bold; display: inline-flex; align-items: center; gap: 8px; transition: 0.3s; margin-bottom: 20px; box-shadow: 0 4px 10px rgba(78, 115, 223, 0.3); font-size: 15px; border: none; cursor: pointer; }
    .btn-add-main:hover { background: #2e59d9; transform: translateY(-2px); box-shadow: 0 6px 15px rgba(78, 115, 223, 0.4); }
    
    table { width: 100%; border-collapse: collapse; min-width: 800px;}
    .table-responsive { overflow-x: auto; }
    th { background: #f8f9fc; padding: 15px; text-align: left; color: #4e73df; border-bottom: 2px solid #eaecf4; font-size: 14px; }
    td { padding: 15px; border-bottom: 1px solid #f1f1f1; font-size: 14px; color: #555; }
    tr:hover { background: #f8f9fc; }
    
    .btn-edit { color: #f6c23e; cursor: pointer; margin-right: 15px; font-size: 18px; transition: 0.2s; }
    .btn-edit:hover { color: #dda20a; transform: scale(1.1); }
    .btn-del { color: #e74a3b; font-size: 18px; transition: 0.2s; }
    .btn-del:hover { color: #be2617; transform: scale(1.1); }
    
    /* 🚀 อัปเกรด Modal Style ให้ดูโมเดิร์นและพรีเมียม */
    .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(3px); }
    .modal-content { 
        background: white; 
        margin: 5% auto; 
        padding: 35px; 
        width: 90%; 
        max-width: 500px; 
        border-radius: 20px; 
        box-shadow: 0 15px 35px rgba(0,0,0,0.2);
        animation: modalFadeIn 0.3s ease-out forwards;
        position: relative;
    }
    
    @keyframes modalFadeIn {
        from { opacity: 0; transform: translateY(-30px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .modal-header h3 { margin: 0; color: #2c3e50; font-size: 20px; }
    .close-btn { background: none; border: none; font-size: 24px; color: #aaa; cursor: pointer; transition: 0.2s; }
    .close-btn:hover { color: #e74a3b; transform: rotate(90deg); }

    .form-group { margin-bottom: 18px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: bold; color: #4a5568; font-size: 14px; }
    .form-group input, .form-group textarea { 
        width: 100%; 
        padding: 12px 15px; 
        border: 1px solid #e3e6f0; 
        border-radius: 10px; 
        background-color: #f8f9fc;
        font-family: 'Sarabun';
        font-size: 14px;
        transition: 0.3s;
        box-sizing: border-box;
    }
    .form-group input:focus, .form-group textarea:focus {
        border-color: #4e73df;
        background-color: #fff;
        outline: none;
        box-shadow: 0 0 0 3px rgba(78,115,223,0.15);
    }
    
    .modal-actions { display: flex; gap: 15px; margin-top: 30px; }
    .btn-save { flex: 1; background: #1cc88a; color: white; border: none; padding: 12px; border-radius: 10px; font-weight: bold; font-size: 15px; cursor: pointer; transition: 0.3s; box-shadow: 0 4px 10px rgba(28,200,138,0.3); }
    .btn-save:hover { background: #17a673; transform: translateY(-2px); }
    .btn-cancel { flex: 1; background: #eaecf4; color: #5a5c69; border: none; padding: 12px; border-radius: 10px; font-weight: bold; font-size: 15px; cursor: pointer; transition: 0.3s; }
    .btn-cancel:hover { background: #d1d3e2; color: #333; }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top:0;"><i class="fa-solid fa-address-book" style="color: #4e73df;"></i> จัดการฐานข้อมูลลูกค้า (Customers)</h2>

    <button class="btn-add-main" onclick="openModal()"><i class="fa-solid fa-user-plus"></i> เพิ่มรายชื่อลูกค้าใหม่</button>

    <div class="card">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ชื่อลูกค้า / บริษัท</th>
                        <th>เบอร์โทรศัพท์</th>
                        <th>เครดิต (วัน)</th>
                        <th>เลขผู้เสียภาษี</th>
                        <th style="text-align:right;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $res = mysqli_query($conn, "SELECT * FROM customers ORDER BY cus_name ASC");
                    if (mysqli_num_rows($res) > 0) {
                        while($row = mysqli_fetch_assoc($res)) {
                    ?>
                    <tr>
                        <td>
                            <strong style="color: #2c3e50; font-size: 15px;"><?= $row['cus_name'] ?></strong><br>
                            <small class="text-muted"><i class="fa-solid fa-location-dot" style="color:#ccc;"></i> <?= mb_strimwidth($row['cus_address'], 0, 50, "...") ?></small>
                        </td>
                        <td><i class="fa-solid fa-phone" style="color:#ccc; font-size:12px;"></i> <?= $row['cus_tel'] ?: '-' ?></td>
                        <td><span class="badge" style="background:#e3f2fd; color:#1976d2; padding:5px 12px; border-radius:50px; font-weight:bold;"><?= $row['credit_term'] ?> วัน</span></td>
                        <td><?= $row['cus_tax_id'] ?: '-' ?></td>
                        <td style="text-align:right;">
                            <a href="javascript:void(0)" class="btn-edit" onclick='editCustomer(<?= json_encode($row) ?>)'><i class="fa-solid fa-pen-to-square"></i></a>
                            <a href="?delete=<?= $row['id'] ?>" class="btn-del" onclick="return confirm('ยืนยันการลบข้อมูลลูกค้าท่านนี้?')"><i class="fa-solid fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php 
                        } 
                    } else {
                        echo "<tr><td colspan='5' style='text-align:center; padding:30px; color:#999;'>ยังไม่มีข้อมูลลูกค้าในระบบ</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="cusModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle"><i class="fa-solid fa-user-tag" style="color:#4e73df;"></i> เพิ่มลูกค้าใหม่</h3>
            <button class="close-btn" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        
        <form method="POST">
            <input type="hidden" name="cus_id" id="cus_id">
            
            <div class="form-group">
                <label>ชื่อลูกค้า / ชื่อบริษัท <span style="color:#e74a3b;">*</span></label>
                <input type="text" name="cus_name" id="cus_name" placeholder="เช่น บจก. พัฒนาฟาร์ม" required>
            </div>
            
            <div class="form-group">
                <label>ที่อยู่สำหรับออกบิล</label>
                <textarea name="cus_address" id="cus_address" rows="3" placeholder="บ้านเลขที่, ถนน, ตำบล, อำเภอ, จังหวัด..."></textarea>
            </div>
            
            <div class="form-group">
                <label>เบอร์โทรศัพท์ติดต่อ</label>
                <input type="text" name="cus_tel" id="cus_tel" placeholder="08X-XXX-XXXX">
            </div>
            
            <div class="form-group">
                <label>เลขประจำตัวผู้เสียภาษี (13 หลัก)</label>
                <input type="text" name="cus_tax_id" id="cus_tax_id" placeholder="0123456789012">
            </div>
            
            <div class="form-group">
                <label>จำนวนวันให้เครดิตชำระเงิน (Credit Term)</label>
                <input type="number" name="credit_term" id="credit_term" value="0" min="0">
            </div>
            
            <div class="modal-actions">
                <button type="submit" name="save_customer" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> บันทึกข้อมูล</button>
                <button type="button" class="btn-cancel" onclick="closeModal()">ยกเลิก</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal() {
        document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-user-plus" style="color:#4e73df;"></i> เพิ่มลูกค้าใหม่';
        document.getElementById('cus_id').value = "";
        document.getElementById('cus_name').value = "";
        document.getElementById('cus_address').value = "";
        document.getElementById('cus_tel').value = "";
        document.getElementById('cus_tax_id').value = "";
        document.getElementById('credit_term').value = "0";
        document.getElementById('cusModal').style.display = "block";
    }
    
    function closeModal() { 
        document.getElementById('cusModal').style.display = "none"; 
    }
    
    function editCustomer(data) {
        document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-user-pen" style="color:#f6c23e;"></i> แก้ไขข้อมูลลูกค้า';
        document.getElementById('cus_id').value = data.id;
        document.getElementById('cus_name').value = data.cus_name;
        document.getElementById('cus_address').value = data.cus_address;
        document.getElementById('cus_tel').value = data.cus_tel;
        document.getElementById('cus_tax_id').value = data.cus_tax_id;
        document.getElementById('credit_term').value = data.credit_term;
        document.getElementById('cusModal').style.display = "block";
    }

    // ปิด Modal เมื่อคลิกพื้นที่ว่างข้างนอก
    window.onclick = function(event) {
        if (event.target == document.getElementById('cusModal')) {
            closeModal();
        }
    }
</script>