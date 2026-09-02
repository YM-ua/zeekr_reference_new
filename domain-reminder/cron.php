<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = db();
$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    if (!defined('CRON_TOKEN') || CRON_TOKEN === '' || CRON_TOKEN === 'CHANGE_LONG_RANDOM_TOKEN') {
        http_response_code(403);
        echo 'Set CRON_TOKEN in config.php';
        exit;
    }

    $token = (string)($_GET['token'] ?? '');

    if (!hash_equals(CRON_TOKEN, $token)) {
        http_response_code(403);
        echo 'Invalid token';
        exit;
    }
}

$today = new DateTimeImmutable('today');

$domains = $pdo->query('SELECT * FROM domains WHERE active = 1')->fetchAll();

$reports = [];

foreach ($domains as $domain) {
    $expires = new DateTimeImmutable((string)$domain['expires_at']);
    $diff = $today->diff($expires);

    $days = $diff->invert === 1 ? -$diff->days : $diff->days;

    // Домен просрочен
    if ($days < 0) {
        $type = 'expired';

        if (!notification_already_sent($pdo, (int)$domain['id'], (string)$domain['expires_at'], $type, 0)) {
            $text = domain_expired_text($domain, abs($days));

            if (send_telegram($text)) {
                mark_notification_sent($pdo, (int)$domain['id'], (string)$domain['expires_at'], $type, 0);
                $reports[] = 'Sent expired notification for ' . $domain['name'];
            } else {
                $reports[] = 'Failed expired notification for ' . $domain['name'];
            }
        }

        continue;
    }

    // Домен истекает сегодня
    if ($days === 0) {
        $type = 'today';

        if (!notification_already_sent($pdo, (int)$domain['id'], (string)$domain['expires_at'], $type, 0)) {
            $text = domain_today_text($domain);

            if (send_telegram($text)) {
                mark_notification_sent($pdo, (int)$domain['id'], (string)$domain['expires_at'], $type, 0);
                $reports[] = 'Sent today notification for ' . $domain['name'];
            } else {
                $reports[] = 'Failed today notification for ' . $domain['name'];
            }
        }

        continue;
    }

    // Обычные напоминания
    $thresholds = parse_remind_days((string)$domain['remind_days']);
    $threshold = null;

    foreach ($thresholds as $t) {
        if ($days <= $t) {
            $threshold = $t;
            break;
        }
    }

    if ($threshold !== null && !notification_already_sent($pdo, (int)$domain['id'], (string)$domain['expires_at'], 'reminder', $threshold)) {
        $text = domain_reminder_text($domain, $days);

        if (send_telegram($text)) {
            mark_notification_sent($pdo, (int)$domain['id'], (string)$domain['expires_at'], 'reminder', $threshold);
            $reports[] = 'Sent reminder for ' . $domain['name'] . ' (' . $days . ' days)';
        } else {
            $reports[] = 'Failed reminder for ' . $domain['name'] . ' (' . $days . ' days)';
        }
    }
}

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    echo $reports ? implode(PHP_EOL, $reports) : 'OK: no notifications to send.';
} else {
    if ($reports) {
        echo implode(PHP_EOL, $reports) . PHP_EOL;
    }
}