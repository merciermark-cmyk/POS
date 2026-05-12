<?php
class TerminalController {

    public function index(): void {
        requireManager();
        $terminals = (new Terminal())->getAll();
        require APP_PATH . '/views/admin/terminals/index.php';
    }

    public function create(): void {
        requireManager();
        $errors   = [];
        $terminal = ['name' => '', 'print_service_url' => 'http://localhost:5000', 'moneris_terminal_id' => '', 'is_active' => 1];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken();
            $data = [
                'name'                => trim($_POST['name'] ?? ''),
                'print_service_url'   => trim($_POST['print_service_url'] ?? 'http://localhost:5000'),
                'moneris_terminal_id' => trim($_POST['moneris_terminal_id'] ?? ''),
                'is_active'           => isset($_POST['is_active']) ? 1 : 0,
            ];
            $terminal = $data;

            if (strlen($data['name']) < 1) $errors[] = 'Name is required.';
            if (strlen($data['print_service_url']) < 1) $errors[] = 'Print service URL is required.';

            if (empty($errors)) {
                try {
                    (new Terminal())->create($data);
                    setFlash('success', 'Terminal created.');
                    redirect('/terminals');
                    return;
                } catch (Exception $e) {
                    $errors[] = 'Error: ' . $e->getMessage();
                }
            }
        }

        $editing = false;
        require APP_PATH . '/views/admin/terminals/form.php';
    }

    public function edit(int $id): void {
        requireManager();

        $model    = new Terminal();
        $terminal = $model->findById($id);
        if (!$terminal) {
            setFlash('error', 'Terminal not found.');
            redirect('/terminals');
            return;
        }

        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken();
            $data = [
                'name'                => trim($_POST['name'] ?? ''),
                'print_service_url'   => trim($_POST['print_service_url'] ?? 'http://localhost:5000'),
                'moneris_terminal_id' => trim($_POST['moneris_terminal_id'] ?? ''),
                'is_active'           => isset($_POST['is_active']) ? 1 : 0,
            ];

            if (strlen($data['name']) < 1) $errors[] = 'Name is required.';
            if (strlen($data['print_service_url']) < 1) $errors[] = 'Print service URL is required.';

            if (empty($errors)) {
                try {
                    $model->update($id, $data);
                    setFlash('success', 'Terminal updated.');
                    redirect('/terminals');
                    return;
                } catch (Exception $e) {
                    $errors[] = 'Error: ' . $e->getMessage();
                }
            }

            $terminal = array_merge($terminal, $data);
        }

        $editing = true;
        require APP_PATH . '/views/admin/terminals/form.php';
    }

    public function delete(int $id): void {
        requireManager();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken();
            $result = (new Terminal())->delete($id);
            if ($result) {
                setFlash('success', 'Terminal deleted.');
            } else {
                setFlash('error', 'Cannot delete terminal — shifts exist for it. Deactivate it instead.');
            }
        }
        redirect('/terminals');
    }
}
