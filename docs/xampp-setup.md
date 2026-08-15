# التشغيل المحلي على XAMPP | Local setup on XAMPP

دليل تشغيل المنصة على جهاز ويندوز باستخدام XAMPP. الخطوات مُجرَّبة فعلياً
على أباتشي حقيقي بكلا التخطيطين المذكورين أدناه.

> ⚠ **الخادم المدمج في PHP لا يكفي للاختبار.** الأمر `php -S` يتجاهل ملفات
> `.htaccess` تجاهلاً تامّاً، فلا يُنفِّذ أيّاً من ضوابط الحماية في هذا
> المستند. أخطاء أوقعت المنصة بالكامل تحت أباتشي كانت غير مرئية إطلاقاً
> تحت `php -S`. اختبر دائماً على أباتشي قبل الاعتماد على أي إعداد.

---

## 1. المتطلّبات

| المتطلَّب | الحدّ الأدنى | التحقّق |
|---|---|---|
| PHP | **8.2** (8.3+ مُستحسن) | `php -v` |
| MySQL / MariaDB | MySQL 8 أو MariaDB 10.6 | `mysql --version` |
| أباتشي | 2.4 مع `mod_rewrite` | `httpd -M \| findstr rewrite` |
| إضافات PHP | `pdo` · `pdo_mysql` · `mbstring` · `json` · `fileinfo` | `php -m` |

### إصدار PHP

الحدّ الأدنى **8.2**، وهو ما تشحنه معظم حِزم XAMPP الحالية، فلا حاجة
لترقية في الغالب. تحقّق قبل أي خطوة أخرى:

```bat
C:\xampp\php\php.exe -v
```

إن ظهر أقدم من 8.2 فنزّل حزمة XAMPP أحدث من موقع Apache Friends، ولا تحاول
تبديل PHP داخل حزمة قائمة.

> كان `composer.json` يشترط `>=8.3` فيرفض التثبيت على 8.2 برسالة
> `Your lock file does not contain a compatible set of packages`. تبيّن أن
> الشيفرة لا تستعمل أي خاصية من خصائص 8.3، فخُفِّض الحدّ إلى 8.2 بعد تشغيل
> مجموعة الاختبارات كاملة (499 اختباراً) على 8.2 و8.4 معاً.

### امتداد GD (اختياري لكن مهم)

مرفق مع XAMPP افتراضياً. بدونه تعمل المنصة، لكن الصور المرفوعة تُخزَّن
**بلا إعادة ترميز** فلا تُسقَط أي حمولة مدسوسة في بياناتها الوصفية.
`php bin\console health` يطبع تنبيهاً أصفر إن كان غائباً. لتفعيله أزل
علامة `;` من سطر `extension=gd` في `C:\xampp\php\php.ini` وأعد تشغيل أباتشي.

### تفعيل mod_rewrite

مفعّل افتراضياً في XAMPP. للتأكّد، افتح `C:\xampp\apache\conf\httpd.conf`
وتحقّق من أن السطر التالي **غير** مسبوق بعلامة `#`:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
```

---

## 2. اختيار التخطيط

أمامك تخطيطان. الأول أكثر أماناً، والثاني أسرع إعداداً.

| | جذر الويب هو `public/` (مضيف افتراضي) | التثبيت في `htdocs/rowad` |
|---|---|---|
| الرابط | `http://nilepreneurs.local` | `http://localhost/rowad` |
| الملفات الحسّاسة | **خارج نطاق الخادم أصلاً** | داخل النطاق، ويحميها `.htaccess` |
| `APP_BASE_PATH` | يُترك فارغاً | `/rowad` |
| التوصية | ✅ المفضَّل | مقبول بشرط بقاء `.htaccess` |

الفرق جوهري: في التخطيط الأول لا يستطيع أباتشي الوصول إلى `.env` مهما حدث،
لأن المجلد ليس ضمن ما يخدمه. في الثاني يستطيع، والمانع الوحيد هو ملف
`.htaccess` في جذر المشروع. **إن حُذف ذلك الملف أو عُطِّل `AllowOverride`،
صار ملف `.env` بكلمات المرور قابلاً للتنزيل من المتصفّح فوراً.**

---

## 3. التثبيت في `htdocs/rowad`

هذا هو التخطيط المطلوب في هذا الدليل.

### 3.1 نسخ المشروع

```bat
cd C:\xampp\htdocs
git clone -b claude/sme-marketplace-mvp-design-9ymw57 ^
  https://github.com/yhelmy40/egypt-industrial-platform.git rowad
cd rowad
composer install --no-dev
```

> ⚠ **حدِّد الفرع.** الفرع الافتراضي `main` يحمل النموذج الأوّلي القديم
> (وهو نفسه محفوظ داخل `legacy/`)، لا المنصّة. الاستنساخ بلا `-b` يعطيك
> الشيفرة القديمة فتبدو المنصّة كأنها لا تعمل.

إن كان مجلد `rowad` موجوداً مسبقاً فلا مشكلة ما دام **فارغاً**؛ وإن كان
يحوي ملفات فاستنسخ باسم آخر ثم انقل المحتويات.

**لماذا `--no-dev`؟** المشروع بلا اعتماديات تشغيل خارجية، فهذا الأمر لا
يُنزّل شيئاً من الإنترنت ويكتفي بتوليد محمّل الأصناف (autoloader) في ثوانٍ.
أما `composer install` بلا `--no-dev` فيجلب PHPUnit وعشرات حِزمه من
الإنترنت، وقد يتوقّف طويلاً أو يفشل خلف جدار حماية. لا تحتاجه إلا إذا أردت
تشغيل الاختبارات (`vendor\bin\phpunit`).

توليد المحمّل **إلزامي** في الحالتين: `public/index.php` يبدأ بـ
`require vendor/autoload.php`، ومن دونه تحصل على صفحة بيضاء. وكومبوزر غير
مرفق مع XAMPP، فنزّله من getcomposer.org.

يجب أن يكون المسار النهائي `C:\xampp\htdocs\rowad\public\index.php`.

### 3.2 إعداد البيئة

```bat
copy .env.example .env
C:\xampp\php\php.exe bin\console key:generate
```

ثم افتح `.env` واضبط:

```dotenv
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost/rowad
APP_BASE_PATH=/rowad

DB_HOST=127.0.0.1
DB_DATABASE=nilepreneurs
DB_USERNAME=root
DB_PASSWORD=
```

`APP_BASE_PATH` هو المفتاح: يقتطعه `Request::resolvePath()` من الطلب الوارد
عند التوجيه، ويضيفه مولّد الروابط إلى كل رابط وأصل صادر. من دونه تُولَّد كل
الروابط بلا البادئة `/rowad` فتُصبح كل صفحة 404 وكل ملف CSS مفقوداً.

**بلا شرطة في النهاية:** `/rowad` وليس `/rowad/`.

### 3.3 قاعدة البيانات

شغّل Apache و MySQL من لوحة تحكّم XAMPP، ثم:

```bat
C:\xampp\php\php.exe bin\console db:create
C:\xampp\php\php.exe bin\console migrate
C:\xampp\php\php.exe bin\console seed
C:\xampp\php\php.exe bin\console health
```

الأمر `health` بوّابة نشر: يخرج برمز 1 عند فشل أي فحص.

### 3.4 التحقّق

افتح `http://localhost/rowad` — يجب أن تظهر الصفحة الرئيسية بالعربية من
اليمين إلى اليسار وبخط Cairo. ثم **تحقّق من الحماية بنفسك**، ولا تفترضها:

| الرابط | المتوقَّع |
|---|---|
| `http://localhost/rowad/.env` | 403 |
| `http://localhost/rowad/vendor/autoload.php` | 404 |
| `http://localhost/rowad/storage/logs/` | 403 |
| `http://localhost/rowad/config/app.php` | 403 |
| `http://localhost/rowad/legacy/public/index.php` | 403 |
| `http://localhost/rowad/app/Core/Kernel.php` | 404 |
| `http://localhost/rowad/app/customers` | 200 (بعد الدخول) |

السطر الأخير ليس زائداً: `/app` بادئة 173 مساراً لمنطقة المستخدم المسجَّل،
وهي في الوقت نفسه اسم مجلد الشيفرة. أي حظر يستهدف الاسم `app` يُسقِط المنصة
كلّها بعد تسجيل الدخول. راجع التعليق المفصّل داخل `.htaccess` قبل تعديله.

**إن ظهر أي من هذه الروابط بمحتوى فعلي، أوقف الخادم فوراً** وتحقّق من وجود
`C:\xampp\htdocs\rowad\.htaccess` ومن أن `AllowOverride All` مضبوط.

---

## 4. البديل الموصى به: مضيف افتراضي

يجعل `public/` جذر الويب، فيخرج كل ما عداه من نطاق الخادم تماماً.

أضف إلى `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName nilepreneurs.local
    DocumentRoot "C:/xampp/htdocs/rowad/public"
    <Directory "C:/xampp/htdocs/rowad/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

أضف إلى `C:\Windows\System32\drivers\etc\hosts` (يتطلّب صلاحيات مدير):

```
127.0.0.1    nilepreneurs.local
```

ثم في `.env`:

```dotenv
APP_URL=http://nilepreneurs.local
APP_BASE_PATH=
```

`APP_BASE_PATH` **فارغ** هنا. أعد تشغيل أباتشي.

---

## 5. أعطال شائعة

| العَرَض | السبب | الحل |
|---|---|---|
| كل الصفحات 500 | `<Directory>` مكتوب داخل `.htaccess` | ممنوع تماماً. الضبط الموضعي يكون بملف `.htaccess` داخل المجلد نفسه |
| الصفحة الرئيسية تعمل وما عداها 404 | `mod_rewrite` معطّل | فعّله في `httpd.conf` وأعد التشغيل |
| كل الروابط والأصول مكسورة | `APP_BASE_PATH` غير مضبوط | اضبطه على `/rowad` |
| 500 بلا سطر في السجلّ | تعذّرت الكتابة في `storage/` | امنح صلاحية الكتابة لمستخدم أباتشي |
| العربية تظهر `?????` | ترميز الاتصال | تأكّد من `DB_CHARSET=utf8mb4` |
| `.env` يُنزَّل من المتصفّح | `.htaccess` مفقود أو `AllowOverride None` | **خطر مباشر** — أوقف الخادم وأصلح فوراً |
| الدخول يعيدك إلى صفحة الدخول | حسابات البذور تفرض تغيير كلمة المرور | استخدم كلمة المرور التجريبية ثم غيّرها |

### قراءة السجلّات

```bat
type C:\xampp\apache\logs\error.log
type C:\xampp\htdocs\rowad\storage\logs\app.log
```

---

## 6. الحسابات التجريبية

تُنشئها `DemoUsersSeeder`، وكلها **للتطوير المحلي فقط**. كل حساب مضبوط على
`must_change_password = 1`، والبذرة نفسها ترفض العمل في بيئة الإنتاج.

| الدور | البريد | كلمة المرور |
|---|---|---|
| مدير المنصة | `admin@nilepreneurs.test` | `DemoAdmin!2026` |
| تشغيل | `ops@nilepreneurs.test` | `DemoOps!2026` |
| محرِّر محتوى | `editor@nilepreneurs.test` | `DemoEditor!2026` |
| منشأة صغيرة | `sme@nilepreneurs.test` | `DemoSme!2026` |
| موظّف منشأة | `employee@nilepreneurs.test` | `DemoEmp!2026` |
| جهة تمويل | `bank@nilepreneurs.test` | `DemoBank!2026` |
| مقدّم خدمة | `provider@nilepreneurs.test` | `DemoProv!2026` |
| جهة داعمة | `ngo@nilepreneurs.test` | `DemoNgo!2026` |
| أخصائي تطوير أعمال | `bds@nilepreneurs.test` | `DemoBds!2026` |
| عميل | `customer@nilepreneurs.test` | `DemoCust!2026` |

> **بيانات تجريبية.** كل المنشآت والمنتجات التمويلية والبرامج المولَّدة
> بالبذور بيانات وهمية لأغراض العرض، وليست عروضاً حقيقية من أي جهة.

---

## 7. قبل أي نشر حقيقي

هذا الدليل للتطوير المحلي فقط. راجع `docs/deployment.md` §11 قبل النشر،
وعلى الأقل: `APP_DEBUG=false`، و`APP_ENV=production`، و`APP_KEY` جديد،
وحساب قاعدة بيانات محدود الصلاحيات بدل `root`، و HTTPS، وحذف الحسابات
التجريبية.
