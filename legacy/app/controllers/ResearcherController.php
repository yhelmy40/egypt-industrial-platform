<?php
/** متحكم الباحثين والخبراء | Researcher / Expert controller */
class ResearcherController extends Controller
{
    public function index(): void
    {
        $this->requireLogin();
        $rpM = $this->model('ResearcherProfile');
        // فلترة حسب النوع | optional type filter
        $type = $_GET['type'] ?? null;
        if (!in_array($type, ['researcher', 'expert'], true)) {
            $type = null;
        }
        $this->view('researchers/index', [
            'profiles' => $rpM->allWithRefs($type),
            'type'     => $type,
        ]);
    }

    public function show(string $id = '0'): void
    {
        $this->requireLogin();
        $rpM = $this->model('ResearcherProfile');
        $profile = $rpM->findWithRefs((int) $id);
        if (!$profile) {
            $this->flash('danger', 'الملف غير موجود.');
            $this->redirect('researcher');
        }
        $this->view('researchers/show', ['profile' => $profile]);
    }

    public function profile(): void
    {
        $this->requireRole(['researcher', 'expert']);
        $rpM = $this->model('ResearcherProfile');
        $profile = $rpM->findByUser(Auth::id());
        if (!$profile) {
            $this->redirect('researcher/create');
        }
        $this->redirect('researcher/edit/' . $profile['id']);
    }

    public function create(): void
    {
        $this->requireRole(['admin', 'researcher', 'expert']);
        $this->renderForm(null);
    }

    public function store(): void
    {
        $this->requireRole(['admin', 'researcher', 'expert']);
        $this->verifyCsrf();
        $this->persist(null);
    }

    public function edit(string $id = '0'): void
    {
        $this->requireRole(['admin', 'researcher', 'expert']);
        $rpM = $this->model('ResearcherProfile');
        $profile = $rpM->find((int) $id);
        if (!$profile) {
            $this->flash('danger', 'الملف غير موجود.');
            $this->redirect('researcher');
        }
        $this->authorizeOwner($profile);
        $this->renderForm($profile);
    }

    public function update(string $id = '0'): void
    {
        $this->requireRole(['admin', 'researcher', 'expert']);
        $this->verifyCsrf();
        $rpM = $this->model('ResearcherProfile');
        $profile = $rpM->find((int) $id);
        if (!$profile) {
            $this->flash('danger', 'الملف غير موجود.');
            $this->redirect('researcher');
        }
        $this->authorizeOwner($profile);
        $this->persist($profile);
    }

    public function destroy(string $id = '0'): void
    {
        $this->requireRole('admin');
        $this->verifyCsrf();
        $this->model('ResearcherProfile')->delete((int) $id);
        $this->flash('success', 'تم حذف الملف.');
        $this->redirect('researcher');
    }

    // ---------------- helpers ----------------

    private function authorizeOwner(array $profile): void
    {
        if (!Auth::isAdmin() && (int) $profile['user_id'] !== (int) Auth::id()) {
            $this->flash('danger', 'لا يمكنك تعديل ملف شخص آخر.');
            $this->redirect('dashboard');
        }
    }

    private function renderForm(?array $profile): void
    {
        $this->view('researchers/form', [
            'profile'      => $profile,
            'governorates' => $this->model('Governorate')->all(),
        ]);
    }

    private function persist(?array $existing): void
    {
        $v = new Validator($_POST);
        $v->required('name', 'الاسم')
          ->required('specialization', 'التخصص')
          ->required('expertise_keywords', 'كلمات الخبرة')
          ->email('email', 'البريد الإلكتروني')
          ->in('profile_type', 'النوع', ['researcher', 'expert']);

        if ($v->fails()) {
            store_old($_POST);
            $this->flash('danger', $v->firstError());
            $this->redirect($existing ? 'researcher/edit/' . $existing['id'] : 'researcher/create');
        }
        clear_old();

        // تحديد النوع تلقائياً حسب دور المستخدم | infer type from role
        $type = $_POST['profile_type'] ?? 'researcher';
        if (Auth::is('researcher')) $type = 'researcher';
        if (Auth::is('expert'))     $type = 'expert';

        $data = [
            'user_id'           => $existing['user_id'] ?? (in_array(Auth::role(), ['researcher', 'expert'], true) ? Auth::id() : null),
            'profile_type'      => $type,
            'name'              => trim($_POST['name']),
            'organization'      => trim($_POST['organization'] ?? ''),
            'specialization'    => trim($_POST['specialization']),
            'expertise_keywords'=> trim($_POST['expertise_keywords']),
            'previous_projects' => trim($_POST['previous_projects'] ?? ''),
            'email'             => trim($_POST['email'] ?? ''),
            'phone'             => trim($_POST['phone'] ?? ''),
            'governorate_id'    => !empty($_POST['governorate_id']) ? (int) $_POST['governorate_id'] : null,
        ];

        $rpM = $this->model('ResearcherProfile');
        $rpM->save($data, $existing['id'] ?? null);

        $this->flash('success', $existing ? 'تم تحديث الملف.' : 'تم إنشاء الملف بنجاح.');
        $this->redirect('researcher');
    }
}
