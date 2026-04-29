<?php
session_start();
include '../db.php';

if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

$userid = $_SESSION['userid'];

include '../sidebar.php';
?>

<title>ประวัติและสถานะ OT | Top Feed Mills</title>

<style>
    .dashboard-card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-bottom: 25px; border-top: 4px solid #4e73df; }
    .section-title { color: #2c3e50; font-size: 18px; margin-top: 0; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; border-bottom: 2px solid #eaecf4; padding-bottom: 10px; }
    
    table { width: 100%; border-collapse: collapse; min-width: 800px; }
    .table-responsive { overflow-x: auto; }
    th { background: #f8f9fc; padding: 12px 15px; color: #5a5c69; font-size: 14px; text-align: left; border-bottom: 2px solid #eaecf4; font-weight: bold; }
    td { padding: 12px 15px; border-bottom: 1px solid #f1f1f1; font-size: 14px; color: #555; vertical-align: middle; }
    tr:hover { background: #f8f9fc; }

    .badge { padding: 5px 12px; border-radius: 50px; font-size: 12px; font-weight: bold; display: inline-block; }
    .b-pending { background: #fff3cd; color: #856404; }
    .b-mgr-approved { background: #cce5ff; color: #004085; }
    .b-approved { background: #d4edda; color: #155724; }
    .b-rejected { background: #f8d7da; color: #721c24; }

    .time-box { display: flex; flex-direction: column; gap: 3px; font-size: 12px; color: #4e73df; }
    .time-item { background: #f8f9fc; padding: 3px 8px; border-radius: 4px; border: 1px solid #eaecf4; display: inline-block; width: fit-content;}
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top:0; margin-bottom: 25px;"><i class="fa-solid fa-file-invoice-dollar" style="color: #4e73df;"></i> ประวัติและสถานะการขอทำล่วงเวลา (OT) ของฉัน</h2>

    <div class="dashboard-card">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="width: 25%;">วันที่ทำ OT</th>
                        <th style="width: 35%;">ชั่วโมงล่วงเวลา</th>
                        <th style="width: 20%;">วันที่ส่งคำร้อง</th>
                        <th style="width: 20%;">สถานะปัจจุบัน</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql_my = "SELECT * FROM ot_records WHERE userid = '$userid' ORDER BY id DESC LIMIT 50";
                    $res_my = mysqli_query($conn, $sql_my);

                    if (mysqli_num_rows($res_my) > 0) {
                        while($row = mysqli_fetch_assoc($res_my)) {
                            $badge = "";
                            if ($row['status'] == 'Pending') $badge = "<span class='badge b-pending'><i class='fa-solid fa-hourglass-half'></i> รอหัวหน้าอนุมัติ</span>";
                            elseif ($row['status'] == 'Manager_Approved') $badge = "<span class='badge b-mgr-approved'><i class='fa-solid fa-user-check'></i> รอ HR ยืนยัน</span>";
                            elseif ($row['status'] == 'Approved') $badge = "<span class='badge b-approved'><i class='fa-solid fa-check-double'></i> อนุมัติสำเร็จ</span>";
                            elseif ($row['status'] == 'Rejected') $badge = "<span class='badge b-rejected'><i class='fa-solid fa-xmark'></i> ไม่อนุมัติ</span>";
                    ?>
                    <tr>
                        <td><strong><?= date('d/m/Y', strtotime($row['ot_date'])) ?></strong></td>
                        <td>
                            <div class="time-box">
                                <?php if($row['ot_1_h']>0 || $row['ot_1_m']>0) echo "<span class='time-item'>1 เท่า: {$row['ot_1_h']} ชม. {$row['ot_1_m']} น.</span>"; ?>
                                <?php if($row['ot_15_h']>0 || $row['ot_15_m']>0) echo "<span class='time-item'>1.5 เท่า: {$row['ot_15_h']} ชม. {$row['ot_15_m']} น.</span>"; ?>
                                <?php if($row['ot_3_h']>0 || $row['ot_3_m']>0) echo "<span class='time-item'>3 เท่า: {$row['ot_3_h']} ชม. {$row['ot_3_m']} น.</span>"; ?>
                            </div>
                        </td>
                        <td><small><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></small></td>
                        <td><?= $badge ?></td>
                    </tr>
                    <?php } } else { echo "<tr><td colspan='4' style='text-align:center; padding:40px; color:#888;'><i class='fa-regular fa-folder-open fa-2x'></i><br><br>ยังไม่มีประวัติการทำ OT</td></tr>"; } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>