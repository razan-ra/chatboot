# المساعد الصوتي — شات بوت يعمل بالصوت (Gemini API)

تطبيق ويب بسيط يتيح التحدث بالعربية عبر الميكروفون، يحوّل الكلام إلى نص، يرسله إلى نموذج
Google Gemini عبر وسيط PHP، ثم ينطق الرد صوتيًا.

الواجهة مبنية بـ HTML/CSS/JavaScript خالص، والخلفية ملف PHP واحد يحمي مفتاح الـ API
من الظهور في المتصفح.

# بنية المشروع

chat_bot_exmample/
├── index.html            # واجهة الدردشة
├── style.css             # تنسيقات الواجهة (وضع داكن، RTL)
├── app.js                # التعرّف على الصوت + استدعاء الخلفية + النطق
├── api/
│   └── chat.php          # الوسيط الذي يستدعي Gemini API بأمان
├── config.example.php    # قالب الإعدادات (يُرفع إلى GitHub)
├── config.php            # المفتاح الحقيقي (مستبعد عبر .gitignore)
├── .htaccess             # يمنع فتح config.php من المتصفح
├── .gitignore
└── README.md
```

 كيف يعمل التطبيق

```
المتصفح                          الخادم (PHP)                  Google
────────                        ─────────────                 ────────
Web Speech API
(صوت ← نص)
    │
    │  POST api/chat.php
    │  {"prompt": "..."}
    ├──────────────────────────────►
    │                          يقرأ المفتاح من config.php
    │                          ويستدعي Gemini عبر cURL
    │                                  ├─────────────────────────►
    │                                  ◄─────────────────────────┤
    │  {"reply": "..."}                                   نص الرد
    ◄──────────────────────────────┤
SpeechSynthesis
(نص ← صوت)
```

المفتاح لا يغادر الخادم أبدًا — المتصفح يتحدث مع `api/chat.php` فقط.

---

# الخطوة 1: رفع الملفات على السيرفر

# على سيرفر محلي (XAMPP) — الطريقة المستخدمة هنا

1. تثبيت [XAMPP](https://www.apachefriends.org/) وتشغيل 
Apache من لوحة التحكم
   (لا حاجة لتشغيل MySQL — المشروع لا يستخدم قاعدة بيانات).
2. نسخ مجلد المشروع إلى جذر الويب:

   C:\xampp\htdocs\chat_bot_exmample\

3. فتح المتصفح على:

   ```
   http://localhost/chat_bot_exmample/
   ```

التحقق من جاهزية البيئة — امتداد cURL مطلوب لاستدعاء Gemini:
bash
C:\xampp\php\php.exe -r "echo extension_loaded('curl') ? 'cURL OK' : 'cURL OFF';"
```

إن ظهر `cURL OFF`، افتح `C:\xampp\php\php.ini` وأزل الفاصلة المنقوطة من سطر
`;extension=curl` ثم أعد تشغيل Apache.

# على استضافة خارجية (InfinityFree / cPanel وغيرها)

1. من لوحة التحكم افتح File Manager، أو اتصل عبر FTP ببرنامج مثل FileZilla.
2. ارفع محتويات المشروع داخل مجلد `htdocs/` أو `public_html/` (حسب المستضيف).
3. احرص على رفع مجلد `api/` بكامله — وليس ملفاته في الجذر..
4. تأكد أن الاستضافة تدعم PHP 7.4+ مع تفعيل cURL.

> الميكروفون يتطلب HTTPS. متصفحات Chrome/Edge تسمح بـ Web Speech API فقط عبر
> `https://` أو `http://localhost`. على استضافة خارجية فعّل شهادة SSL، وإلا لن يعمل زر الميكروفون.


# الخطوة 2: المشكلة في كود PHP وكيف حلت

عند الضغط على الميكروفون والتحدث، يتعرف المتصفح على الكلام بنجاح، لكن الرد يكون دائمًا:

«حدث خطأ أثناء الاتصال بالخادم. حاول مجددًا.»

# التشخيص

الرسالة كانت مضلِّلة: نص `catch` عام في `app.js` يبتلع أي خطأ ويعرض العبارة نفسها
مهما كان السبب. باختبار نقطة النهاية مباشرة ظهر السبب الحقيقي — بل أربعة أعطال متتالية:

1. مسار خاطئ — الواجهة تطلب ملفًا غير موجود (404)

`app.js` كان يستدعي:

```js
const BACKEND_URL = "api/chat.php";
```

بينما الملف كان موجودًا في "جذر المشروع" باسم `chat.php` لا داخل مجلد `api/`:

```bash
curl -I http://localhost/chat_bot_exmample/api/chat.php
# HTTP/1.1 404 Not Found
```

فيرجع Apache صفحة HTML بحالة 404، ويفشل `res.json()` في تحليلها → استثناء → رسالة الخطأ العامة.

2. مسار `require` معطوب — خطأ قاتل (Fatal Error)

الملف كُتب أصلاً ليكون داخل `api/`، لذا كان يستدعي الإعدادات بمسار صاعد:

```php
require __DIR__ . '/../config.php';
```

ولأنه وُضع في الجذر، صار المسار يشير إلى `C:\xampp\htdocs\config.php` — وهو غير موجود:

```
Fatal error: Uncaught Error: Failed opening required
'C:\xampp\htdocs\chat_bot_exmample/../config.php' in chat.php:9
```

فينتج "HTML"بدل JSON، ويفشل التحليل في المتصفح.

3. مفتاح API فارغ لا يكتشفه الفحص

```php
define('GEMINI_API_KEY', '');            // config.php — فارغ

// chat.php — يقارن بالقيمة الافتراضية فقط، فيمرّ الفراغ دون اعتراض
if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === 'ضع_مفتاحك_هنا') { ... }
```

فيُرسل الطلب إلى Google بمفتاح فارغ ويُرفض بحالة 400.
4. `.htaccess` بصيغة Apache 2.2 المهجورة

```apache
Order Allow,Deny
Deny from all
```

هذه الصيغة تحتاج `mod_access_compat`. إن كان معطّلاً على Apache 2.4 يرد الخادم
بـ **500 Internal Server Error** على كامل المجلد — بما فيه `index.html`.

# الحل

| # | المشكلة | الإصلاح |
|---|---------|---------|
| 1 | `api/chat.php` غير موجود → 404 | نُقل الملف إلى `api/chat.php` ليطابق ما يطلبه `app.js` وما تصفه تعليقات الكود |
| 2 | `require '../config.php'` يفشل | صار المسار صحيحًا تلقائيًا بعد النقل، مع فحص `is_file()` قبل الاستدعاء |
| 3 | المفتاح الفارغ يمر | أضيف `trim(GEMINI_API_KEY) === ''` إلى شرط الفحص |
| 4 | `.htaccess` قديم | استُبدل بـ `Require all denied` مع تفرّع `<IfModule>` يدعم الإصدارين |

نقل الملف إلى `api/` أصلح العطلين 1 و 2 معًا، لأن الكود كُتب من البداية لهذا الموقع.

تحسينات إضافية على المتانة:

- "JSON دائمًا، مهما حدث" — `display_errors=0` مع `register_shutdown_function` يلتقط أي
  خطأ قاتل ويحوّله إلى JSON، فلا يختلط HTML بالاستجابة مرة أخرى:

  ```php
  register_shutdown_function(function () {
      $err = error_get_last();
      if ($err && in_array($err['type'], [E_ERROR, E_PARSE, ...], true)) {
          http_response_code(500);
          echo json_encode(['error' => 'خطأ داخلي في الخادم', ...]);
      }
  });
  ```

- "رسائل خطأ صادقة في الواجهة" — بدل ابتلاع كل شيء، يقرأ `app.js` النص أولاً ويعرض
  السبب الفعلي:

  ```js
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); }
  catch { throw new Error(`الخادم أعاد استجابة غير متوقعة (${res.status})...`); }
  if (!res.ok || data.error) throw new Error(data.error || `فشل الطلب: ${res.status}`);
  ```

- المفتاح في ترويسة لا في الرابط — كان يُرسل كـ `?key=...` في الـ URL فيُسجَّل في
  سجلات الوصول والوسطاء. صار يُرسل في ترويسة `x-goog-api-key`.

- معالجة شهادات SSL بأمان — بدل تعطيل `CURLOPT_SSL_VERIFYPEER` (وهو ما كانت
  التعليقات القديمة تقترحه)، يُمرَّر ملف الشهادات المرفق مع XAMPP عند غياب `curl.cainfo`،
  فيبقى التحقق مفعّلاً.

- تحقق أدق من المدخلات — فحص `json_last_error()`، والتأكد أن `prompt` نص فعلاً،
  ورسالة واضحة عند تعطّل امتداد cURL أو حجب الرد بفلاتر الأمان.

# نتيجة الاختبار بعد الإصلاح

```bash
# POST صحيح — يصل إلى المنطق ويعيد JSON نظيفًا
$ curl -X POST http://localhost/chat_bot_exmample/api/chat.php \
       -H "Content-Type: application/json" -d '{"prompt":"مرحبا"}'
HTTP 200  {"reply":"..."}

# الطريقة الخاطئة مرفوضة كما ينبغي
$ curl -I http://localhost/chat_bot_exmample/api/chat.php
HTTP 405  {"error":"الطريقة غير مسموحة، استخدم POST"}

# المفتاح محمي من الوصول المباشر
$ curl -I http://localhost/chat_bot_exmample/config.php
HTTP 403 Forbidden
```

---

# الخطوة 3: ضبط مفتاح Gemini

1. احصل على مفتاح مجاني من [Google AI Studio](https://aistudio.google.com/app/apikey).
2. انسخ القالب:

   ```bash
   copy config.example.php config.php
   ```

3. افتح `config.php`وضع المفتاح :

   ```php
   define('GEMINI_API_KEY', 'AIza...');
   define('GEMINI_MODEL', 'gemini-2.0-flash');
   ```

> `config.php` مُدرج في `.gitignore` ومحجوب بـ `.htaccess`، 

#التشغيل والاختبار

1. شغّل Apache من لوحة تحكم XAMPP.
2. افتح http://localhost/chat_bot_exmample/.
3. اضغط زر الميكروفون  واسمح بالوصول عند طلب المتصفح.
4. تحدّث بالعربية — سيظهر كلامك ثم رد البوت، ويُنطق صوتيًا.

# حلّ المشاكل الشائعة

| المشكلة | السبب المحتمل | الحل |

| «لم يتم ضبط مفتاح Gemini» | `config.php` فارغ | ضع مفتاحك في `config.php` |
| «الخادم أعاد استجابة غير متوقعة (404)» | مجلد `api/` لم يُرفع | تأكد من رفع `api/chat.php` |
| «فشل الاتصال بـ Gemini API» + `error 60` | شهادات SSL ناقصة | فعّل `curl.cainfo` في `php.ini` |
| زر الميكروفون معطّل | متصفح غير مدعوم أو HTTP | استخدم Chrome/Edge عبر HTTPS أو localhost |
| «رفض Gemini API الطلب» (400) | مفتاح غير صالح | أنشئ مفتاحًا جديدًا من AI Studio |

افتح DevTools → Console و Network لرؤية الاستجابة الخام عند أي خطأ.

 التقنيات المستخدمة

 الطبقة  التقنية 
 الواجهة  HTML5, CSS3 (RTL, وضع داكن), JavaScript (ES2020) 
 الصوت  Web Speech API — `SpeechRecognition` + `SpeechSynthesis` 
 الخلفية  PHP 8.2 + cURL 
 الذكاء الاصطناعي  Google Gemini API (`gemini-2.0-flash`) 
 الخادم  Apache 2.4 (XAMPP) 

developer: razan alziyadi

لتجربة الشات بوت: http://localhost/chat_bot_exmample/
نسخ الرابط وفتحه في صفحة ويب جديده
