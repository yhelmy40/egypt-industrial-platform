<?php
/** متحكم المصادقة | Authentication controller */
class AuthController extends Controller
{
    /** صفحة الدخول الافتراضية | Default landing => login or dashboard */
    public function index(): void
    {
        if (Auth::check()) {
            $this->redirect('dashboard');
        }
        $this->login();
    }

    /** عرض ومعالجة تسجيل الدخول | Show + handle login */
    public function login(): void
    {
        if (Auth::check()) {
            $this->redirect('dashboard');
        }

        if ($this->isPost()) {
            $this->verifyCsrf();
            $email    = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            $v = new Validator($_POST);
            $v->required('email', 'البريد الإلكتروني')->email('email', 'البريد الإلكتروني')
              ->required('password', 'كلمة المرور');

            if ($v->fails()) {
                $this->flash('danger', $v->firstError());
                $this->view('auth/login', ['email' => $email], 'auth');
                return;
            }

            $userModel = $this->model('User');
            $user = $userModel->attempt($email, $password);

            if ($user) {
                Auth::login($user);
                $this->flash('success', 'مرحباً بك، ' . $user['name']);
                $this->redirect('dashboard');
            } else {
                $this->flash('danger', 'بيانات الدخول غير صحيحة أو الحساب غير مُفعّل.');
                $this->view('auth/login', ['email' => $email], 'auth');
            }
            return;
        }

        $this->view('auth/login', [], 'auth');
    }

    public function logout(): void
    {
        Auth::logout();
        session_start(); // جلسة جديدة لرسالة الوداع
        $this->flash('info', 'تم تسجيل الخروج بنجاح.');
        $this->redirect('auth/login');
    }
}
