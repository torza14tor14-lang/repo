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

// สิทธิ์เข้าถึง: ADMIN, MANAGER, และ แผนกคลังสินค้า
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && strpos($user_dept, 'คลังสินค้า') === false) { 
    echo "<script>alert('เฉพาะผู้ดูแลระบบและแผนกคลังสินค้าเท่านั้น'); window.location='stock.php';</script>"; 
    exit(); 
}

$status = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_scrap'])) {
    list($product_id, $wh_id) = explode('|', $_POST['source_stock']);
    $product_id = (int)$product_id;
    $wh_id = (int)$wh_id;
    
    $qty = (float)$_POST['qty'];
    $reason = mysqli_real_escape_string($conn, $_POST['reason']);
    $lot_no = mysqli_real_escape_string($conn, $_POST['lot_no'] ?? '-');

    $q_stock = mysqli_query($conn, "SELECT qty FROM stock_balances WHERE product_id = $product_id AND wh_id = $wh_id");
    $current_qty = ($q_stock && mysqli_num_rows($q_stock) > 0) ? (float)mysqli_fetch_assoc($q_stock)['qty'] : 0;

    if ($qty <= 0) {
        $error_msg = "จำนวนที่ตัดชำรุดต้องมากกว่า 0";
    } elseif ($qty > $current_qty) {
        $error_msg = "ยอดสินค้าในคลังที่ระบุมีไม่เพียงพอ! (มีแค่ $current_qty)";
    } else {
        mysqli_query($conn, "UPDATE stock_balances SET qty = qty - $qty WHERE product_id = $product_id AND wh_id = $wh_id");
        mysqli_query($conn, "UPDATE products SET p_qty = p_qty - $qty WHERE id = $product_id");

        mysqli_query($conn, "INSERT INTO scrap_records (product_id, lot_no, qty, reason, reported_by) 
                             VALUES ($product_id, '$lot_no', $qty, '$reason', '$fullname')");

        mysqli_query($conn, "INSERT INTO stock_log (product_id, type, qty, from_wh_id, reference, action_by) 
                             VALUES ($product_id, 'OUT', $qty, $wh_id, 'ตัดชำรุด/ของเสีย: $reason', '$fullname')");

        if (function_exists('log_event')) {
            $p_name = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p_name FROM products WHERE id = $product_id"))['p_name'] ?? 'Unknown';
            log_event($conn, 'INSERT', 'scrap_records', "ตัดชำรุด $p_name จำนวน $qty (จากคลัง ID: $wh_id) สาเหตุ: $reason");
        }

        include_once '../line_api.php';
        $wh_name = mysqli_fetch_assoc(mysqli_query($conn, "SELECT wh_name FROM warehouses WHERE wh_id = $wh_id"))['wh_name'];
        $msg = "🗑️ [แจ้งเตือนคลังสินค้า] บันทึกตัดชำรุด/ของเสีย\n\n📦 สินค้า: $p_name\n🏢 ตัดออกจาก: $wh_name\n📉 จำนวนที่ตัด: " . number_format($qty, 2) . "\nผู้ทำรายการ: $fullname\n💬 เหตุผล: $reason";
        if(function_exists('sendLineMessage')) { sendLineMessage($msg); }

        header("Location: scrap_product.php?status=success"); exit();
    }
}

$sql_source = "SELECT sb.product_id, sb.wh_id, sb.qty, p.p_name, p.p_unit, w.wh_name, w.plant 
               FROM stock_balances sb
               JOIN products p ON sb.product_id = p.id
               JOIN warehouses w ON sb.wh_id = w.wh_id
               WHERE sb.qty > 0
               ORDER BY p.p_name ASC, w.plant ASC";
$res_source = mysqli_query($conn, $sql_source);

$res_history = mysqli_query($conn, "SELECT sr.*, p.p_name, p.p_unit FROM scrap_records sr JOIN products p ON sr.product_id = p.id ORDER BY sr.created_at DESC LIMIT 50");

include '../sidebar.php';
?>

<title>บันทึกตัดชำรุด (Scrap) | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    :root { --bg: #f8fafc; --card: #ffffff; --text-main: #1e293b; --text-muted: #64748b; }
    body { font-family: 'Sarabun', sans-serif; background: var(--bg); }
    .content-padding { padding: 30px; max-width: 1000px; margin: auto; } /* ปรับความกว้างให้กำลังดี */
    
    .scrap-card { background: var(--card); padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border-top: 5px solid var(--primary); margin-bottom:40px;}
    .history-card { background: white; border-radius: 20px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
    
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-weight: 800; color: var(--text-main); margin-bottom: 10px; font-size: 15px; }
    .form-control { width: 100%; padding: 14px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-family: 'Sarabun'; font-size: 15px; box-sizing: border-box; transition: 0.2s;}
    .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(231, 74, 59, 0.15); }
    
    .select2-container--default .select2-selection--single { height: 50px; border: 1.5px solid #e2e8f0; border-radius: 10px; display: flex; align-items: center; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { padding-left: 15px; font-weight: bold; color: var(--text-main); font-size:15px;}
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 48px; right: 10px; }

    .btn-submit { background: var(--primary); color: white; width: 100%; padding: 16px; border: none; border-radius: 10px; font-size: 18px; font-weight: 800; cursor: pointer; transition: 0.3s; font-family: 'Sarabun'; display:flex; justify-content:center; align-items:center; gap:10px;}
    .btn-submit:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(231, 74, 59, 0.3); }

    table { width: 100%; border-collapse: collapse; min-width: 600px;}
    th { text-align: left; padding: 15px; background: #f8fafc; color: var(--text-muted); font-size: 14px; font-weight: 800; border-bottom: 2px solid #e2e8f0; }
    td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 15px; vertical-align:middle;}
    tr:hover { background: #f8fafc; }
</style>

<div class="content-padding">
    
    <div style="margin-bottom: 30px;">
        <h2 style="margin:0; color:var(--text-main); font-weight:800; font-size:26px;">
            <i class="fa-solid fa-dumpster-fire" style="color:var(--primary);"></i> บันทึกตัดชำรุด / ของเสีย
        </h2>
        <p style="color:var(--text-muted); margin-top:5px;">ระบุคลัง/ไซโล ที่ต้องการนำสินค้าหรือวัตถุดิบที่ชำรุดออกจากระบบ</p>
    </div>

    <div class="scrap-card">
        <?php if($error_msg): ?>
            <div style="background: #fef2f2; color: #ef4444; padding: 15px; border-radius: 10px; margin-bottom: 25px; font-weight:bold; border:1px solid #fecaca;">
                <i class="fa-solid fa-circle-exclamation"></i> <?= $error_msg ?>
            </div>
        <?php endif; ?>

        <form method="POST" onsubmit="return confirm('ยืนยันการตัดยอดของเสีย? ระบบจะหักสต็อกออกจากโกดังที่คุณเลือกทันที');">
            
            <div class="form-group" style="background:#f8fafc; padding:20px; border-radius:12px; border:1px dashed #cbd5e1;">
                <label class="form-label">1. เลือกสินค้า และคลัง/ไซโล ที่ต้องการหักยอด <span style="color:red;">*</span></label>
                <select name="source_stock" id="source_stock" class="form-control select2" required onchange="updateMaxQty()">
                    <option value="">-- พิมพ์ค้นหาสินค้า หรือ โกดัง --</option>
                    <?php while($src = mysqli_fetch_assoc($res_source)): ?>
                        <option value="<?= $src['product_id'] ?>|<?= $src['wh_id'] ?>" data-max="<?= $src['qty'] ?>" data-unit="<?= $src['p_unit'] ?>">
                            <?= htmlspecialchars($src['p_name']) ?> — [<?= $src['plant'] ?>] <?= $src['wh_name'] ?> (มี: <?= number_format($src['qty'],2) ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div style="display:flex; gap:20px; flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:200px;">
                    <label class="form-label">2. จำนวนที่ตัดทิ้ง <span style="color:red;">*</span></label>
                    <div style="position:relative;">
                        <input type="number" step="0.001" name="qty" id="qty" class="form-control" style="font-weight:bold; color:var(--primary);" required>
                        <span id="unit_display" style="position:absolute; right:15px; top:15px; color:#94a3b8; font-weight:bold;">-</span>
                    </div>
                </div>
                <div class="form-group" style="flex:1; min-width:200px;">
                    <label class="form-label">3. Lot No. <small style="color:#94a3b8;">(ถ้ามี)</small></label>
                    <input type="text" name="lot_no" class="form-control" placeholder="เช่น LOT-2026...">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">4. สาเหตุที่ชำรุด / ของเสีย <span style="color:red;">*</span></label>
                <select name="reason" class="form-control" required style="font-weight:bold;">
                    <option value="">-- เลือกเหตุผล --</option>
                    <option value="มอด/แมลงลง">🐛 มอด / แมลงลง</option>
                    <option value="ความชื้น/เชื้อรา">💧 ความชื้น / เชื้อราขึ้น</option>
                    <option value="หนูกัดกระสอบฉีกขาด">🐁 หนูกัด / กระสอบฉีกขาด</option>
                    <option value="สินค้าหมดอายุ">⏳ สินค้าหมดอายุ</option>
                    <option value="เสียหายระหว่างผลิต/ขนส่ง">🚚 เสียหายระหว่างการผลิต / ขนส่ง</option>
                    <option value="อื่นๆ">📝 อื่นๆ (โปรดระบุในหมายเหตุ)</option>
                </select>
            </div>

            <button type="submit" name="submit_scrap" class="btn-submit">
                <i class="fa-solid fa-trash-can"></i> ยืนยันตัดสต็อกของเสีย
            </button>
        </form>
    </div>

    <div class="history-card">
        <h3 style="margin-top:0; color:#475569; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; font-size:20px;">
            <i class="fa-solid fa-clock-rotate-left"></i> ประวัติการตัดชำรุด (ล่าสุด)
        </h3>
        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th width="15%">วันที่/เวลา</th>
                        <th width="35%">สินค้า / Lot</th>
                        <th width="15%" style="text-align:right;">ปริมาณ</th>
                        <th width="35%">สาเหตุ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(mysqli_num_rows($res_history) > 0): ?>
                        <?php while($row = mysqli_fetch_assoc($res_history)): ?>
                            <tr>
                                <td>
                                    <span style="color:#64748b; font-weight:bold; font-size:14px;"><?= date('d/m/Y', strtotime($row['created_at'])) ?></span><br>
                                    <small style="color:#94a3b8;"><?= date('H:i', strtotime($row['created_at'])) ?></small>
                                </td>
                                <td>
                                    <strong style="color:var(--text-main); font-size:16px;"><?= htmlspecialchars($row['p_name']) ?></strong><br>
                                    <span style="background:#f1f5f9; padding:2px 8px; border-radius:4px; font-size:12px; color:#64748b; font-weight:bold;"><?= $row['lot_no'] ?></span>
                                </td>
                                <td align="right">
                                    <strong style="color:var(--primary); font-size:18px;">-<?= number_format($row['qty'],2) ?></strong> <small style="color:#94a3b8; font-weight:bold;"><?= $row['p_unit'] ?></small>
                                </td>
                                <td>
                                    <span style="color:#475569; font-weight:bold;"><i class="fa-solid fa-caret-right" style="color:#cbd5e1; margin-right:5px;"></i><?= htmlspecialchars($row['reason']) ?></span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="text-align:center; padding:40px; color:#94a3b8; font-size:16px;">ยังไม่มีประวัติการตัดชำรุด</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() { $('.select2').select2({ width: '100%' }); });

    function updateMaxQty() {
        let opt = $('#source_stock').find(':selected');
        let max = opt.data('max');
        let unit = opt.data('unit');
        
        if (opt.val() !== '') {
            $('#qty').attr('max', max);
            $('#qty').attr('placeholder', 'ระบุจำนวน (ไม่เกิน ' + max + ')');
            $('#unit_display').text(unit);
        } else {
            $('#qty').attr('placeholder', '');
            $('#unit_display').text('-');
        }
    }

    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('status') === 'success') {
        Swal.fire({ icon: 'success', title: 'บันทึกสำเร็จ!', text: 'ระบบตัดสต็อกของเสียเรียบร้อยแล้ว', timer: 2000, showConfirmButton: false })
        .then(() => { window.history.replaceState(null, null, window.location.pathname); });
    }
</script>