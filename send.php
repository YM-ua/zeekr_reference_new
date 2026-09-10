<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Метод не дозволений']);
    exit;
}

$to          = 'o........@gmail.com';
$tg_token    = '........';
$tg_chat_id  = '......';

$name = isset($_POST['name']) ? htmlspecialchars(trim($_POST['name'])) : '';

// --- Форма запису (phone) ---
if (isset($_POST['phone'])) {

    $phone   = htmlspecialchars(trim($_POST['phone']));
    $message = isset($_POST['message']) ? htmlspecialchars(trim($_POST['message'])) : '';

    if (empty($name) || empty($phone)) {
        echo json_encode(['success' => false, 'message' => 'Заповніть обов\'язкові поля']);
        exit;
    }

    $subject = '=?UTF-8?B?' . base64_encode('Нова заявка з сайту proshivka-zeekr.com.ua') . '?=';
    $body    = "Нова заявка з сайту proshivka-zeekr.com.ua:\n\n";
    $body   .= "Ім'я: {$name}\n";
    $body   .= "Телефон: {$phone}\n";
    if (!empty($message)) {
        $body .= "Повідомлення: {$message}\n";
    }

// --- Контактна форма (email) ---
} elseif (isset($_POST['email'])) {

    // Honeypot
    if (!empty($_POST['website'])) {
        echo json_encode(['success' => false, 'message' => 'Spam detected']);
        exit;
    }

    // Таймер: мінімум 15 секунд
    $loaded_at = isset($_POST['loaded_at']) ? (int)$_POST['loaded_at'] : 0;
    $elapsed   = (int)(microtime(true) * 1000) - $loaded_at;
    if ($loaded_at === 0 || $elapsed < 15000) {
        echo json_encode(['success' => false, 'message' => 'Spam detected']);
        exit;
    }

    $email   = htmlspecialchars(trim($_POST['email']));
    $message = isset($_POST['message']) ? htmlspecialchars(trim($_POST['message'])) : '';

    if (empty($name) || empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Заповніть обов\'язкові поля']);
        exit;
    }

    $subject = '=?UTF-8?B?' . base64_encode('Нове повідомлення з контактної форми proshivka-zeekr.com.ua') . '?=';
    $body    = "Нове повідомлення з контактної форми proshivka-zeekr.com.ua:\n\n";
    $body   .= "Ім'я: {$name}\n";
    $body   .= "Email: {$email}\n";
    if (!empty($message)) {
        $body .= "Повідомлення: {$message}\n";
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Невідомий тип форми']);
    exit;
}

$headers  = "From: noreply@proshivka-zeekr.com.ua\r\n";
$headers .= "Reply-To: noreply@proshivka-zeekr.com.ua\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: base64\r\n";

$result = mail($to, $subject, base64_encode($body), $headers);

// --- Відправка в Телеграм ---
$tg_text = urlencode($body);
file_get_contents("https://api.telegram.org/bot{$tg_token}/sendMessage?chat_id={$tg_chat_id}&text={$tg_text}");

if ($result) {
    echo json_encode(['success' => true, 'message' => 'Повідомлення відправлено']);
} else {
    echo json_encode(['success' => false, 'message' => 'Помилка відправки. Спробуйте пізніше.']);
}
