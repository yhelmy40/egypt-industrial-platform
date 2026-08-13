<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\MembershipRepository;
use App\Services\NotificationService;

/**
 * الإشعارات داخل المنصة | In-app notifications (§4.11).
 *
 * كل عملية مقيّدة بالمستخدم الحالي في الاستعلام نفسه، فلا يمكن قراءة أو تعليم
 * إشعار مستخدم آخر بتمرير معرّفه.
 * Every operation is scoped to the current user inside the query itself, so
 * another user's notification cannot be read or marked by passing its id.
 */
final class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
    ) {
    }

    public function index(Request $request): Response
    {
        $userId = (int) $this->currentUserId();

        return $this->view('sme/notifications', [
            'pageTitle'     => 'الإشعارات',
            'notifications' => $this->notifications->forUser($userId, 50),
            'unreadCount'   => $this->notifications->unreadCount($userId),
            'organizations' => $this->memberships->organizationsForUser($userId),
        ], 'app');
    }

    public function markRead(Request $request): Response
    {
        $userId         = (int) $this->currentUserId();
        $notificationId = $request->routeInt('id');

        if ($notificationId !== null) {
            $this->notifications->markRead($notificationId, $userId);
        }

        return $this->redirect('/app/notifications');
    }

    public function markAllRead(Request $request): Response
    {
        $count = $this->notifications->markAllRead((int) $this->currentUserId());

        $this->flash('success', 'تم تعليم ' . number_ar($count) . ' إشعاراً كمقروء.');

        return $this->redirect('/app/notifications');
    }
}
