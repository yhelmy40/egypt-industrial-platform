# منصة رواد النيل لتمكين المشروعات الصغيرة والمتوسطة
# NilePreneurs SME Marketplace & Business Enablement Platform

منصة عربية (RTL) تجمع الحضور الرقمي للمشروعات الصغيرة والمتوسطة، وسوق المنتجات والخدمات،
والوصول إلى الخدمات المالية وغير المالية، ودعم مراكز تطوير الأعمال، وأدوات إدارة يومية
مبسّطة — تحت إدارة **مبادرة رواد النيل**.

An Arabic-first (RTL) platform combining SME digital presence, a products/services
marketplace, financial and non-financial service access, business-development support,
and lightweight CRM/ERP tools — administered by the NilePreneurs initiative.

| | |
|---|---|
| **الإصدار** | `1.0.0-mvp` — المرحلة الأولى (الأساس) مكتملة |
| **المتطلبات** | PHP 8.3+ · MySQL 8 · Apache + mod_rewrite |
| **المعمارية** | MVC مخصّص · PDO بعبارات مُجهَّزة · بلا إطار عمل خارجي |
| **الاعتماديات** | Composer للتحميل التلقائي فقط + PHPUnit (تطوير) |

---

## 1. حالة التنفيذ | Implementation status

| المرحلة | المحتوى | الحالة |
|---|---|---|
| 0 | التخطيط والمعمارية وتصميم قاعدة البيانات | ✅ مكتملة |
| **1** | **الأساس: MVC، المصادقة، الصلاحيات، تعدّد المنشآت، التدقيق، الاختبارات** | ✅ **مكتملة** |
| 2 | تسجيل المنشآت والتوثيق ورفع المستندات | ⏳ التالية |
| 3 | الصفحات التعريفية والسوق والطلبات | ⏳ |
| 4 | الخدمات المالية وغير المالية والمطابقة | ⏳ |
| 5 | إدارة حالات مراكز تطوير الأعمال | ⏳ |
| 6 | إدارة العملاء والموارد المبسّطة | ⏳ |
| 7 | المحتوى والتقارير والتدعيم الأمني والنشر | ⏳ |

---

## 2. التثبيت المحلي | Local installation

```bash
# 1) الاعتماديات | Dependencies
composer install

# 2) البيئة | Environment
cp .env.example .env
php bin/console key:generate        # يولّد APP_KEY
# عدّل بيانات قاعدة البيانات في .env

# 3) قاعدة البيانات | Database
php bin/console db:create
php bin/console migrate

# 4) البيانات المرجعية والتجريبية | Reference + demo data
php bin/console seed

# 5) فحص الجاهزية | Health check
php bin/console health

# 6) التشغيل | Run (development)
php -S 127.0.0.1:8000 -t public
```

ثم افتح `http://127.0.0.1:8000`.

> جذر الويب هو مجلد `public/` فقط. أي إعداد يجعل جذر الموقع هو مجلد المشروع
> يكشف الكود والإعدادات والملفات المرفوعة — راجع `docs/deployment.md`.

---

## 3. أوامر سطر الأوامر | CLI commands

```bash
php bin/console db:create           # إنشاء قاعدة البيانات
php bin/console migrate             # تنفيذ الترحيلات المعلّقة
php bin/console migrate:status      # عرض حالة الترحيلات
php bin/console migrate:rollback    # التراجع عن آخر دفعة
php bin/console migrate:fresh --force   # إعادة بناء كاملة (ممنوعة في الإنتاج)
php bin/console seed                # كل البذور
php bin/console seed --class=SectorsSeeder
php bin/console key:generate        # توليد مفتاح التطبيق
php bin/console health              # فحص الجاهزية
```

---

## 4. حسابات العرض التوضيحي | Demo accounts

> ⚠ **بيانات تجريبية للتطوير فقط.** لا تعمل هذه البذرة في `APP_ENV=production`،
> وكل حساب مُعلَّم بـ `must_change_password`، وكلمات المرور مُدرجة في قائمة المنع
> فلا يمكن إعادة تعيينها كما هي.

| الدور | البريد الإلكتروني | كلمة المرور |
|---|---|---|
| مدير المنصة | `admin@nilepreneurs.test` | `DemoAdmin!2026` |
| مسؤول تشغيل | `ops@nilepreneurs.test` | `DemoOps!2026` |
| محرّر محتوى | `editor@nilepreneurs.test` | `DemoEditor!2026` |
| صاحب مشروع | `sme@nilepreneurs.test` | `DemoSme!2026` |
| موظف بمشروع | `employee@nilepreneurs.test` | `DemoEmp!2026` |
| مسؤول بنك | `bank@nilepreneurs.test` | `DemoBank!2026` |
| مقدّم خدمة | `provider@nilepreneurs.test` | `DemoProv!2026` |
| منظمة أهلية | `ngo@nilepreneurs.test` | `DemoNgo!2026` |
| أخصائي تطوير أعمال | `bds@nilepreneurs.test` | `DemoBds!2026` |
| عميل السوق | `customer@nilepreneurs.test` | `DemoCust!2026` |

كل المنشآت التجريبية موسومة `بيانات تجريبية` ولا تمثّل أي جهة حقيقية.

---

## 5. الاختبارات | Tests

```bash
./vendor/bin/phpunit                      # كل الاختبارات
./vendor/bin/phpunit --testsuite Security # العزل بين المنشآت
./vendor/bin/phpunit --testsuite Feature
./vendor/bin/phpunit --testsuite Unit
```

تعمل الاختبارات على قاعدة منفصلة (`DB_TEST_DATABASE`) تُعاد بناؤها في كل تشغيل،
وكل اختبار داخل معاملة يُتراجع عنها.

---

## 6. بنية المشروع | Project structure

```
app/
  Core/          النواة: التوجيه، الطلب، الاستجابة، العرض، قاعدة البيانات، الترجمة
  Controllers/   متحكّمات رقيقة (بلا SQL ولا قواعد أعمال)
  Repositories/  الوصول للبيانات — العزل بين المنشآت يُفرض هنا
  Services/      قواعد الأعمال والمعاملات وسجل التدقيق
  Middleware/    الجلسة، CSRF، المصادقة، تحديد المنشأة، الصلاحيات، حدّ المعدّل
  Validation/    التحقق من المدخلات على الخادم
  Contracts/     واجهات المزوّدين (بريد، رسائل، دفع مستقبلاً)
  Adapters/      منفّذات قابلة للتبديل عبر الإعدادات
  Views/         قوالب PHP عربية RTL
config/          app · database · security · session · uploads
database/
  migrations/    ترحيلات مرقّمة (up/down)
  seeders/       بيانات مرجعية + بيانات تجريبية منفصلة
public/          جذر الويب: index.php + الأصول المستضافة محلياً
resources/lang/  ar (مكتملة) · en (جاهزة للتوسعة)
storage/         السجلات · المرفوعات (خارج جذر الويب) · التصدير
tests/           Unit · Feature · Security
docs/            التثبيت · النشر · الأدوار · الأمان · قاموس البيانات
```

---

## 7. الوثائق | Documentation

| المستند | المحتوى |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) | المعمارية وقرارات التصميم |
| [`docs/database-dictionary.md`](docs/database-dictionary.md) | قاموس البيانات وشرح العلاقات |
| [`docs/security.md`](docs/security.md) | نموذج الأمان وقائمة المراجعة |
| [`docs/roles-and-permissions.md`](docs/roles-and-permissions.md) | الأدوار ومصفوفة الصلاحيات |
| [`docs/deployment.md`](docs/deployment.md) | النشر على Ubuntu 24.04 والنسخ الاحتياطي والاسترجاع |
| [`docs/phase-1-report.md`](docs/phase-1-report.md) | تقرير تسليم المرحلة الأولى |

---

## 8. ملاحظات مهمة | Important notes

- **المنصة وسيط تقني** ولا تتخذ أي قرار تمويلي. لا يُعرض أي طلب كموافَق عليه إلا
  بتسجيل الجهة المموّلة للقرار.
- **التقارير المالية في وحدة إدارة الموارد تقارير إدارية** وليست مخرجات نظام محاسبي
  معتمد، ويجب مراجعتها من محاسب مؤهّل.
- **لا يُجمع الرقم القومي ولا بيانات الحسابات البنكية** في هذه النسخة.
- صياغات الشروط وسياسة الخصوصية **أولية** وتحتاج مراجعة قانونية قبل الإطلاق الرسمي.
- الشعار المستخدم **عنصر نائب قابل للاستبدال** ولا ينتحل أي هوية رسمية.
