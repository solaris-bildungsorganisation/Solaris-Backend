# إعدادات الأمان (SEC-04)

## رؤوس الأمان (SecurityHeaders)
- X-Content-Type-Options: nosniff: يمنع المتصفح من تخمين نوع الملف.
- X-Frame-Options: DENY: يمنع تضمين الموقع داخل iframe (حماية من clickjacking).
- Referrer-Policy: no-referrer: ما بيرسل عنوان الصفحة السابقة للمواقع الأخرى.
- Strict-Transport-Security (الإنتاج فقط): max-age=31536000; includeSubDomains، يجبر المتصفح على HTTPS لمدة سنة.

الـ Middleware: app/Http/Middleware/SecurityHeaders.php، مسجّل عالمياً في bootstrap/app.php.

## Rate limiting
القيم في config/solaris.php تحت rate_limits، وتتغير من .env.

| الاسم | المتغير | الافتراضي | الاستخدام | المفتاح |
|---|---|---|---|---|
| login-auth | RATE_LIMIT_LOGIN | 5/دقيقة | تسجيل الدخول | IP + الإيميل |
| code-auth | RATE_LIMIT_CODE | 5/دقيقة | إرسال الرموز (نسيت كلمة المرور) | IP + الإيميل |
| auth-register | RATE_LIMIT_REGISTER | 5/دقيقة | التسجيل | IP |
| api | RATE_LIMIT_API | 60/دقيقة | باقي الـ API | المستخدم أو IP |

السبب: منع تخمين كلمات المرور وإغراق الخدمة. عند التجاوز يرجع 429 مع Retry-After.

## CORS
الملف: config/cors.php.
- allowed_origins تُقرأ من FRONTEND_URLS في .env (نطاقات مفصولة بفاصلة)، ولا يُستعمل *.
- supports_credentials = true لأن الواجهة تستعمل Sanctum.
- exposed_headers = Retry-After ليقرأه المتصفح عند 429.
- السبب: السماح لنطاقات الواجهة المعروفة فقط.
