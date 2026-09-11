<?php
require __DIR__ . '/lib.php';

function dili_wants_json(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requested = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return strpos($accept, 'application/json') !== false || strcasecmp($requested, 'fetch') === 0;
}

function dili_respond(bool $ok, string $message, int $code = 200, bool $mailed = false): void
{
    if (dili_wants_json()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => $ok, 'mailed' => $mailed, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $status = $ok ? 'ok' : 'error';
    header('Location: index.html?status=' . $status . '#contact');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    dili_respond(false, 'Ongeldige aanvraag.', 405);
}

if (trim((string) ($_POST['company_url'] ?? '')) !== '') {
    dili_respond(true, 'Bedankt, uw bericht is verzonden.', 200, true);
}

$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    dili_respond(false, 'Vul naam, een geldig e-mailadres en een bericht in.', 422);
}

if (strlen($name) > 120 || strlen($phone) > 40 || strlen($message) > 8000) {
    dili_respond(false, 'Een of meer velden zijn te lang.', 422);
}

$lead = [
    'id' => bin2hex(random_bytes(8)),
    'created_at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels')))->format('Y-m-d H:i:s'),
    'name' => $name,
    'email' => $email,
    'phone' => $phone,
    'message' => $message,
    'status' => 'nieuw',
];

$stored = dili_add_lead($lead);
$sent = false;
if ($stored) {
    $sent = dili_send_mail($lead, dili_config());
}

if (!$stored) {
    dili_respond(false, 'Het bericht kon niet worden bewaard. Mail of bel ons rechtstreeks.', 500);
}

dili_respond(
    true,
    $sent
        ? 'Bedankt, uw bericht is verzonden. We nemen zo snel mogelijk contact op.'
        : 'Bedankt, uw aanvraag is bewaard. We nemen zo snel mogelijk contact op.',
    200,
    $sent
);
