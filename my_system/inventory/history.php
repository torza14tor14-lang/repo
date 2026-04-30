<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';

// 🚀 กรองข้อมูล
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days')); // ค่าเริ่มต้นดูย้อนหลัง 7 วัน
$date_to = $_GET['date_to'] ?? date('Y-m-d');

$where_clause = "1=1";
if ($search != '') {
    $s = mysqli_real_escape_string($conn, $search);
    $where_clause .= " AND (p.p_name LIKE '%$s%' OR sl.reference LIKE '%$s%' OR sl.action_by LIKE '%$s%')";
}
if ($type_filter != '') {
    $where_clause .= " AND sl.type = '$type_filter'";
}
if ($date_from != '') {
    $where_clause .= " AND DATE(sl.created_at) >= '$date_from'";
}
if ($date_to != '') {
    $where_clause .= " AND DATE(sl.created_at) <= '$date_to'";
}

// 🚀 ดึงประวัติความเคลื่อนไหว พร้อม Join ชื่อคลังต้นทางและปลายทาง (Multi-Warehouse)
$sql = "SELECT sl.*, p.p_name, p.p_unit,
               w_from.wh_name as from_wh_name, w_from.plant as from_plant,
               w_to.wh_name as to_wh_name, w_to.plant as to_plant
        FROM stock_log sl
        JOIN products p ON sl.product_id = p.id
        LEFT JOIN warehouses w_from ON sl.from_wh_id = w_from.wh_id
        LEFT JOIN warehouses w_to ON sl.to_wh_id = w_to.wh_id
        WHERE $where_clause
        ORDER BY sl.created_at DESC LIMIT 500";
$result = mysqli_query($conn, $sql);

include '../sidebar.php';
?>

<title>ประวัติความเคลื่อนไหวสต็อก | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    :root { --bg: #f8fafc; --card: #ffffff; --text-main: #1e293b; --text-muted: #64748b; --border: #e2e8f0; }
    body { font-family: 'Sarabun', sans-serif; background: var(--bg); color: var(--text-main); }
    .content-padding { padding: 30px; max-width: 1400px; margin: auto; }
    
    .history-card { background: var(--card); border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid var(--border); overflow: hidden; }
    .filter-box { background: #f1f5f9; padding: 20px 25px; border-bottom: 1px solid var(--border); display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
    
    .form-group { display: flex; flex-direction: column; flex: 1; min-width: 200px; }
    .form-label { font-size: 13px; font-weight: 800; color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase; }
    .form-control { padding: 10px 15px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: 'Sarabun'; font-size: 14px; }
    
    .btn-search { background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 5px; transition: 0.2s;}
    .btn-search:hover { background: #2563eb; }
    .btn-reset { background: #e2e8f0; color: #475569; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; display: flex; align-items: center; gap: 5px;}

    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; padding: 15px 20px; background: #f8fafc; font-size: 13px; font-weight: 800; color: var(--text-muted); border-bottom: 2px solid var(--border); white-space: nowrap; }
    td { padding: 15px 20px; border-bottom: 1px solid var(--border); font-size: 14.5px; vertical-align: top; }
    tr:hover { background: #f8fafc; }

    .badge-type { padding: 5px 12px; border-radius: 50px; font-size: 12px; font-weight: 800; display: inline-flex; align-items: center; gap: 5px;}
    .type-IN { background: #dcfce7; color: #166534; }
    .type-OUT { background: #fee2e2; color: #991b1b; }
    .type-TRANSFER { background: #e0f2fe; color: #075985; }
    .type-ADJUST { background: #fef3c7; color: #92400e; }

    .wh-box { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: bold; color: #475569; display: inline-block;}
</style>

<div class="content-padding">
    <h2 style="margin:0 0 5px 0; font-weight:800; font-size:24px;"><i class="fa-solid fa-clock-rotate-left" style="color:#3b82f6;"></i> ประวัติความเคลื่อนไหวสต็อก (Stock Log)</h2>
    <p style="color:var(--text-muted); margin-bottom:25px;">ตรวจสอบประวัติการรับเข้า, เบิกออก และการโอนย้ายระหว่างไซโล/โกดัง</p>

    <div class="history-card">
        <form method="GET" class="filter-box">
            <div class="form-group">
                <label class="form-label">ค้นหาสินค้า / ผู้ทำรายการ</label>
                <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="พิมพ์คำค้นหา...">
            </div>
            <div class="form-group" style="min-width: 150px; flex:0.5;">
                <label class="form-label">ประเภทรายการ</label>
                <select name="type" class="form-control">
                    <option value="">-- ทั้งหมด --</option>
                    <option value="IN" <?= ($type_filter=='IN')?'selected':'' ?>>IN (รับเข้า)</option>
                    <option value="OUT" <?= ($type_filter=='OUT')?'selected':'' ?>>OUT (เบิกออก)</option>
                    <option value="TRANSFER" <?= ($type_filter=='TRANSFER')?'selected':'' ?>>TRANSFER (โอนย้าย)</option>
                    <option value="ADJUST" <?= ($type_filter=='ADJUST')?'selected':'' ?>>ADJUST (ปรับปรุงยอด)</option>
                </select>
            </div>
            <div class="form-group" style="min-width: 130px; flex:0.5;">
                <label class="form-label">ตั้งแต่วันที่</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="form-group" style="min-width: 130px; flex:0.5;">
                <label class="form-label">ถึงวันที่</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div style="display:flex; gap:10px;">
                <button type="submit" class="btn-search"><i class="fa-solid fa-magnifying-glass"></i> ค้นหา</button>
                <a href="history.php" class="btn-reset"><i class="fa-solid fa-rotate-right"></i></a>
            </div>
        </form>

        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>วัน-เวลา</th>
                        <th>ประเภท</th>
                        <th>สินค้า / วัตถุดิบ</th>
                        <th style="text-align:right;">ปริมาณ</th>
                        <th>ต้นทาง (From) <i class="fa-solid fa-arrow-right" style="color:#cbd5e1; margin:0 5px;"></i> ปลายทาง (To)</th>
                        <th>ผู้ทำรายการ</th>
                        <th>หมายเหตุ / อ้างอิง</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(mysqli_num_rows($result) > 0): ?>
                        <?php while($row = mysqli_fetch_assoc($result)): ?>
                            <tr>
                                <td style="color:var(--text-muted); font-size:13px;">
                                    <strong style="color:var(--text-main);"><?= date('d/m/Y', strtotime($row['created_at'])) ?></strong><br>
                                    <i class="fa-regular fa-clock"></i> <?= date('H:i:s', strtotime($row['created_at'])) ?>
                                </td>
                                <td>
                                    <?php
                                        $icon = 'fa-circle-dot';
                                        if($row['type'] == 'IN') $icon = 'fa-arrow-right-to-bracket';
                                        if($row['type'] == 'OUT') $icon = 'fa-arrow-right-from-bracket';
                                        if($row['type'] == 'TRANSFER') $icon = 'fa-right-left';
                                    ?>
                                    <span class="badge-type type-<?= $row['type'] ?>"><i class="fa-solid <?= $icon ?>"></i> <?= $row['type'] ?></span>
                                </td>
                                <td><strong style="color:var(--text-main);"><?= htmlspecialchars($row['p_name']) ?></strong></td>
                                <td style="text-align:right;">
                                    <strong style="font-size:16px; color: <?= ($row['type'] == 'OUT') ? '#ef4444' : '#10b981' ?>;">
                                        <?= ($row['type'] == 'OUT') ? '-' : '+' ?><?= number_format($row['qty'], 2) ?>
                                    </strong> 
                                    <span style="font-size:12px; color:var(--text-muted);"><?= $row['p_unit'] ?></span>
                                </td>
                                <td>
                                    <?php if ($row['type'] == 'IN' && $row['to_wh_name']): ?>
                                        <span class="wh-box"><i class="fa-solid fa-download" style="color:#10b981;"></i> [<?= $row['to_plant'] ?>] <?= $row['to_wh_name'] ?></span>
                                    <?php elseif ($row['type'] == 'OUT' && $row['from_wh_name']): ?>
                                        <span class="wh-box"><i class="fa-solid fa-upload" style="color:#ef4444;"></i> [<?= $row['from_plant'] ?>] <?= $row['from_wh_name'] ?></span>
                                    <?php elseif ($row['type'] == 'TRANSFER'): ?>
                                        <div style="display:flex; align-items:center; gap:5px; flex-wrap:wrap;">
                                            <span class="wh-box" style="background:#fef2f2; border-color:#fecaca;">[<?= $row['from_plant'] ?>] <?= $row['from_wh_name'] ?></span>
                                            <i class="fa-solid fa-arrow-right-long" style="color:#cbd5e1;"></i>
                                            <span class="wh-box" style="background:#f0fdf4; border-color:#bbf7d0;">[<?= $row['to_plant'] ?>] <?= $row['to_wh_name'] ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#cbd5e1;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><i class="fa-solid fa-user-circle" style="color:#cbd5e1;"></i> <?= htmlspecialchars($row['action_by']) ?></td>
                                <td style="color:#475569; font-size:13px; line-height:1.4; max-width: 250px;"><?= htmlspecialchars($row['reference']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:50px; color:var(--text-muted);">
                                <i class="fa-solid fa-magnifying-glass fa-3x" style="opacity:0.2; margin-bottom:15px;"></i><br>
                                ไม่พบประวัติความเคลื่อนไหวในช่วงเวลานี้
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>