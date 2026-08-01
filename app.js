// ============================================================
// app.js — منطق الشات بوت الصوتي (يعمل في المتصفح)
// ============================================================

const micBtn = document.getElementById("micBtn");
const micIcon = document.getElementById("micIcon");
const chatLog = document.getElementById("chatLog");
const statusText = document.getElementById("statusText");

// عنوان الخادم الخلفي الذي يستدعي Gemini بأمان (انظر api/gemini.php)
// مسار نسبي حتى يعمل تلقائيًا مهما كان اسم النطاق على InfinityFree
//
// ملاحظة مهمة: الاسم gemini.php وليس chat.php لأن InfinityFree يحجب
// اسم الملف chat.php بقاعدة أمنية ويرد 403 حتى لو كان الملف سليمًا.
const BACKEND_URL = "api/gemini.php";

// اللغة المستخدمة للتعرف على الصوت وللنطق
const LANG = "ar-SA";

let isListening = false;

// --------------------------------------------------------
// 1) إعداد التعرف على الصوت (Speech-to-Text)
// --------------------------------------------------------
const SpeechRecognitionAPI = window.SpeechRecognition || window.webkitSpeechRecognition;

if (!SpeechRecognitionAPI) {
  statusText.textContent = "متصفحك لا يدعم التعرف على الصوت. جرّب Chrome أو Edge.";
  micBtn.disabled = true;
} else {
  const recognition = new SpeechRecognitionAPI();
  recognition.lang = LANG;
  recognition.interimResults = false;
  recognition.maxAlternatives = 1;

  micBtn.addEventListener("click", () => {
    if (isListening) {
      recognition.stop();
      return;
    }
    try {
      recognition.start();
    } catch (err) {
      console.error("تعذر بدء الاستماع:", err);
    }
  });

  recognition.onstart = () => {
    isListening = true;
    micBtn.classList.add("listening");
    micIcon.textContent = "⏹️";
    statusText.textContent = "أستمع الآن... تحدّث بحرية";
  };

  recognition.onend = () => {
    isListening = false;
    micBtn.classList.remove("listening");
    micIcon.textContent = "🎤";
    statusText.textContent = "اضغط على الميكروفون وابدأ الحديث";
  };

  recognition.onerror = (event) => {
    console.error("خطأ في التعرف على الصوت:", event.error);
    statusText.textContent = "لم أستطع سماعك، حاول مرة أخرى";
  };

  recognition.onresult = async (event) => {
    const userText = event.results[0][0].transcript;
    if (!userText) return;

    addMessage("user", userText);
    const thinkingEl = addMessage("bot", "...يفكر", { thinking: true });

    try {
      const reply = await askGemini(userText);
      thinkingEl.remove();
      addMessage("bot", reply);
      speak(reply);
    } catch (err) {
      console.error(err);
      thinkingEl.remove();
      // نعرض سبب الخطأ الفعلي بدل رسالة عامة، ليسهل تشخيص المشكلة.
      addMessage("bot", `تعذر الاتصال بالخادم: ${err.message}`);
    }
  };
}

// --------------------------------------------------------
// 2) الاتصال بالخادم الخلفي الذي يستدعي Gemini
//    (لا نستدعي Gemini مباشرة من المتصفح لحماية مفتاح الـ API)
// --------------------------------------------------------
async function askGemini(prompt) {
  const res = await fetch(BACKEND_URL, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ prompt }),
  });

  // نقرأ النص أولًا: لو أعاد الخادم HTML (خطأ PHP أو صفحة 404)
  // فإن res.json() سيرمي استثناءً مبهمًا يخفي السبب الحقيقي.
  const text = await res.text();

  let data;
  try {
    data = JSON.parse(text);
  } catch {
    throw new Error(
      `الخادم أعاد استجابة غير متوقعة (${res.status}). ` +
        `تأكد أن المسار ${BACKEND_URL} صحيح. المحتوى: ${text.slice(0, 200)}`
    );
  }

  if (!res.ok || data.error) {
    throw new Error(data.error || `فشل الطلب: ${res.status}`);
  }

  return data.reply || "لم يصل رد من الخادم.";
}

// --------------------------------------------------------
// 3) تحويل النص إلى كلام (Text-to-Speech)
// --------------------------------------------------------
function speak(text) {
  if (!("speechSynthesis" in window)) return;

  window.speechSynthesis.cancel(); // إيقاف أي نطق سابق
  const utterance = new SpeechSynthesisUtterance(text);
  utterance.lang = LANG;
  utterance.rate = 1;
  window.speechSynthesis.speak(utterance);
}

// --------------------------------------------------------
// أدوات مساعدة لواجهة الدردشة
// --------------------------------------------------------
function addMessage(role, text, opts = {}) {
  const el = document.createElement("div");
  el.className = `message ${role}${opts.thinking ? " thinking" : ""}`;
  const p = document.createElement("p");
  p.textContent = text;
  el.appendChild(p);
  chatLog.appendChild(el);
  chatLog.scrollTop = chatLog.scrollHeight;
  return el;
}
