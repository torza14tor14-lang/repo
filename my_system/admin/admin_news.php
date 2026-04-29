<?php
session_start();
include '../db.php';

// สเต็ปที่ 1: ตรวจสอบว่า "ล็อกอินหรือยัง?" (ถ้ายัง ให้เด้งไปหน้า login ทันที)
if (empty($_SESSION['userid'])) { 
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location='../login.php';</script>"; 
    exit(); 
}

// ตรวจสอบสิทธิ์ Admin และ Manager
$user_role = $_SESSION['role'] ?? '';
if ($user_role !== 'ADMIN' && $user_role !== 'MANAGER') { 
    echo "<script>alert('ไม่มีสิทธิ์เข้าถึง'); window.location='../index.php';</script>"; exit(); 
}

// 1. จัดการเรื่องการ "ลบข้อมูล"
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete']; // ป้องกัน SQL Injection โดยบังคับเป็นตัวเลข
    $sql = "DELETE FROM announcements WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        header("Location: admin_news.php?status=deleted");
        exit;
    }
}

// 🚀 2. จัดการเรื่องการ "เพิ่มข้อมูล" (พร้อมส่ง LINE Notify)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_news'])) {
    $title = mysqli_real_escape_string($conn, trim($_POST['title']));
    $content = mysqli_real_escape_string($conn, trim($_POST['content']));
    $date = mysqli_real_escape_string($conn, trim($_POST['date']));

    $sql = "INSERT INTO announcements (title, content, announcement_date) VALUES ('$title', '$content', '$date')";
    if (mysqli_query($conn, $sql)) {
        
        // -----------------------------------------------------------------
        // 🚀 แจ้งเตือน LINE: เมื่อมีการประกาศข่าวสารใหม่
        // -----------------------------------------------------------------
        include_once '../line_api.php';
        
        $msg = "📢 ประกาศข่าวสารใหม่จากบริษัท!\n\n";
        $msg .= "📌 หัวข้อ: " . trim($_POST['title']) . "\n";
        $msg .= "🗓️ วันที่: " . date('d/m/Y', strtotime($date)) . "\n\n";
        $msg .= "💬 รายละเอียด:\n" . trim($_POST['content']) . "\n\n";
        $msg .= "พนักงานทุกท่านโปรดรับทราบโดยทั่วกันครับ/ค่ะ";

        sendLineMessage($msg);
        // -----------------------------------------------------------------

        header("Location: admin_news.php?status=added");
        exit;
    }
}

// 3. จัดการเรื่องการ "แก้ไขข้อมูล"
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_news'])) {
    $id = (int)$_POST['edit_id'];
    $title = mysqli_real_escape_string($conn, trim($_POST['edit_title']));
    $content = mysqli_real_escape_string($conn, trim($_POST['edit_content']));
    $date = mysqli_real_escape_string($conn, trim($_POST['edit_date']));

    $sql = "UPDATE announcements SET title='$title', content='$content', announcement_date='$date' WHERE id=$id";
    if (mysqli_query($conn, $sql)) {
        header("Location: admin_news.php?status=updated");
        exit;
    }
}

// หลังจากจัดการ Logic เสร็จแล้ว ถึงค่อยเรียก Sidebar และแสดง HTML
include '../sidebar.php';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Top Feed Mills | ประกาศข่าว</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        .admin-wrapper {
            font-family: 'Sarabun', sans-serif;
            animation: fadeIn 0.5s ease-in-out;
        }

        .news-grid {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 30px;
        }
        @media (max-width: 992px) {
            .news-grid { grid-template-columns: 1fr; }
        }
        
        .card {
            background: #ffffff;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.03);
            border: 1px solid #f0f0f0;
            height: fit-content;
        }

        h3 {
            color: #2c3e50;
            margin-top: 0;
            margin-bottom: 25px;
            font-weight: 600;
            border-bottom: 2px solid #f1f2f6;
            padding-bottom: 15px;
        }

        h3 i { color: #4e73df; margin-right: 8px; }

        .form-group { margin-bottom: 20px; }
        
        label { 
            display: block; 
            margin-bottom: 8px; 
            font-weight: 600; 
            color: #555;
            font-size: 0.95rem;
        }

        input[type="text"], input[type="date"], textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-family: 'Sarabun', sans-serif;
            font-size: 1rem;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        input[type="text"]:focus, input[type="date"]:focus, textarea:focus {
            border-color: #4e73df;
            outline: none;
            box-shadow: 0 0 0 3px rgba(78, 115, 223, 0.15);
        }

        .btn-submit {
            background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: transform 0.2s, box-shadow 0.2s;
            font-family: 'Sarabun', sans-serif;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(78, 115, 223, 0.4);
        }

        .table-responsive { overflow-x: auto; }
        
        table { 
            width: 100%; 
            border-collapse: separate; 
            border-spacing: 0;
            margin-top: 10px; 
        }

        th, td { 
            padding: 15px; 
            border-bottom: 1px solid #f0f0f0; 
            text-align: left; 
            vertical-align: top;
        }

        th { 
            background-color: #f8f9fa; 
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        th:first-child { border-top-left-radius: 10px; border-bottom-left-radius: 10px; }
        th:last-child { border-top-right-radius: 10px; border-bottom-right-radius: 10px; }

        tbody tr { transition: background-color 0.2s ease; }
        tbody tr:hover { background-color: #f8f9fc; }

        .date-badge {
            background-color: #e3e6f0;
            color: #4e73df;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }

        .action-group { display: flex; gap: 8px; flex-wrap: wrap; }

        .btn-edit {
            color: #856404;
            background: #fff3cd;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: bold;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            font-family: 'Sarabun';
        }
        .btn-edit:hover { background: #ffeeba; }

        .btn-delete {
            color: #e74a3b;
            text-decoration: none;
            background: #fceceb;
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: bold;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-delete:hover {
            background: #e74a3b;
            color: white;
        }

        /* Modal สำหรับแก้ไขประกาศ */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(3px); }
        .modal-content { background: white; border-radius: 16px; width: 90%; max-width: 550px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); animation: pop 0.3s ease; }
        @keyframes pop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .modal-header { padding: 20px; border-bottom: 1px solid #eaecf4; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { margin: 0; padding: 0; border: none; }
        .modal-close { cursor: pointer; font-size: 20px; color: #888; transition: 0.2s;}
        .modal-close:hover { color: #e74a3b; }
        .modal-body { padding: 20px; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<div class="content-padding">
    <div class="admin-wrapper">
        <div class="news-grid">
            
            <div class="card">
                <h3><i class="fa-solid fa-pen-to-square"></i> เพิ่มประกาศใหม่</h3>
                <form method="POST">
                    <div class="form-group">
                        <label>หัวข้อข่าวสาร</label>
                        <input type="text" name="title" required placeholder="เช่น แจ้งข่าวสารใหม่...">
                    </div>
                    <div class="form-group">
                        <label>วันที่ประกาศ</label>
                        <input type="date" name="date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label>รายละเอียดเนื้อหา</label>
                        <textarea name="content" rows="6" required placeholder="พิมพ์เนื้อหาข่าวสารที่นี่..."></textarea>
                    </div>
                    <button type="submit" name="add_news" class="btn-submit">
                        <i class="fa-solid fa-paper-plane"></i> ลงประกาศข่าว
                    </button>
                </form>
            </div>

            <div class="card">
                <h3><i class="fa-solid fa-list-ul"></i> รายการประกาศปัจจุบัน</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 20%;">วันที่</th>
                                <th style="width: 55%;">หัวข้อและเนื้อหา</th>
                                <th style="width: 25%;">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $result = mysqli_query($conn, "SELECT * FROM announcements ORDER BY announcement_date DESC, id DESC");
                            if (mysqli_num_rows($result) > 0) {
                                while($row = mysqli_fetch_assoc($result)) {
                                    $date_formatted = date('d/m/Y', strtotime($row['announcement_date']));
                                    
                                    $id = $row['id'];
                                    $title_attr = htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8');
                                    $date_attr = $row['announcement_date'];
                                    $content_attr = htmlspecialchars($row['content'], ENT_QUOTES, 'UTF-8');
                                    
                                    echo "<tr>
                                            <td><span class='date-badge'><i class='fa-regular fa-calendar'></i> {$date_formatted}</span></td>
                                            <td>
                                                <strong style='font-size:1.05rem; color:#2c3e50; display:block; margin-bottom:5px;'>{$row['title']}</strong>
                                                <span style='font-size:0.95rem; color:#666; line-height: 1.5;'>".nl2br(htmlspecialchars(mb_substr($row['content'], 0, 100))).(mb_strlen($row['content']) > 100 ? '...' : '')."</span>
                                            </td>
                                            <td>
                                                <div class='action-group'>
                                                    <button type='button' class='btn-edit' 
                                                        data-id='{$id}' 
                                                        data-title='{$title_attr}' 
                                                        data-date='{$date_attr}' 
                                                        data-content='{$content_attr}' 
                                                        onclick='openEditModal(this)'>
                                                        <i class='fa-solid fa-pen'></i> แก้ไข
                                                    </button>
                                                    <a href='#' class='btn-delete' onclick='confirmDelete({$row['id']})'>
                                                        <i class='fa-solid fa-trash'></i> ลบ
                                                    </a>
                                                </div>
                                            </td>
                                          </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='3' style='text-align:center; color:#999; padding:40px;'><i class='fa-regular fa-folder-open fa-2x'></i><br><br>ยังไม่มีประกาศข่าวสาร</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<div id="editModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fa-solid fa-pen-to-square"></i> แก้ไขประกาศ</h3>
            <div class="modal-close" onclick="closeEditModal()"><i class="fa-solid fa-xmark"></i></div>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="form-group">
                    <label>หัวข้อประกาศ</label>
                    <input type="text" name="edit_title" id="edit_title" required>
                </div>
                <div class="form-group">
                    <label>วันที่ประกาศ</label>
                    <input type="date" name="edit_date" id="edit_date" required>
                </div>
                <div class="form-group">
                    <label>เนื้อหา / รายละเอียด</label>
                    <textarea name="edit_content" id="edit_content" rows="6" required></textarea>
                </div>
                <div style="text-align: right; margin-top: 10px;">
                    <button type="submit" name="edit_news" class="btn-submit" style="width:auto; background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%);">
                        <i class="fa-solid fa-save"></i> บันทึกการแก้ไข
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal(btn) {
        document.getElementById('edit_id').value = btn.getAttribute('data-id');
        document.getElementById('edit_title').value = btn.getAttribute('data-title');
        document.getElementById('edit_date').value = btn.getAttribute('data-date');
        document.getElementById('edit_content').value = btn.getAttribute('data-content');
        document.getElementById('editModal').style.display = 'flex';
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    window.onclick = function(event) {
        let modal = document.getElementById('editModal');
        if (event.target == modal) { closeEditModal(); }
    }

    function confirmDelete(id) {
        Swal.fire({
            title: 'ยืนยันการลบ?',
            text: "คุณต้องการลบประกาศนี้ใช่หรือไม่? ไม่สามารถกู้คืนได้นะ",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e74a3b',
            cancelButtonColor: '#858796',
            confirmButtonText: 'ใช่, ลบเลย!',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '?delete=' + id;
            }
        });
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('status')) {
        const status = urlParams.get('status');
        let alertTitle = '';
        let alertText = '';

        if (status === 'added') { alertTitle = 'สำเร็จ!'; alertText = 'เพิ่มประกาศและส่งแจ้งเตือนเข้า LINE เรียบร้อยแล้ว'; } 
        else if (status === 'deleted') { alertTitle = 'ลบสำเร็จ!'; alertText = 'ประกาศถูกลบออกจากระบบแล้ว'; }
        else if (status === 'updated') { alertTitle = 'แก้ไขสำเร็จ!'; alertText = 'อัปเดตข้อมูลประกาศเรียบร้อยแล้ว'; }

        if(alertTitle != ''){
            Swal.fire({
                icon: 'success',
                title: alertTitle,
                text: alertText,
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                window.history.replaceState(null, null, window.location.pathname);
            });
        }
    }
</script>

</body>
</html>