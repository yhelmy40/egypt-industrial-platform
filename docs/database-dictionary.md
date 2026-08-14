# قاموس البيانات | Database Dictionary

نطاق هذا المستند: الجداول المُنفَّذة حتى **المرحلة الرابعة** (60 جدولاً).
تُضاف بقية المجموعات مع مراحلها.

**اتفاقيات عامة:** InnoDB · `utf8mb4_unicode_ci` · `created_at`/`updated_at` على كل
جدول أعمال · `deleted_at` للحذف الناعم حيث يجب الاحتفاظ بالسجل · المبالغ
`DECIMAL(14,2)` (لا `FLOAT` أبداً) · عناوين IP في `VARBINARY(16)` عبر `inet_pton`
· الرموز السرّية تُخزَّن مجزّأة `CHAR(64)` (SHA-256)، وأعمدة الأسرار كلها
بترتيب `ascii_bin` لتكون المقارنة بايتاً ببايت (ترحيل `0015`).

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

---

## المجموعة 5: الملفات والمستندات والتوثيق (المرحلة الثانية)

### `media` — سجل الوسائط
| العمود | ملاحظات |
|---|---|
| `disk_path` | مسار **خارج جذر الويب**؛ لا يُقدَّم ملف إلا عبر `FileController` |
| `stored_name` | 32 حرفاً عشوائياً — لا صلة له بالاسم الأصلي |
| `mime_type` | من `finfo` لا من المتصفح |
| `visibility` | `public`·`private` — الخاص يستوجب تفويضاً وتُسجَّل قراءته |
| `checksum` | SHA-256 لكشف التكرار والتلف |

### `document_types` · `organization_documents`
أنواع المستندات **بيانات** لا كود: الإدارة تعدّل المطلوب دون نشر إصدار.
كل مستند يحمل حالة مراجعة مستقلة عن حالة المنشأة، وسبب الرفض إلزامي.

### `sme_profiles` · `provider_profiles` · `bds_centers`
ملف تفصيلي لكل نوع منشأة، مفتاحه `organization_id` لا `id`.
`formalization_status` يقبل NULL بمعنى «لم يُحدَّد بعد» (ترحيل `0010`).

### `organization_verifications` — سجل قرارات التوثيق
إلحاقي: كل إجراء (`submit`·`start_review`·`request_info`·`approve`·`reject`·
`suspend`·`reinstate`) صفٌّ جديد بفاعله وسببه. `internal_note` **لا يُعرض للمنشأة**.

### `notifications`
إشعار داخل المنصة لكل مستخدم، مع `entity_type`/`entity_id` وربط اختياري بالمنشأة.

---

## المجموعة 6: الصفحة التعريفية والكتالوج (المرحلة الثالثة)

### `public_pages` — الصفحة العامة للمنشأة
| العمود | ملاحظات |
|---|---|
| `status` | `draft`·`published`·`unpublished` — النشر ممنوع قبل توثيق المنشأة |
| `theme` · `primary_color` | من قائمة مغلقة · `#RRGGBB` مُتحقَّق منه قبل الطباعة في `style` |
| `show_phone`·`show_email`·`show_address`·`show_whatsapp` | **افتراضها 0**: الأقل كشفاً |
| `enable_enquiry_form`·`enable_quote_request` | تحكّم صاحب المنشأة في قنوات الوصول |
| `meta_title`·`meta_description` | ظهور عربي في محركات البحث |
| `view_count` | عدّاد زيارات بسيط |

### `page_sections` — أقسام الصفحة
`UNIQUE(public_page_id, section_type)` و`section_type` من مجموعة مغلقة من تسعة أنواع.
لا يمكن استحداث قسم خارجها؛ `sort_order` يُقصَر على 0–999.

### `page_media` — المعرض والشهادات
`collection` = `gallery`·`certificate`. عرض شهادة هنا **ليس** تصديقاً من المنصة.

### `categories` — تصنيفات السوق
شجرة من مستويين، `UNIQUE(type, code)`، 41 تصنيفاً مبذوراً كبيانات مرجعية.

### `listings` — المنتجات والخدمات
| العمود | ملاحظات |
|---|---|
| `listing_type` | `product`·`service` |
| `pricing_mode` | `fixed` (سعر معروض) · `quote` (اطلب عرض سعر) |
| `price`·`vat_rate`·`vat_included` | `DECIMAL(14,2)` — لا `FLOAT` |
| `track_inventory`·`available_quantity` | الخصم مشروط بـ `available_quantity >= ?` |
| `status` | `draft`·`pending_review`·`published`·`rejected`·`archived` |
| `moderation_note`·`moderated_by`·`moderated_at` | الرفض يستوجب سبباً يصل للمنشأة |
| `rating_average`·`rating_count`·`order_count` | عدّادات مشتقّة تُحدَّث من المصدر |

فهرس `FULLTEXT` على `name_ar`/`short_description`/`description`، وفهرس تصفّح مركّب
`(status, listing_type, category_id, deleted_at)`.

### `listing_images`
`alt_text` متطلّب إمكانية وصول؛ الصور نفسها في `media`.

---

## المجموعة 7: التجارة (المرحلة الثالثة)

### `payment_methods`
أربع وسائل، كلها `driver = 'offline'`: المنصة **لا تنفّذ تحصيلاً إلكترونياً** ولا
تحتفظ ببيانات بطاقات. `requires_proof` يحدّد ما يستوجب إيصالاً.

### `carts` · `cart_items`
سلة الزائر مفتاحها `guest_token` عشوائي (`ascii_bin`)؛ سلة المستخدم مفتاحها
`user_id`. الدمج يتم عند تسجيل الدخول. `UNIQUE(cart_id, listing_id)` يمنع التكرار.
`cart_items.seller_organization_id` مكرَّر عمداً لتسهيل التجميع حسب البائع.

### `orders` — الطلب
| العمود | ملاحظات |
|---|---|
| `order_number` | رقم معروض للعميل، فريد |
| `organization_id` | **المنشأة البائعة** — كل طلب لبائع واحد |
| `tracking_token` | 48 حرفاً عشوائياً، `ascii_bin` — مفتاح الزائر الوحيد |
| `status` | عشر حالات محكومة بآلة حالة في `OrderService` |
| `subtotal`·`vat_amount`·`delivery_fee`·`discount_amount`·`total` | كلها `DECIMAL(14,2)` |
| `payment_status` | `unpaid`·`proof_submitted`·`paid`·`refunded` — يسجّلها البائع |
| `source` | `marketplace`·`storefront`·`quotation` |
| `confirmed_at`·`delivered_at`·`completed_at` | أختام زمنية للحالات المفصلية |

### `order_items`
نسخة **مجمَّدة** من بيانات الصنف وقت الشراء (`name_ar`·`sku`·`unit_price`·`vat_rate`)،
فحذف الإعلان لاحقاً لا يفسد الطلب. `listing_id` يقبل NULL لهذا السبب.

### `order_status_history`
إلحاقي: كل انتقال صفٌّ بفاعله (`seller`·`customer`·`platform`·`system`) وسببه.

### `order_payments`
تسجيل ما دُفع خارج المنصة: مبلغ ومرجع وإثبات اختياري وحالة تحقّق.

---

## المجموعة 8: الاستفسارات والتقييمات والشكاوى (المرحلة الثالثة)

### `customer_enquiries`
استفسار عن صنف بعينه أو عن المنشأة. `source_ip` لكشف الإساءة فقط.
الرد يسجَّل مع فاعله وتاريخه، والحالة تنتقل `new`→`read`→`replied`.

### `quotations` · `quotation_items`
| العمود | ملاحظات |
|---|---|
| `status` | `requested`·`quoted`·`accepted`·`rejected`·`expired`·`withdrawn` |
| `valid_until` | العرض المنقضي يُوسم `expired` ويُرفض قبوله |
| `converted_order_id` | القبول ينشئ طلباً بحالة `confirmed` مباشرة |
| `tracking_token` | 48 حرفاً — متابعة دون حساب |

### `reviews`
`UNIQUE(order_id)` — **تقييم واحد لكل طلب**، ولا تقييم إلا بعد طلب `completed`.
`status` يسمح بالحجب الإداري، والمحجوب لا يدخل في المتوسط.
`seller_reply` يتيح للمنشأة الرد علناً.

### `complaints`
شكوى مرتبطة اختيارياً بمنشأة أو طلب أو إعلان. في هذه المرحلة **شاشة اطّلاع وإحالة**:
لا تُعرض شكوى كـ«محلولة» ما لم يسجّل ذلك مسؤول مختصّ.

---

## مخطط العلاقات (المرحلتان الثانية والثالثة)

```
organizations ──< organization_documents >── document_types
organizations ──1 sme_profiles | provider_profiles | bds_centers
organizations ──< organization_verifications
organizations ──1 public_pages ──< page_sections
                                └──< page_media >── media

categories ──< listings ──< listing_images >── media
organizations ──< listings

carts ──< cart_items >── listings
organizations ──< orders ──< order_items >── listings
                    ├──< order_status_history
                    └──< order_payments >── payment_methods

organizations ──< customer_enquiries >── listings
organizations ──< quotations ──< quotation_items
                      └──1 orders (converted_order_id)
orders ──1 reviews ──> organizations · listings
organizations · orders · listings ──< complaints
```

---

## المجموعة 9: الخدمات المالية (المرحلة الرابعة)

### `financing_products` — المنتجات التمويلية
| العمود | ملاحظات |
|---|---|
| `organization_id` | المؤسسة المالية المالكة |
| `financing_type` | `working_capital`·`asset_finance`·`microfinance`·`trade_finance`·`leasing`·`grant`·`equity`·`other` |
| `min_amount`·`max_amount` | شريحة التمويل — التصفية تطابق الشريحة لا سعراً واحداً |
| `rate_note_ar` | **نص** لا رقم: المنصة لا تحسب أقساطاً ولا تحوّل نسباً |
| `eligibility_summary_ar`·`required_documents_ar` | تُعرض كقوائم، سطر لكل بند |
| `min_years_in_business`·`min_annual_revenue`·`requires_formal_registration` | قواعد ترتيب الاقتراحات — **لا تحجب** منتجاً عن أحد |
| `eligible_governorate_ids`·`eligible_sector_ids` | قوائم معرّفات؛ الفارغ يعني «الكل»، والمطابقة بـ `FIND_IN_SET` لا `LIKE` |
| `status` | `draft`→`pending_review`→`published`، أو `rejected`·`archived` |
| `approved_by`·`approved_at` | **ختم اعتماد المنصة** — يُمسح عند الرفض |
| `is_demo` | يظهر بوسم «بيانات تجريبية» (§15) |

فهرس `FULLTEXT` على الاسم والوصفين، وفهرس تصفّح `(status, financing_type, deleted_at)`.

### `financing_applications` — طلبات التمويل
| العمود | ملاحظات |
|---|---|
| `organization_id` / `provider_organization_id` | الطرفان، وكل جانب يُقيَّد على عموده |
| `product_name_ar` | نسخة مجمّدة وقت التقديم — أرشفة المنتج لا تفسد الطلب |
| `status` | عشر حالات؛ `approved`·`rejected` نهائيتان |
| `screened_by`·`screening_note` | فرز المنصة — الملاحظة **داخلية لا تُعرض للمشروع** |
| `decided_by`·`decided_at`·`decision_note_ar` | **قرار المزوّد وحده**؛ تُكتب في نفس عبارة `approved` |
| `approved_amount`·`approved_tenor_months` | قد يختلفان عمّا طُلب |
| `tracking_token` | 48 حرفاً، `ascii_bin` |

### `financing_application_documents`
المزوّد يطلب مستنداً باسمه والمشروع يرفعه. الملف في `media` بمرئية `private`،
ولا يُقدَّم إلا عبر `FileController` المفوِّض. العمودان `organization_id` و
`provider_organization_id` مكرّران عمداً ليُقيَّد كل طرف على عموده.

### `financing_application_history`
إلحاقي. `note_ar` يراه المشروع، و`internal_note_ar` **لا يراه أبداً** — الحذف يتم في
`FinancingApplicationService::history($id, false)` لا في القالب.

---

## المجموعة 10: الخدمات غير المالية (المرحلة الرابعة)

### `service_offerings` — باقات الخدمات
| العمود | ملاحظات |
|---|---|
| `service_type` | عشرة أنواع من استشارات إلى شهادات جودة |
| `delivery_mode` | `onsite`·`remote`·`hybrid` |
| `pricing_mode` | `fixed`·`range`·`quote`·`free` |
| `funded_by_ar` | **إلزامي عند `free`**: «مجاني» بلا مصدر يوحي بأن المنصة تتحمّل التكلفة |
| `covers_all_governorates`·`governorate_ids` | الباقة العامة لا تختفي عند تحديد محافظة |
| `status`·`approved_by`·`approved_at` | نفس بوابة اعتماد المنتجات التمويلية |

### `service_requests` — طلبات الخدمة
تسع حالات. `proposed_price` و`proposal_note_ar` يكتبهما المزوّد، و`responded_at`
يسجّل ردّ المشروع. `referred_by_user_id`/`referred_by_organization_id` جاهزان لإحالة
مراكز تطوير الأعمال في المرحلة الخامسة.

### `service_milestones` · `service_request_history`
مراحل التنفيذ يديرها المزوّد ويراها الطرفان. السجل إلحاقي بفاعل لكل انتقال.

---

## المجموعة 11: التقييم والمطابقة (المرحلة الرابعة)

### `assessment_questions`
أسئلة تديرها المنصة: `section` من ستة مجالات، `answer_type` = `scale`·`boolean`·
`choice`·`number`·`text`، و`weight` يحدّد ثقل السؤال في درجة مجاله.
الأسئلة **بيانات لا كود**: تعديل الاستبيان لا يحتاج نشر إصدار.

### `needs_assessments` · `assessment_answers`
درجة لكل مجال من 0 إلى 100 و`priority_sections` بأضعف مجالين.
`UNIQUE(assessment_id, question_id)` يمنع ازدواج الإجابة.
الدرجات **معلنة ذاتياً بلا تحقّق مستندي**، وليست تقييماً ائتمانياً.

### `match_suggestions`
| العمود | ملاحظات |
|---|---|
| `target_type`·`target_id` | `financing_product` أو `service_offering` |
| `score` | 0–100، **ترتيب لا حجب** |
| `reasons_ar` | تفسير الاقتراح، سطر لكل سبب؛ الأسطر المبدوءة بـ ⚠ فجوات |
| `status` | `suggested`·`viewed`·`acted`·`dismissed` — والمخفي يبقى مخفياً بعد كل تحديث |

`UNIQUE(organization_id, target_type, target_id)` يجعل إعادة التوليد عملية تحديث لا تكرار.

---

## مخطط العلاقات (المرحلة الرابعة)

```
organizations ──< financing_products >── categories (type=financial)
organizations ──< financing_applications >── financing_products
                        ├──< financing_application_documents >── media
                        └──< financing_application_history

organizations ──< service_offerings >── categories (type=service)
organizations ──< service_requests >── service_offerings
                        ├──< service_milestones
                        └──< service_request_history

assessment_questions ──< assessment_answers >── needs_assessments ──> organizations
organizations ──< match_suggestions ──> financing_products | service_offerings
```

---

## المجموعة 12: حالات مراكز تطوير الأعمال (المرحلة الخامسة)

> **نقطة الإنفاذ في هذه المجموعة هي `BdsCaseRepository` لا القوالب.** كل جدول هنا
> يحمل `organization_id` و`center_organization_id` معاً، والاستعلام يُقيَّد على عمود
> **الجهة القارئة** — فالمشروع لا يبلغ نسخة المركز ولو مرّر `side = 'center'`.

### `bds_cases`
ملفّ الدعم. `case_number` مرجع فريد يُعرض للطرفين.

| العمود | ملاحظات |
|---|---|
| `organization_id`·`center_organization_id` | طرفا الحالة؛ الاستعلام يُقيَّد على أحدهما بحسب الجهة القارئة |
| `assigned_to`·`assigned_at`·`assigned_by` | الأخصائي المسؤول — **يجب أن يكون عضواً نشطاً في المركز نفسه** |
| `status` | تسع حالات: `requested`·`triage`·`assigned`·`in_progress`·`on_hold`·`closed_completed`·`closed_referred`·`closed_unreachable`·`cancelled` |
| `assessment_id` | التقييم الذي بُني عليه الطلب — يبدأ الأخصائي من تشخيص لا من صفحة بيضاء |
| `outcome_summary_ar` | **إلزامي عند الإغلاق**؛ يُكتب في جملة التحديث نفسها التي تكتب حالة الإغلاق |
| `closed_at`·`closed_by` | تُمحى إلى `NULL` عند إعادة الفتح، فلا تبدو الحالة مغلقة لأي استعلام |
| `satisfaction_rating` | تقييم المشروع للخدمة بعد الإغلاق (1–5) |

`cancelled` نهائية بلا إجراء بعدها.

### `bds_case_notes`
`visibility` = `internal` · `shared` — **الفارق بين مساحة عمل الأخصائي وما يصل المشروع.**
الاستعلام الذي يقرأ نيابةً عن المشروع يضيف `visibility = 'shared'` إلى `WHERE`،
فالصفّ الداخلي **لا يُجلب من قاعدة البيانات أصلاً**. والمشروع لا يكتب ملاحظة داخلية:
«داخلية» تعني داخل المركز.

### `bds_consultations`
الجلسة الاستشارية. `mode` = `onsite`·`phone`·`online`، و`status` =
`scheduled`·`completed`·`no_show`·`cancelled`.

- `summary_ar` — يراه المشروع، و**إلزامي حين تُسجَّل الجلسة كمكتملة**.
- `internal_note_ar` — انطباع الأخصائي. **نسخة المشروع لا تحوي هذا العمود إطلاقاً**؛
  الحجب في قائمة أعمدة `SELECT` لا في العرض.

الجدولة والتسجيل خطوتان منفصلتان: الأولى وعد والثانية واقعة، ودمجهما كان سيسمح بتسجيل
جلسة لم تُعقد.

### `bds_action_plans` · `bds_plan_tasks`
`shared_at` نقطة التحوّل: قبلها الخطة مسودة داخلية للمركز، وبعدها التزام معلن.
استعلام المشروع يشترط `shared_at IS NOT NULL`. **خطة بلا مهمة واحدة لا تُشارك.**

`bds_plan_tasks.owner_side` = `organization`·`center` يحدّد **من يحقّ له تحديث حالة
المهمة**؛ تحديث أحد الطرفين مهمة الآخر يزوّر تقدّماً لم يحدث. مهمة خطة غير مشتركة
محجوبة عن المشروع بـ 404.

### `bds_referrals`
| العمود | ملاحظات |
|---|---|
| `target_type`·`target_id` | `financing_product` أو `service_offering` |
| `target_name_ar` | يُنسخ عند الإحالة ليبقى السجلّ مقروءاً لو تغيّر اسم العرض |
| `reason_ar` | **إلزامي** — توصية بلا تعليل لا تساعد المشروع على القرار |
| `status` | `suggested`·`accepted`·`declined` — والقرار للمشروع |

**الإحالة توصية موثّقة لا التزام:** لا تُنشئ طلباً ولا تُلزم الجهة المُحال إليها.
ولا تُوجَّه إلا إلى عرض `published` من منشأة `verified` — نفس حدّ الرؤية العامة.

### `bds_case_history`
`from_status`·`to_status`·`actor_user_id`·`actor_side`·`note_ar`. قيد الإنشاء يحمل
`from_status = NULL`، فسجلّ حالة مرّت بستة انتقالات يحوي سبعة قيود.

---

## مخطط العلاقات (المرحلة الخامسة)

```
organizations (المشروع) ──┐
                          ├──< bds_cases >── users (الأخصائي)
organizations (المركز) ───┘        │
                                   ├──< bds_case_notes        (internal | shared)
                                   ├──< bds_consultations     (summary | internal_note)
                                   ├──< bds_action_plans ──< bds_plan_tasks
                                   ├──< bds_referrals ──> financing_products | service_offerings
                                   └──< bds_case_history

bds_cases ──> needs_assessments        (التشخيص الذي بُني عليه الطلب)
```
