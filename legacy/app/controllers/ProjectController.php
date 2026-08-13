<?php
/** متحكم مشاريع البحث والتطوير | R&D Projects controller */
class ProjectController extends Controller
{
    public function index(): void
    {
        $this->requireLogin();
        $projectM = $this->model('RDProject');
        $filters = [];

        // فلترة حسب دور المستخدم | scope by role
        if (Auth::is('factory')) {
            $factory = $this->model('Factory')->findByUser(Auth::id());
            $filters['factory_id'] = $factory['id'] ?? 0;
        } elseif (in_array(Auth::role(), ['researcher', 'expert'], true)) {
            $profile = $this->model('ResearcherProfile')->findByUser(Auth::id());
            $filters['researcher_profile_id'] = $profile['id'] ?? 0;
        }
        if (!empty($_GET['status'])) {
            $filters['status'] = $_GET['status'];
        }

        $this->view('projects/index', [
            'projects' => $projectM->allWithRefs($filters),
        ]);
    }

    public function show(string $id = '0'): void
    {
        $this->requireLogin();
        $project = $this->model('RDProject')->findWithRefs((int) $id);
        if (!$project) {
            $this->flash('danger', 'المشروع غير موجود.');
            $this->redirect('project');
        }
        $this->view('projects/show', ['project' => $project]);
    }

    /** إنشاء مشروع (يمكن تمرير معرّف تحدٍّ لتحويله) | Create, optionally from a challenge */
    public function create(string $challengeId = '0'): void
    {
        $this->requireRole('admin');
        $this->renderForm(null, (int) $challengeId);
    }

    public function store(): void
    {
        $this->requireRole('admin');
        $this->verifyCsrf();
        $this->persist(null);
    }

    public function edit(string $id = '0'): void
    {
        $this->requireRole('admin');
        $project = $this->model('RDProject')->find((int) $id);
        if (!$project) {
            $this->flash('danger', 'المشروع غير موجود.');
            $this->redirect('project');
        }
        $this->renderForm($project, 0);
    }

    public function update(string $id = '0'): void
    {
        $this->requireRole('admin');
        $this->verifyCsrf();
        $project = $this->model('RDProject')->find((int) $id);
        if (!$project) {
            $this->flash('danger', 'المشروع غير موجود.');
            $this->redirect('project');
        }
        $this->persist($project);
    }

    // ---------------- helpers ----------------

    private function renderForm(?array $project, int $challengeId): void
    {
        $challengeM = $this->model('Challenge');
        // التحديات المعتمدة المتاحة للتحويل | approved challenges available
        $challenges = $challengeM->allWithRefs(['status' => 'open']);
        $matched    = $challengeM->allWithRefs(['status' => 'matched']);
        $challenges = array_merge($matched, $challenges);

        $preChallenge = null;
        if ($challengeId) {
            $preChallenge = $challengeM->findWithRefs($challengeId);
        }

        $this->view('projects/form', [
            'project'      => $project,
            'challenges'   => $challenges,
            'factories'    => $this->model('Factory')->allWithRefs(),
            'profiles'     => $this->model('ResearcherProfile')->allWithRefs(),
            'preChallenge' => $preChallenge,
        ]);
    }

    private function persist(?array $existing): void
    {
        $v = new Validator($_POST);
        $v->required('title', 'عنوان المشروع')
          ->required('challenge_id', 'التحدي المرتبط')
          ->in('status', 'الحالة', ['planned', 'active', 'completed', 'cancelled'])
          ->numeric('budget_estimate', 'الميزانية التقديرية')
          ->date('start_date', 'تاريخ البداية')
          ->date('end_date', 'تاريخ النهاية');

        if ($v->fails()) {
            store_old($_POST);
            $this->flash('danger', $v->firstError());
            $this->redirect($existing ? 'project/edit/' . $existing['id'] : 'project/create');
        }
        clear_old();

        $data = [
            'challenge_id'         => (int) $_POST['challenge_id'],
            'factory_id'           => !empty($_POST['factory_id']) ? (int) $_POST['factory_id'] : null,
            'researcher_profile_id'=> !empty($_POST['researcher_profile_id']) ? (int) $_POST['researcher_profile_id'] : null,
            'title'                => trim($_POST['title']),
            'start_date'           => $_POST['start_date'] ?: null,
            'end_date'             => $_POST['end_date'] ?: null,
            'budget_estimate'      => $_POST['budget_estimate'] !== '' ? (float) $_POST['budget_estimate'] : null,
            'status'               => $_POST['status'] ?? 'planned',
            'expected_outcome'     => trim($_POST['expected_outcome'] ?? ''),
            'actual_outcome'       => trim($_POST['actual_outcome'] ?? ''),
        ];

        $projectM   = $this->model('RDProject');
        $challengeM = $this->model('Challenge');

        if ($existing) {
            $projectM->save($data, $existing['id']);
            // مزامنة حالة التحدي عند اكتمال المشروع | sync challenge status
            if ($data['status'] === 'completed') {
                $challengeM->setStatus($data['challenge_id'], 'solved');
            } elseif ($data['status'] === 'active') {
                $challengeM->setStatus($data['challenge_id'], 'in_progress');
            }
            $this->flash('success', 'تم تحديث المشروع.');
            $this->redirect('project/show/' . $existing['id']);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $newId = $projectM->save($data);
            $challengeM->setStatus($data['challenge_id'], 'in_progress');

            // إشعار المصنع والباحث | notify factory + researcher
            $challenge = $challengeM->find($data['challenge_id']);
            if ($challenge && !empty($challenge['created_by'])) {
                $this->model('Notification')->push(
                    (int) $challenge['created_by'],
                    'تم إنشاء مشروع بحث وتطوير',
                    'تم تحويل تحديكم إلى مشروع: «' . $data['title'] . '».'
                );
            }
            if (!empty($data['researcher_profile_id'])) {
                $profile = $this->model('ResearcherProfile')->find($data['researcher_profile_id']);
                if ($profile && !empty($profile['user_id'])) {
                    $this->model('Notification')->push(
                        (int) $profile['user_id'],
                        'تم تكليفك بمشروع جديد',
                        'تم تعيينك على مشروع: «' . $data['title'] . '».'
                    );
                }
            }
            $this->flash('success', 'تم إنشاء المشروع بنجاح.');
            $this->redirect('project/show/' . $newId);
        }
    }
}
