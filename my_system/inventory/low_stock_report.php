<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    header("Location: ../login.php"); exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';

// 🚀 รับค่าการกรอง
$filter_type = $_GET['p_type'] ?? '';
$filter_plant = $_GET['plant'] ?? ''; // รับค่า โรงงาน 1 หรือ 2

$where_clause = "IFNULL(sb.total_qty, 0) <= p.p_min AND p.p_min > 0";
if ($filter_type != '') {
    $where_clause .= " AND p.p_type = '$filter_type'";
}

// 🚀 เงื่อนไขสำหรับคำนวณสต็อกแยกระดับโรงงาน
$sb_join_condition = "";
if ($filter_plant != '') {
    $sb_join_condition = " JOIN warehouses w ON sb.wh_id = w.wh_id WHERE w.plant = '$filter_plant' ";
}

// SQL สรุปยอดรวมเทียบกับค่า Min Stock
$sql = "SELECT p.id, p.p_name, p.p_type, p.p_unit, p.p_min, 
               IFNULL(sb.total_qty, 0) as total_on_hand
        FROM products p
        LEFT JOIN (
            SELECT sb.product_id, SUM(sb.qty) as total_qty 
            FROM stock_balances sb
            $sb_join_condition
            GROUP BY sb.product_id
        ) sb ON p.id = sb.product_id
        WHERE $where_clause
        ORDER BY (p.p_min - IFNULL(sb.total_qty, 0)) DESC";

$result = mysqli_query($conn, $sql);

include '../sidebar.php';
?>

<title>รายงานสินค้าใกล้หมด | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    :root { --danger: #ef4444; --warning: #f59e0b; --bg: #f8fafc; }
    body { font-family: 'Sarabun', sans-serif; background-color: var(--bg); }
    
    .content-padding { padding: 30px; width: 100%; box-sizing: border-box; }
    .report-card { background: white; border-radius: 16px; box-shadow: 0 5px 20px rgba(0,0,0,0.03); padding: 30px; border-top: 5px solid var(--danger); width: 100%; box-sizing: border-box; }
    
    .filter-section { background: #f8fafc; padding: 20px; border-radius: 12px; margin-bottom: 25px; border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;}
    
    .plant-tabs { display: flex; gap: 10px; }
    .plant-btn { padding: 10px 20px; border-radius: 50px; text-decoration: none; font-weight: bold; font-size: 14px; border: 2px solid #cbd5e1; color: #64748b; transition: 0.2s; background: white; display: inline-flex; align-items: center; gap: 6px;}
    .plant-btn:hover { border-color: #94a3b8; color: #334155; }
    .plant-btn.active { background: #3b82f6; border-color: #3b82f6; color: white; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3); }

    .form-control { padding: 10px 15px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-family: 'Sarabun'; font-size: 14px; min-width: 250px; outline: none; }
    .form-control:focus { border-color: #3b82f6; }

    table { width: 100%; border-collapse: collapse; min-width: 1000px; }
    .table-responsive { width: 100%; overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px;}
    th { text-align: left; padding: 15px 20px; background: #f1f5f9; color: #475569; font-size: 14px; border-bottom: 2px solid #e2e8f0; white-space: nowrap;}
    td { padding: 15px 20px; border-bottom: 1px solid #f1f5f9; font-size: 15px; vertical-align: middle; }
    tr:hover { background: #f8fafc; }
    
    .status-critical { color: var(--danger); font-weight: 900; font-size:18px; }
    
    .btn-buy { background: #10b981; color: white; padding: 10px 16px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 13px; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 5px; border:none; cursor:pointer; font-family:'Sarabun'; width: 100%; box-sizing: border-box;}
    .btn-buy:hover { background: #059669; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3); }

    .btn-prod { background: #8b5cf6; color: white; padding: 10px 16px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 13px; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 5px; border:none; cursor:pointer; font-family:'Sarabun'; width: 100%; box-sizing: border-box;}
    .btn-prod:hover { background: #7c3aed; box-shadow: 0 4px 10px rgba(139, 92, 246, 0.3); }

    .btn-wh-view { background: white; color: #0ea5e9; border: 1.5px solid #7dd3fc; padding: 8px 15px; border-radius: 50px; font-size: 13px; font-weight: 800; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-family: 'Sarabun'; width: 100%;}
    .btn-wh-view:hover { background: #e0f2fe; color: #0284c7; border-color: #38bdf8; }
    
    .out-of-stock { background: #fef2f2; color: #ef4444; border: 1.5px solid #fecaca; padding: 8px 15px; border-radius: 50px; font-size: 13px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; width: 100%; box-sizing: border-box;}
    
    .progress-bar-bg { width: 100%; background: #e2e8f0; height: 8px; border-radius: 10px; margin-top: 8px; overflow: hidden; }
    .progress-fill { height: 100%; background: var(--danger); border-radius: 10px;}
</style>

<div class="content-padding">
    <div class="report-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 25px; flex-wrap:wrap; gap:15px;">
            <div>
                <h2 style="margin:0; color:#1e293b;"><i class="fa-solid fa-triangle-exclamation" style="color:var(--danger);"></i> รายการวัตถุดิบและสินค้าที่ต้องสั่งซื้อ/สั่งผลิตด่วน</h2>
                <p style="color:#64748b; margin-top:5px; margin-bottom:0;">แสดงรายการที่ยอดคงเหลือ ต่ำกว่าจุดสั่งซื้อ/สั่งผลิตขั้นต่ำ (Min Stock)</p>
            </div>
        </div>

        <div class="filter-section">
            <div class="plant-tabs">
                <a href="?p_type=<?= $filter_type ?>&plant=" class="plant-btn <?= ($filter_plant == '') ? 'active' : '' ?>">
                    <i class="fa-solid fa-layer-group"></i> รวมทุกโรงงาน
                </a>
                <a href="?p_type=<?= $filter_type ?>&plant=โรงงาน 1" class="plant-btn <?= ($filter_plant == 'โรงงาน 1') ? 'active' : '' ?>">
                    <i class="fa-solid fa-industry"></i> เฉพาะ โรงงาน 1
                </a>
                <a href="?p_type=<?= $filter_type ?>&plant=โรงงาน 2" class="plant-btn <?= ($filter_plant == 'โรงงาน 2') ? 'active' : '' ?>">
                    <i class="fa-solid fa-industry"></i> เฉพาะ โรงงาน 2
                </a>
            </div>

            <form method="GET" style="display:flex; align-items:center; gap:10px;">
                <input type="hidden" name="plant" value="<?= htmlspecialchars($filter_plant) ?>">
                <strong style="color:#475569;"><i class="fa-solid fa-filter"></i> กรองประเภท:</strong>
                <select name="p_type" class="form-control" onchange="this.form.submit()">
                    <option value="">-- แสดงทุกประเภท --</option>
                    <option value="RAW" <?= ($filter_type == 'RAW') ? 'selected' : '' ?>>🌾 วัตถุดิบ (Raw Material)</option>
                    <option value="PRODUCT" <?= ($filter_type == 'PRODUCT') ? 'selected' : '' ?>>📦 สินค้าสำเร็จรูป (FG)</option>
                    <option value="SPARE" <?= ($filter_type == 'SPARE') ? 'selected' : '' ?>>🛠️ อะไหล่/วัสดุสิ้นเปลือง</option>
                </select>
            </form>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="width:30%;">รายการสินค้า / วัตถุดิบ</th>
                        <th style="width:15%; text-align:right;">ยอดคงเหลือ</th>
                        <th style="width:15%; text-align:right;">จุดวิกฤต (Min)</th>
                        <th style="width:20%; text-align:center;">รายละเอียด (รายคลัง)</th>
                        <th style="width:20%; text-align:center;">ดำเนินการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(mysqli_num_rows($result) > 0): ?>
                        <?php while($row = mysqli_fetch_assoc($result)): 
                            $shortage = $row['p_min'] - $row['total_on_hand'];
                            $percent = ($row['p_min'] > 0) ? ($row['total_on_hand'] / $row['p_min']) * 100 : 0;
                            $p_id = $row['id'];
                            
                            $wh_data = [];
                            $q_wh = mysqli_query($conn, "SELECT sb.qty, w.wh_name, w.plant 
                                                         FROM stock_balances sb 
                                                         JOIN warehouses w ON sb.wh_id = w.wh_id 
                                                         WHERE sb.product_id = $p_id AND sb.qty > 0 
                                                         ORDER BY w.plant ASC, w.wh_name ASC");
                            if(mysqli_num_rows($q_wh) > 0){
                                while($wh = mysqli_fetch_assoc($q_wh)){
                                    $wh_data[] = [
                                        'plant' => $wh['plant'],
                                        'name' => $wh['wh_name'],
                                        'qty' => number_format($wh['qty'], 2)
                                    ];
                                }
                            }
                            $json_wh = json_encode($wh_data);
                        ?>
                            <tr>
                                <td>
                                    <strong style="color:#334155; font-size:15.5px;"><?= htmlspecialchars($row['p_name']) ?></strong><br>
                                    <span style="font-size:12px; color:#94a3b8; font-weight:bold;"><?= $row['p_type'] ?></span>
                                    <div class="progress-bar-bg">
                                        <div class="progress-fill" style="width: <?= min($percent, 100) ?>%; background: <?= ($percent < 30) ? '#ef4444' : '#f59e0b' ?>;"></div>
                                    </div>
                                </td>
                                <td align="right">
                                    <span class="status-critical"><?= number_format($row['total_on_hand'], 2) ?></span> <span style="color:#64748b; font-size:13px;"><?= $row['p_unit'] ?></span><br>
                                    <small style="color:var(--danger); font-weight:bold;">ขาดสต็อก -<?= number_format($shortage, 2) ?></small>
                                </td>
                                <td align="right" style="color:#64748b; font-weight:bold; font-size:15px;">
                                    <?= number_format($row['p_min'], 2) ?>
                                </td>
                                
                                <td align="center">
                                    <?php if(count($wh_data) > 0): ?>
                                        <button type="button" class="btn-wh-view" onclick='showWhDetails(<?= htmlspecialchars(json_encode($row['p_name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($json_wh, ENT_QUOTES, 'UTF-8') ?>, "<?= $row['p_unit'] ?>")'>
                                            <i class="fa-solid fa-boxes-stacked"></i> เช็คยอดทุกคลัง
                                        </button>
                                    <?php else: ?>
                                        <span class="out-of-stock"><i class="fa-solid fa-ban"></i> หมดเกลี้ยงทุกคลัง!</span>
                                    <?php endif; ?>
                                </td>

                                <td align="center">
                                    <?php if($row['p_type'] == 'PRODUCT'): ?>
                                        <?php if(in_array($user_dept, ['ฝ่ายผลิต', 'แผนกผลิต 1', 'แผนกผลิต 2', 'ผลิตอาหารสัตว์น้ำ', 'แผนกคอมพิวเตอร์']) || $user_role == 'ADMIN' || $user_role == 'MANAGER'): ?>
                                            <a href="../production/production_orders.php" class="btn-prod">
                                                <i class="fa-solid fa-gears"></i> สั่งผลิต
                                            </a>
                                        <?php else: ?>
                                            <span style="font-size:13px; color:#94a3b8; font-weight:bold;">รอฝ่ายผลิตดำเนินการ</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if(in_array($user_dept, ['ฝ่ายจัดซื้อ', 'แผนกคอมพิวเตอร์']) || $user_role == 'ADMIN' || $user_role == 'MANAGER'): ?>
                                            <a href="../purchase/create_po.php?p_id=<?= $row['id'] ?>&qty=<?= $shortage ?>" class="btn-buy">
                                                <i class="fa-solid fa-cart-plus"></i> ออกใบสั่งซื้อ
                                            </a>
                                        <?php else: ?>
                                            <span style="font-size:13px; color:#94a3b8; font-weight:bold;">รอฝ่ายจัดซื้อสั่งของ</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding:80px; color:#94a3b8;">
                                <i class="fa-solid fa-circle-check fa-4x" style="color:#10b981; margin-bottom:20px; opacity:0.3;"></i><br>
                                <h3 style="margin:0;">ยอดสต็อกอยู่ในระดับปลอดภัย</h3>
                                <p style="margin-top:5px; font-size:14px;">(ไม่มีรายการใดต่ำกว่าค่า Min Stock ในเงื่อนไขที่คุณเลือก)</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function showWhDetails(p_name, wh_data, unit) {
        let htmlContent = '<div style="text-align:left; margin-top:15px; max-height:350px; overflow-y:auto; padding-right:10px;">';
        
        let currentPlant = '';
        wh_data.forEach(w => {
            if(w.plant !== currentPlant) {
                htmlContent += `<div style="background:#f1f5f9; padding:8px 15px; margin-top:10px; border-radius:8px; font-weight:bold; color:#3b82f6; font-size:13px;"><i class="fa-solid fa-industry"></i> ${w.plant}</div>`;
                currentPlant = w.plant;
            }

            htmlContent += `
                <div style="padding:12px 15px; border-bottom:1px dashed #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                    <span style="color:#475569; font-size:14px; font-weight:bold;">
                        <i class="fa-solid fa-warehouse" style="color:#cbd5e1; margin-right:8px;"></i>
                        ${w.name}
                    </span> 
                    <span style="color:#0ea5e9; font-weight:900; font-size:16px;">
                        ${w.qty} <small style="font-size:12px; color:#94a3b8;">${unit}</small>
                    </span>
                </div>`;
        });
        htmlContent += '</div>';

        Swal.fire({
            title: `<div style="font-size:20px; color:#1e293b; border-bottom:2px solid #f1f5f9; padding-bottom:15px; text-align:left;"><i class="fa-solid fa-box-open" style="color:#f59e0b;"></i> ${p_name}</div>`,
            html: htmlContent,
            width: 500,
            confirmButtonText: 'ปิดหน้าต่าง',
            confirmButtonColor: '#64748b',
        });
    }
</script>