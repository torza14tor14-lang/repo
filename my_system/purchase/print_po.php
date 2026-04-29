<?php
session_start();
include '../db.php';

// สเต็ปที่ 1: ตรวจสอบว่า "ล็อกอินหรือยัง?" (ถ้ายัง ให้เด้งไปหน้า login ทันที)
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

// 1. รับค่า ID ของใบสั่งซื้อที่ส่งมาจากหน้า view_pos.php
if(!isset($_GET['id'])) { die("ไม่พบรหัสเอกสาร"); }
$po_id = (int)$_GET['id'];

// 2. ดึงข้อมูล "หัวบิล" (ข้อมูลบริษัทที่สั่งซื้อ)
$sql_po = "SELECT * FROM purchase_orders WHERE po_id = '$po_id'";
$po_result = mysqli_query($conn, $sql_po);
$po = mysqli_fetch_assoc($po_result);

if (!$po) { die("ไม่พบข้อมูลเอกสารในระบบ"); }
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ใบสั่งซื้อ #PO-<?php echo str_pad($po_id, 5, '0', STR_PAD_LEFT); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        /* --- ตั้งค่าหน้ากระดาษและการแสดงผล --- */
        body { font-family: 'Sarabun', sans-serif; color: #333; margin: 0; background: #525659; }
        
        /* ขอบเขตของกระดาษจำลอง (A4) ให้ดูสวยงามบนหน้าจอคอม */
        .paper-a4 {
            width: 21cm;
            min-height: 29.7cm;
            padding: 2cm;
            margin: 1cm auto;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.5);
            box-sizing: border-box;
        }

        /* --- การตั้งค่าสำหรับการ "พิมพ์ (Print)" --- */
        @media print {
            body { background: white; margin: 0; }
            .paper-a4 { margin: 0; box-shadow: none; padding: 0; width: 100%; height: 100%; }
            .no-print { display: none !important; } /* ซ่อนแถบปุ่มตอนพิมพ์ */
        }

        /* --- ส่วนหัวกระดาษ (Header) --- */
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 20px; }
        .company-info h1 { margin: 0 0 5px 0; color: #2c3e50; font-size: 24px; }
        .company-info p { margin: 0; font-size: 14px; }
        .doc-title { text-align: right; }
        .doc-title h2 { margin: 0 0 5px 0; font-size: 22px; }

        /* --- ข้อมูลผู้ขายและวันที่ --- */
        .info-row { display: flex; justify-content: space-between; margin-bottom: 20px; font-size: 15px; }
        .info-box { padding: 10px; border: 1px solid #ddd; border-radius: 5px; width: 45%; }

        /* --- ตารางรายการสินค้า --- */
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 15px; }
        th { background: #f0f0f0; border: 1px solid #333; padding: 10px; text-align: center; }
        td { border: 1px solid #333; padding: 10px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        /* --- ลายเซ็นท้ายเอกสาร --- */
        .signature-section { display: flex; justify-content: space-around; margin-top: 50px; }
        .sign-box { text-align: center; width: 200px; }
        .sign-line { border-top: 1px dotted #333; margin-top: 50px; padding-top: 10px; }
        
        /* ปุ่มคำสั่งด้านบน */
        .top-controls { background: #333; padding: 15px; text-align: center; position: sticky; top: 0; z-index: 100; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-family: 'Sarabun'; font-weight: bold; font-size: 16px; margin: 0 10px; }
        .btn-print { background: #4e73df; color: white; }
        .btn-close { background: #e74a3b; color: white; }
    </style>
</head>
<body onload="window.print()">

    <div class="top-controls no-print">
        <button class="btn btn-print" onclick="window.print()">🖨️ กดเพื่อพิมพ์เอกสาร</button>
        <button class="btn btn-close" onclick="window.close()">❌ ปิดหน้าต่างนี้</button>
    </div>

    <div class="paper-a4">
        
        <div class="header">
            <div class="company-info">
                <h1>บริษัท ท็อป ฟีด มิลล์ จำกัด</h1>
                <p>32 หมู่ที่ 6 ตำบลหน้าไม้ อำเภอลาดหลุมแก้ว จ.ปทุมธานี 12140<br>โทรศัพท์: 02-194-5678</p>
            </div>
            <div class="doc-title">
                <h2>ใบสั่งซื้อ (Purchase Order)</h2>
                <p><strong>เลขที่:</strong> PO-<?php echo str_pad($po_id, 5, '0', STR_PAD_LEFT); ?><br>
                <strong>วันที่ออกเอกสาร:</strong> <?php echo date('d/m/Y', strtotime($po['created_at'])); ?></p>
            </div>
        </div>

        <div class="info-row">
            <div class="info-box">
                <strong>สั่งซื้อจาก (Supplier):</strong><br>
                <?php echo $po['supplier_name']; ?>
            </div>
            <div class="info-box" style="text-align:right; border:none;">
                <strong>วันที่กำหนดส่งมอบ:</strong><br>
                <?php echo date('d/m/Y', strtotime($po['expected_delivery_date'])); ?>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 10%;">ลำดับ</th>
                    <th style="width: 45%;">รายการวัตถุดิบ</th>
                    <th style="width: 15%;">จำนวน (กก.)</th>
                    <th style="width: 15%;">ราคา/หน่วย</th>
                    <th style="width: 15%;">จำนวนเงิน</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // ดึงรายการย่อยที่อยู่ในบิลนี้
                $sql_items = "SELECT pi.*, p.p_name FROM po_items pi JOIN products p ON pi.item_id = p.id WHERE pi.po_id = '$po_id'";
                $items_res = mysqli_query($conn, $sql_items);
                $i = 1;
                $grand_total = 0;
                
                while($item = mysqli_fetch_assoc($items_res)) {
                    $total_price = $item['quantity'] * $item['unit_price'];
                    $grand_total += $total_price;
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
                    <td colspan="4" class="text-right" style="font-weight: bold; background: #f9f9f9;">ยอดรวมทั้งสิ้น (บาท)</td>
                    <td class="text-right" style="font-weight: bold; font-size: 16px; background: #f9f9f9;">
                        <?php echo number_format($grand_total, 2); ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="signature-section">
            <div class="sign-box">
                <div class="sign-line">ผู้จัดทำ / ผู้สั่งซื้อ</div>
                <p style="margin-top:5px; font-size:14px;">วันที่ ______/______/______</p>
            </div>
            <div class="sign-box">
                <div class="sign-line">ผู้อนุมัติสั่งซื้อ</div>
                <p style="margin-top:5px; font-size:14px;">วันที่ ______/______/______</p>
            </div>
        </div>

    </div>
</body>
</html>