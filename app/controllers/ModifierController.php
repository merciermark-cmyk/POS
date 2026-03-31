<?php
class ModifierController {

    public function index(): void {
        requireManager();
        $modifiers = (new Modifier())->getAll();
        require APP_PATH . '/views/admin/modifiers/index.php';
    }

    public function create(): void {
        requireManager();
        $errors   = [];
        $modifier = ['name' => '', 'price' => '', 'sort_order' => 0, 'is_active' => 1];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken();
            $data = [
                'name'       => trim($_POST['name'] ?? ''),
                'price'      => $_POST['price'] ?? '',
                'sort_order' => (int)($_POST['sort_order'] ?? 0),
                'is_active'  => isset($_POST['is_active']) ? 1 : 0,
            ];
            $modifier = $data;

            if (strlen($data['name']) < 1) $errors[] = 'Name is required.';
            if (!is_numeric($data['price']) || (float)$data['price'] < 0) $errors[] = 'Price must be a non-negative number.';

            if (empty($errors)) {
                try {
                    (new Modifier())->create($data);
                    setFlash('success', 'Modifier created.');
                    redirect('/modifiers');
                    return;
                } catch (Exception $e) {
                    $errors[] = 'Error: ' . $e->getMessage();
                }
            }
        }

        $editing = false;
        require APP_PATH . '/views/admin/modifiers/form.php';
    }

    public function edit(int $id): void {
        requireManager();

        $model    = new Modifier();
        $modifier = $model->findById($id);
        if (!$modifier) {
            setFlash('error', 'Modifier not found.');
            redirect('/modifiers');
            return;
        }

        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken();
            $data = [
                'name'       => trim($_POST['name'] ?? ''),
                'price'      => $_POST['price'] ?? '',
                'sort_order' => (int)($_POST['sort_order'] ?? 0),
                'is_active'  => isset($_POST['is_active']) ? 1 : 0,
            ];

            if (strlen($data['name']) < 1) $errors[] = 'Name is required.';
            if (!is_numeric($data['price']) || (float)$data['price'] < 0) $errors[] = 'Price must be a non-negative number.';

            if (empty($errors)) {
                try {
                    $model->update($id, $data);
                    setFlash('success', 'Modifier updated.');
                    redirect('/modifiers');
                    return;
                } catch (Exception $e) {
                    $errors[] = 'Error: ' . $e->getMessage();
                }
            }

            $modifier = array_merge($modifier, $data);
        }

        $editing = true;
        require APP_PATH . '/views/admin/modifiers/form.php';
    }

    public function delete(int $id): void {
        requireManager();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken();
            (new Modifier())->delete($id);
            setFlash('success', 'Modifier deleted.');
        }
        redirect('/modifiers');
    }
}
