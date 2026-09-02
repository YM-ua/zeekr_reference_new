<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();
require __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    $authError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_login'], $_POST['auth_password'])) {
        if ($_POST['auth_login'] === AUTH_LOGIN && $_POST['auth_password'] === AUTH_PASSWORD) {
            $_SESSION['authenticated'] = true;
            header('Location: index.php');
            exit;
        } else {
            $authError = 'Неверный логин или пароль';
        }
    }
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="robots" content="noindex, nofollow">
        <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><circle cx='50' cy='50' r='45' fill='%23FFD700'/></svg>">
        <title>Вход</title>
    </head>
    <body>
    <h2>Вход</h2>
    <?php if ($authError !== ''): ?>
        <div style="color:red;"><?= htmlspecialchars($authError) ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="text" name="auth_login" placeholder="Логин" required><br><br>
        <input type="password" name="auth_password" placeholder="Пароль" required><br><br>
        <button type="submit">Войти</button>
    </form>
    </body>
    </html>
    <?php
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$pdo = db();
$error = '';
$sort = $_GET['sort'] ?? 'date';
if (!in_array($sort, ['date', 'name', 'cloudflare'], true)) {
    $sort = 'date';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = strtolower(trim((string)($_POST['name'] ?? '')));
            $registrationDate = trim((string)($_POST['registration_date'] ?? ''));
            $periodYears = 1;
            $remindDays = trim((string)($_POST['remind_days'] ?? '30,14,7,1'));
            $registrar = trim((string)($_POST['registrar'] ?? ''));
            $cloudflareAccount = trim((string)($_POST['cloudflare_account'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Укажите имя домена.');
            }

            if ($registrationDate === '') {
                throw new RuntimeException('Укажите дату регистрации.');
            }

            $expiresAt = calculate_expires_from_registration($registrationDate, $periodYears);

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE domains
                    SET name = ?, registration_date = ?, period_years = ?, expires_at = ?, remind_days = ?, registrar = ?, cloudflare_account = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $registrationDate, $periodYears, $expiresAt, $remindDays, $registrar, $cloudflareAccount, $notes, $id]);
                header('Location: index.php?msg=updated');
                exit;
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO domains (name, registration_date, period_years, expires_at, remind_days, registrar, cloudflare_account, notes, active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([$name, $registrationDate, $periodYears, $expiresAt, $remindDays, $registrar, $cloudflareAccount, $notes]);
                header('Location: index.php?msg=added');
                exit;
            }
        }

        if ($action === 'renew') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM domains WHERE id = ?');
            $stmt->execute([$id]);
            $domain = $stmt->fetch();

            if ($domain) {
                $newExpires = calculate_next_expiry_from_current((string)$domain['expires_at'], (int)$domain['period_years']);
                $stmt = $pdo->prepare('UPDATE domains SET expires_at = ?, last_renewed_at = NOW() WHERE id = ?');
                $stmt->execute([$newExpires, $id]);
                header('Location: index.php?msg=renewed');
                exit;
            }
        }

        if ($action === 'deactivate') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare('UPDATE domains SET active = 0 WHERE id = ?')->execute([$id]);
            header('Location: index.php?msg=deactivated');
            exit;
        }

        if ($action === 'activate') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare('UPDATE domains SET active = 1 WHERE id = ?')->execute([$id]);
            header('Location: index.php?msg=activated');
            exit;
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM domains WHERE id = ?')->execute([$id]);
            header('Location: index.php?msg=deleted');
            exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$editDomain = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM domains WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editDomain = $stmt->fetch();
}

$orderByMap = [
    'date' => 'active DESC, expires_at ASC',
    'name' => 'active DESC, name ASC',
    'cloudflare' => 'active DESC, cloudflare_account ASC',
];
$stmt = $pdo->query('SELECT *, DATEDIFF(expires_at, CURDATE()) AS days_left FROM domains ORDER BY ' . $orderByMap[$sort]);
$domains = $stmt->fetchAll();

$messages = [
    'added' => 'Домен добавлен.',
    'updated' => 'Домен обновлён.',
    'renewed' => 'Дата окончания обновлена.',
    'deactivated' => 'Домен отключён.',
    'activated' => 'Домен включён.',
    'deleted' => 'Домен удалён.',
];
$msg = $_GET['msg'] ?? '';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><circle cx='50' cy='50' r='45' fill='%23FFD700'/></svg>">
    <title>Контроль доменов</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1400px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        input, textarea { padding: 8px; width: 100%; box-sizing: border-box; }
        button { padding: 4px 6px; cursor: pointer; font-size: 12px; }
        .actions { white-space: nowrap; }
        .message { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        .form-group { margin-bottom: 5px; }
        .form-group label { display: block; margin-bottom: 5px; }
        .form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .full { grid-column: 1 / -1; }
        .actions form { display: inline-block; margin-right: 3px; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 4px; color: #fff; font-size: 12px; white-space: nowrap; }
        .green { background: #2e7d32; }
        .orange { background: #ef6c00; }
        .red { background: #c62828; }
        .gray { background: #757575; }
        .btn-save { background: #28a745; color: white; border: none; padding: 10px 20px; cursor: pointer; border-radius: 4px; font-size: 16px; }
    </style>
</head>
<body>
<div class="container">
    <h1>DOMAIN CONTROL PANEL <a href="index.php?logout=1" style="font-size:14px; color:red;">Выйти</a></h1>

    <?php if (isset($messages[$msg])): ?>
        <div class="message success"><?= htmlspecialchars($messages[$msg]) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="message error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <h2><?= $editDomain ? 'Редактировать домен' : 'Добавить домен' ?></h2>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $editDomain ? (int)$editDomain['id'] : 0 ?>">

        <div class="form-grid">
            <div class="form-group">
                <label>Домен *</label>
                <input type="text" name="name" required value="<?= $editDomain ? htmlspecialchars($editDomain['name']) : '' ?>">
            </div>

            <div class="form-group">
                <label>Дата регистрации *</label>
                <input type="date" name="registration_date" required value="<?= $editDomain ? htmlspecialchars($editDomain['registration_date']) : '' ?>">
            </div>

            <div class="form-group">
                <label>Напоминать за дни</label>
                <input type="text" name="remind_days" value="<?= $editDomain ? htmlspecialchars($editDomain['remind_days']) : '30,14,7,1' ?>">
            </div>

            <div class="form-group">
                <label>Регистратор</label>
                <input type="text" name="registrar" value="<?= $editDomain ? htmlspecialchars($editDomain['registrar']) : '' ?>">
            </div>

            <div class="form-group">
                <label>Cloudflare</label>
                <input type="text" name="cloudflare_account" value="<?= $editDomain ? htmlspecialchars($editDomain['cloudflare_account']) : '' ?>">
            </div>

            <div class="form-group">
                <label>Заметка</label>
                 <textarea name="notes" rows="1" style="resize: vertical;"><?= $editDomain ? htmlspecialchars($editDomain['notes']) : '' ?></textarea>
            </div>

            <div class="full">
                <button type="submit" class="btn-save">Сохранить</button>
                <?php if ($editDomain): ?>
                    <a href="index.php">Отмена</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <h2>Список доменов
        <span style="font-size: 14px; margin-left: 15px;">
            Сортировка:
            <a href="index.php?sort=date" style="<?= $sort === 'date' ? 'font-weight: bold; color: #000;' : 'color: #888;' ?>">по дате окончания</a>
            |
            <a href="index.php?sort=name" style="<?= $sort === 'name' ? 'font-weight: bold; color: #000;' : 'color: #888;' ?>">по алфавиту</a>
            |
            <a href="index.php?sort=cloudflare" style="<?= $sort === 'cloudflare' ? 'font-weight: bold; color: #000;' : 'color: #888;' ?>">по Cloudflare</a>
        </span>
    </h2>
    <table>
        <tr>
            <th>Домен</th>
            <th>Статус</th>
            <th>Осталось</th>
            <th>Дата окончания</th>
            <th>Регистратор</th>
            <th>Cloudflare</th>
            <th>Действия</th>
            <th style="width: 150px;">Заметка</th>
        </tr>
        <?php foreach ($domains as $domain): ?>
            <?php
            $daysLeft = (int)$domain['days_left'];
            $badgeClass = 'green';
            $statusText = 'Активен';

            if (!$domain['active']) {
                $badgeClass = 'gray';
                $statusText = 'Отключен';
            } elseif ($daysLeft < 0) {
                $badgeClass = 'red';
                $statusText = 'Просрочен';
            } elseif ($daysLeft === 0) {
                $badgeClass = 'red';
                $statusText = 'Истекает сегодня';
            } elseif ($daysLeft <= 30) {
                $badgeClass = 'orange';
                $statusText = 'Скоро истекает';
            }
            ?>
            <tr>
                <td><?= htmlspecialchars($domain['name']) ?></td>
                <td><span class="badge <?= $badgeClass ?>"><?= $statusText ?></span></td>
                <td><?= $domain['active'] ? abs($daysLeft) . ' ' . plural_days($daysLeft) : '—' ?></td>
                <td><?= htmlspecialchars(format_date($domain['expires_at'])) ?></td>
                <td><?= htmlspecialchars($domain['registrar']) ?></td>
                <td><?= htmlspecialchars($domain['cloudflare_account']) ?></td>
                <td class="actions">
                    <form method="post">
                        <input type="hidden" name="action" value="renew">
                        <input type="hidden" name="id" value="<?= (int)$domain['id'] ?>">
                        <button type="submit">Продлён</button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="action" value="<?= $domain['active'] ? 'deactivate' : 'activate' ?>">
                        <input type="hidden" name="id" value="<?= (int)$domain['id'] ?>">
                        <button type="submit"><?= $domain['active'] ? 'Отключить' : 'Включить' ?></button>
                    </form>
                    <form method="get">
                        <input type="hidden" name="edit" value="<?= (int)$domain['id'] ?>">
                        <button type="submit">Изменить</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Удалить домен?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$domain['id'] ?>">
                        <button type="submit">Удалить</button>
                    </form>
                </td>
                <td>
                    <div style="width: 140px; overflow-x: auto; white-space: nowrap; background: #fafafa; border: 1px solid #eee; padding: 4px;"><?= htmlspecialchars($domain['notes']) ?></div>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
</body>
</html>