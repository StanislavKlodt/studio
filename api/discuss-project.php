<?php

header('Content-Type: application/json; charset=UTF-8');
date_default_timezone_set('Europe/Moscow');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array(
        'ok' => false,
        'message' => 'Method Not Allowed',
    ));
    exit;
}

function clean_value($value)
{
    if (is_array($value)) {
        $items = array();
        foreach ($value as $key => $item) {
            $items[] = $key . ': ' . clean_value($item);
        }
        return implode("; \n", $items);
    }

    $value = trim((string) $value);
    return preg_replace('/\s+/u', ' ', $value);
}

function post_value($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function encode_mail_header($text)
{
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

function send_utf8_mail($to, $subjectText, $body, $replyTo)
{
    $headers = array(
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: Klodt Studio <hello@klodt-studio.ru>',
        'Reply-To: ' . $replyTo,
        'X-Mailer: PHP/' . phpversion(),
    );

    return mail(
        $to,
        encode_mail_header($subjectText),
        $body,
        implode("\r\n", $headers)
    );
}

function append_submission_log($payload)
{
    $logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
    $logFile = $logDir . DIRECTORY_SEPARATOR . 'form-submissions.log';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $record = date('c') . ' ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($logFile, $record, FILE_APPEND | LOCK_EX);
}

$name = post_value('name');
$company = post_value('company');
$email = post_value('email');
$phone = post_value('phone');
$message = post_value('message');
$isChecked = isset($_POST['is_checked']) ? 'Да' : 'Нет';
$activity = post_value('activity');
$isExistClient = post_value('is_exist_client');
$utm = isset($_POST['utm']) && is_array($_POST['utm']) ? $_POST['utm'] : array();
$fromPage = isset($utm['from_page']) ? clean_value($utm['from_page']) : '';
$remoteIp = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? clean_value($_SERVER['HTTP_USER_AGENT']) : 'unknown';

$missingFields = array();
if ($name === '') {
    $missingFields[] = 'name';
}
if ($company === '') {
    $missingFields[] = 'company';
}
if ($email === '') {
    $missingFields[] = 'email';
}
if ($phone === '') {
    $missingFields[] = 'phone';
}

if (!empty($missingFields)) {
    http_response_code(422);
    echo json_encode(array(
        'ok' => false,
        'message' => 'Required fields are missing',
        'missing_fields' => $missingFields,
    ));
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(array(
        'ok' => false,
        'message' => 'Invalid email',
    ));
    exit;
}

$pageLabel = 'неизвестная страница';
if ($fromPage !== '') {
    $parsedUrl = parse_url($fromPage);
    if ($parsedUrl !== false && isset($parsedUrl['path'])) {
        $pageLabel = $parsedUrl['path'] !== '' ? $parsedUrl['path'] : '/';
    } else {
        $pageLabel = $fromPage;
    }
}

$subjectText = 'Новая заявка с сайта: ' . $pageLabel;

$lines = array(
    'Новая заявка с сайта klodt-studio.ru',
    '',
    'Страница: ' . ($fromPage !== '' ? $fromPage : 'Не определена'),
    'Имя: ' . clean_value($name),
    'Компания: ' . clean_value($company),
    'Email: ' . clean_value($email),
    'Телефон: ' . clean_value($phone),
    'Сообщение: ' . ($message !== '' ? clean_value($message) : 'Не указано'),
    'Согласие с политикой: ' . $isChecked,
);

if ($activity !== '') {
    $lines[] = 'Активность партнера: ' . clean_value($activity);
}

if ($isExistClient !== '') {
    $lines[] = 'Существующий клиент: ' . clean_value($isExistClient);
}

if (!empty($utm)) {
    $lines[] = '';
    $lines[] = 'UTM и служебные данные:';
    foreach ($utm as $key => $value) {
        $lines[] = $key . ': ' . clean_value($value);
    }
}

$lines[] = '';
$lines[] = 'IP: ' . $remoteIp;
$lines[] = 'User-Agent: ' . $userAgent;
$lines[] = 'Дата: ' . date('Y-m-d H:i:s');

$body = implode("\n", $lines);

$ownerSent = send_utf8_mail('hello@klodt-studio.ru', $subjectText, $body, $email);

$clientBody = implode("\n", array(
    'Здравствуйте, ' . clean_value($name) . '!',
    '',
    'Спасибо за заявку в Klodt Studio.',
    'Мы получили ваше сообщение и свяжемся с вами в ближайшее время.',
    '',
    'Кратко по заявке:',
    'Компания: ' . clean_value($company),
    'Email: ' . clean_value($email),
    'Телефон: ' . clean_value($phone),
    'Сообщение: ' . ($message !== '' ? clean_value($message) : 'Не указано'),
    '',
    'С уважением,',
    'Klodt Studio',
    'https://klodt-studio.ru',
    'hello@klodt-studio.ru',
));

$clientSent = send_utf8_mail(
    $email,
    'Мы получили вашу заявку в Klodt Studio',
    $clientBody,
    'hello@klodt-studio.ru'
);

append_submission_log(array(
    'page' => $fromPage,
    'name' => $name,
    'company' => $company,
    'email' => $email,
    'phone' => $phone,
    'message' => $message,
    'is_checked' => $isChecked,
    'activity' => $activity,
    'is_exist_client' => $isExistClient,
    'utm' => $utm,
    'ip' => $remoteIp,
    'user_agent' => $userAgent,
    'owner_mail_sent' => $ownerSent,
    'client_mail_sent' => $clientSent,
));

if (!$ownerSent) {
    http_response_code(500);
    echo json_encode(array(
        'ok' => false,
        'message' => 'Mail send failed',
    ));
    exit;
}

echo json_encode(array(
    'ok' => true,
    'client_copy_sent' => (bool) $clientSent,
));
