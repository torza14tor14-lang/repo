<?php
session_start();
include 'db.php';

// ==========================================
// 🚀 1. ระบบ API สำหรับโหลดปฏิทินด้วย AJAX (ไม่ต้องรีเฟรชเว็บ)
// ==========================================
if (isset($_GET['action']) && $_GET['action'] == 'get_calendar') {
    $month = isset($_GET['m']) ? (int)$_GET['m'] : date('m');
    $year = isset($_GET['y']) ? (int)$_GET['y'] : date('Y');

    $first_day = date('w', strtotime("$year-$month-01"));
    $days_in_month = date('t', strtotime("$year-$month-01"));

    // ดึงวันหยุดจากฐานข้อมูล
    $special_holidays = [];
    $check_table = mysqli_query($conn, "SHOW TABLES LIKE 'company_holidays'");
    if (mysqli_num_rows($check_table) > 0) {
        $h_res = mysqli_query($conn, "SELECT holiday_date, holiday_name FROM company_holidays WHERE MONTH(holiday_date) = '$month' AND YEAR(holiday_date) = '$year'");
        while($h = mysqli_fetch_assoc($h_res)) {
            $special_holidays[$h['holiday_date']] = $h['holiday_name'];
        }
    }

    $html = '';
    // สร้างหัวตาราง (ชื่อย่อ)
    $days_name = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
    foreach($days_name as $i => $d) {
        $color = ($i == 0) ? 'color:#e74a3b;' : '';
        $html .= "<div class='cal-day-name' style='$color'>$d</div>";
    }

    // สร้างช่องว่างก่อนวันที่ 1
    for ($i = 0; $i < $first_day; $i++) { $html .= "<div></div>"; }

    // วนลูปสร้างวันที่
    for ($day = 1; $day <= $days_in_month; $day++) {
        $current_date = sprintf("%04d-%02d-%02d", $year, $month, $day);
        $day_of_week = date('w', strtotime($current_date));
        
        $class = "cal-cell";
        $title = "";

        if ($day_of_week == 0) {
            $class .= " cal-sunday cal-holiday";
            $title = "วันหยุดประจำสัปดาห์";
        }
        if (isset($special_holidays[$current_date])) {
            $class .= " cal-holiday";
            $title = $special_holidays[$current_date];
        }
        if ($current_date == date('Y-m-d')) {
            $class .= " cal-today";
        }

        $html .= "<div class='$class' data-title='{$title}' title='{$title}'>{$day}</div>";
    }

    // สร้างส่วนอธิบายวันหยุดประจำเดือน
    $holiday_list_html = '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #eaecf4;">';
    $holiday_list_html .= '<h5 style="margin: 0 0 10px 0; color: #5a5c69; font-size: 13px;"><i class="fa-solid fa-circle-info" style="color:#4e73df;"></i> รายละเอียดวันหยุดพิเศษ</h5>';
    
    if (empty($special_holidays)) {
        $holiday_list_html .= '<div style="font-size: 12px; color: #888; text-align: center; padding: 10px; background: #f8f9fc; border-radius: 8px;">ไม่มีวันหยุดพิเศษในเดือนนี้ (หยุดเฉพาะวันอาทิตย์)</div>';
    } else {
        $holiday_list_html .= '<div style="display: flex; flex-direction: column; gap: 8px;">';
        ksort($special_holidays); // เรียงตามวันที่
        foreach ($special_holidays as $date => $name) {
            $day_num = (int)date('d', strtotime($date));
            $holiday_list_html .= "
            <div style='display: flex; align-items: center; gap: 10px; background: #fff5f5; padding: 8px 12px; border-radius: 8px; border-left: 3px solid #e74a3b;'>
                <span style='background: #e74a3b; color: white; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; min-width: 25px; text-align: center;'>วันที่ {$day_num}</span>
                <span style='font-size: 13px; color: #e74a3b; font-weight: 600;'>{$name}</span>
            </div>";
        }
        $holiday_list_html .= '</div>';
    }
    $holiday_list_html .= '</div>';

    $months_th = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
    $month_text = $months_th[$month] . " " . ($year + 543);

    // ส่งข้อมูลกลับไปเป็น JSON ให้ JavaScript ทำงานต่อ
    echo json_encode([
        'html' => $html,
        'holiday_list' => $holiday_list_html, 
        'month_text' => $month_text,
        'prev_m' => ($month == 1) ? 12 : $month - 1,
        'prev_y' => ($month == 1) ? $year - 1 : $year,
        'next_m' => ($month == 12) ? 1 : $month + 1,
        'next_y' => ($month == 12) ? $year + 1 : $year
    ]);
    exit(); 
}
// ==========================================

// 2. โหลดหน้าเว็บปกติ
include 'sidebar.php';

// 🚀 ดึงชื่อจาก Session อย่างถูกต้อง (ถ้าไม่มีให้ขึ้นว่า "พนักงาน")
$fullname = $_SESSION['fullname'] ?? 'พนักงาน';

// ==========================================
// 📊 คำนวณสถิติวันลาคงเหลือ และ คำขอ OT
// ==========================================
$my_userid = $_SESSION['userid'] ?? '';
$current_year = date('Y');
$current_month = date('m');
$leave_stats = [];
$ot_summary = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];

if ($my_userid != '') {
    // โควตาวันลา
    $quota = ['sick_max'=>30, 'personal_other_max'=>7, 'vacation_max'=>6, 'maternity_max'=>120];
    $check_quota_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'leave_quotas'");
    if (mysqli_num_rows($check_quota_tbl) > 0) {
        $q_quota = mysqli_query($conn, "SELECT * FROM leave_quotas WHERE userid = '$my_userid' AND year = '$current_year'");
        if ($row_q = mysqli_fetch_assoc($q_quota)) { $quota = $row_q; }
    }

    $types = [
        'ลาป่วย' => ['max' => $quota['sick_max'], 'color' => '#e74a3b', 'condition' => "leave_type = 'ลาป่วย'"],
        'ลากิจ / ลาอื่นๆ' => ['max' => $quota['personal_other_max'], 'color' => '#f6c23e', 'condition' => "leave_type IN ('ลากิจ', 'ลาอื่นๆ')"],
        'พักร้อน' => ['max' => $quota['vacation_max'], 'color' => '#1cc88a', 'condition' => "leave_type = 'พักร้อน'"]
    ];

    foreach ($types as $label => $info) {
        $q_used = mysqli_query($conn, "SELECT SUM(d) as total_d FROM leave_records WHERE userid = '$my_userid' AND {$info['condition']} AND status = 'Approved' AND YEAR(start_date) = '$current_year'");
        $used = 0;
        if($q_used && $row_used = mysqli_fetch_assoc($q_used)) { $used = $row_used['total_d'] ?: 0; }
        
        $remain = max(0, $info['max'] - $used);
        $percent = ($info['max'] > 0) ? min(100, ($used / $info['max']) * 100) : 0;
        
        $leave_stats[] = ['label' => $label, 'used' => $used, 'max' => $info['max'], 'remain' => $remain, 'percent' => $percent, 'color' => $info['color']];
    }

    // 🚀 สถิติ OT ของเดือนปัจจุบัน
    $check_ot_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'ot_records'");
    if (mysqli_num_rows($check_ot_tbl) > 0) {
        $q_ot = mysqli_query($conn, "SELECT status, COUNT(id) as cnt FROM ot_records WHERE userid='$my_userid' AND MONTH(ot_date)='$current_month' AND YEAR(ot_date)='$current_year' GROUP BY status");
        while($row_ot = mysqli_fetch_assoc($q_ot)) {
            $status_key = ($row_ot['status'] == 'Manager_Approved') ? 'Pending' : $row_ot['status']; // รวบ Manager_Approved เข้ากับ Pending
            if(isset($ot_summary[$status_key])) {
                $ot_summary[$status_key] += $row_ot['cnt'];
            }
        }
    }
}
// ==========================================
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Top Feed Mills Co., Ltd. | หน้าหลัก</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* === โทนสีหลัก === */
        :root {
            --primary: #4e73df;
            --success: #1cc88a;
            --warning: #f6c23e;
            --dark: #2c3e50;
            --gray-light: #f8f9fc;
            --gray-text: #858796;
            --border: #eaecf4;
        }

        /* === โครงสร้างหลัก === */
        .welcome-banner {
            background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(78, 115, 223, 0.2);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            animation: fadeIn 0.5s ease;
        }
        
        /* 🚀 เมนูลัด Quick Actions */
        .quick-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-quick { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); color: white; padding: 10px 15px; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: bold; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
        .btn-quick:hover { background: white; color: var(--primary); transform: translateY(-2px); }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 25px;
            animation: fadeIn 0.6s ease;
        }

        @media (max-width: 992px) {
            .dashboard-grid { grid-template-columns: 1fr; }
        }

        /* === การ์ดฝั่งซ้าย (Company Info) === */
        .company-card {
            background: white;
            border-radius: 15px;
            padding: 30px 25px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            border-top: 5px solid var(--primary);
            height: fit-content;
        }

        .company-icon {
            width: 70px; height: 70px;
            background: var(--gray-light);
            color: var(--primary);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 30px; margin: 0 auto 15px auto;
        }

        .company-card h2 { color: var(--dark); font-size: 1.4rem; margin: 0 0 5px 0; }
        .company-card p.sub { color: var(--gray-text); font-size: 0.95rem; margin-bottom: 20px; }

        .video-box {
            border-radius: 10px; overflow: hidden;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            background: #000; margin-bottom: 15px;
        }
        .video-box video { width: 100%; display: block; }
        
        .motto { font-size: 14px; color: var(--primary); font-weight: bold; }

        /* CSS กล่องสถิติ */
        .stats-box { margin-top: 15px; padding: 15px; background: #fff; border-radius: 10px; border: 1px solid var(--border); text-align: left; }
        .leave-item { margin-bottom: 12px; }
        .leave-item:last-child { margin-bottom: 0; }
        .leave-info { display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px; font-weight: bold; color: #555; }
        .progress-bg { height: 6px; background: #eaecf4; border-radius: 10px; overflow: hidden; }
        .progress-bar { height: 100%; transition: 0.5s; }
        .leave-remain { font-size: 11px; color: #888; display: block; margin-top: 3px; }

        /* 🚀 CSS สถิติ OT */
        .ot-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 10px; }
        .ot-stat-item { background: #f8f9fc; padding: 10px; border-radius: 8px; text-align: center; border: 1px solid #eaecf4;}
        .ot-stat-item span { display: block; font-size: 11px; color: #858796; margin-bottom: 5px; }
        .ot-stat-item strong { font-size: 18px; color: #333; }

        /* === การ์ดฝั่งขวา (Tabs System) === */
        .content-card {
            background: white; border-radius: 15px; padding: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .tabs-container {
            background: #f1f5f9;
            padding: 8px;
            border-radius: 12px;
            display: flex;
            gap: 5px;
            margin-bottom: 25px;
            flex-wrap: wrap; 
        }

        .tab-btn {
            flex: 1;
            min-width: 120px;
            background: transparent; border: none; padding: 12px 20px; border-radius: 8px;
            font-family: 'Sarabun', sans-serif; font-size: 15px; font-weight: bold; color: #64748b;
            cursor: pointer; transition: 0.3s;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        
        .tab-btn:hover { color: var(--dark); background: rgba(255,255,255,0.5); }
        .tab-btn.active { background: white; color: var(--primary); box-shadow: 0 2px 8px rgba(0,0,0,0.05); }

        .tab-content { display: none; animation: fadeIn 0.4s ease; }
        .tab-content.active { display: block; }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .section-title { color: var(--dark); font-size: 1.2rem; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid var(--border); }

        .about-list { list-style: none; padding: 0; margin-top: 20px; }
        .about-list li { display: flex; align-items: center; gap: 12px; margin-bottom: 15px; color: #555; font-size: 15px; background: var(--gray-light); padding: 12px 15px; border-radius: 8px; }
        .about-list li i { color: var(--success); font-size: 18px; }

        .news-item {
            display: flex; gap: 20px; align-items: flex-start;
            padding: 20px; border: 1px solid var(--border); border-radius: 12px;
            margin-bottom: 15px; transition: 0.3s;
        }
        .news-item:hover { border-color: var(--primary); box-shadow: 0 5px 15px rgba(78, 115, 223, 0.05); }
        .news-icon { width: 45px; height: 45px; background: #e3fdfd; color: #36b9cc; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .news-info h4 { margin: 0 0 5px 0; color: var(--dark); font-size: 16px; }
        .news-info .date { font-size: 13px; color: var(--gray-text); margin-bottom: 8px; display: block; }
        .news-info p { margin: 0; font-size: 14px; color: #666; line-height: 1.6; }

        .contact-group { margin-bottom: 25px; }
        .contact-group h5 { color: var(--gray-text); margin-bottom: 15px; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; }
        .contact-grid-inner { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; }
        .contact-box {
            display: flex; align-items: center; gap: 15px;
            background: var(--gray-light); padding: 20px; border-radius: 12px; border: 1px solid var(--border);
        }
        .contact-box-icon { width: 50px; height: 50px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); flex-shrink: 0; }
        .contact-box-info h6 { margin: 0 0 5px 0; color: var(--dark); font-size: 15px; }
        .contact-box-info p { margin: 0; color: #666; font-size: 13.5px; }
        .contact-box-info strong { color: var(--primary); font-size: 14px; display: block; margin-top: 5px; }

        /* ========================================== */
        /* CSS ปฏิทินขนาดย่อ (Mini Calendar) ซ้อนในกล่องบริษัท */
        /* ========================================== */
        .mini-calendar-wrapper {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px dashed #eaecf4;
            text-align: left; 
        }
        .cal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .cal-header h4 { margin: 0; color: #2c3e50; font-size: 15px; display: flex; align-items: center; gap: 8px; }
        .btn-nav { border: none; cursor: pointer; color: #4e73df; font-weight: bold; padding: 4px 10px; border-radius: 5px; background: #f0f3ff; transition: 0.3s; font-size: 12px; }
        .btn-nav:hover { background: #e2e8f0; }
        
        .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; margin-top: 10px; }
        .cal-day-name { text-align: center; font-weight: bold; color: #858796; font-size: 12px; padding-bottom: 5px; }
        
        .cal-cell { 
            aspect-ratio: 1; 
            border-radius: 6px; display: flex; flex-direction: column; align-items: center; justify-content: center; 
            font-weight: bold; position: relative; background: #fdfdfd; border: 1px solid #f1f1f1; 
            transition: 0.2s; font-size: 13px; color: #555; cursor: pointer;
        }
        .cal-cell:hover { background: #eaecf4; }
        .cal-sunday { color: #e74a3b; background: #fff5f5; }
        .cal-holiday { background: #ffe5e5 !important; color: #e74a3b !important; }
        .cal-holiday::after { 
            content: ''; position: absolute; bottom: 4px; width: 4px; height: 4px; border-radius: 50%; background: #e74a3b;
        }
        .cal-today { background: #4e73df !important; color: white !important; box-shadow: 0 2px 8px rgba(78,115,223,0.4); border-color: #4e73df; }
    </style>
</head>
<body>

<div class="content-padding">

    <div class="welcome-banner">
        <div style="display:flex; align-items:center; gap:15px;">
            <i class="fa-solid fa-hand-sparkles" style="font-size: 30px; color: #f6c23e;"></i>
            <div>
                <h2 style="margin: 0 0 5px 0; font-size: 1.5rem;">สวัสดีคุณ <?php echo htmlspecialchars($fullname); ?>!</h2>
                <p style="margin: 0; opacity: 0.9; font-size: 14px;">ยินดีต้อนรับเข้าสู่ระบบจัดการทรัพยากรองค์กร (ERP) ของ Top Feed Mills</p>
            </div>
        </div>
    </div>

    <div class="dashboard-grid">
        
        <div class="company-card">
            <div class="company-icon">
                <i class="fa-solid fa-industry"></i>
            </div>
            <h2>Top Feed Mills</h2>
            <p class="sub">บริษัท ท็อป ฟีด มิลล์ จำกัด</p>
            
            <div class="video-box">
                <video autoplay muted loop playsinline>
                    <source src="Project/TopFeed.mp4" type="video/mp4">
                    เบราว์เซอร์ของคุณไม่รองรับการแสดงผลวิดีโอ
                </video>
            </div>
            
            <p class="motto" style="margin-bottom: 0;">"มุ่งมั่นพัฒนาคุณภาพอาหารสัตว์<br>เพื่อการเกษตรที่ยั่งยืน"</p>

            <?php if($my_userid != '' && count($leave_stats) > 0): ?>
            <div class="stats-box">
                <h4 style="margin:0 0 10px 0; font-size:13px; color:#2c3e50;"><i class="fa-solid fa-clock" style="color:#f6c23e;"></i> รายการขอ OT เดือนนี้</h4>
                <div class="ot-grid">
                    <div class="ot-stat-item">
                        <span>รออนุมัติ</span>
                        <strong style="color:#f6c23e;"><?= $ot_summary['Pending'] ?></strong>
                    </div>
                    <div class="ot-stat-item">
                        <span>อนุมัติแล้ว</span>
                        <strong style="color:#1cc88a;"><?= $ot_summary['Approved'] ?></strong>
                    </div>
                    <div class="ot-stat-item">
                        <span>ไม่อนุมัติ</span>
                        <strong style="color:#e74a3b;"><?= $ot_summary['Rejected'] ?></strong>
                    </div>
                </div>
            </div>

            <div class="stats-box">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <h4 style="margin:0; font-size:13px; color:#2c3e50;"><i class="fa-solid fa-chart-pie" style="color:#4e73df;"></i> สิทธิวันลาคงเหลือปี <?= $current_year+543 ?></h4>
                </div>
                
                <?php foreach($leave_stats as $stat): ?>
                <div class="leave-item">
                    <div class="leave-info">
                        <span><?= $stat['label'] ?></span>
                        <span style="color:<?= $stat['color'] ?>;"><?= $stat['used'] ?>/<?= $stat['max'] ?> วัน</span>
                    </div>
                    <div class="progress-bg">
                        <div class="progress-bar" style="width: <?= $stat['percent'] ?>%; background: <?= $stat['color'] ?>;"></div>
                    </div>
                    <span class="leave-remain">คงเหลือ <strong style="color:<?= $stat['color'] ?>;"><?= $stat['remain'] ?></strong> วัน</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="mini-calendar-wrapper">
                <div class="cal-header">
                    <h4><i class="fa-solid fa-calendar-days" style="color:#e74a3b;"></i> ปฏิทินบริษัท</h4>
                    <div>
                        <button type="button" id="btn-prev" class="btn-nav"><i class="fa-solid fa-chevron-left"></i></button>
                        <span id="calendar-title" style="margin: 0 5px; font-weight: bold; font-size: 13px; color: #4e73df;">
                            โหลด...
                        </span>
                        <button type="button" id="btn-next" class="btn-nav"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                </div>
                <div id="calendar-grid" class="cal-grid">
                    </div>
                <div id="holiday-list-container">
                    </div>
            </div>
            
        </div>

        <div class="content-card">
            
            <div class="tabs-container">
                <button class="tab-btn active" onclick="openTab(event, 'tab-home')">
                    <i class="fa-solid fa-building"></i> องค์กรของเรา
                </button>
                <button class="tab-btn" onclick="openTab(event, 'tab-news')">
                    <i class="fa-solid fa-bullhorn"></i> ประกาศข่าวสาร
                </button>
                <button class="tab-btn" onclick="openTab(event, 'tab-contact')">
                    <i class="fa-solid fa-address-book"></i> ติดต่อเรา
                </button>
            </div>

            <div id="tab-home" class="tab-content active">
                <h3 class="section-title">ผู้นำการผลิตอาหารสัตว์คุณภาพ</h3>
                <p style="color: #555; font-size: 15px; line-height: 1.6;">
                    ประกอบกิจการโรงงานอุตสาหกรรมเพื่อผลิตและจำหน่ายอาหารสัตว์ทุกชนิด ด้วยกระบวนการผลิตที่ทันสมัย 
                    และใส่ใจในทุกขั้นตอน เพื่อให้ได้อาหารสำเร็จรูปสำหรับเลี้ยงปศุสัตว์ในฟาร์มที่มีประสิทธิภาพสูงสุด
                </p>
                <ul class="about-list">
                    <li><i class="fa-solid fa-circle-check"></i> <div><strong>คัดสรรวัตถุดิบคุณภาพ</strong><br><small>เลือกใช้วัตถุดิบเกรดเอ เพื่อความปลอดภัยของปศุสัตว์</small></div></li>
                    <li><i class="fa-solid fa-circle-check"></i> <div><strong>ควบคุมโดยผู้เชี่ยวชาญ</strong><br><small>ดูแลสูตรอาหารโดยทีมสัตวบาลและนักโภชนาการสัตว์</small></div></li>
                    <li><i class="fa-solid fa-circle-check"></i> <div><strong>มาตรฐานการผลิตสากล</strong><br><small>โรงงานสะอาด ปลอดภัย ตรวจสอบได้ทุกขั้นตอน</small></div></li>
                </ul>
            </div>

            <div id="tab-news" class="tab-content">
                <h3 class="section-title">ประกาศล่าสุด</h3>            
                <?php
                $check_ann_table = mysqli_query($conn, "SHOW TABLES LIKE 'announcements'");
                if (mysqli_num_rows($check_ann_table) > 0) {
                    $query = "SELECT * FROM announcements ORDER BY announcement_date DESC LIMIT 5";
                    $news_result = mysqli_query($conn, $query);

                    if (mysqli_num_rows($news_result) > 0) {
                        while($news = mysqli_fetch_assoc($news_result)) {
                            $display_date = date('d/m/Y', strtotime($news['announcement_date']));
                ?>
                    <div class="news-item">
                        <div class="news-icon"><i class="fa-regular fa-bell"></i></div>
                        <div class="news-info">
                            <h4><?php echo htmlspecialchars($news['title']); ?></h4>
                            <span class="date"><i class="fa-regular fa-calendar"></i> <?php echo $display_date; ?></span>
                            <p><?php echo nl2br(htmlspecialchars($news['content'])); ?></p>
                        </div>
                    </div>
                <?php 
                        }
                    } else {
                        echo "<div style='text-align:center; padding: 40px; color: #ccc;'><i class='fa-regular fa-folder-open fa-3x'></i><br><br>ยังไม่มีประกาศข่าวสารในขณะนี้</div>";
                    }
                } else {
                    echo "<div style='text-align:center; padding: 40px; color: #ccc;'>ยังไม่มีฐานข้อมูลข่าวสาร</div>";
                }
                ?>
            </div>

            <div id="tab-contact" class="tab-content">
                <h3 class="section-title">ช่องทางการติดต่อ</h3>
                
                <div class="contact-group">
                    <h5>📌 สถานที่ตั้งสำนักงาน / โรงงาน</h5>
                    <div class="contact-box" style="background: white;">
                        <div class="contact-box-icon" style="color: var(--primary); background: #ebf4ff;"><i class="fa-solid fa-map-location-dot"></i></div>
                        <div class="contact-box-info">
                            <h6>บริษัท ท็อป ฟีด มิลล์ จำกัด</h6>
                            <p>32 หมู่ที่ 6 ตำบลหน้าไม้ อำเภอลาดหลุมแก้ว จ.ปทุมธานี 12140</p>
                            <strong><i class="fa-solid fa-phone"></i> 02-194-5678</strong>
                        </div>
                    </div>
                </div>

                <div class="contact-group">
                    <h5>📞 ติดต่อสายตรงภายใน (สำหรับพนักงาน)</h5>
                    <div class="contact-grid-inner">
                        <div class="contact-box">
                            <div class="contact-box-icon" style="color: #36b9cc; background: #e3fdfd;"><i class="fa-solid fa-users"></i></div>
                            <div class="contact-box-info">
                                <h6>ฝ่ายทรัพยากรบุคคล (HR)</h6>
                                <p>เรื่องสวัสดิการ, ลา, OT</p>
                                <strong>ต่อ 112, 113</strong>
                            </div>
                        </div>
                        
                        <div class="contact-box">
                            <div class="contact-box-icon" style="color: var(--success); background: #e8f9f3;"><i class="fa-solid fa-desktop"></i></div>
                            <div class="contact-box-info">
                                <h6>ฝ่ายไอที (IT Support)</h6>
                                <p>แจ้งซ่อมคอมฯ, ปัญหาระบบ</p>
                                <strong>ต่อ 105</strong>
                            </div>
                        </div>
                        
                        <div class="contact-box">
                            <div class="contact-box-icon" style="color: #f6c23e; background: #fdf5df;"><i class="fa-solid fa-truck-fast"></i></div>
                            <div class="contact-box-info">
                                <h6>ฝ่ายจัดซื้อ / คลังสินค้า</h6>
                                <p>ติดตามสินค้า, เช็คสต็อก</p>
                                <strong>ต่อ 120, 121</strong>
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>

        </div>
    </div> 
</div>

<script>
    // สคริปต์สำหรับระบบ Tabs
    function openTab(evt, tabId) {
        let tabContents = document.getElementsByClassName("tab-content");
        for (let i = 0; i < tabContents.length; i++) {
            tabContents[i].classList.remove("active");
        }

        let tabBtns = document.getElementsByClassName("tab-btn");
        for (let i = 0; i < tabBtns.length; i++) {
            tabBtns[i].classList.remove("active");
        }

        document.getElementById(tabId).classList.add("active");
        evt.currentTarget.classList.add("active");
    }

    // ==========================================
    // สคริปต์สำหรับระบบ ปฏิทิน AJAX
    // ==========================================
    let currentMonth = <?= date('m') ?>;
    let currentYear = <?= date('Y') ?>;

    function loadCalendar(m, y) {
        fetch(`index.php?action=get_calendar&m=${m}&y=${y}`)
        .then(response => response.json())
        .then(data => {
            document.getElementById('calendar-grid').innerHTML = data.html;
            document.getElementById('calendar-title').innerText = data.month_text;
            document.getElementById('holiday-list-container').innerHTML = data.holiday_list; 

            document.getElementById('btn-prev').onclick = () => loadCalendar(data.prev_m, data.prev_y);
            document.getElementById('btn-next').onclick = () => loadCalendar(data.next_m, data.next_y);
        })
        .catch(error => {
            console.error('Error loading calendar:', error);
            document.getElementById('calendar-grid').innerHTML = '<div style="grid-column: span 7; text-align: center; font-size: 13px; color: #e74a3b;">Error loading calendar</div>';
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        loadCalendar(currentMonth, currentYear);
    });
</script>

</body>
</html>