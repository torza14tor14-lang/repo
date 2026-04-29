<?php
session_start();
include '../db.php';

// สเต็ปที่ 1: ตรวจสอบว่า "ล็อกอินหรือยัง?" (ถ้ายัง ให้เด้งไปหน้า login ทันที)
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

// ตรวจสอบสิทธิ์ (เฉพาะ Admin และ จัดซื้อ)
$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
if ($user_role !== 'ADMIN' && $user_dept !== 'ฝ่ายจัดซื้อ') { 
    echo "<script>alert('ไม่มีสิทธิ์เข้าถึง'); window.location='../index.php';</script>"; exit(); 
}

$status = '';
// บันทึก / แก้ไข ข้อมูล
if (isset($_POST['save_supplier'])) {
    $s_name = mysqli_real_escape_string($conn, $_POST['s_name']);
    $contact = mysqli_real_escape_string($conn, $_POST['contact_person']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $address = mysqli_real_escape_string($conn, $_POST['address']);
    $s_id = $_POST['s_id'];

    if (!empty($s_id)) {
        $sql = "UPDATE suppliers SET s_name='$s_name', contact_person='$contact', phone='$phone', address='$address' WHERE id='$s_id'";
    } else {
        $sql = "INSERT INTO suppliers (s_name, contact_person, phone, address) VALUES ('$s_name', '$contact', '$phone', '$address')";
    }
    
    if (mysqli_query($conn, $sql)) { $status = 'success'; }
}

// ลบข้อมูล
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    mysqli_query($conn, "DELETE FROM suppliers WHERE id = '$id'");
    header("Location: manage_suppliers.php?status=deleted"); exit();
}

include '../sidebar.php';
?>

<title>จัดการรายชื่อผู้ขาย | Top Feed Mills</title>
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.12/dist/sweetalert2.min.css" rel="stylesheet">

<style>
    .card-custom { background: white; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 25px; border-top: 5px solid #4e73df; }
    .form-control { width: 100%; padding: 12px; border: 1.5px solid #e2e8f0; border-radius: 10px; margin-bottom: 15px; font-family: 'Sarabun'; }
    .btn-save { background: #4e73df; color: white; border: none; padding: 12px 25px; border-radius: 10px; font-weight: bold; cursor: pointer; transition: 0.3s; width: 100%; }
    .btn-save:hover { background: #2e59d9; transform: translateY(-2px); }
    
    table { width: 100%; border-collapse: collapse; }
    th { background: #f8f9fc; padding: 15px; text-align: left; color: #4e73df; border-bottom: 2px solid #eaecf4; font-size: 14px; }
    td { padding: 15px; border-bottom: 1px solid #eaecf4; color: #333; font-size: 15px; }
    .btn-edit { color: #f6c23e; background: #fdfaf2; padding: 8px; border-radius: 8px; margin-right: 5px; }
    .btn-del { color: #e74a3b; background: #fff5f5; padding: 8px; border-radius: 8px; }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top:0;"><i class="fa-solid fa-truck-field" style="color: #4e73df;"></i> ฐานข้อมูลผู้ขาย (Suppliers Master Data)</h2>
    
    <div style="display: grid; grid-template-columns: 350px 1fr; gap: 25px; align-items: start;">
        <div class="card-custom">
            <h4 id="form-title" style="margin-top:0; color:#5a5c69;">➕ เพิ่มผู้ขายรายใหม่</h4>
            <form method="POST" id="supplierForm">
                <input type="hidden" name="s_id" id="s_id">
                <label style="font-size:13px; font-weight:bold; color:#888;">ชื่อบริษัท/ร้านค้า</label>
                <input type="text" name="s_name" id="s_name" class="form-control" placeholder="เช่น บจก. พืชผล" required>
                
                <label style="font-size:13px; font-weight:bold; color:#888;">ชื่อผู้ติดต่อ</label>
                <input type="text" name="contact_person" id="contact_person" class="form-control" placeholder="ชื่อเล่น/ชื่อจริง">
                
                <label style="font-size:13px; font-weight:bold; color:#888;">เบอร์โทรศัพท์</label>
                <input type="text" name="phone" id="phone" class="form-control" placeholder="0xx-xxx-xxxx">
                
                <label style="font-size:13px; font-weight:bold; color:#888;">ที่อยู่ / ข้อมูลเพิ่มเติม</label>
                <textarea name="address" id="address" class="form-control" rows="3"></textarea>
                
                <button type="submit" name="save_supplier" class="btn-save"><i class="fa-solid fa-save"></i> บันทึกข้อมูล</button>
                <button type="button" onclick="resetForm()" id="btn-reset" style="display:none; background:#858796; color:white; border:none; padding:8px; width:100%; border-radius:10px; margin-top:10px; cursor:pointer;">ยกเลิกแก้ไข</button>
            </form>
        </div>

        <div class="card-custom" style="border-top-color: #1cc88a;">
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ชื่อผู้ขาย</th>
                            <th>ผู้ติดต่อ</th>
                            <th>เบอร์โทรศัพท์</th>
                            <th style="text-align:center;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $list = mysqli_query($conn, "SELECT * FROM suppliers ORDER BY s_name ASC");
                        while($row = mysqli_fetch_assoc($list)) {
                        ?>
                        <tr>
                            <td><strong><?php echo $row['s_name']; ?></strong></td>
                            <td><?php echo $row['contact_person'] ?: '-'; ?></td>
                            <td><?php echo $row['phone'] ?: '-'; ?></td>
                            <td style="text-align:center;">
                                <a href="javascript:void(0)" onclick='editSupplier(<?php echo json_encode($row); ?>)' class="btn-edit" title="แก้ไข"><i class="fa-solid fa-pen"></i></a>
                                <a href="javascript:void(0)" onclick="confirmDel(<?php echo $row['id']; ?>)" class="btn-del" title="ลบ"><i class="fa-solid fa-trash-can"></i></a>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    function editSupplier(data) {
        document.getElementById('form-title').innerText = "📝 แก้ไขข้อมูลผู้ขาย";
        document.getElementById('s_id').value = data.id;
        document.getElementById('s_name').value = data.s_name;
        document.getElementById('contact_person').value = data.contact_person;
        document.getElementById('phone').value = data.phone;
        document.getElementById('address').value = data.address;
        document.getElementById('btn-reset').style.display = "block";
    }

    function resetForm() {
        document.getElementById('supplierForm').reset();
        document.getElementById('s_id').value = "";
        document.getElementById('form-title').innerText = "➕ เพิ่มผู้ขายรายใหม่";
        document.getElementById('btn-reset').style.display = "none";
    }

    function confirmDel(id) {
        Swal.fire({
            title: 'ยืนยันการลบ?', text: "ข้อมูลผู้ขายจะถูกลบถาวร!", icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#e74a3b', confirmButtonText: 'ลบเลย', cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) { window.location.href = '?delete=' + id; }
        });
    }

    <?php if($status == 'success'): ?>
        Swal.fire({ icon: 'success', title: 'บันทึกสำเร็จ!', showConfirmButton: false, timer: 1500 });
    <?php endif; ?>
</script>