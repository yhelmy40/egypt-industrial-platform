<?php
/** مكتبة المعرفة | Knowledge hub list */
$pageTitle = 'مكتبة المعرفة';
?>

<div class="page-head d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="section-title mb-1">مكتبة المعرفة</h2>
        <p class="text-muted small mb-0">إجمالي <?= count($resources) ?> مورد معرفي</p>
    </div>
    <?php if (in_array(Auth::role(), ['admin', 'researcher', 'expert', 'investor'], true)): ?>
        <a href="<?= url('knowledge/create') ?>" class="btn btn-primary">➕ إضافة مورد</a>
    <?php endif; ?>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="<?= url('knowledge') ?>" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small">التصنيف</label>
                <select name="category" class="form-select">
                    <option value="">كل التصنيفات</option>
                    <?php foreach (KnowledgeResource::CATEGORIES as $cat): ?>
                        <option value="<?= $cat ?>" <?= ($filters['category'] ?? '') === $cat ? 'selected' : '' ?>><?= e(knowledge_category_label($cat)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small">القطاع</label>
                <select name="sector_id" class="form-select">
                    <option value="">كل القطاعات</option>
                    <?php foreach ($sectors as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= (int)($filters['sector_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-teal flex-grow-1">تصفية</button>
                <a href="<?= url('knowledge') ?>" class="btn btn-outline-navy">إعادة ضبط</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3">
    <?php if (empty($resources)): ?>
        <div class="col-12"><div class="card"><div class="card-body"><div class="empty-state p-4"><div class="ico">📚</div><p>لا توجد موارد مطابقة.</p></div></div></div></div>
    <?php else: foreach ($resources as $r): ?>
        <div class="col-md-6 col-xl-4">
            <div class="card h-100">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge bg-teal"><?= e(knowledge_category_label($r['category'])) ?></span>
                        <small class="text-muted"><?= fmt_date($r['created_at']) ?></small>
                    </div>
                    <h5 class="text-navy"><?= e($r['title']) ?></h5>
                    <p class="text-muted small flex-grow-1"><?= e(mb_substr($r['description'] ?? '', 0, 140)) ?><?= mb_strlen($r['description'] ?? '') > 140 ? '…' : '' ?></p>
                    <div class="small text-muted mb-2">
                        <?php if (!empty($r['sector_name'])): ?>🏷️ <?= e($r['sector_name']) ?> · <?php endif; ?>
                        👤 <?= e($r['uploader_name'] ?? '—') ?>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if (!empty($r['file_path'])): ?>
                            <a href="<?= base_path() . '/' . ltrim($r['file_path'], '/') ?>" target="_blank" class="btn btn-outline-navy btn-sm">📄 الملف</a>
                        <?php endif; ?>
                        <?php if (!empty($r['link'])): ?>
                            <a href="<?= e($r['link']) ?>" target="_blank" rel="noopener" class="btn btn-outline-navy btn-sm">🔗 الرابط</a>
                        <?php endif; ?>
                        <?php if (Auth::isAdmin()): ?>
                            <form method="post" action="<?= url('knowledge/destroy/' . $r['id']) ?>" class="ms-auto"
                                  data-confirm="حذف هذا المورد؟">
                                <?= Csrf::field() ?>
                                <button class="btn btn-outline-navy btn-sm">🗑</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; endif; ?>
</div>
