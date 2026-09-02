<?php
declare(strict_types=1);

// Настройки базы данных
define('DB_HOST', 'localhost');
define('DB_NAME', 'domains');
define('DB_USER', 'domains');
define('DB_PASS', 'jN9qO2jQ3r');

// Telegram
define('TELEGRAM_BOT_TOKEN', '5378318992:AAEjYRWm-XsSnx97aC46QPaUYJAiFp0n5rU');
define('TELEGRAM_CHAT_ID', '1016289101');

// Секретный токен для запуска cron.php через браузер
define('CRON_TOKEN', '5378318992:AAEjYRWm');

// Авторизация для доступа к странице
define('AUTH_LOGIN', 'admin');
define('AUTH_PASSWORD', '5787');

// Часовой пояс
define('APP_TIMEZONE', 'Europe/Kiev');