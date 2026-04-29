<?php
session_start();
include '../db.php';

// 1. รับค่า ID
if(!isset($_GET['id'])) { die("ไม่พบรหัสเอกสาร"); }
$sale_id = (int)$_GET['id'];

// 2. 🚀 ดึงข้อมูล หัวบิลขาย + เชื่อมข้อมูลลูกค้า (ที่อยู่, เบอร์โทร, เลขผู้เสียภาษี)
$sql_sale = "SELECT s.*, c.cus_name, c.cus_address, c.cus_tel, c.cus_tax_id 
             FROM sales_orders s 
             LEFT JOIN customers c ON s.cus_id = c.id 
             WHERE s.sale_id = '$sale_id'";
$sale_result = mysqli_query($conn, $sql_sale);
$sale = mysqli_fetch_assoc($sale_result);

if (!$sale) { die("ไม่พบข้อมูลเอกสารในระบบ"); }

// จัดการชื่อลูกค้า (ดึงจากตารางลูกค้าก่อน ถ้าไม่มีให้ใช้ชื่อที่พิมพ์ไว้เดิม)
$customer_name = !empty($sale['cus_name']) ? $sale['cus_name'] : $sale['customer_name'];

// จัดการข้อความสถานะการเงิน
$pay_status = 'รอเก็บเงิน';
$pay_color = '#e74a3b';
if ($sale['payment_status'] == 'Paid') { $pay_status = 'ชำระเงินแล้ว'; $pay_color = '#1cc88a'; }
elseif ($sale['payment_status'] == 'Credit') { $pay_status = 'เครดิต (รอวางบิล)'; $pay_color = '#1976d2'; }
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ใบส่งของ/ใบแจ้งหนี้ #INV-<?php echo str_pad($sale_id, 5, '0', STR_PAD_LEFT); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        /* ใช้ Style เดียวกันกับใบ PO ได้เลย เพื่อความสม่ำเสมอของบริษัท */
        body { font-family: 'Sarabun', sans-serif; color: #333; margin: 0; background: #525659; }
        * { box-sizing: border-box; }
        .paper-a4 { width: 21cm; min-height: 29.7cm; padding: 2cm; margin: 1cm auto; background: white; box-shadow: 0 0 10px rgba(0,0,0,0.5); }
        @media print { body { background: white; margin: 0; } .paper-a4 { margin: 0; box-shadow: none; padding: 0; width: 100%; height: 100%; } .no-print { display: none !important; } }
        
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 20px; }
        .company-info h1 { margin: 0 0 5px 0; color: #2c3e50; font-size: 24px; }
        .company-info p { margin: 0; font-size: 14px; line-height: 1.5; }
        .doc-title { text-align: right; }
        .doc-title h2 { margin: 0 0 5px 0; font-size: 22px; color: #fd7e14; }
        
        .info-row { display: flex; justify-content: space-between; margin-bottom: 20px; font-size: 14px; }
        .info-box { padding: 15px; border: 1px solid #ddd; border-radius: 8px; width: 48%; line-height: 1.6; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 14px; }
        th { background: #f0f0f0; border: 1px solid #333; padding: 10px; text-align: center; }
        td { border: 1px solid #333; padding: 10px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        .signature-section { display: flex; justify-content: space-between; margin-top: 60px; padding: 0 20px;}
        .sign-box { text-align: center; width: 250px; }
        .sign-line { border-top: 1px solid #333; margin-top: 60px; padding-top: 10px; font-weight: bold; }
        
        .top-controls { background: #333; padding: 15px; text-align: center; position: sticky; top: 0; z-index: 100; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-family: 'Sarabun'; font-weight: bold; font-size: 16px; margin: 0 10px; }
        .btn-print { background: #fd7e14; color: white; }
        .btn-close { background: #858796; color: white; }
    </style>
</head>
<body onload="window.print()">

    <div class="top-controls no-print">
        <button class="btn btn-print" onclick="window.print()">🖨️ กดเพื่อพิมพ์ใบแจ้งหนี้</button>
        <button class="btn btn-close" onclick="window.close()">❌ ปิดหน้าต่างนี้</button>
    </div>

    <div class="paper-a4">
        <div class="header">
            <div class="company-info">
                <h1>บริษัท ท็อป ฟีด มิลล์ จำกัด</h1>
                <p>32 หมู่ที่ 6 ตำบลหน้าไม้ อำเภอลาดหลุมแก้ว จ.ปทุมธานี 12140<br>โทรศัพท์: 02-194-5678</p>
            </div>
            <div class="doc-title">
                <h2>ใบส่งของ / ใบแจ้งหนี้</h2>
                <p><strong>เลขที่:</strong> INV-<?php echo str_pad($sale_id, 5, '0', STR_PAD_LEFT); ?><br>
                <strong>วันที่ออกเอกสาร:</strong> <?php echo date('d/m/Y', strtotime($sale['sale_date'])); ?></p>
            </div>
        </div>

        <div class="info-row">
            <div class="info-box">
                <strong>ลูกค้า / Customer:</strong><br>
                <span style="font-size: 16px; font-weight: bold; color: #2c3e50;"><?php echo $customer_name; ?></span><br>
                <?php if(!empty($sale['cus_address'])): ?>
                    ที่อยู่: <?php echo nl2br($sale['cus_address']); ?><br>
                <?php endif; ?>
                <?php if(!empty($sale['cus_tel'])): ?>
                    โทร: <?php echo $sale['cus_tel']; ?><br>
                <?php endif; ?>
                <?php if(!empty($sale['cus_tax_id'])): ?>
                    เลขประจำตัวผู้เสียภาษี: <?php echo $sale['cus_tax_id']; ?>
                <?php endif; ?>
            </div>
            
            <div class="info-box">
                <strong>ข้อมูลการชำระเงิน:</strong><br>
                พนักงานขาย: <?php echo $sale['created_by']; ?><br>
                สถานะ: <span style="color: <?php echo $pay_color; ?>; font-weight: bold;"><?php echo $pay_status; ?></span><br>
                <?php if(!empty($sale['due_date'])): ?>
                    <strong>ครบกำหนดชำระ (Due Date): <?php echo date('d/m/Y', strtotime($sale['due_date'])); ?></strong>
                <?php endif; ?>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 10%;">ลำดับ</th>
                    <th style="width: 45%;">รายการสินค้าสำเร็จรูป</th>
                    <th style="width: 15%;">จำนวน</th>
                    <th style="width: 15%;">ราคา/หน่วย</th>
                    <th style="width: 15%;">จำนวนเงิน</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // ดึงรายการย่อยของการขาย
                $sql_items = "SELECT si.*, p.p_name FROM sales_items si JOIN products p ON si.product_id = p.id WHERE si.sale_id = '$sale_id'";
                $items_res = mysqli_query($conn, $sql_items);
                $i = 1;
                
                while($item = mysqli_fetch_assoc($items_res)) {
                    $total_price = $item['quantity'] * $item['unit_price'];
                ?>
                <tr>
                    <td class="text-center"><?php echo $i++; ?></td>
                    <td><?php echo $item['p_name']; ?></td>
                    <td class="text-right"><?php echo number_format($item['quantity'], 2); ?></td>
                    <td class="text-right"><?php echo number_format($item['unit_price'], 2); ?></td>
                    <td class="text-right"><?php echo number_format($total_price, 2); ?></td>
                </tr>
                <?php } ?>
                
                <tr>
                    <td colspan="4" class="text-right" style="font-weight: bold; background: #fff4e6;">ยอดรวมสุทธิ (Net Total)</td>
                    <td class="text-right" style="font-weight: bold; font-size: 16px; background: #fff4e6; color: #fd7e14;">
                        ฿<?php echo number_format($sale['total_amount'], 2); ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="signature-section">
            <div class="sign-box">
                <div class="sign-line">ผู้รับสินค้า (Received By)</div>
                <p style="margin-top:5px; font-size:14px; color:#555;">วันที่ ______/______/______</p>
            </div>
            <div class="sign-box">
                <div class="sign-line">ผู้รับมอบอำนาจ (Authorized By)</div>
                <p style="margin-top:5px; font-size:14px; color:#555;">วันที่ ______/______/______</p>
            </div>
        </div>

    </div>
</body>
</html>