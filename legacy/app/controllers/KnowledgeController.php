<?php
/** متحكم مكتبة المعرفة | Knowledge hub controller */
class KnowledgeController extends Controller
{
    private const ALLOWED_EXT = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'zip'];
    private const MAX_SIZE = 10485760; // 10 MB

    public function index(): void
    {
        $this->requireLogin();
        $kM = $this->model('KnowledgeResource');
        $filters = [];
        if (!empty($_GET['category'])) $filters['category'] = $_GET['category'];
        if (!empty($_GET['sector_id'])) $filters['sector_id'] = (int) $_GET['sector_id'];

        $this->view('knowledge/index', [
            'resources' => $kM->allWithRefs($filters),
            'sectors'   => $this->model('Sector')->all(),
            'filters'   => $filters,
        ]);
    }

    public function create(): void
    {
        $this->requireRole(['admin', 'researcher', 'expert', 'investor']);
        $this->model('KnowledgeResource'); // تحميل الصنف لإتاحة الثوابت في النموذج | load class for CATEGORIES constant
        $this->view('knowledge/form', [
            'resource' => null,
            'sectors'  => $this->model('Sector')->all(),
        ]);
    }

    public function store(): void
    {
        $this->requireRole(['admin', 'researcher', 'expert', 'investor']);
        $this->verifyCsrf();

        $v = new Validator($_POST);
        $v->required('title', 'العنوان')
          ->required('category', 'التصنيف')
          ->in('category', 'التصنيف', KnowledgeResource::CATEGORIES);

        if ($v->fails()) {
            store_old($_POST);
            $this->flash('danger', $v->firstError());
            $this->redirect('knowledge/create');
        }
        clear_old();

        // معالجة رفع الملف (اختياري) | optional file upload
        $filePath = null;
        if (!empty($_FILES['file']['name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $filePath = $this->handleUpload($_FILES['file']);
            if ($filePath === false) {
                $this->redirect('knowledge/create');
            }
        }

        $data = [
            'title'       => trim($_POST['title']),
            'category'    => $_POST['category'],
            'sector_id'   => !empty($_POST['sector_id']) ? (int) $_POST['sector_id'] : null,
            'description' => trim($_POST['description'] ?? ''),
            'file_path'   => $filePath,
            'link'        => trim($_POST['link'] ?? ''),
            'uploaded_by' => Auth::id(),
            'created_at'  => date('Y-m-d H:i:s'),
        ];

        $this->model('KnowledgeResource')->save($data);
        $this->flash('success', 'تمت إضافة المورد المعرفي بنجاح.');
        $this->redirect('knowledge');
    }

    public function destroy(string $id = '0'): void
    {
        $this->requireRole('admin');
        $this->verifyCsrf();
        $kM = $this->model('KnowledgeResource');
        $resource = $kM->find((int) $id);
        if ($resource) {
            if (!empty($resource['file_path'])) {
                $abs = UPLOAD_PATH . '/' . basename($resource['file_path']);
                if (is_file($abs)) @unlink($abs);
            }
            $kM->delete((int) $id);
            $this->flash('success', 'تم حذف المورد.');
        }
        $this->redirect('knowledge');
    }

    // ---------------- helpers ----------------

    /** التحقق من الملف وتخزينه | Validate + store uploaded file */
    private function handleUpload(array $file)
    {
        if ($file['size'] > self::MAX_SIZE) {
            $this->flash('danger', 'حجم الملف يتجاوز الحد المسموح (10 ميجابايت).');
            return false;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            $this->flash('danger', 'نوع الملف غير مسموح به.');
            return false;
        }
        if (!is_dir(UPLOAD_PATH)) {
            @mkdir(UPLOAD_PATH, 0775, true);
        }
        $safeName = 'res_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = UPLOAD_PATH . '/' . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->flash('danger', 'تعذّر رفع الملف.');
            return false;
        }
        return 'uploads/' . $safeName;
    }
}
