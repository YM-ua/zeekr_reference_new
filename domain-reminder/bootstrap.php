<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if (defined('APP_TIMEZONE')) {
    date_default_timezone_set(APP_TIMEZONE);
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS domains (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                registration_date DATE NOT NULL,
                period_years TINYINT UNSIGNED NOT NULL DEFAULT 1,
                expires_at DATE NOT NULL,
                remind_days VARCHAR(100) NOT NULL DEFAULT '30,14,7,1',
                registrar VARCHAR(190) NOT NULL DEFAULT '',
                cloudflare_account VARCHAR(190) NOT NULL DEFAULT '',
                notes TEXT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                last_renewed_at DATE NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_name (name),
                KEY idx_active_expires (active, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sent_notifications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                domain_id INT UNSIGNED NOT NULL,
                expires_at DATE NOT NULL,
                notification_type VARCHAR(20) NOT NULL,
                reminder_days INT UNSIGNED NOT NULL DEFAULT 0,
                sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_sent (domain_id, expires_at, notification_type, reminder_days),
                KEY idx_domain (domain_id),
                CONSTRAINT fk_sent_domain FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    return $pdo;
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function format_date($date): string
{
    if (!$date) return '';
    try {
        return (new DateTimeImmutable($date))->format('d.m.Y');
    } catch (Throwable $e) {
        return (string)$date;
    }
}

function add_years(DateTimeImmutable $date, int $years): DateTimeImmutable
{
    $year = (int)$date->format('Y') + $years;
    $month = (int)$date->format('m');
    $day = (int)$date->format('d');

    if (!checkdate($month, $day, $year)) {
        $day = 28;
    }

    return $date->setDate($year, $month, $day);
}

function calculate_expires_from_registration(string $registrationDate, int $periodYears): string
{
    if ($periodYears < 1) {
        $periodYears = 1;
    }

    $registration = new DateTimeImmutable($registrationDate);
    $today = new DateTimeImmutable('today');

    $expires = add_years($registration, $periodYears);
    $lastPassed = null;

    while ($expires <= $today) {
        $lastPassed = $expires;
        $expires = add_years($expires, $periodYears);
    }

    if ($lastPassed !== null) {
        return $lastPassed->format('Y-m-d');
    }

    return $expires->format('Y-m-d');
}

function calculate_next_expiry_from_current(string $currentExpiry, int $periodYears): string
{
    if ($periodYears < 1) {
        $periodYears = 1;
    }

    $current = new DateTimeImmutable($currentExpiry);
    return add_years($current, $periodYears)->format('Y-m-d');
}

function parse_remind_days(string $value): array
{
    $parts = explode(',', $value);
    $result = [];

    foreach ($parts as $part) {
        $part = (int)trim($part);
        if ($part >= 0) {
            $result[] = $part;
        }
    }

    $result = array_unique($result);
    sort($result);

    return array_values($result);
}

function plural_days(int $n): string
{
    $n = abs($n);
    $mod10 = $n % 10;
    $mod100 = $n % 100;

    if ($mod10 === 1 && $mod100 !== 11) {
        return 'день';
    }

    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
        return 'дня';
    }

    return 'дней';
}

function send_telegram(string $text): bool
{
    if (TELEGRAM_BOT_TOKEN === 'CHANGE_TELEGRAM_BOT_TOKEN' || TELEGRAM_CHAT_ID === 'CHANGE_TELEGRAM_CHAT_ID') {
        return false;
    }

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $postData = http_build_query([
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $text,
        'parse_mode' => 'HTML',
    ]);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpCode === 200;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'timeout' => 10,
        ],
    ]);

    $result = @file_get_contents($url, false, $context);
    return $result !== false;
}

function notification_already_sent(PDO $pdo, int $domainId, string $expiresAt, string $type, int $reminderDays = 0): bool
{
    $stmt = $pdo->prepare("
        SELECT id FROM sent_notifications
        WHERE domain_id = ? AND expires_at = ? AND notification_type = ? AND reminder_days = ?
        LIMIT 1
    ");
    $stmt->execute([$domainId, $expiresAt, $type, $reminderDays]);
    return (bool)$stmt->fetch();
}

function mark_notification_sent(PDO $pdo, int $domainId, string $expiresAt, string $type, int $reminderDays = 0): void
{
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO sent_notifications (domain_id, expires_at, notification_type, reminder_days)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$domainId, $expiresAt, $type, $reminderDays]);
}

function domain_extra_info(array $domain): string
{
    $info = '';
    if (!empty($domain['registrar'])) {
        $info .= 'Регистратор: ' . e($domain['registrar']) . "\n";
    }
    if (!empty($domain['cloudflare_account'])) {
        $info .= 'Cloudflare: ' . e($domain['cloudflare_account']) . "\n";
    }
    if (!empty($domain['notes'])) {
        $info .= 'Заметка: ' . e($domain['notes']) . "\n";
    }
    return $info;
}

function domain_reminder_text(array $domain, int $days): string
{
    return '⚠️ Домен <b>' . e($domain['name']) . '</b> истекает через ' . $days . ' ' . plural_days($days) . '.' . "\n"
        . 'Дата окончания: ' . format_date($domain['expires_at']) . "\n"
        . domain_extra_info($domain);
}

function domain_today_text(array $domain): string
{
    return '🔴 Домен <b>' . e($domain['name']) . '</b> истекает сегодня.' . "\n"
        . 'Дата окончания: ' . format_date($domain['expires_at']) . "\n"
        . domain_extra_info($domain);
}

function domain_expired_text(array $domain, int $daysOverdue): string
{
    return '🔴 Домен <b>' . e($domain['name']) . '</b> просрочен.' . "\n"
        . 'Дата окончания была: ' . format_date($domain['expires_at']) . "\n"
        . 'Просрочка: ' . $daysOverdue . ' ' . plural_days($daysOverdue) . "\n"
        . domain_extra_info($domain);
}