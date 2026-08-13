# قاموس البيانات | Database Dictionary

نطاق هذا المستند: الجداول المُنفَّذة في **المرحلة الأولى** (16 جدولاً).
تُضاف بقية المجموعات مع مراحلها.

**اتفاقيات عامة:** InnoDB · `utf8mb4_unicode_ci` · `created_at`/`updated_at` على كل
جدول أعمال · `deleted_at` للحذف الناعم حيث يجب الاحتفاظ بالسجل · المبالغ
`DECIMAL(14,2)` (لا `FLOAT` أبداً) · عناوين IP في `VARBINARY(16)` عبر `inet_pton`
· الرموز السرّية تُخزَّن مجزّأة `CHAR(64)` (SHA-256).

---

## المجموعة 1: البيانات المرجعية

### `governorates` — المحافظات (27)
| العمود | النوع | ملاحظات |
|---|---|---|
| `id` | SMALLINT UNSIGNED PK | |
| `code` | VARCHAR(10) UNIQUE | `CAI`, `GIZ`, `ALX` … |
| `name_ar` / `name_en` | VARCHAR(120) | |
| `region` | VARCHAR(60) | الإقليم الجغرافي |
| `is_active`, `sort_order` | | فهرس `(is_active, sort_order)` |

### `cities` — المدن (194)
`governorate_id` → `governorates` (RESTRICT) · فهرس `(governorate_id, is_active)`.

### `sectors` / `sub_sectors` — القطاعات (16 / 106)
`sectors.code` فريد. `sub_sectors.sector_id` → `sectors` (RESTRICT).

### `organization_types` — أنواع المنشآت (6)
`code` فريد: `sme` · `bank` · `ngo` · `service_provider` · `bds_center` · `government`.
النوع يحدّد رحلة التسجيل والأدوار المتاحة داخل المنشأة.

---

## المجموعة 2: الهوية والوصول

### `users` — المستخدمون
| العمود | النوع | ملاحظات |
|---|---|---|
| `email` | VARCHAR(190) UNIQUE | يُخزَّن بحروف صغيرة |
| `password_hash` | VARCHAR(255) | `password_hash()` — لا يُسجَّل ولا يُدقَّق |
| `status` | ENUM | `pending` → `active` \| `suspended` \| `deactivated` |
| `email_verified_at` | DATETIME NULL | شرط الدخول (قابل للضبط) |
| `must_change_password` | TINYINT(1) | يُستخدم لحسابات العرض التجريبي |
| `failed_login_count`, `locked_until` | | قفل على مستوى الحساب |
| `last_login_ip` | VARBINARY(16) | مضغوط |
| `last_organization_id` | FK NULL | تسهيل فقط — تُعاد مطابقة العضوية دائماً |
| `deleted_at` | | حذف ناعم |

**لا يُخزَّن هنا رقم قومي ولا بيانات بنكية** (§10).

### `roles` · `permissions` · `role_permissions` · `user_roles`
- `roles.scope` ∈ {`platform`, `organization`} — أساس التصميم ذي المستويين.
- `permissions.code` بصيغة `module.resource.action`.
- `permissions.is_sensitive` ⇒ تُدقَّق كل ممارسة.
- `permissions.assignable_by_owner` ⇒ يجوز لصاحب المشروع منحها لموظفيه.
- `user_roles` تحمل أدوار المنصة فقط.

### `login_attempts` · `password_resets` · `verification_tokens` · `rate_limits` · `user_consents`
- محاولات الدخول تُسجَّل ناجحةً وفاشلة (بلا كلمة المرور المُجرَّبة).
- رموز الاستعادة والتحقق **مجزّأة**؛ تحمل `expires_at` و`used_at`/`verified_at`.
- `rate_limits(bucket, signature, hit_at)` نافذة منزلقة.
- `user_consents` تحفظ **نسخة السياسة** والوقت وعنوان IP (§10).

---

## المجموعة 3: المنشآت والعضويات

### `organizations` — جذر المستأجر
| العمود | ملاحظات |
|---|---|
| `slug` UNIQUE | معرّف الصفحة العامة `/business/{slug}` — يدعم العربية |
| `status` ENUM | `draft` · `submitted` · `under_review` · `more_info_required` · `verified` · `rejected` · `suspended` |
| `verified_at` / `verified_by` | تُملأ عند الاعتماد فقط |
| `sector_id` · `sub_sector_id` · `governorate_id` · `city_id` | SET NULL |
| `owner_user_id` | المالك الأساسي (موجود أيضاً في العضويات) |
| `completion_score` | 0–100 |
| `is_demo` | يظهر بوسم «بيانات تجريبية» في الواجهة |
| `deleted_at` | حذف ناعم — العضوية في منشأة محذوفة لا تمنح شيئاً |

**كل جدول أعمال في المراحل التالية يحمل `organization_id` يشير إلى هذا الجدول.**

### `organization_members` — العضويات
`UNIQUE(organization_id, user_id)` · `status` ∈ {`invited`,`active`,`suspended`,`removed`}.
**هذا الجدول هو مصدر الحقيقة الوحيد لقرار «هل يحق لهذا المستخدم العمل باسم هذه المنشأة؟»**
ويُقرأ في كل طلب.

### `organization_member_permissions` — تجاوزات فردية
`effect` ∈ {`grant`,`deny`} — **المنع يغلب المنح**. تُقبل فقط الصلاحيات التي
`assignable_by_owner = 1`.

### `organization_invitations`
رمز مجزّأ + `expires_at` + `status` ∈ {`pending`,`accepted`,`expired`,`revoked`}.

---

## المجموعة 4: التدقيق والإعدادات

### `audit_logs` — سجل التدقيق (إلحاقي فقط)
| العمود | ملاحظات |
|---|---|
| `user_id` · `organization_id` | الفاعل وسياقه (NULL = إجراء نظام) |
| `action` | `auth.login` · `org.account.verify` · `order.status_changed` … |
| `category` | `auth`·`rbac`·`verification`·`order`·`application`·`finance`·`document`·`export`·`config`·`record`·`security` |
| `severity` | `info`·`notice`·`warning`·`critical` |
| `entity_type` / `entity_id` | الهدف |
| `changes` | JSON `{before,after}` **بعد التنقيح** (حد 8000 حرف) |
| `ip_address` · `user_agent` · `route` · `method` | سياق الطلب |

لا يوجد مسار تحديث أو حذف من التطبيق. الفهارس على المستخدم والمنشأة والإجراء
والفئة والكيان والتاريخ.

### `system_settings`
`UNIQUE(group_key, setting_key)` · `value_type` يحدّد التحويل عند القراءة ·
`is_public` يسمح بالقراءة دون مصادقة.
المجموعات: `general` · `marketplace` · `privacy` · `matching` · `notifications`.

### `migrations`
سجل الترحيلات المنفّذة مع رقم الدفعة، لدعم التراجع عن آخر دفعة كاملة.

---

## مخطط العلاقات (المرحلة الأولى)

```
governorates ──< cities
sectors ──< sub_sectors
organization_types ──< organizations

users ──< user_roles >── roles ──< role_permissions >── permissions
users ──< organization_members >── organizations
                │
                └──< organization_member_permissions >── permissions

organizations ──< organization_invitations
users ──< login_attempts · password_resets · verification_tokens · user_consents
users · organizations ──< audit_logs
```
