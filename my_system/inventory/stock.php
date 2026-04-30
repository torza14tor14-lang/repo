<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';
$fullname = $_SESSION['fullname'] ?? 'พนักงาน';

// 🚀 [สิทธิ์การใช้งาน] 
// ฝ่ายที่แก้ไขข้อมูลได้: ADMIN, MANAGER, แผนกคลังสินค้า
// ฝ่ายที่ "ดูได้อย่างเดียว": ฝ่ายขาย, ฝ่ายผลิต, QA, จัดซื้อ
$allow_edit = ($user_role === 'ADMIN' || $user_role === 'MANAGER' || strpos($user_dept, 'คลังสินค้า') !== false);

// ดึงรายชื่อคลังสินค้าทั้งหมดมาทำ Filter
$wh_list = mysqli_query($conn, "SELECT * FROM warehouses ORDER BY plant ASC, wh_code ASC");

// 🚀 กรองข้อมูลตาม Plant และ Warehouse
$filter_plant = $_GET['plant'] ?? '';
$filter_wh = $_GET['wh_id'] ?? '';

$where_clause = "1=1";
if ($filter_plant != '') $where_clause .= " AND w.plant = '$filter_plant'";
if ($filter_wh != '') $where_clause .= " AND sb.wh_id = '$filter_wh'";

// 🚀 SQL ดึงยอดสต็อกแยกตามคลัง (ตัด p_category ที่ทำให้เกิด Error ออก)
$sql_stock = "SELECT p.id as p_id, p.p_name, p.p_unit, 
                     w.wh_name, w.wh_code, w.plant, w.wh_type,
                     sb.qty as balance_qty, sb.wh_id
              FROM stock_balances sb
              JOIN products p ON sb.product_id = p.id
              JOIN warehouses w ON sb.wh_id = w.wh_id
              WHERE $where_clause
              ORDER BY w.plant ASC, p.p_name ASC";
$res_stock = mysqli_query($conn, $sql_stock);

include '../sidebar.php';
?>

<title>แผงควบคุมคลังสินค้าแยกโรงงาน | Top Feed Mills</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    :root { 
        --p1-color: #0ea5e9; --p2-color: #8b5cf6;
        --bg-color: #f1f5f9; --card-bg: #ffffff; --border-color: #e2e8f0;
        --text-main: #1e293b; --text-muted: #64748b;
    }
    body { font-family: 'Sarabun', sans-serif; background-color: var(--bg-color); }
    .content-padding { padding: 24px; max-width: 1600px; margin: auto; }
    
    /* Plant Tabs */
    .plant-tabs { display: flex; gap: 10px; margin-bottom: 20px; }
    .tab-btn { padding: 12px 24px; border-radius: 12px; font-weight: 800; cursor: pointer; border: 2px solid transparent; transition: 0.3s; background: #e2e8f0; color: #64748b; text-decoration: none;}
    .tab-p1.active { background: white; border-color: var(--p1-color); color: var(--p1-color); box-shadow: 0 4px 12px rgba(14, 165, 233, 0.2); }
    .tab-p2.active { background: white; border-color: var(--p2-color); color: var(--p2-color); box-shadow: 0 4px 12px rgba(139, 92, 246, 0.2); }
    
    .card-stock { background: var(--card-bg); border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid var(--border-color); overflow: hidden; }
    .card-header { padding: 20px 25px; background: #f8fafc; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }

    /* Table Custom */
    .table-responsive { width: 100%; overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f8fafc; color: var(--text-muted); font-size: 13px; text-transform: uppercase; font-weight: 700; padding: 16px 20px; text-align: left; border-bottom: 2px solid var(--border-color); }
    td { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 15px; }
    tr:hover td { background-color: #f8fafc; }

    .badge-wh { padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 800; }
    .type-Normal { background: #dcfce7; color: #166534; }
    .type-Hold { background: #fee2e2; color: #991b1b; }
    .type-Silo { background: #e0f2fe; color: #075985; }
    .type-Scrap { background: #fef3c7; color: #92400e; }
    .type-WIP { background: #f3e8ff; color: #6d28d9; }

    .btn-action { padding: 8px; border-radius: 8px; color: var(--text-muted); transition: 0.2s; text-decoration: none;}
    .btn-action:hover { background: #f1f5f9; color: var(--p1-color); }
    
    .view-only-tag { background: #fef3c7; color: #92400e; padding: 5px 12px; border-radius: 50px; font-size: 12px; font-weight: bold; margin-left: 10px; }
</style>

<div class="content-padding">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom: 25px;">
        <div>
            <h2 style="margin:0; color:var(--text-main); font-weight:800; font-size:26px;">
                <i class="fa-solid fa-boxes-stacked" style="color:var(--p1-color);"></i> แผงควบคุมคลังสินค้า (Inventory Dashboard)
                <?php if(!$allow_edit): ?><span class="view-only-tag"><i class="fa-solid fa-eye"></i> ดูข้อมูลเท่านั้น</span><?php endif; ?>
            </h2>
            <p style="color:var(--text-muted); margin-top:5px;">ตรวจสอบยอดคงเหลือแยกตามโรงงาน โกดัง และไซโล</p>
        </div>
        
        <?php if($allow_edit): ?>
        <div style="display:flex; gap:10px;">
            <a href="inventory_transfer.php" class="tab-btn" style="background:var(--p1-color); color:white;"><i class="fa-solid fa-truck-ramp-box"></i> โอนย้ายคลัง</a>
            <a href="add_product.php" class="tab-btn" style="background:#10b981; color:white;"><i class="fa-solid fa-plus"></i> เพิ่มสินค้าใหม่</a>
        </div>
        <?php endif; ?>
    </div>

    <div class="plant-tabs">
        <a href="stock.php" class="tab-btn <?= ($filter_plant == '') ? 'active' : '' ?>">ทั้งหมด</a>
        <a href="stock.php?plant=โรงงาน 1" class="tab-btn tab-p1 <?= ($filter_plant == 'โรงงาน 1') ? 'active' : '' ?>">🏗️ โรงงาน 1</a>
        <a href="stock.php?plant=โรงงาน 2" class="tab-btn tab-p2 <?= ($filter_plant == 'โรงงาน 2') ? 'active' : '' ?>">🏗️ โรงงาน 2</a>
    </div>

    <div class="card-stock">
        <div class="card-header">
            <form method="GET" style="display:flex; gap:10px; flex:1;">
                <input type="hidden" name="plant" value="<?= $filter_plant ?>">
                <select name="wh_id" class="tab-btn" style="padding:8px 15px; border:1px solid #ddd; font-size:14px;" onchange="this.form.submit()">
                    <option value="">-- เลือกดูรายคลัง/ไซโล --</option>
                    <?php 
                    if($wh_list) {
                        mysqli_data_seek($wh_list, 0);
                        while($wh = mysqli_fetch_assoc($wh_list)): 
                    ?>
                        <option value="<?= $wh['wh_id'] ?>" <?= ($filter_wh == $wh['wh_id']) ? 'selected' : '' ?>>
                            [<?= $wh['plant'] ?>] <?= $wh['wh_name'] ?>
                        </option>
                    <?php 
                        endwhile; 
                    }
                    ?>
                </select>
                <?php if($filter_wh != '' || $filter_plant != ''): ?>
                    <a href="stock.php" class="btn-action" style="display:flex; align-items:center;"><i class="fa-solid fa-xmark"></i> ล้างการกรอง</a>
                <?php endif; ?>
            </form>
            <div style="color:var(--text-muted); font-size:14px; font-weight:bold;">
                พบทั้งหมด <?= $res_stock ? mysqli_num_rows($res_stock) : 0 ?> รายการ
            </div>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th width="80">โรงงาน</th>
                        <th width="200">คลัง / ไซโล</th>
                        <th>ประเภท</th>
                        <th>ชื่อสินค้า / วัตถุดิบ</th>
                        <th style="text-align:right;">ยอดคงเหลือ</th>
                        <th width="50">หน่วย</th>
                        <?php if($allow_edit): ?><th width="100" style="text-align:center;">จัดการ</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if($res_stock && mysqli_num_rows($res_stock) > 0): ?>
                        <?php while($row = mysqli_fetch_assoc($res_stock)): ?>
                            <tr>
                                <td>
                                    <span style="font-weight:800; color: <?= ($row['plant'] == 'โรงงาน 1') ? 'var(--p1-color)' : 'var(--p2-color)' ?>;">
                                        <?= ($row['plant'] == 'โรงงาน 1') ? 'P1' : 'P2' ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color:var(--text-main);"><?= $row['wh_code'] ?></strong><br>
                                    <small style="color:var(--text-muted); font-size:12px;"><?= $row['wh_name'] ?></small>
                                </td>
                                <td><span class="badge-wh type-<?= $row['wh_type'] ?>"><?= $row['wh_type'] ?></span></td>
                                <td>
                                    <strong style="color:var(--text-main);"><?= htmlspecialchars($row['p_name']) ?></strong>
                                </td>
                                <td style="text-align:right;">
                                    <strong style="font-size:18px; color: <?= ($row['balance_qty'] <= 0) ? '#ef4444' : '#1e293b' ?>;">
                                        <?= number_format($row['balance_qty'], 3) ?>
                                    </strong>
                                </td>
                                <td><small style="color:var(--text-muted);"><?= $row['p_unit'] ?></small></td>
                                
                                <?php if($allow_edit): ?>
                                <td style="text-align:center;">
                                    <div style="display:flex; gap:5px; justify-content:center;">
                                        <a href="stock_adjust.php?p_id=<?= $row['p_id'] ?>&wh_id=<?= $row['wh_id'] ?>" class="btn-action" title="ปรับปรุงยอด"><i class="fa-solid fa-sliders"></i></a>
                                        <a href="history.php?p_id=<?= $row['p_id'] ?>&wh_id=<?= $row['wh_id'] ?>" class="btn-action" title="ดูประวัติการเคลื่อนไหว"><i class="fa-solid fa-clock-rotate-left"></i></a>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= $allow_edit ? '7' : '6' ?>" style="text-align:center; padding:100px; color:var(--text-muted);">
                                <i class="fa-solid fa-box-open fa-4x" style="margin-bottom:20px; opacity:0.2;"></i><br>
                                ไม่พบข้อมูลสต็อกในเงื่อนไขที่เลือก
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('msg') === 'transferred') {
        Swal.fire({ icon: 'success', title: 'โอนย้ายสินค้าสำเร็จ', timer: 2000, showConfirmButton: false });
    }
</script>