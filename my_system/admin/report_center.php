<?php
session_start();
include '../db.php';

// ล็อกอินและสิทธิ์ (เฉพาะผู้บริหาร)
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$user_role = $_SESSION['role'] ?? '';
$user_dept = $_SESSION['dept'] ?? '';

if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER') { 
    echo "<script>alert('เฉพาะผู้บริหารเท่านั้นที่สามารถเข้าดู Dashboard ได้'); window.location='../index.php';</script>"; 
    exit(); 
}

// ==========================================
// 🚀 1. ประมวลผลข้อมูล KPI (Key Performance Indicators)
// ==========================================

// 1.1 รายรับเดือนนี้ (ยอดขาย)
$q_sales = mysqli_query($conn, "SELECT SUM(total_amount) as val FROM sales_orders WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())");
$sales_month = mysqli_fetch_assoc($q_sales)['val'] ?? 0;

// 1.2 รายจ่ายเดือนนี้ (ยอดโอนเงินจ่ายซัพพลายเออร์ AP Paid)
$q_exp = mysqli_query($conn, "SELECT SUM(pi.quantity * pi.unit_price) as val 
                              FROM purchase_orders po 
                              JOIN po_items pi ON po.po_id = pi.po_id 
                              WHERE po.payment_status = 'Paid' 
                              AND MONTH(po.payment_date) = MONTH(CURDATE()) 
                              AND YEAR(po.payment_date) = YEAR(CURDATE())");
$expense_month = mysqli_fetch_assoc($q_exp)['val'] ?? 0;

// 1.3 กำไรเบื้องต้น (Profit)
$profit_month = $sales_month - $expense_month;

// 1.4 ลูกหนี้การค้า (AR - รอเก็บเงินลูกค้า)
$q_ar = mysqli_query($conn, "SELECT SUM(total_amount) as val FROM sales_orders WHERE payment_status != 'Paid'");
$ar_total = mysqli_fetch_assoc($q_ar)['val'] ?? 0;

// 1.5 เจ้าหนี้การค้า (AP - รอจ่ายเงินซัพพลายเออร์)
$q_ap = mysqli_query($conn, "SELECT SUM(pi.quantity * pi.unit_price) as val 
                             FROM purchase_orders po 
                             JOIN po_items pi ON po.po_id = pi.po_id 
                             WHERE po.status IN ('Completed', 'Delivered') AND po.payment_status = 'Unpaid'");
$ap_total = mysqli_fetch_assoc($q_ap)['val'] ?? 0;


// ==========================================
// 🚀 2. ประมวลผลข้อมูลกราฟ (Chart Data)
// ==========================================

// กราฟ 6 เดือนย้อนหลัง (รายรับ vs รายจ่าย)
$months = [];
$income_data = [];
$expense_data = [];

for ($i = 5; $i >= 0; $i--) {
    $m = date('m', strtotime("-$i month"));
    $y = date('Y', strtotime("-$i month"));
    $m_name = date('M Y', strtotime("-$i month"));
    $months[] = $m_name;
    
    // ดึงรายรับ
    $q_inc = mysqli_query($conn, "SELECT SUM(total_amount) as val FROM sales_orders WHERE MONTH(sale_date) = '$m' AND YEAR(sale_date) = '$y'");
    $income_data[] = (float)(mysqli_fetch_assoc($q_inc)['val'] ?? 0);

    // ดึงรายจ่าย
    $q_exp_h = mysqli_query($conn, "SELECT SUM(pi.quantity * pi.unit_price) as val 
                                    FROM purchase_orders po 
                                    JOIN po_items pi ON po.po_id = pi.po_id 
                                    WHERE po.payment_status = 'Paid' 
                                    AND MONTH(po.payment_date) = '$m' AND YEAR(po.payment_date) = '$y'");
    $expense_data[] = (float)(mysqli_fetch_assoc($q_exp_h)['val'] ?? 0);
}

// กราฟสินค้าขายดี Top 5
$top_labels = [];
$top_data = [];
$q_top = mysqli_query($conn, "SELECT p.p_name, SUM(si.quantity) as total_qty 
                              FROM sales_items si 
                              JOIN products p ON si.product_id = p.id 
                              GROUP BY si.product_id 
                              ORDER BY total_qty DESC LIMIT 5");
while ($t = mysqli_fetch_assoc($q_top)) {
    $top_labels[] = $t['p_name'];
    $top_data[] = (float)$t['total_qty'];
}

include '../sidebar.php';
?>

<title>Top Feed Mills | Executive Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    .wrapper { font-family: 'Sarabun', sans-serif; animation: fadeIn 0.5s ease-in-out; }
    
    /* 🚀 ปรับ KPI Cards ให้ยืดหยุ่นอัตโนมัติตามขนาดจอ */
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px; }
    
    .kpi-card { background: white; padding: 25px 20px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.03); display: flex; align-items: center; justify-content: space-between; position: relative; overflow: hidden; border-left: 5px solid #ccc; transition: 0.3s; }
    .kpi-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); }
    .kpi-info h4 { margin: 0 0 8px 0; color: #858796; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold; }
    .kpi-info h2 { margin: 0; color: #2c3e50; font-size: 24px; font-weight: 900; }
    .kpi-icon { font-size: 45px; opacity: 0.15; position: absolute; right: -10px; bottom: -10px; transform: rotate(-15deg); }
    
    /* Colors for KPI */
    .kpi-primary { border-left-color: #4e73df; } .kpi-primary .kpi-info h4 { color: #4e73df; } .kpi-primary .kpi-icon { color: #4e73df; }
    .kpi-danger { border-left-color: #e74a3b; } .kpi-danger .kpi-info h4 { color: #e74a3b; } .kpi-danger .kpi-icon { color: #e74a3b; }
    .kpi-success { border-left-color: #1cc88a; } .kpi-success .kpi-info h4 { color: #1cc88a; } .kpi-success .kpi-icon { color: #1cc88a; }
    .kpi-warning { border-left-color: #f6c23e; } .kpi-warning .kpi-info h4 { color: #f6c23e; } .kpi-warning .kpi-icon { color: #f6c23e; }
    .kpi-info-color { border-left-color: #36b9cc; } .kpi-info-color .kpi-info h4 { color: #36b9cc; } .kpi-info-color .kpi-icon { color: #36b9cc; }

    /* 🚀 ปรับ Layout ของ Chart และ Table ป้องกันทะลุจอ */
    .chart-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 25px; }
    .grid-half { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; }
    
    @media (max-width: 1200px) { 
        .chart-grid { grid-template-columns: 1fr; } 
        .grid-half { grid-template-columns: 1fr; } /* ปัดตารางลงมาเรียงบนล่างถ้าจอเล็ก */
    }
    
    .chart-card { 
        background: white; padding: 25px; border-radius: 15px; 
        box-shadow: 0 5px 15px rgba(0,0,0,0.03); 
        min-width: 0; /* 🚀 คำสั่งนี้สำคัญมาก! บังคับให้ Grid ไม่ขยายตัวตามเนื้อหาที่ล้น */
        overflow: hidden; /* ซ่อนส่วนที่เกินจากกล่องหลัก */
    }

    .chart-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #f0f0f0; flex-wrap: wrap; gap: 10px; }
    .chart-header h3 { margin: 0; color: #4e73df; font-size: 16px; font-weight: bold; }
    
    /* Table Styling */
    table { width: 100%; border-collapse: collapse; }
    .table-responsive { 
        overflow-x: auto; /* ให้มีแถบเลื่อนแนวนอนเฉพาะจุดที่ตารางล้น */
        width: 100%; 
        border-radius: 8px; 
        -webkit-overflow-scrolling: touch;
    }
    th { background: #f8f9fc; padding: 12px 15px; text-align: left; color: #5a5c69; border-bottom: 2px solid #eaecf4; font-size: 13px; text-transform: uppercase; white-space: nowrap; }
    td { padding: 12px 15px; border-bottom: 1px solid #eaecf4; color: #333; font-size: 14px; white-space: nowrap; }
    tr:hover { background: #f8f9fc; }
    
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>

<div class="content-padding">
    <div class="wrapper">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 25px; flex-wrap: wrap; gap: 10px;">
            <h2 style="color: #2c3e50; margin:0;"><i class="fa-solid fa-chart-pie" style="color: #4e73df;"></i> ศูนย์บัญชาการข้อมูล (Executive Dashboard)</h2>
            <div style="color:#888; font-size:14px; background: white; padding: 8px 15px; border-radius: 50px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);"><i class="fa-solid fa-clock" style="color:#4e73df;"></i> ข้อมูลล่าสุด: <?= date('d/m/Y H:i') ?></div>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card kpi-primary">
                <div class="kpi-info">
                    <h4>รายรับเดือนนี้ (Income)</h4>
                    <h2><?= number_format($sales_month, 2) ?></h2>
                </div>
                <i class="fa-solid fa-wallet kpi-icon"></i>
            </div>
            
            <div class="kpi-card kpi-danger">
                <div class="kpi-info">
                    <h4>รายจ่ายเดือนนี้ (Expense)</h4>
                    <h2 style="color:#e74a3b;">-<?= number_format($expense_month, 2) ?></h2>
                </div>
                <i class="fa-solid fa-file-invoice-dollar kpi-icon"></i>
            </div>

            <div class="kpi-card kpi-success">
                <div class="kpi-info">
                    <h4>กำไรสุทธิ (Profit)</h4>
                    <h2 style="color:<?= $profit_month >= 0 ? '#1cc88a' : '#e74a3b' ?>;"><?= number_format($profit_month, 2) ?></h2>
                </div>
                <i class="fa-solid fa-coins kpi-icon"></i>
            </div>
            
            <div class="kpi-card kpi-info-color">
                <div class="kpi-info">
                    <h4>ลูกหนี้รอเก็บเงิน (AR)</h4>
                    <h2><?= number_format($ar_total, 2) ?></h2>
                </div>
                <i class="fa-solid fa-hand-holding-dollar kpi-icon"></i>
            </div>

            <div class="kpi-card kpi-warning">
                <div class="kpi-info">
                    <h4>เจ้าหนี้รอจ่าย (AP)</h4>
                    <h2><?= number_format($ap_total, 2) ?></h2>
                </div>
                <i class="fa-solid fa-building-columns kpi-icon"></i>
            </div>
        </div>

        <div class="chart-grid">
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fa-solid fa-chart-line"></i> กระแสเงินสด (Cash Flow) 6 เดือนย้อนหลัง</h3>
                </div>
                <canvas id="cashFlowChart" style="max-height: 350px;"></canvas>
            </div>
            
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fa-solid fa-ranking-star"></i> 5 อันดับสินค้าขายดี</h3>
                </div>
                <canvas id="topProductsChart" style="max-height: 350px;"></canvas>
            </div>
        </div>

        <div class="grid-half">
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fa-solid fa-bolt"></i> บิลขายที่ค้างชำระ (Top 5 AR)</h3>
                    <a href="../sales/payment_tracker.php" style="text-decoration:none; background:#e3f2fd; color:#4e73df; padding:5px 12px; border-radius:50px; font-size:12px; font-weight:bold; white-space: nowrap;">ดูทั้งหมด <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>เลขที่บิล</th>
                                <th>ลูกค้า / บริษัท</th>
                                <th>กำหนดจ่าย</th>
                                <th style="text-align:right;">ยอดเงิน (฿)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $q_recent_ar = mysqli_query($conn, "SELECT * FROM sales_orders WHERE payment_status != 'Paid' ORDER BY due_date ASC LIMIT 5");
                            if(mysqli_num_rows($q_recent_ar) > 0) {
                                while($r = mysqli_fetch_assoc($q_recent_ar)) {
                                    $is_overdue = (strtotime($r['due_date']) < strtotime(date('Y-m-d')));
                                    $color = $is_overdue ? "color:#e74a3b; font-weight:bold;" : "color:#333;";
                            ?>
                            <tr>
                                <td><strong style="color:#4e73df;">INV-<?= str_pad($r['sale_id'], 5, '0', STR_PAD_LEFT) ?></strong></td>
                                <td><?= htmlspecialchars($r['customer_name']) ?></td>
                                <td style="<?= $color ?>"><?= date('d/m/y', strtotime($r['due_date'])) ?> <?= $is_overdue ? '<i class="fa-solid fa-circle-exclamation"></i>' : '' ?></td>
                                <td style="text-align:right; font-weight:bold; color:#2c3e50; font-size:15px;"><?= number_format($r['total_amount'], 2) ?></td>
                            </tr>
                            <?php } } else { echo "<tr><td colspan='4' style='text-align:center; padding:30px; color:#888;'><i class='fa-solid fa-check-circle fa-2x' style='color:#1cc88a; margin-bottom:10px;'></i><br>ไม่มีลูกหนี้ค้างชำระ</td></tr>"; } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="chart-card">
                <div class="chart-header">
                    <h3 style="color:#e74a3b;"><i class="fa-solid fa-money-bill-transfer"></i> ยอดต้องจ่ายซัพพลายเออร์ (Top 5 AP)</h3>
                    <a href="../purchase/payment_supplier.php" style="text-decoration:none; background:#fceceb; color:#e74a3b; padding:5px 12px; border-radius:50px; font-size:12px; font-weight:bold; white-space: nowrap;">ดูทั้งหมด <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>เลขที่ PO</th>
                                <th>ซัพพลายเออร์</th>
                                <th>กำหนดโอน</th>
                                <th style="text-align:right;">ยอดเงิน (฿)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $q_recent_ap = mysqli_query($conn, "SELECT po.po_id, po.supplier_name, po.expected_delivery_date, SUM(pi.quantity * pi.unit_price) as total 
                                                                FROM purchase_orders po 
                                                                JOIN po_items pi ON po.po_id = pi.po_id 
                                                                WHERE po.payment_status = 'Unpaid' AND po.status IN ('Completed', 'Delivered')
                                                                GROUP BY po.po_id ORDER BY po.expected_delivery_date ASC LIMIT 5");
                            if(mysqli_num_rows($q_recent_ap) > 0) {
                                while($a = mysqli_fetch_assoc($q_recent_ap)) {
                                    $is_overdue_ap = (strtotime($a['expected_delivery_date']) < strtotime(date('Y-m-d')));
                                    $color_ap = $is_overdue_ap ? "color:#e74a3b; font-weight:bold;" : "color:#333;";
                            ?>
                            <tr>
                                <td><strong style="color:#e74a3b;">PO-<?= str_pad($a['po_id'], 5, '0', STR_PAD_LEFT) ?></strong></td>
                                <td><?= htmlspecialchars($a['supplier_name']) ?></td>
                                <td style="<?= $color_ap ?>"><?= date('d/m/y', strtotime($a['expected_delivery_date'])) ?> <?= $is_overdue_ap ? '<i class="fa-solid fa-circle-exclamation"></i>' : '' ?></td>
                                <td style="text-align:right; font-weight:bold; color:#e74a3b; font-size:15px;"><?= number_format($a['total'], 2) ?></td>
                            </tr>
                            <?php } } else { echo "<tr><td colspan='4' style='text-align:center; padding:30px; color:#888;'><i class='fa-solid fa-check-circle fa-2x' style='color:#1cc88a; margin-bottom:10px;'></i><br>ไม่มีเจ้าหนี้ค้างจ่าย</td></tr>"; } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    // Data for Cash Flow Chart (Income vs Expense)
    const labels = <?= json_encode($months) ?>;
    const incomeData = <?= json_encode($income_data) ?>;
    const expenseData = <?= json_encode($expense_data) ?>;

    const ctxCashFlow = document.getElementById('cashFlowChart').getContext('2d');
    new Chart(ctxCashFlow, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'รายรับ (Income)',
                    data: incomeData,
                    borderColor: '#4e73df',
                    backgroundColor: 'rgba(78, 115, 223, 0.1)',
                    borderWidth: 3,
                    pointBackgroundColor: '#4e73df',
                    pointRadius: 4,
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'รายจ่าย (Expense)',
                    data: expenseData,
                    borderColor: '#e74a3b',
                    backgroundColor: 'rgba(231, 74, 59, 0.1)',
                    borderWidth: 3,
                    pointBackgroundColor: '#e74a3b',
                    pointRadius: 4,
                    fill: true,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { 
                legend: { position: 'top', labels: { font: { family: 'Sarabun' } } } 
            },
            scales: {
                y: { beginAtZero: true, grid: { color: '#f0f0f0' } },
                x: { grid: { display: false } }
            }
        }
    });

    // Data for Doughnut Chart (Top Products)
    const topLabels = <?= json_encode($top_labels) ?>;
    const topData = <?= json_encode($top_data) ?>;

    const ctxTop = document.getElementById('topProductsChart').getContext('2d');
    new Chart(ctxTop, {
        type: 'doughnut',
        data: {
            labels: topLabels,
            datasets: [{
                data: topData,
                backgroundColor: ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b'],
                hoverBackgroundColor: ['#2e59d9', '#17a673', '#2c9faf', '#dda20a', '#be2617'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
                legend: { position: 'bottom', labels: { font: { family: 'Sarabun' } } }
            }
        }
    });
</script>

</body>
</html>