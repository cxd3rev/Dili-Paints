<?php

function dili_config(): array
{
    $path = __DIR__ . '/data/config.json';
    $raw = @file_get_contents($path);
    $config = $raw ? json_decode($raw, true) : null;
    if (!is_array($config)) {
        $config = [];
    }

    return array_merge([
        'notify_emails' => ['fadil.vasolli@telenet.be'],
        'from_name' => 'Dili Paints',
        'from_email' => 'fadil.vasolli@telenet.be',
        'admin_password' => 'DiliLeads2026',
        'smtp_host' => '',
        'smtp_port' => 587,
        'smtp_user' => '',
        'smtp_password' => '',
    ], $config);
}

function dili_leads_path(): string
{
    return __DIR__ . '/data/leads.json';
}

function dili_csv_path(): string
{
    return __DIR__ . '/data/leads.csv';
}

function dili_load_leads(): array
{
    $path = dili_leads_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $leads = json_decode($raw, true);
    return is_array($leads) ? $leads : [];
}

function dili_save_leads(array $leads): bool
{
    $dir = dirname(dili_leads_path());
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $jsonOk = file_put_contents(
        dili_leads_path(),
        json_encode(array_values($leads), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    ) !== false;

    $fp = fopen(dili_csv_path(), 'w');
    if ($fp === false) {
        return $jsonOk;
    }
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, ['Datum', 'Naam', 'E-mail', 'Telefoon', 'Bericht', 'Status', 'ID'], ';');
    foreach ($leads as $lead) {
        fputcsv($fp, [
            $lead['created_at'] ?? '',
            $lead['name'] ?? '',
            $lead['email'] ?? '',
            $lead['phone'] ?? '',
            $lead['message'] ?? '',
            $lead['status'] ?? '',
            $lead['id'] ?? '',
        ], ';');
    }
    fclose($fp);

    return $jsonOk;
}

function dili_add_lead(array $lead): bool
{
    $leads = dili_load_leads();
    array_unshift($leads, $lead);
    return dili_save_leads($leads);
}

function dili_http_json(string $url, array $payload): ?array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            ]),
            'content' => $body,
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function dili_send_via_formsubmit(array $lead, array $recipients): bool
{
    $ok = false;
    $payload = [
        'name' => $lead['name'],
        'email' => $lead['email'],
        'phone' => $lead['phone'] !== '' ? $lead['phone'] : '-',
        'message' => $lead['message'],
        '_subject' => 'Nieuwe offerteaanvraag van ' . $lead['name'],
        '_captcha' => 'false',
        '_template' => 'table',
        '_replyto' => $lead['email'],
        '_cc' => 'fadil.vasolli@telenet.be',
    ];
    foreach ($recipients as $to) {
        $result = dili_http_json('https://formsubmit.co/ajax/' . rawurlencode($to), $payload);
        $success = $result['success'] ?? false;
        $message = (string) ($result['message'] ?? '');
        if ($success === true || $success === 'true' || stripos($message, 'activat') !== false) {
            $ok = true;
        }
    }
    return $ok;
}

function dili_send_via_smtp(array $lead, array $config, array $recipients): bool
{
    $host = trim((string) ($config['smtp_host'] ?? ''));
    $user = trim((string) ($config['smtp_user'] ?? ''));
    $password = (string) ($config['smtp_password'] ?? '');
    if ($host === '' || $user === '' || $password === '') {
        return false;
    }

    $port = (int) ($config['smtp_port'] ?? 587);
    $from = $config['from_email'];
    $subject = 'Nieuwe offerteaanvraag van ' . $lead['name'];
    $body = "Nieuwe offerteaanvraag via de website\n\n";
    $body .= 'Datum: ' . $lead['created_at'] . "\nNaam: " . $lead['name'] . "\n";
    $body .= 'E-mail: ' . $lead['email'] . "\nTelefoon: " . ($lead['phone'] !== '' ? $lead['phone'] : '-') . "\n\n";
    $body .= "Bericht:\n" . $lead['message'] . "\n";

    $headers = 'From: ' . $config['from_name'] . ' <' . $from . ">\r\n";
    $headers .= 'Reply-To: ' . $lead['email'] . "\r\n";
    $headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";

    $to = implode(', ', $recipients);
    ini_set('SMTP', $host);
    ini_set('smtp_port', (string) $port);
    ini_set('sendmail_from', $from);

    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

function dili_send_mail(array $lead, array $config): bool
{
    $recipients = array_values(array_filter($config['notify_emails'] ?? []));
    if (!$recipients) {
        return false;
    }

    $targets = array_values(array_filter($config['formsubmit_ids'] ?? []));
    foreach ($recipients as $email) {
        if ($email !== '' && !in_array($email, $targets, true)) {
            $targets[] = $email;
        }
    }
    if (dili_send_via_formsubmit($lead, $targets)) {
        return true;
    }
    if (dili_send_via_smtp($lead, $config, $recipients)) {
        return true;
    }

    $to = implode(', ', $recipients);
    $subject = 'Nieuwe offerteaanvraag van ' . $lead['name'];
    $body = "Nieuwe offerteaanvraag via de website\n\n";
    $body .= 'Datum: ' . $lead['created_at'] . "\nNaam: " . $lead['name'] . "\n";
    $body .= 'E-mail: ' . $lead['email'] . "\nTelefoon: " . ($lead['phone'] !== '' ? $lead['phone'] : '-') . "\n\n";
    $body .= "Bericht:\n" . $lead['message'] . "\n";
    $headers = 'From: ' . $config['from_name'] . ' <' . $config['from_email'] . ">\r\n";
    $headers .= 'Reply-To: ' . $lead['email'] . "\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to, $subject, $body, $headers);
}
