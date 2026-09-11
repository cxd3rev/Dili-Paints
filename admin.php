<?php
session_start();
require __DIR__ . '/lib.php';

$config = dili_config();
$authed = ($_SESSION['dili_admin'] ?? false) === true;

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $password = (string) ($_POST['password'] ?? '');
    if (hash_equals((string) $config['admin_password'], $password)) {
        $_SESSION['dili_admin'] = true;
        header('Location: admin.php');
        exit;
    }
    $error = 'Onjuist wachtwoord.';
}

if ($authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'status') {
    $id = (string) ($_POST['id'] ?? '');
    $status = (string) ($_POST['status'] ?? 'nieuw');
    if (!in_array($status, ['nieuw', 'contact'], true)) {
        $status = 'nieuw';
    }
    $leads = dili_load_leads();
    foreach ($leads as &$lead) {
        if (($lead['id'] ?? '') === $id) {
            $lead['status'] = $status;
        }
    }
    unset($lead);
    dili_save_leads($leads);
    header('Location: admin.php');
    exit;
}

if ($authed && isset($_GET['export'])) {
    $path = dili_csv_path();
    if (!is_file($path)) {
        dili_save_leads(dili_load_leads());
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="dili-offertes.csv"');
    readfile(dili_csv_path());
    exit;
}

$leads = $authed ? dili_load_leads() : [];
$h = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offertes | Dili Paints</title>
    <link rel="stylesheet" href="main.css">
    <style>
        .admin-wrap { max-width: 1080px; margin: 40px auto 80px; padding: 0 20px; }
        .admin-card { background: #fff; border-radius: 20px; padding: 28px; box-shadow: var(--shadow); }
        .admin-top { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: center; margin-bottom: 22px; }
        .lead { border: 1px solid var(--line); border-radius: 16px; padding: 18px; margin: 0 0 14px; background: var(--paper); }
        .lead-head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
        .lead-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
        .lead-actions a, .lead-actions button { font: inherit; font-size: 0.85rem; font-weight: 700; border-radius: 999px; padding: 8px 12px; text-decoration: none; border: 0; cursor: pointer; }
        .lead-actions a { background: var(--navy); color: #fff; }
        .lead-actions button { background: #fff; color: var(--navy); border: 1px solid var(--line); }
        .muted { color: var(--muted); }
        .badge { font-size: 0.75rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--gold); }
        .login-form { max-width: 360px; display: flex; flex-direction: column; gap: 12px; }
        .login-form input { padding: 12px 14px; border-radius: 12px; border: 1px solid var(--line); font: inherit; }
        .empty { color: var(--muted); padding: 20px 0; }
        .message { white-space: pre-wrap; margin: 10px 0 0; }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="nav-bar">
            <a class="brand" href="index.html">
                <span class="brand-mark"><img src="icon.png" alt="" class="logo"></span>
                <span class="brand-name">Dili Paints</span>
            </a>
            <?php if ($authed): ?>
                <a class="nav-cta" href="admin.php?export=1" style="display:inline-flex">Export CSV</a>
                <a href="admin.php?logout=1">Uitloggen</a>
            <?php endif; ?>
        </div>
    </header>

    <main class="admin-wrap">
        <div class="admin-card">
            <?php if (!$authed): ?>
                <p class="eyebrow">Intern</p>
                <h1>Offertes</h1>
                <p class="muted">Log in om aanvragen te bekijken, te beantwoorden en te exporteren.</p>
                <?php if ($error): ?><p class="form-status error"><?= $h($error) ?></p><?php endif; ?>
                <form class="login-form" method="post">
                    <input type="hidden" name="action" value="login">
                    <label>
                        Wachtwoord
                        <input type="password" name="password" required>
                    </label>
                    <button class="btn btn-primary" type="submit">Open inbox</button>
                </form>
            <?php else: ?>
                <div class="admin-top">
                    <div>
                        <p class="eyebrow">Inbox</p>
                        <h1>Offerteaanvragen</h1>
                        <p class="muted"><?= count($leads) ?> bewaarde aanvraag<?= count($leads) === 1 ? '' : 'en' ?>.</p>
                    </div>
                </div>

                <?php if (!$leads): ?>
                    <p class="empty">Nog geen aanvragen. Zodra iemand het offerteformulier verstuurt, verschijnt die hier.</p>
                <?php endif; ?>

                <?php foreach ($leads as $lead): ?>
                    <?php
                    $mailto = 'mailto:' . rawurlencode($lead['email'] ?? '') .
                        '?subject=' . rawurlencode('Re: uw offerteaanvraag bij Dili Paints') .
                        '&body=' . rawurlencode("Beste " . ($lead['name'] ?? '') . ",\n\nBedankt voor uw aanvraag.\n\n");
                    ?>
                    <article class="lead">
                        <div class="lead-head">
                            <strong><?= $h($lead['name'] ?? '') ?></strong>
                            <span class="badge"><?= $h($lead['status'] ?? 'nieuw') ?> · <?= $h($lead['created_at'] ?? '') ?></span>
                        </div>
                        <div><a href="mailto:<?= $h($lead['email'] ?? '') ?>"><?= $h($lead['email'] ?? '') ?></a></div>
                        <?php if (($lead['phone'] ?? '') !== ''): ?>
                            <div><a href="tel:<?= $h($lead['phone']) ?>"><?= $h($lead['phone']) ?></a></div>
                        <?php endif; ?>
                        <p class="message"><?= $h($lead['message'] ?? '') ?></p>
                        <div class="lead-actions">
                            <a href="<?= $h($mailto) ?>">Beantwoord via e-mail</a>
                            <form method="post">
                                <input type="hidden" name="action" value="status">
                                <input type="hidden" name="id" value="<?= $h($lead['id'] ?? '') ?>">
                                <input type="hidden" name="status" value="<?= ($lead['status'] ?? '') === 'contact' ? 'nieuw' : 'contact' ?>">
                                <button type="submit"><?= ($lead['status'] ?? '') === 'contact' ? 'Markeer als nieuw' : 'Markeer als gecontacteerd' ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
