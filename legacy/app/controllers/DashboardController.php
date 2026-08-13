<?php
/** متحكم لوحة المعلومات | Dashboard controller */
class DashboardController extends Controller
{
    public function index(): void
    {
        $this->requireLogin();

        // لوحة الوزارة الكاملة للمدير | Full ministry dashboard for admin
        if (Auth::isAdmin()) {
            $this->adminDashboard();
            return;
        }

        // لوحة مبسطة لباقي الأدوار | Simplified dashboard for other roles
        $this->roleDashboard();
    }

    private function adminDashboard(): void
    {
        $userM      = $this->model('User');
        $factoryM   = $this->model('Factory');
        $challengeM = $this->model('Challenge');
        $projectM   = $this->model('RDProject');
        $fundingM   = $this->model('FundingOpportunity');
        $rpM        = $this->model('ResearcherProfile');

        $kpi = [
            'factories'        => $factoryM->count(),
            'researchers'      => $rpM->count('profile_type = ?', ['researcher']),
            'experts'          => $rpM->count('profile_type = ?', ['expert']),
            'challenges_total' => $challengeM->count(),
            'challenges_open'  => $challengeM->countByStatus('open'),
            'challenges_matched' => $challengeM->countByStatus('matched'),
            'challenges_pending' => $challengeM->countByStatus('pending'),
            'projects_active'  => $projectM->countByStatus('active'),
            'projects_completed' => $projectM->countByStatus('completed'),
            'funding'          => $fundingM->count(),
        ];

        $priorityCounts = $challengeM->countsByPriority();
        $sectorCounts   = $projectM->countsBySector();

        $recentChallenges = array_slice($challengeM->allWithRefs(), 0, 6);

        $this->view('dashboard/admin', [
            'kpi'              => $kpi,
            'priorityCounts'   => $priorityCounts,
            'sectorCounts'     => $sectorCounts,
            'recentChallenges' => $recentChallenges,
        ]);
    }

    private function roleDashboard(): void
    {
        $role = Auth::role();
        $uid  = Auth::id();
        $data = ['role' => $role];

        $challengeM = $this->model('Challenge');
        $fundingM   = $this->model('FundingOpportunity');

        if ($role === 'factory') {
            $factoryM = $this->model('Factory');
            $factory  = $factoryM->findByUser($uid);
            $data['factory'] = $factory;
            $data['myChallenges'] = $factory
                ? $challengeM->allWithRefs(['factory_id' => $factory['id']])
                : [];
            $data['fundingCount'] = $fundingM->count();
        } elseif (in_array($role, ['researcher', 'expert'], true)) {
            $rpM = $this->model('ResearcherProfile');
            $data['profile'] = $rpM->findByUser($uid);
            $data['openChallenges'] = $challengeM->allWithRefs(['status' => 'open']);
        } elseif ($role === 'investor') {
            $data['myFunding'] = $fundingM->byUser($uid);
            $data['challengeCount'] = $challengeM->count();
        }

        $this->view('dashboard/role', $data);
    }
}
