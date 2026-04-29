<?php
// ไฟล์ line_api.php สำหรับส่งข้อความผ่าน LINE Messaging API
function sendLineMessage($message) {
    
    // 1. Channel Access Token ของคุณ
    $access_token = 'HftK4+iLWm9mrJ3TtZoX0U//HNDDErwHihyvRZ6rf35itp1KS5Zs3PP9d+uAUvFd6+sFUxnLboIekR/GEBHPL38SCteQNRia0R3UzT8twlq5cw5brDFm4w2C73xJYNUFWFV11mC6GRFtKQs4KV7r1gdB04t89/1O/w1cDnyilFU=';
    
    // 2. Your User ID ของคุณ
    $user_id = 'Uc9ceda2ac25be1ab2c73f53767b2a1e1';

    // URL ของ LINE API 
    $url = 'https://api.line.me/v2/bot/message/push';
    
    // จัดรูปแบบข้อความที่จะส่ง
    $data = [
        'to' => $user_id,
        'messages' => [
            [
                'type' => 'text',
                'text' => $message
            ]
        ]
    ];
    
    $post = json_encode($data);
    
    $headers = array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token
    );
    
    // ใช้ cURL ในการยิงข้อมูลไปหา LINE
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}
?>