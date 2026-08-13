<?php
/** متحكم فرص التمويل | Funding opportunities controller */
class FundingController extends Controller
{
    public function index(): void
    {
        $this->requireLogin();
        $fundingM = $this->model('FundingOpportunity');
        $this->view('funding/index', [
            'opportunities' => $fundingM->allWithRefs(),
        ]);
    }

    public function show(string $id = '0'): void
    {
        $this->requireLogin();
        $opportunity = $this->model('FundingOpportunity')->findWithRefs((int) $id);
        if (!$opportunity) {
            $this->flash('danger', 'الفرصة غير موجودة.');
            $this->redirect('funding');
        }
        $this->view('funding/show', ['opportunity' => $opportunity]);
    }

    public function create(): void
    {
        $this->requireRole(['admin', 'investor']);
        $this->renderForm(null);
    }

    public function store(): void
    {
        $this->requireRole(['admin', 'investor']);
        $this->verifyCsrf();
        $this->persist(null);
    }

    public function edit(string $id = '0'): void
    {
        $this->requireRole(['admin', 'investor']);
        $opportunity = $this->model('FundingOpportunity')->find((int) $id);
        if (!$opportunity) {
            $this->flash('danger', 'الفرصة غير موجودة.');
            $this->redirect('funding');
        }
        $this->authorizeOwner($opportunity);
        $this->renderForm($opportunity);
    }

    public function update(string $id = '0'): void
    {
        $this->requireRole(['admin', 'investor']);
        $this->verifyCsrf();
        $opportunity = $this->model('FundingOpportunity')->find((int) $id);
        if (!$opportunity) {
            $this->flash('danger', 'الفرصة غير موجودة.');
            $this->redirect('funding');
        }
        $this->authorizeOwner($opportunity);
        $this->persist($opportunity);
    }

    public function destroy(string $id = '0'): void
    {
        $this->requireRole(['admin', 'investor']);
        $this->verifyCsrf();
        $opportunity = $this->model('FundingOpportunity')->find((int) $id);
        if ($opportunity) {
            $this->authorizeOwner($opportunity);
            $this->model('FundingOpportunity')->delete((int) $id);
            $this->flash('success', 'تم حذف الفرصة.');
        }
        $this->redirect('funding');
    }

    // ---------------- helpers ----------------

    private function authorizeOwner(array $opportunity): void
    {
        if (!Auth::isAdmin() && (int) $opportunity['user_id'] !== (int) Auth::id()) {
            $this->flash('danger', 'لا يمكنك تعديل فرصة جهة أخرى.');
            $this->redirect('funding');
        }
    }

    private function renderForm(?array $opportunity): void
    {
        $this->view('funding/form', [
            'opportunity' => $opportunity,
            'sectors'     => $this->model('Sector')->all(),
        ]);
    }

    private function persist(?array $existing): void
    {
        $v = new Validator($_POST);
        $v->required('program_name', 'اسم البرنامج')
          ->required('funding_entity', 'جهة التمويل')
          ->numeric('max_funding_amount', 'الحد الأقصى للتمويل')
          ->date('application_deadline', 'الموعد النهائي');

        if ($v->fails()) {
            store_old($_POST);
            $this->flash('danger', $v->firstError());
            $this->redirect($existing ? 'funding/edit/' . $existing['id'] : 'funding/create');
        }
        clear_old();

        // القطاعات المؤهلة (متعددة) | eligible sectors (multi-select)
        $sectors = $_POST['eligible_sectors'] ?? [];
        $eligible = is_array($sectors) ? implode(',', array_map('intval', $sectors)) : '';

        $data = [
            'user_id'             => $existing['user_id'] ?? Auth::id(),
            'program_name'        => trim($_POST['program_name']),
            'funding_entity'      => trim($_POST['funding_entity']),
            'eligible_sectors'    => $eligible,
            'max_funding_amount'  => (float) $_POST['max_funding_amount'],
            'application_deadline'=> $_POST['application_deadline'] ?: null,
            'description'         => trim($_POST['description'] ?? ''),
            'contact_details'     => trim($_POST['contact_details'] ?? ''),
        ];

        $fundingM = $this->model('FundingOpportunity');

        if ($existing) {
            $fundingM->save($data, $existing['id']);
            $this->flash('success', 'تم تحديث فرصة التمويل.');
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $fundingM->save($data);
            // إشعار المدراء | notify admins
            $this->model('Notification')->pushAdmins(
                'فرصة تمويل جديدة',
                'تمت إضافة فرصة تمويل: «' . $data['program_name'] . '».'
            );
            $this->flash('success', 'تمت إضافة فرصة التمويل بنجاح.');
        }
        $this->redirect('funding');
    }
}
