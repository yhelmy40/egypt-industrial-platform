<?php
/** متحكم الإشعارات | Notifications controller */
class NotificationController extends Controller
{
    public function index(): void
    {
        $this->requireLogin();
        $nM = $this->model('Notification');
        $this->view('notifications/index', [
            'notifications' => $nM->forUser(Auth::id()),
        ]);
    }

    public function read(string $id = '0'): void
    {
        $this->requireLogin();
        $this->model('Notification')->markRead((int) $id, Auth::id());
        $this->redirect('notification');
    }

    public function readAll(): void
    {
        $this->requireLogin();
        $this->model('Notification')->markAllRead(Auth::id());
        $this->flash('success', 'تم تعليم كل الإشعارات كمقروءة.');
        $this->redirect('notification');
    }
}
