<?php
class UserController {

    public function index(): void {
        requireManager();
        $users = (new PosUser())->getAll();
        $schedUsers = (new ScheduleAttendance())->getScheduleUsers();
        $schedMap = [];
        foreach ($schedUsers as $su) {
            $schedMap[$su['id']] = $su['name'];
        }
        require APP_PATH . '/views/admin/users/index.php';
    }

    public function create(): void {
        requireManager();
        $errors = [];
        $user   = ['username' => '', 'pin' => '', 'role' => ROLE_CASHIER, 'is_active' => 1];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken();
            $data = [
                'username'   => trim($_POST['username'] ?? ''),
                'password'   => $_POST['password'] ?? '',
                'pin'        => trim($_POST['pin'] ?? ''),
                'role'       => $_POST['role'] ?? ROLE_CASHIER,
                'is_active'  => isset($_POST['is_active']) ? 1 : 0,
            ];
            $user = $data;

            if (strlen($data['username']) < 3) $errors[] = 'Username must be at least 3 characters.';
            if (strlen($data['password']) < 6) $errors[] = 'Password must be at least 6 characters.';
            if ($data['pin'] && !preg_match('/^\d{1,3}$/', $data['pin'])) $errors[] = 'PIN must be 1–3 digits.';

            if (empty($errors)) {
                try {
                    (new PosUser())->create($data);
                    setFlash('success', 'User created.');
                    redirect('/users');
                    return;
                } catch (Exception $e) {
                    $errors[] = 'Error: ' . $e->getMessage();
                }
            }
        }

        $editing = false;
        require APP_PATH . '/views/admin/users/form.php';
    }

    public function edit(int $id): void {
        requireManager();

        $userModel = new PosUser();
        $user = $userModel->findById($id);
        if (!$user) {
            setFlash('error', 'User not found.');
            redirect('/users');
            return;
        }

        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken();
            $data = [
                'username'         => trim($_POST['username'] ?? ''),
                'password'         => $_POST['password'] ?? '',
                'pin'              => trim($_POST['pin'] ?? ''),
                'role'             => $_POST['role'] ?? ROLE_CASHIER,
                'is_active'        => isset($_POST['is_active']) ? 1 : 0,
                'schedule_user_id' => ($_POST['schedule_user_id'] ?? '') !== '' ? (int)$_POST['schedule_user_id'] : null,
            ];

            if (strlen($data['username']) < 3) $errors[] = 'Username must be at least 3 characters.';
            if ($data['password'] && strlen($data['password']) < 6) $errors[] = 'Password must be at least 6 characters.';
            if ($data['pin'] && !preg_match('/^\d{1,3}$/', $data['pin'])) $errors[] = 'PIN must be 1–3 digits.';

            if (empty($errors)) {
                try {
                    $userModel->update($id, $data);
                    setFlash('success', 'User updated.');
                    redirect('/users');
                    return;
                } catch (Exception $e) {
                    $errors[] = 'Error: ' . $e->getMessage();
                }
            }

            $user = array_merge($user, $data);
        }

        $editing = true;
        $schedUsers = (new ScheduleAttendance())->getScheduleUsers();
        require APP_PATH . '/views/admin/users/form.php';
    }

    public function delete(int $id): void {
        requireManager();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken();
            (new PosUser())->delete($id);
            setFlash('success', 'User deleted.');
        }
        redirect('/users');
    }
}
