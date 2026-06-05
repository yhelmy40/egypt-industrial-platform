<?php
/** متحكم التحديات الصناعية | Industrial challenges controller */
class ChallengeController extends Controller
{
    public function index(): void
    {
        $this->requireLogin();
        $challengeM = $this->model('Challenge');

        $filters = [];
        if (!empty($_GET['status']))    $filters['status'] = $_GET['status'];
        if (!empty($_GET['sector_id'])) $filters['sector_id'] = (int) $_GET['sector_id'];

        // المصنع يرى تحدياته فقط | factory sees only its own challenges
        if (Auth::is('factory')) {
            $factory = $this->model('Factory')->findByUser(Auth::id());
            $filters['factory_id'] = $factory['id'] ?? 0;
        }

        $this->view('challenges/index', [
            'challenges' => $challengeM->allWithRefs($filters),
            'sectors'    => $this->model('Sector')->all(),
            'filters'    => $filters,
        ]);
    }

    public function show(string $id = '0'): void
    {
        $this->requireLogin();
        $challengeM = $this->model('Challenge');
        $challenge = $challengeM->findWithRefs((int) $id);
        if (!$challenge) {
            $this->flash('danger', 'التحدي غير موجود.');
            $this->redirect('challenge');
        }

        $matchM = $this->model('ChallengeMatch');
        $this->view('challenges/show', [
            'challenge'    => $challenge,
            'savedMatches' => $matchM->savedForChallenge((int) $id),
        ]);
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
        $challenge = $this->model('Challenge')->find((int) $id);
        if (!$challenge) {
            $this->flash('danger', 'التحدي غير موجود.');
            $this->redirect('challenge');
        }
        $this->authorizeOwner($challenge);
        $this->renderForm($challenge);
    }

    public function update(string $id = '0'): void
    {
        $this->requireRole(['admin', 'factory']);
        $this->verifyCsrf();
        $challenge = $this->model('Challenge')->find((int) $id);
        if (!$challenge) {
            $this->flash('danger', 'التحدي غير موجود.');
            $this->redirect('challenge');
        }
        $this->authorizeOwner($challenge);
        $this->persist($challenge);
    }

    /** اعتماد التحدي | Approve a challenge (admin) */
    public function approve(string $id = '0'): void
    {
        $this->requireRole('admin');
        $this->verifyCsrf();
        $challengeM = $this->model('Challenge');
        $challenge = $challengeM->find((int) $id);
        if (!$challenge) {
            $this->redirect('challenge');
        }
        $challengeM->setStatus((int) $id, 'open');

        // إشعار صاحب التحدي | notify creator
        if (!empty($challenge['created_by'])) {
            $this->model('Notification')->push(
                (int) $challenge['created_by'],
                'تم اعتماد التحدي',
                'تم اعتماد التحدي «' . $challenge['title'] . '» وأصبح مفتوحاً للمطابقة.'
            );
        }
        $this->flash('success', 'تم اعتماد التحدي.');
        $this->redirect('challenge/show/' . $id);
    }

    /** رفض التحدي | Reject a challenge (admin) */
    public function reject(string $id = '0'): void
    {
        $this->requireRole('admin');
        $this->verifyCsrf();
        $challengeM = $this->model('Challenge');
        $challenge = $challengeM->find((int) $id);
        if (!$challenge) {
            $this->redirect('challenge');
        }
        $challengeM->setStatus((int) $id, 'rejected');
        if (!empty($challenge['created_by'])) {
            $this->model('Notification')->push(
                (int) $challenge['created_by'],
                'تم رفض التحدي',
                'تم رفض التحدي «' . $challenge['title'] . '». يرجى مراجعة التفاصيل.'
            );
        }
        $this->flash('warning', 'تم رفض التحدي.');
        $this->redirect('challenge/show/' . $id);
    }

    /**
     * صفحة المطابقة الذكية | Smart matching page.
     * تعرض الباحثين/الخبراء الموصى بهم لهذا التحدي.
     */
    public function matches(string $id = '0'): void
    {
        $this->requireRole(['admin', 'factory']);
        $challengeM = $this->model('Challenge');
        $challenge = $challengeM->findWithRefs((int) $id);
        if (!$challenge) {
            $this->flash('danger', 'التحدي غير موجود.');
            $this->redirect('challenge');
        }
        $this->authorizeOwner($challenge, true);

        $rpM = $this->model('ResearcherProfile');
        $matchM = $this->model('ChallengeMatch');

        $profiles = $rpM->allForMatching();
        $matches  = $matchM->computeMatches($challenge, $profiles, $challenge['sector_name'] ?? '');

        $this->view('challenges/matches', [
            'challenge' => $challenge,
            'matches'   => $matches,
            'saved'     => $matchM->savedForChallenge((int) $id),
        ]);
    }

    /** حفظ المطابقات المختارة وتحديث الحالة | Persist selected matches (admin) */
    public function assign(string $id = '0'): void
    {
        $this->requireRole('admin');
        $this->verifyCsrf();
        $challengeM = $this->model('Challenge');
        $challenge = $challengeM->find((int) $id);
        if (!$challenge) {
            $this->redirect('challenge');
        }

        $selected = $_POST['profiles'] ?? []; // profile_id => score
        $matchM = $this->model('ChallengeMatch');
        $matchM->deleteForChallenge((int) $id);

        $rpM = $this->model('ResearcherProfile');
        $profiles = $rpM->allForMatching();
        $computed = $matchM->computeMatches($challenge, $profiles, '');
        $scoreMap = [];
        foreach ($computed as $c) {
            $scoreMap[(int) $c['profile']['id']] = [
                'score'    => $c['score'],
                'keywords' => implode('، ', $c['matched_keywords']),
            ];
        }

        $count = 0;
        foreach ((array) $selected as $pid) {
            $pid = (int) $pid;
            $info = $scoreMap[$pid] ?? ['score' => 0, 'keywords' => ''];
            $matchM->add((int) $id, $pid, (int) $info['score'], $info['keywords']);
            $count++;
        }

        if ($count > 0) {
            $challengeM->setStatus((int) $id, 'matched');
            if (!empty($challenge['created_by'])) {
                $this->model('Notification')->push(
                    (int) $challenge['created_by'],
                    'تم ربط التحدي',
                    'تم ربط التحدي «' . $challenge['title'] . '» بعدد ' . $count . ' من الخبراء/الباحثين.'
                );
            }
            $this->flash('success', "تم حفظ {$count} مطابقة وتحديث حالة التحدي إلى «تم الربط».");
        } else {
            $this->flash('warning', 'لم يتم اختيار أي باحث/خبير.');
        }
        $this->redirect('challenge/show/' . $id);
    }

    // ---------------- helpers ----------------

    private function authorizeOwner(array $challenge, bool $allowFactoryView = false): void
    {
        if (Auth::isAdmin()) {
            return;
        }
        if (Auth::is('factory')) {
            $factory = $this->model('Factory')->findByUser(Auth::id());
            if ($factory && (int) $challenge['factory_id'] === (int) $factory['id']) {
                return;
            }
        }
        $this->flash('danger', 'لا تملك صلاحية على هذا التحدي.');
        $this->redirect('challenge');
    }

    private function renderForm(?array $challenge): void
    {
        $this->view('challenges/form', [
            'challenge' => $challenge,
            'sectors'   => $this->model('Sector')->all(),
        ]);
    }

    private function persist(?array $existing): void
    {
        $v = new Validator($_POST);
        $v->required('title', 'عنوان التحدي')
          ->required('description', 'وصف المشكلة')
          ->required('sector_id', 'القطاع')
          ->in('priority', 'الأولوية', ['low', 'medium', 'high']);

        if ($v->fails()) {
            store_old($_POST);
            $this->flash('danger', $v->firstError());
            $this->redirect($existing ? 'challenge/edit/' . $existing['id'] : 'challenge/create');
        }
        clear_old();

        // تحديد المصنع | resolve factory
        $factoryId = $existing['factory_id'] ?? null;
        if (Auth::is('factory')) {
            $factory = $this->model('Factory')->findByUser(Auth::id());
            if (!$factory) {
                $this->flash('warning', 'يجب إنشاء ملف المصنع أولاً قبل إضافة تحدٍّ.');
                $this->redirect('factory/create');
            }
            $factoryId = $factory['id'];
        } elseif (Auth::isAdmin() && !empty($_POST['factory_id'])) {
            $factoryId = (int) $_POST['factory_id'];
        }

        $data = [
            'factory_id'      => $factoryId,
            'created_by'      => $existing['created_by'] ?? Auth::id(),
            'title'           => trim($_POST['title']),
            'description'     => trim($_POST['description']),
            'sector_id'       => (int) $_POST['sector_id'],
            'priority'        => $_POST['priority'] ?? 'medium',
            'expected_impact' => trim($_POST['expected_impact'] ?? ''),
            'needed_expertise'=> trim($_POST['needed_expertise'] ?? ''),
        ];

        $challengeM = $this->model('Challenge');

        if ($existing) {
            $challengeM->save($data, $existing['id']);
            $this->flash('success', 'تم تحديث التحدي.');
            $this->redirect('challenge/show/' . $existing['id']);
        } else {
            // التحدي الجديد يبدأ بانتظار المراجعة (أو مفتوح إن أنشأه المدير)
            $data['status'] = Auth::isAdmin() ? 'open' : 'pending';
            $data['created_at'] = date('Y-m-d H:i:s');
            $newId = $challengeM->save($data);

            // إشعار المدراء بوجود تحدٍّ جديد | notify admins
            if (!Auth::isAdmin()) {
                $this->model('Notification')->pushAdmins(
                    'تحدٍّ جديد بانتظار المراجعة',
                    'تم تقديم تحدٍّ جديد: «' . $data['title'] . '».'
                );
            }
            $this->flash('success', 'تم تقديم التحدي بنجاح وهو بانتظار مراجعة الوزارة.');
            $this->redirect('challenge/show/' . $newId);
        }
    }
}
