<?php
session_start();
include '../db.php';

// เปิดระบบแสดง Error (ช่วยให้เห็นปัญหาถ้ามีอะไรผิดพลาด)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';

// อนุญาตให้ คลัง, ซื้อ, ขาย, ผลิต, วางแผน, QA, บัญชี เข้ามาดูได้ (ช่างซ่อมเข้าไม่ได้)
$allowed_depts = [
    'แผนกคลังสินค้า 1', 'แผนกคลังสินค้า 2', 
    'ฝ่ายจัดซื้อ', 'ฝ่ายขาย', 
    'แผนกผลิต 1', 'แผนกผลิต 2', 'ผลิตอาหารสัตว์น้ำ', 'ฝ่ายงานวางแผน',
    'แผนก QA', 'แผนก QC', 'ฝ่ายวิชาการ',
    'ฝ่ายบัญชี', 'ฝ่ายการเงิน', 'ฝ่ายสินเชื่อ', 'บัญชี - ท็อปธุรกิจ'
];

if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER' && !in_array($user_dept, $allowed_depts)) { 
    echo "<script>alert('คุณไม่มีสิทธิ์เข้าถึงคลังสินค้า'); window.location='../index.php';</script>"; exit(); 
}

// ซ่อมแซมฐานข้อมูลเผื่อไว้
mysqli_query($conn, "ALTER TABLE `products` MODIFY `p_type` VARCHAR(50) NOT NULL");
mysqli_query($conn, "UPDATE `products` SET `p_type` = 'SPARE' WHERE `p_type` = '' OR `p_type` IS NULL");

// ตรวจสอบและซ่อมแซมตาราง inventory_lots (ถ้ายังไม่มี)
$check_lot_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'inventory_lots'");
if (mysqli_num_rows($check_lot_tbl) == 0) {
    mysqli_query($conn, "CREATE TABLE inventory_lots (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        product_id INT(11) NOT NULL,
        lot_no VARCHAR(100) NOT NULL,
        mfg_date DATE NOT NULL,
        exp_date DATE NOT NULL,
        qty DECIMAL(15,2) NOT NULL,
        status VARCHAR(50) DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}

// ระบบสร้าง Lot ให้อัตโนมัติสำหรับของเก่ายกมา
$q_fix = mysqli_query($conn, "SELECT id, p_qty, shelf_life_days FROM products WHERE p_type = 'PRODUCT' AND p_qty > 0");
if($q_fix){
    while($f = mysqli_fetch_assoc($q_fix)){
        $pid = $f['id'];
        $qty = $f['p_qty'];
        $check_lot = mysqli_query($conn, "SELECT id FROM inventory_lots WHERE product_id = $pid");
        if($check_lot && mysqli_num_rows($check_lot) == 0){
             $lot_no = "LOT-OPENING-" . $pid;
             $mfg = date('Y-m-d');
             $exp = '2099-12-31';
             mysqli_query($conn, "INSERT INTO inventory_lots (product_id, lot_no, mfg_date, exp_date, qty, status) 
                                 VALUES ($pid, '$lot_no', '$mfg', '$exp', $qty, 'Active')");
        }
    }
}

// สรุปตัวเลข Dashbord
$q_raw = mysqli_query($conn, "SELECT COUNT(*) as c FROM products WHERE p_type='RAW'");
$raw_count = $q_raw ? (mysqli_fetch_assoc($q_raw)['c'] ?? 0) : 0;

$q_fg = mysqli_query($conn, "SELECT COUNT(*) as c FROM products WHERE p_type='PRODUCT'");
$fg_count = $q_fg ? (mysqli_fetch_assoc($q_fg)['c'] ?? 0) : 0;

$q_spare = mysqli_query($conn, "SELECT COUNT(*) as c FROM products WHERE p_type='SPARE'");
$spare_count = $q_spare ? (mysqli_fetch_assoc($q_spare)['c'] ?? 0) : 0;

$q_alert = mysqli_query($conn, "SELECT COUNT(*) as c FROM products WHERE p_qty <= p_min");
$alert_count = $q_alert ? (mysqli_fetch_assoc($q_alert)['c'] ?? 0) : 0;

$qa_pending_count = 0;
$expire_count = 0;
if (mysqli_num_rows(mysqli_query($conn, "SHOW TABLES LIKE 'inventory_lots'")) > 0) {
    $q_qa = mysqli_query($conn, "SELECT COUNT(*) as c FROM inventory_lots WHERE status = 'Pending_QA' AND qty > 0");
    $qa_pending_count = $q_qa ? (mysqli_fetch_assoc($q_qa)['c'] ?? 0) : 0;

    $q_exp = mysqli_query($conn, "SELECT COUNT(*) as c FROM inventory_lots WHERE DATEDIFF(CURDATE(), mfg_date) >= 90 AND qty > 0");
    $expire_count = $q_exp ? (mysqli_fetch_assoc($q_exp)['c'] ?? 0) : 0;
}

include '../sidebar.php';
?>

<title>แผงควบคุมสต็อก | Top Feed Mills</title>
<style>
    .grid-cards { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin-bottom: 30px; }
    @media (max-width: 1400px) { .grid-cards { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 768px) { .grid-cards { grid-template-columns: 1fr; } }
    
    .stat-card { background: white; padding: 18px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: space-between; transition: 0.3s; border-left: 5px solid #4e73df; cursor: pointer; opacity: 0.7; }
    .stat-card.active { opacity: 1; transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.15); border-width: 0 0 0 8px; }
    .stat-card:hover { opacity: 1; }
    
    .stat-info h4 { margin: 0; color: #858796; font-size: 11px; text-transform: uppercase; font-weight: bold; }
    .stat-info h2 { margin: 5px 0 0 0; color: #3a3b45; font-size: 22px; }
    .stat-icon { font-size: 30px; color: #dddfeb; }
    
    .table-container { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-bottom: 30px; animation: fadeIn 0.4s ease; }
    .table-title { margin-top: 0; border-bottom: 2px solid #eaecf4; padding-bottom: 15px; margin-bottom: 20px; font-size: 18px; display: flex; align-items: center; gap: 10px;}
    
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; min-width: 1000px; }
    th { background: #f8f9fc; color: #4e73df; padding: 15px; text-align: left; font-size: 13px; border-bottom: 2px solid #eaecf4; white-space: nowrap; }
    td { padding: 15px; border-bottom: 1px solid #eaecf4; color: #5a5c69; font-size: 14px; vertical-align: middle; }
    
    .status-badge { padding: 5px 12px; border-radius: 50px; font-size: 11px; font-weight: bold; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
    .status-ok { background: #e3fdfd; color: #1cc88a; border: 1px solid #bcece0; }
    .status-low { background: #ffe5e5; color: #e74a3b; border: 1px solid #f5c6cb; }
    .status-pending { background: #fff4e5; color: #f6c23e; border: 1px solid #ffeeba; }
    
    .btn-toggle { background: #e3f2fd; color: #4e73df; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; transition: 0.3s; }
    .btn-toggle.open { background: #fceceb; color: #e74a3b; }
    .master-row { cursor: pointer; }
    .master-row:hover { background: #f8f9fc; }
    .nested-table th { background: #f1f3f9; padding: 10px; font-size: 12px; color: #4e73df; border-bottom: 1px solid #d1d3e2; }
    .nested-table td { padding: 10px; font-size: 13px; background: white; border-bottom: 1px solid #eaecf4; }

    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-bottom: 25px; margin-top: 0;"><i class="fa-solid fa-boxes-stacked" style="color: #4e73df;"></i> ระบบบริหารสต็อกและคุณภาพ (Inventory & QA Control)</h2>
    
    <div class="grid-cards">
        <div class="stat-card active" id="card-raw" style="border-left-color: #f6c23e;" onclick="showTab('raw')">
            <div class="stat-info"><h4>วัตถุดิบ (RAW)</h4><h2><?= number_format($raw_count); ?></h2></div>
            <div class="stat-icon" style="color: #f6c23e;"><i class="fa-solid fa-wheat-awn"></i></div>
        </div>
        <div class="stat-card" id="card-product" style="border-left-color: #1cc88a;" onclick="showTab('product')">
            <div class="stat-info"><h4>สินค้า (FG)</h4><h2><?= number_format($fg_count); ?></h2></div>
            <div class="stat-icon" style="color: #1cc88a;"><i class="fa-solid fa-box-open"></i></div>
        </div>
        <div class="stat-card" id="card-qa" style="border-left-color: #f6c23e;" onclick="showTab('product')">
            <div class="stat-info"><h4>กักกันตรวจสอบ (QC)</h4><h2><?= number_format($qa_pending_count); ?> <small style="font-size:10px;">LOT</small></h2></div>
            <div class="stat-icon" style="color: #f6c23e;"><i class="fa-solid fa-vial-circle-check"></i></div>
        </div>
        <div class="stat-card" id="card-spare" style="border-left-color: #36b9cc;" onclick="showTab('spare')">
            <div class="stat-info"><h4>อะไหล่</h4><h2><?= number_format($spare_count); ?></h2></div>
            <div class="stat-icon" style="color: #36b9cc;"><i class="fa-solid fa-toolbox"></i></div>
        </div>
        <div class="stat-card" id="card-alert" style="border-left-color: #e74a3b;" onclick="showTab('alert')">
            <div class="stat-info"><h4>ต่ำกว่ากำหนด</h4><h2><?= number_format($alert_count); ?></h2></div>
            <div class="stat-icon" style="color: #e74a3b;"><i class="fa-solid fa-triangle-exclamation"></i></div>
        </div>
        <div class="stat-card" id="card-expire" style="border-left-color: #e74a3b;" onclick="showTab('expire')">
            <div class="stat-info"><h4>ค้างสต็อกนาน</h4><h2><?= number_format($expire_count); ?> <small style="font-size:10px;">LOT</small></h2></div>
            <div class="stat-icon" style="color: #e74a3b;"><i class="fa-solid fa-calendar-xmark"></i></div>
        </div>
    </div>

    <div id="tab-raw" class="table-container">
        <h3 class="table-title" style="color: #f6c23e;"><i class="fa-solid fa-wheat-awn"></i> รายการวัตถุดิบ (Raw Material)</h3>
        <div class="table-responsive">
            <table>
                <tr><th>รหัส</th><th>ชื่อวัตถุดิบ</th><th>คงเหลือ</th><th>จุดสั่งซื้อ (Min)</th><th>สถานะ</th></tr>
                <?php
                $q_raw = mysqli_query($conn, "SELECT * FROM products WHERE p_type='RAW' ORDER BY p_name ASC");
                if($q_raw && mysqli_num_rows($q_raw) > 0){
                    while($r = mysqli_fetch_assoc($q_raw)) {
                        $is_low = $r['p_qty'] <= $r['p_min'];
                        echo "<tr>
                                <td><strong>{$r['p_code']}</strong></td>
                                <td>{$r['p_name']}</td>
                                <td><strong style='color:".($is_low?'#e74a3b':'#2c3e50')."'>".number_format($r['p_qty'], 2)." {$r['p_unit']}</strong></td>
                                <td>".number_format($r['p_min'], 2)."</td>
                                <td><span class='status-badge ".($is_low?'status-low':'status-ok')."'>".($is_low?'ต่ำกว่ากำหนด':'ปกติ')."</span></td>
                              </tr>";
                    }
                } else {
                    echo "<tr><td colspan='5' style='text-align:center;'>ไม่มีข้อมูลวัตถุดิบ</td></tr>";
                }
                ?>
            </table>
        </div>
    </div>

    <div id="tab-product" class="table-container" style="display:none;">
        <h3 class="table-title" style="color: #1cc88a;"><i class="fa-solid fa-box-open"></i> คลังสินค้าสำเร็จรูป (จัดกลุ่มตามผลิตภัณฑ์)</h3>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="width: 5%; text-align:center;">LOT</th>
                        <th style="width: 10%;">รหัสสินค้า</th>
                        <th style="width: 25%;">ชื่อสินค้า / สูตร</th>
                        <th style="width: 15%; background:#e8f5e9; color:#2e7d32;">✅ พร้อมขาย</th>
                        <th style="width: 15%; background:#fff3e0; color:#ef6c00;">⏳ รอตรวจ (QC)</th>
                        <th style="width: 10%; background:#ffebeb; color:#CD5C5C;">จุดต่ำสุด (Min)</th>
                        <th style="width: 15%; text-align:center;">สถานะรวม</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $sql_prod = "SELECT * FROM products WHERE p_type = 'PRODUCT' ORDER BY p_name ASC";
                $res_prod = mysqli_query($conn, $sql_prod);

                if($res_prod && mysqli_num_rows($res_prod) > 0){
                    while ($p = mysqli_fetch_assoc($res_prod)) {
                        $pid = $p['id'];
                        $unit = $p['p_unit'] ?: 'หน่วย';
                        
                        $ready_qty = $p['p_qty'];
                        
                        $q_pending = mysqli_query($conn, "SELECT SUM(qty) as s FROM inventory_lots WHERE product_id = $pid AND status = 'Pending_QA' AND qty > 0");
                        $pending_qty = $q_pending ? (mysqli_fetch_assoc($q_pending)['s'] ?? 0) : 0;

                        $res_lots = mysqli_query($conn, "SELECT * FROM inventory_lots WHERE product_id = $pid AND qty > 0 ORDER BY mfg_date ASC");
                        $lot_count = $res_lots ? mysqli_num_rows($res_lots) : 0;

                        $is_low = ($ready_qty <= $p['p_min']);
                        
                        // 🚀 เช็คว่ามีสินค้าค้างสต็อกนานในกลุ่มนี้หรือไม่
                        $q_dead = mysqli_query($conn, "SELECT COUNT(*) as c FROM inventory_lots WHERE product_id = $pid AND status = 'Active' AND DATEDIFF(CURDATE(), mfg_date) >= 90 AND qty > 0");
                        $has_dead_stock = $q_dead ? (mysqli_fetch_assoc($q_dead)['c'] > 0) : false;

                        // กำหนดสถานะของ Master Row
                        if ($ready_qty <= 0 && $pending_qty <= 0) {
                            $master_status = "<span class='status-badge' style='background:#f2f2f2; color:#888; border:1px solid #ccc;'><i class='fa-solid fa-box-open'></i> สินค้าหมด</span>";
                        } elseif ($has_dead_stock) {
                            $master_status = "<span class='status-badge status-low'><i class='fa-solid fa-triangle-exclamation'></i> มีค้างสต็อกนาน</span>";
                        } elseif ($is_low) {
                            $master_status = "<span class='status-badge status-low'><i class='fa-solid fa-arrow-down'></i> สต็อกต่ำ</span>";
                        } else {
                            $master_status = "<span class='status-badge status-ok'><i class='fa-solid fa-check'></i> ปกติ</span>";
                        }

                        echo "<tr class='master-row' onclick='toggleLot($pid)'>";
                        echo "<td style='text-align:center;'><button type='button' class='btn-toggle' id='btn-toggle-$pid'><i class='fa-solid fa-plus'></i></button></td>";
                        echo "<td><strong>{$p['p_code']}</strong></td>";
                        echo "<td><strong>{$p['p_name']}</strong> <br><small style='color:#888;'>มีทั้งหมด $lot_count ล็อต</small></td>";
                        echo "<td><strong style='font-size:16px; color:#2e7d32;'>".number_format($ready_qty, 2)."</strong> <small>$unit</small></td>";
                        echo "<td><strong style='font-size:16px; color:#ef6c00;'>".number_format($pending_qty, 2)."</strong> <small>$unit</small></td>";
                        echo "<td><strong style='font-size:16px; color:#FF0033;'>".number_format($p['p_min'], 2)."</strong> <small>$unit</small></td>";
                        echo "<td style='text-align:center;'>$master_status</td>";
                        echo "</tr>";

                        echo "<tr id='detail-row-$pid' style='display:none; background:#fafafa;'>";
                        echo "<td colspan='7' style='padding: 15px 30px;'>";
                        if ($lot_count > 0) {
                            echo "<table class='nested-table' style='width:100%; border:1px solid #eaecf4;'>";
                            // 🚀 แยกคอลัมน์ QA กับ อายุจัดเก็บ ออกจากกันให้ชัดเจน
                            echo "<tr>
                                    <th>เลขที่ LOT</th>
                                    <th>จำนวน</th>
                                    <th>วันที่จัดเก็บ</th>
                                    <th>อายุสะสม</th>
                                    <th>สถานะ QA</th>
                                    <th>สถานะอายุจัดเก็บ</th>
                                  </tr>";
                            
                            while ($l = mysqli_fetch_assoc($res_lots)) {
                                $is_pending = ($l['status'] == 'Pending_QA');
                                
                                $today = new DateTime(date('Y-m-d'));
                                $mfg = new DateTime($l['mfg_date']);
                                $age = ($mfg > $today) ? 0 : $mfg->diff($today)->days;
                                
                                // ป้าย QA
                                $qa_label = $is_pending 
                                    ? "<span class='status-badge status-pending'><i class='fa-solid fa-lock'></i> กักกัน (รอ QA)</span>" 
                                    : "<span class='status-badge status-ok'><i class='fa-solid fa-check'></i> ผ่าน (พร้อมขาย)</span>";

                                // 🚀 ป้ายอายุสินค้า
                                if ($age <= 30) {
                                    $age_color = "#1cc88a"; $age_text = "<i class='fa-solid fa-leaf'></i> สดใหม่";
                                } elseif ($age <= 90) {
                                    $age_color = "#f6c23e"; $age_text = "<i class='fa-solid fa-hourglass-half'></i> ทยอยขาย";
                                } else {
                                    $age_color = "#e74a3b"; $age_text = "<i class='fa-solid fa-triangle-exclamation'></i> ค้างสต็อกนาน";
                                }
                                $age_label = "<span class='status-badge' style='background:$age_color; color:white;'>$age_text</span>";

                                echo "<tr>
                                        <td><code style='font-weight:bold; color:#4e73df; font-size:14px;'>{$l['lot_no']}</code></td>
                                        <td><strong>".number_format($l['qty'], 2)." $unit</strong></td>
                                        <td>".date('d/m/Y', strtotime($l['mfg_date']))."</td>
                                        <td>เก็บมาแล้ว $age วัน</td>
                                        <td>$qa_label</td>
                                        <td>$age_label</td>
                                      </tr>";
                            }
                            echo "</table>";
                        } else { echo "<p style='text-align:center; color:#999; margin:0;'>ไม่มีสินค้าค้างในคลัง</p>"; }
                        echo "</td></tr>";
                    }
                } else {
                    echo "<tr><td colspan='7' style='text-align:center;'>ไม่มีข้อมูลสินค้าสำเร็จรูป</td></tr>";
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="tab-spare" class="table-container" style="display:none;">
        <h3 class="table-title" style="color: #36b9cc;"><i class="fa-solid fa-toolbox"></i> คลังอะไหล่ (Spare Parts)</h3>
        <div class="table-responsive">
            <table>
                <tr><th>รหัส</th><th>ชื่ออะไหล่</th><th>คงเหลือ</th><th>จุดสั่งซื้อ</th><th>สถานะ</th></tr>
                <?php
                $spares = mysqli_query($conn, "SELECT * FROM products WHERE p_type='SPARE' ORDER BY p_name ASC");
                if($spares && mysqli_num_rows($spares) > 0){
                    while($s = mysqli_fetch_assoc($spares)) {
                        $is_low = $s['p_qty'] <= $s['p_min'];
                        echo "<tr>
                                <td><strong>{$s['p_code']}</strong></td>
                                <td>{$s['p_name']}</td>
                                <td><strong style='color:".($is_low?'#e74a3b':'#36b9cc')."'>".number_format($s['p_qty'], 2)." {$s['p_unit']}</strong></td>
                                <td>".number_format($s['p_min'], 2)."</td>
                                <td><span class='status-badge ".($is_low?'status-low':'status-ok')."'>".($is_low?'สั่งด่วน':'ปกติ')."</span></td>
                              </tr>";
                    }
                } else { echo "<tr><td colspan='5' style='text-align:center;'>ไม่มีข้อมูลอะไหล่</td></tr>"; }
                ?>
            </table>
        </div>
    </div>

    <div id="tab-alert" class="table-container" style="display:none; border-top: 4px solid #e74a3b;">
        <h3 class="table-title" style="color: #e74a3b;"><i class="fa-solid fa-triangle-exclamation"></i> แจ้งเตือน: สินค้าต่ำกว่าจุดสั่งซื้อ/ผลิต (Min Stock)</h3>
        <div class="table-responsive">
            <table>
                <tr><th>รหัส</th><th>ชื่อรายการ</th><th>ประเภท</th><th>คงเหลือ</th><th>จุดต่ำสุด (Min)</th></tr>
                <?php
                $alerts = mysqli_query($conn, "SELECT * FROM products WHERE p_qty <= p_min ORDER BY p_type ASC, p_qty ASC");
                if($alerts && mysqli_num_rows($alerts) > 0){
                    while($a = mysqli_fetch_assoc($alerts)) {
                        $type_label = ($a['p_type'] == 'RAW') ? "🌾 วัตถุดิบ" : (($a['p_type'] == 'SPARE') ? "🛠️ อะไหล่" : "📦 สินค้า FG");
                        echo "<tr>
                                <td><strong>{$a['p_code']}</strong></td>
                                <td>{$a['p_name']}</td>
                                <td>$type_label</td>
                                <td><strong style='color:#e74a3b;'>".number_format($a['p_qty'], 2)." {$a['p_unit']}</strong></td>
                                <td>".number_format($a['p_min'], 2)."</td>
                              </tr>";
                    }
                } else { echo "<tr><td colspan='5' style='text-align:center;'>ไม่มีรายการต่ำกว่ากำหนด</td></tr>"; }
                ?>
            </table>
        </div>
    </div>

    <div id="tab-expire" class="table-container" style="display:none; border-top: 4px solid #f6c23e;">
        <h3 class="table-title" style="color: #f6c23e;"><i class="fa-solid fa-calendar-xmark"></i> รายการ LOT ที่ค้างสต็อกเกิน 90 วัน</h3>
        <div class="table-responsive">
            <table>
                <tr><th>LOT No.</th><th>ชื่อสินค้า</th><th>วันที่จัดเก็บ</th><th>อายุจัดเก็บสะสม</th><th>จำนวน</th></tr>
                <?php
                if (mysqli_num_rows($check_lot_tbl) > 0) {
                    $q_exp_list = mysqli_query($conn, "SELECT l.*, p.p_name, p.p_unit FROM inventory_lots l JOIN products p ON l.product_id = p.id WHERE l.qty > 0 AND DATEDIFF(CURDATE(), l.mfg_date) >= 90 ORDER BY l.mfg_date ASC");
                    if($q_exp_list && mysqli_num_rows($q_exp_list) > 0){
                        while($e = mysqli_fetch_assoc($q_exp_list)) {
                            $today = new DateTime(date('Y-m-d'));
                            $mfg = new DateTime($e['mfg_date']);
                            $age_days = ($mfg > $today) ? 0 : $mfg->diff($today)->days;
                            echo "<tr>
                                    <td><strong>{$e['lot_no']}</strong></td>
                                    <td>{$e['p_name']}</td>
                                    <td>".date('d/m/Y', strtotime($e['mfg_date']))."</td>
                                    <td><strong style='color:#e74a3b;'>$age_days วัน</strong></td>
                                    <td>".number_format($e['qty'], 2)." {$e['p_unit']}</td>
                                  </tr>";
                        }
                    } else { echo "<tr><td colspan='5' style='text-align:center;'>ไม่มีสินค้าค้างสต็อกนานเกิน 90 วัน</td></tr>"; }
                }
                ?>
            </table>
        </div>
    </div>

</div>

<script>
    function toggleLot(pid) {
        let row = document.getElementById('detail-row-' + pid);
        let btn = document.getElementById('btn-toggle-' + pid);
        if (row.style.display === 'none') {
            row.style.display = 'table-row';
            btn.innerHTML = '<i class="fa-solid fa-minus"></i>';
            btn.classList.add('open');
        } else {
            row.style.display = 'none';
            btn.innerHTML = '<i class="fa-solid fa-plus"></i>';
            btn.classList.remove('open');
        }
    }
    
    function showTab(tabName) {
        const tabs = ['raw', 'product', 'spare', 'alert', 'expire'];
        tabs.forEach(t => { 
            document.getElementById('tab-'+t).style.display = 'none'; 
            document.getElementById('card-'+t).classList.remove('active');
        });
        document.getElementById('tab-'+tabName).style.display = 'block';
        document.getElementById('card-'+tabName).classList.add('active');
    }
</script>