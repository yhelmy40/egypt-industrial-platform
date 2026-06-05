<?php
/** متحكم المصانع | Factory controller */
class FactoryController extends Controller
{
    public function index(): void
    {
        $this->requireRole(['admin', 'researcher', 'expert', 'investor']);
        $factoryM = $this->model('Factory');
        $this->view('factories/index', [
            'factories' => $factoryM->allWithRefs(),
        ]);
    }

    public function show(string $id = '0'): void
    {
        $this->requireLogin();
        $factoryM = $this->model('Factory');
        $factory = $factoryM->findWithRefs((int) $id);
        if (!$factory) {
            $this->flash('danger', 'المصنع غير موجود.');
            $this->redirect('factory');
        }
        // ربط التحديات الخاصة بالمصنع | challenges of this factory
        $challengeM = $this->model('Challenge');
        $this->view('factories/show', [
            'factory'    => $factory,
            'challenges' => $challengeM->allWithRefs(['factory_id' => (int) $id]),
        ]);
    }

    /** ملف المصنع الخاص بالمستخدم الحالي | Current factory user's own profile */
    public function profile(): void
    {
        $this->requireRole('factory');
        $factoryM = $this->model('Factory');
        $factory  = $factoryM->findByUser(Auth::id());
        if (!$factory) {
            // لا يوجد ملف بعد => انتقل لإنشائه | none yet => create
            $this->redirect('factory/create');
        }
        $this->redirect('factory/edit/' . $factory['id']);
    }

    public function create(): void
    {
        $this->requireRole(['admin', 'factory']);
        $this->renderForm(null);
    }

    public function store(): void
    {
        $this->requireRole(['admin', 'factory']);
        $this->verifyCsrf();
        $this->persist(null);
    }

    public function edit(string $id = '0'): void
    {
        $this->requireRole(['admin', 'factory']);
        $factoryM = $this->model('Factory');
        $factory  = $factoryM->find((int) $id);
        if (!$factory) {
            $this->flash('danger', 'المصنع غير موجود.');
            $this->redirect('factory');
        }
        $this->authorizeOwner($factory);
        $this->renderForm($factory);
    }

    public function update(string $id = '0'): void
    {
        $this->requireRole(['admin', 'factory']);
        $this->verifyCsrf();
        $factoryM = $this->model('Factory');
        $factory  = $factoryM->find((int) $id);
        if (!$factory) {
            $this->flash('danger', 'المصنع غير موجود.');
            $this->redirect('factory');
        }
        $this->authorizeOwner($factory);
        $this->persist($factory);
    }

    public function destroy(string $id = '0'): void
    {
        $this->requireRole('admin');
        $this->verifyCsrf();
        $this->model('Factory')->delete((int) $id);
        $this->flash('success', 'تم حذف المصنع.');
        $this->redirect('factory');
    }

    // ---------------- helpers ----------------

    private function authorizeOwner(array $factory): void
    {
        if (!Auth::isAdmin() && (int) $factory['user_id'] !== (int) Auth::id()) {
            $this->flash('danger', 'لا يمكنك تعديل ملف مصنع آخر.');
            $this->redirect('dashboard');
        }
    }

    private function renderForm(?array $factory): void
    {
        $this->view('factories/form', [
            'factory'      => $factory,
            'sectors'      => $this->model('Sector')->all(),
            'governorates' => $this->model('Governorate')->all(),
        ]);
    }

    private function persist(?array $existing): void
    {
        $v = new Validator($_POST);
        $v->required('name', 'اسم المصنع')
          ->required('sector_id', 'القطاع')
          ->required('governorate_id', 'المحافظة')
          ->email('email', 'البريد الإلكتروني')
          ->in('energy_usage_level', 'مستوى استهلاك الطاقة', ['low', 'medium', 'high']);

        if ($v->fails()) {
            store_old($_POST);
            $this->flash('danger', $v->firstError());
            $this->redirect($existing ? 'factory/edit/' . $existing['id'] : 'factory/create');
        }
        clear_old();

        $data = [
            'user_id'            => $existing['user_id'] ?? (Auth::is('factory') ? Auth::id() : null),
            'name'               => trim($_POST['name']),
            'sector_id'          => (int) $_POST['sector_id'],
            'governorate_id'     => (int) $_POST['governorate_id'],
            'contact_person'     => trim($_POST['contact_person'] ?? ''),
            'email'              => trim($_POST['email'] ?? ''),
            'phone'              => trim($_POST['phone'] ?? ''),
            'products'           => trim($_POST['products'] ?? ''),
            'main_challenges'    => trim($_POST['main_challenges'] ?? ''),
            'energy_usage_level' => $_POST['energy_usage_level'] ?? 'medium',
            'production_capacity'=> trim($_POST['production_capacity'] ?? ''),
        ];

        $factoryM = $this->model('Factory');
        $factoryM->save($data, $existing['id'] ?? null);

        $this->flash('success', $existing ? 'تم تحديث ملف المصنع.' : 'تم تسجيل المصنع بنجاح.');
        $this->redirect('factory');
    }
}
