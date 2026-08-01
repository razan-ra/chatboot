<?php
// ============================================================
// api/chat.php — يستقبل النص من app.js ويستدعي Gemini API بأمان
// مفتاح الـ API يبقى على الخادم ولا يصل المتصفح أبدًا.
// ============================================================

// لا نسمح لأي تحذير/خطأ من PHP بالطباعة داخل الاستجابة،
// وإلا اختلط HTML مع JSON وفشل res.json() في المتصفح.
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * ترسل استجابة JSON وتنهي التنفيذ.
 */
function send_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// شبكة أمان: أي خطأ قاتل غير متوقع يخرج كـ JSON وليس كصفحة HTML،
// حتى يعرض المتصفح رسالة مفهومة بدل "حدث خطأ أثناء الاتصال بالخادم".
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'error'   => 'خطأ داخلي في الخادم',
            'details' => $err['message'] . ' @ ' . $err['file'] . ':' . $err['line'],
        ], JSON_UNESCAPED_UNICODE);
    }
});

$configPath = __DIR__ . '/../config.php';
if (!is_file($configPath)) {
    send_json(500, ['error' => 'ملف config.php غير موجود. انسخ config.example.php إلى config.php وضع مفتاحك.']);
}
require $configPath;

// اسمح فقط بطلبات POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    send_json(405, ['error' => 'الطريقة غير مسموحة، استخدم POST']);
}

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    send_json(400, ['error' => 'جسم الطلب ليس JSON صالحًا: ' . json_last_error_msg()]);
}

$prompt = isset($input['prompt']) && is_string($input['prompt']) ? trim($input['prompt']) : '';

if ($prompt === '') {
    send_json(400, ['error' => 'الرجاء إرسال نص صالح في الحقل prompt']);
}

// فحص المفتاح: الفراغ والقيمة الافتراضية كلاهما غير صالح.
if (!defined('GEMINI_API_KEY') || trim(GEMINI_API_KEY) === '' || GEMINI_API_KEY === 'ضع_مفتاحك_هنا') {
    send_json(500, ['error' => 'لم يتم ضبط مفتاح Gemini في config.php بعد']);
}

if (!function_exists('curl_init')) {
    send_json(500, ['error' => 'امتداد cURL غير مفعّل في PHP. فعّل extension=curl في php.ini']);
}

$model = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.0-flash';
$url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

$body = json_encode([
    'contents' => [
        ['parts' => [['text' => $prompt]]],
    ],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    // المفتاح في ترويسة وليس في الرابط، حتى لا يُسجَّل في سجلات الخادم أو الوسطاء.
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-goog-api-key: ' . GEMINI_API_KEY,
    ],
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

// بعض الخوادم لا تضبط شهادات الجذر (cURL error 60): XAMPP على ويندوز،
// وبعض الاستضافات المشتركة على لينكس. نبحث عن حزمة شهادات صالحة
// بدل تعطيل التحقق، فيبقى الاتصال آمنًا على الحالتين.
$caBundle = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
if (!$caBundle || !is_file($caBundle)) {
    $candidates = [
        // لينكس (استضافات مشتركة، Debian/Ubuntu، RHEL/CentOS)
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/cacert.pem',
        // ويندوز / XAMPP
        'C:/xampp/apache/bin/curl-ca-bundle.crt',
        'C:/xampp/php/extras/ssl/cacert.pem',
        // نسخة مرفوعة يدويًا بجوار هذا الملف كحل أخير
        __DIR__ . '/cacert.pem',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            curl_setopt($ch, CURLOPT_CAINFO, $candidate);
            break;
        }
    }
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    send_json(502, ['error' => 'فشل الاتصال بـ Gemini API', 'details' => $curlErr]);
}

$data = json_decode($response, true);

if ($httpCode >= 400) {
    send_json(502, [
        'error'   => 'رفض Gemini API الطلب',
        'status'  => $httpCode,
        'details' => $data['error']['message'] ?? $response,
    ]);
}

$reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

if ($reply === null) {
    // يحدث عادةً عند حجب الرد بسبب فلاتر الأمان.
    send_json(502, [
        'error'   => 'تعذر الحصول على رد من Gemini',
        'details' => $data['candidates'][0]['finishReason'] ?? 'استجابة بصيغة غير متوقعة',
    ]);
}

send_json(200, ['reply' => $reply]);
