/**
 * منصة رواد النيل — سكربت التطبيق | Application script.
 *
 * جافاسكربت خالص بلا مكتبات ولا أدوات بناء (§6). الحجم صغير عمداً لدعم
 * الاتصالات المحدودة (§16). كل السلوكيات هنا تحسينات تدريجية: الصفحة تعمل
 * كاملةً بدونها.
 * Vanilla JS, no libraries, no bundler. Deliberately small for low-bandwidth
 * connections. Everything here is progressive enhancement — the page works
 * fully without it.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initConfirmations();
        initAutoDismiss();
        initFormGuards();
        initDependentSelects();
        initRepeatableRows();
        initQuantityTotals();
        initAutoSubmit();
    });

    /**
     * قوائم تُرسل نموذجها عند التغيير | Selects that submit on change.
     *
     * لا يُكتب المعالج في سمة `onchange` داخل القالب: سياسة أمن المحتوى تمنع
     * الشيفرة السطرية (`script-src 'self'` بلا `unsafe-inline`)، فالسمة تُحجب
     * ويصبح الاختيار بلا أثر. الربط من هنا يعمل، ويبقى زر `noscript` في القالب
     * هو المخرج لمن يعطّل جافاسكربت.
     * The handler is not written as an inline `onchange`: the CSP forbids
     * inline script, so the attribute would be blocked and the select would do
     * nothing. Binding here works, and the template's noscript button remains
     * the fallback.
     */
    function initAutoSubmit() {
        document.querySelectorAll('select[data-auto-submit]').forEach(function (select) {
            select.addEventListener('change', function () {
                if (select.form) {
                    select.form.submit();
                }
            });
        });
    }

    /** القائمة الجانبية على الشاشات الصغيرة | Mobile sidebar toggle. */
    function initSidebar() {
        var toggle = document.querySelector('[data-sidebar-toggle]');
        var sidebar = document.querySelector('.workspace-sidebar');
        var backdrop = document.querySelector('.sidebar-backdrop');

        if (!toggle || !sidebar) {
            return;
        }

        function setOpen(open) {
            sidebar.classList.toggle('is-open', open);
            if (backdrop) {
                backdrop.classList.toggle('is-visible', open);
            }
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        toggle.addEventListener('click', function () {
            setOpen(!sidebar.classList.contains('is-open'));
        });

        if (backdrop) {
            backdrop.addEventListener('click', function () { setOpen(false); });
        }

        // إغلاق بمفتاح Escape — متطلّب إمكانية وصول لوحة المفاتيح
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
                setOpen(false);
                toggle.focus();
            }
        });
    }

    /** تأكيد الإجراءات الحسّاسة | Confirm destructive actions. */
    function initConfirmations() {
        document.querySelectorAll('[data-confirm]').forEach(function (element) {
            element.addEventListener('click', function (event) {
                var message = element.getAttribute('data-confirm');
                if (message && !window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });
    }

    /** إخفاء رسائل النجاح تلقائياً | Auto-dismiss success alerts. */
    function initAutoDismiss() {
        document.querySelectorAll('.alert-success[role="alert"]').forEach(function (alert) {
            window.setTimeout(function () {
                alert.style.transition = 'opacity .4s ease';
                alert.style.opacity = '0';
                window.setTimeout(function () { alert.remove(); }, 400);
            }, 6000);
        });
    }

    /**
     * منع الإرسال المزدوج | Prevent double submission.
     * مهم للنماذج التي تنشئ سجلات (طلبات، فواتير) حتى لا تتكرر.
     */
    function initFormGuards() {
        document.querySelectorAll('form[data-guard]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var button = form.querySelector('[type="submit"]');
                if (!button || button.disabled) {
                    return;
                }

                // التعطيل مؤجّل حتى لا يُلغى إرسال النموذج في بعض المتصفحات
                window.setTimeout(function () {
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                    if (button.dataset.busyLabel) {
                        button.textContent = button.dataset.busyLabel;
                    }
                }, 0);
            });
        });
    }

    /**
     * القوائم المرتبطة | Dependent selects (governorate → city, sector → sub-sector).
     *
     * تحسين تدريجي: بدون جافاسكربت تبقى القائمة الثانية معبّأة من الخادم بقيم
     * الاختيار المحفوظ، ويظل النموذج قابلاً للإرسال.
     * Progressive enhancement: without JS the dependent select still renders
     * server-side values and the form remains submittable.
     */
    function initDependentSelects() {
        document.querySelectorAll('[data-dependent-target]').forEach(function (source) {
            source.addEventListener('change', function () {
                var target = document.getElementById(source.dataset.dependentTarget);
                if (!target) {
                    return;
                }

                var placeholder = target.options.length ? target.options[0].textContent : '';
                target.disabled = true;

                var url = source.dataset.dependentUrl
                    + '?' + encodeURIComponent(source.dataset.dependentParam)
                    + '=' + encodeURIComponent(source.value);

                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (response) { return response.ok ? response.json() : { data: [] }; })
                    .then(function (payload) {
                        target.innerHTML = '';

                        var blank = document.createElement('option');
                        blank.value = '';
                        blank.textContent = placeholder;
                        target.appendChild(blank);

                        (payload.data || []).forEach(function (item) {
                            var option = document.createElement('option');
                            option.value = item.id;
                            option.textContent = item.name_ar;
                            target.appendChild(option);
                        });
                    })
                    .catch(function () { /* تُترك القائمة كما هي عند فشل الطلب */ })
                    .finally(function () { target.disabled = false; });
            });
        });
    }

    /**
     * صفوف متكرّرة | Repeatable rows (quotation lines).
     *
     * تحسين تدريجي: الخادم يرسم عدداً من الصفوف الفارغة، فيبقى بناء العرض
     * ممكناً بلا جافاسكربت؛ هذا الكود يضيف صفوفاً إضافية عند الحاجة فقط.
     * Progressive enhancement: the server renders several blank rows so a quote
     * can still be built without JS; this only adds more rows on demand.
     */
    function initRepeatableRows() {
        document.querySelectorAll('[data-repeatable]').forEach(function (container) {
            var addButton = container.querySelector('[data-repeat-add]');
            var body = container.querySelector('[data-repeat-body]');

            if (!addButton || !body) {
                return;
            }

            addButton.hidden = false;

            addButton.addEventListener('click', function () {
                var rows = body.querySelectorAll('[data-repeat-row]');
                if (!rows.length || rows.length >= 30) {
                    return;
                }

                var clone = rows[rows.length - 1].cloneNode(true);
                clone.querySelectorAll('input, textarea').forEach(function (field) {
                    if (field.type !== 'hidden') {
                        field.value = field.dataset.repeatDefault || '';
                    }
                    field.removeAttribute('id');
                });
                clone.querySelectorAll('label').forEach(function (label) {
                    label.remove();
                });

                body.appendChild(clone);
                recalculate(container);
            });

            body.addEventListener('click', function (event) {
                var remove = event.target.closest('[data-repeat-remove]');
                if (!remove) {
                    return;
                }

                var rows = body.querySelectorAll('[data-repeat-row]');
                if (rows.length <= 1) {
                    return;
                }

                remove.closest('[data-repeat-row]').remove();
                recalculate(container);
            });
        });
    }

    /**
     * إجمالي تقديري | Client-side total preview.
     *
     * رقم إرشادي فقط؛ الخادم هو من يحسب المبلغ المُلزِم عند الحفظ.
     * Indicative only — the server computes the binding amount on save.
     */
    function initQuantityTotals() {
        document.querySelectorAll('[data-total-scope]').forEach(function (scope) {
            scope.addEventListener('input', function () { recalculate(scope); });
            recalculate(scope);
        });
    }

    function recalculate(scope) {
        var output = scope.querySelector('[data-total-output]');
        if (!output) {
            return;
        }

        var total = 0;

        scope.querySelectorAll('[data-repeat-row]').forEach(function (row) {
            var quantity = parseFloat(row.querySelector('[data-line-quantity]') ? row.querySelector('[data-line-quantity]').value : '0');
            var price = parseFloat(row.querySelector('[data-line-price]') ? row.querySelector('[data-line-price]').value : '0');
            var vat = parseFloat(row.querySelector('[data-line-vat]') ? row.querySelector('[data-line-vat]').value : '0');

            if (isNaN(quantity) || isNaN(price)) {
                return;
            }

            var line = quantity * price;
            total += line + (isNaN(vat) ? 0 : line * vat / 100);
        });

        var delivery = scope.querySelector('[data-delivery-fee]');
        if (delivery) {
            var fee = parseFloat(delivery.value);
            total += isNaN(fee) ? 0 : fee;
        }

        output.textContent = total.toLocaleString('ar-EG', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
})();
