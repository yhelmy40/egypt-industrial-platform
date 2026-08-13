/* =========================================================
   app.js — تفاعلات الواجهة الأمامية
   Egypt Industrial R&D Platform front-end interactions
   ========================================================= */

document.addEventListener('DOMContentLoaded', function () {

    /* ---- تبديل القائمة الجانبية على الجوال | Sidebar toggle (mobile) ---- */
    const burger   = document.getElementById('btnBurger');
    const sidebar  = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('show');
    }
    if (burger && sidebar) {
        burger.addEventListener('click', function () {
            sidebar.classList.toggle('open');
            if (backdrop) backdrop.classList.toggle('show');
        });
    }
    if (backdrop) backdrop.addEventListener('click', closeSidebar);

    /* ---- بحث/تصفية الجداول | Live table search ---- */
    document.querySelectorAll('[data-table-filter]').forEach(function (input) {
        const targetSel = input.getAttribute('data-table-filter');
        const table = document.querySelector(targetSel);
        if (!table) return;
        input.addEventListener('keyup', function () {
            const term = input.value.trim().toLowerCase();
            table.querySelectorAll('tbody tr').forEach(function (row) {
                if (row.classList.contains('no-filter')) return;
                row.style.display = row.textContent.toLowerCase().indexOf(term) > -1 ? '' : 'none';
            });
        });
    });

    /* ---- تأكيد الحذف | Confirm destructive actions ---- */
    document.querySelectorAll('[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(form.getAttribute('data-confirm') || 'هل أنت متأكد؟')) {
                e.preventDefault();
            }
        });
    });

    /* ---- التحقق من النماذج (Bootstrap) | Bootstrap form validation ---- */
    document.querySelectorAll('.needs-validation').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });

    /* ---- إخفاء التنبيهات تلقائياً | Auto-dismiss alerts ---- */
    setTimeout(function () {
        document.querySelectorAll('.alert-dismissible').forEach(function (a) {
            try { bootstrap.Alert.getOrCreateInstance(a).close(); } catch (e) {}
        });
    }, 6000);
});

/* =========================================================
   مساعد الرسوم البيانية | Chart.js helpers (used in dashboard)
   ========================================================= */
window.EGCharts = {
    palette: ['#0b2545', '#0e9594', '#2a9d8f', '#e9c46a', '#f4a261', '#e76f51', '#457b9d', '#6d597a', '#b5838d', '#06d6a0'],

    bar: function (canvasId, labels, data, label) {
        const el = document.getElementById(canvasId);
        if (!el || typeof Chart === 'undefined') return;
        new Chart(el, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{ label: label || '', data: data, backgroundColor: '#0e9594', borderRadius: 6 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    },

    doughnut: function (canvasId, labels, data) {
        const el = document.getElementById(canvasId);
        if (!el || typeof Chart === 'undefined') return;
        new Chart(el, {
            type: 'doughnut',
            data: { labels: labels, datasets: [{ data: data, backgroundColor: this.palette }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { font: { family: 'Tahoma' } } } }
            }
        });
    }
};
