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
    });

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
})();
