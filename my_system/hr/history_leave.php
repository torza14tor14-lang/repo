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

<title>ประวัติและสถานะการลา | Top Feed Mills</title>

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
    
    .time-box { font-size: 13.5px; font-weight: bold; color: #4e73df; }
</style>

<div class="content-padding">
    <h2 style="color: #2c3e50; margin-top:0; margin-bottom: 25px;"><i class="fa-solid fa-calendar-check" style="color: #4e73df;"></i> ประวัติและสถานะการลาของฉัน</h2>

    <div class="dashboard-card">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="width: 25%;">วันที่ขอลา</th>
                        <th style="width: 20%;">ประเภทการลา</th>
                        <th style="width: 20%;">ระยะเวลาที่ลา</th>
                        <th style="width: 15%;">วันที่คีย์ข้อมูล</th>
                        <th style="width: 20%;">สถานะปัจจุบัน</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql_my = "SELECT * FROM leave_records WHERE userid = '$userid' ORDER BY id DESC LIMIT 50";
                    $res_my = mysqli_query($conn, $sql_my);

                    if (mysqli_num_rows($res_my) > 0) {
                        while($row = mysqli_fetch_assoc($res_my)) {
                            $duration = "";
                            if($row['d'] > 0) $duration .= "{$row['d']} วัน ";
                            if($row['h'] > 0) $duration .= "{$row['h']} ชม. ";
                            if($row['m'] > 0) $duration .= "{$row['m']} นาที ";
                            if($row['t'] > 0) $duration .= "{$row['t']} ครั้ง";
                            if($duration == "") $duration = "-";
                            
                            $date_show = date('d/m/Y', strtotime($row['start_date']));
                            if ($row['start_date'] != $row['end_date']) {
                                $date_show .= " - " . date('d/m/Y', strtotime($row['end_date']));
                            }

                            $badge = "";
                            if ($row['status'] == 'Pending') $badge = "<span class='badge b-pending'><i class='fa-solid fa-hourglass-half'></i> รอหัวหน้าอนุมัติ</span>";
                            elseif ($row['status'] == 'Manager_Approved') $badge = "<span class='badge b-mgr-approved'><i class='fa-solid fa-user-check'></i> รอ HR ยืนยัน</span>";
                            elseif ($row['status'] == 'Approved') $badge = "<span class='badge b-approved'><i class='fa-solid fa-check-double'></i> อนุมัติสำเร็จ</span>";
                            elseif ($row['status'] == 'Rejected') $badge = "<span class='badge b-rejected'><i class='fa-solid fa-xmark'></i> ไม่อนุมัติ</span>";
                    ?>
                    <tr>
                        <td><strong><?= $date_show ?></strong></td>
                        <td><?= $row['leave_type'] ?></td>
                        <td><span class="time-box"><?= trim($duration) ?></span></td>
                        <td><small><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></small></td>
                        <td><?= $badge ?></td>
                    </tr>
                    <?php } } else { echo "<tr><td colspan='5' style='text-align:center; padding:40px; color:#888;'><i class='fa-regular fa-folder-open fa-2x'></i><br><br>ยังไม่มีประวัติการลางาน</td></tr>"; } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>