<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

const APP_VERSION = '1.1.0';
$pdo = get_pdo($config);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die("403 Forbidden: Invalid CSRF Token");
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_member') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Ten va Email hop le khong duoc de trong!';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO members (name, email) VALUES (?, ?)");
                $stmt->execute([$name, $email]);
                header("Location: index.php?msg=member_added");
                exit;
            } catch (PDOException $ex) {
                $error = 'Loi: Email nay da ton tai trong he thong!';
            }
        }
    }

    if ($action === 'save_task') {
        $taskId = !empty($_POST['task_id']) ? (int)$_POST['task_id'] : null;
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $memberId = (int)($_POST['member_id'] ?? 0);
        $dueDate = $_POST['due_date'] ?? '';
        $status = $_POST['status'] ?? 'todo';

        if ($title === '' || $memberId <= 0 || empty($dueDate)) {
            $error = 'Vui long dien day du cac truong bat buoc!';
        } else {
            if ($taskId) {
                $stmt = $pdo->prepare("UPDATE tasks SET title = ?, description = ?, member_id = ?, due_date = ?, status = ? WHERE id = ?");
                $stmt->execute([$title, $description, $memberId, $dueDate, $status, $taskId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO tasks (title, description, member_id, due_date, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$title, $description, $memberId, $dueDate, $status]);
            }
            header("Location: index.php?msg=task_saved");
            exit;
        }
    }

    if ($action === 'delete_task') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        if ($taskId > 0) {
            $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
            $stmt->execute([$taskId]);
            header("Location: index.php?msg=task_deleted");
            exit;
        }
    }

    if ($action === 'delete_member') {
        $mId = (int)($_POST['member_id'] ?? 0);
        if ($mId > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM members WHERE id = ?");
                $stmt->execute([$mId]);
                header("Location: index.php?msg=member_deleted");
                exit;
            } catch (PDOException $ex) {
                $error = 'Khong the xoa thanh vien nay vi van con cong viec duoc giao!';
            }
        }
    }
}

$statusFilter = $_GET['status'] ?? '';
$where = '';
$params = [];
if (in_array($statusFilter, ['todo', 'doing', 'done'], true)) {
    $where = 'WHERE t.status = ?';
    $params[] = $statusFilter;
}

$stmt = $pdo->prepare("SELECT t.*, m.name as member_name FROM tasks t JOIN members m ON t.member_id = m.id $where ORDER BY t.due_date ASC");
$stmt->execute($params);
$tasks = $stmt->fetchAll();

$members = $pdo->query("SELECT * FROM members ORDER BY name ASC")->fetchAll();

$counts = ['total' => 0, 'todo' => 0, 'doing' => 0, 'done' => 0, 'overdue' => 0];
$today = date('Y-m-d');
$allTasks = $pdo->query("SELECT status, due_date FROM tasks")->fetchAll();
$counts['total'] = count($allTasks);
foreach ($allTasks as $row) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']]++;
    }
    if ($row['status'] !== 'done' && $row['due_date'] < $today) {
        $counts['overdue']++;
    }
}

$editTask = null;
if (!empty($_GET['edit_task'])) {
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_task']]);
    $editTask = $stmt->fetch() ?: null;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title><?= e($config['app']['name']) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <div class="header">
        <h1><?= e($config['app']['project_name']) ?></h1>
        <p>Phien ban: <?= APP_VERSION ?> | He thong quan ly cong viec nhom</p>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <?php if (isset($_GET['msg'])): ?><div class="alert alert-success">Thao tac thanh cong!</div><?php endif; ?>

    <div class="stats">
        <div class="stat-box"><div class="num"><?= $counts['total'] ?></div>Tong cong viec</div>
        <div class="stat-box"><div class="num"><?= $counts['todo'] ?></div>Chua lam</div>
        <div class="stat-box"><div class="num"><?= $counts['doing'] ?></div>Dang lam</div>
        <div class="stat-box"><div class="num"><?= $counts['done'] ?></div>Hoan thanh</div>
        <div class="stat-box"><div class="num" style="color:red;"><?= $counts['overdue'] ?></div>Qua han</div>
    </div>

    <div class="grid">
        <div>
            <div class="card">
                <h3>Them thanh vien</h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="save_member">
                    <div class="form-group">
                        <label>Ho va ten:</label>
                        <input type="text" name="name" required placeholder="Nguyen Van A">
                    </div>
                    <div class="form-group">
                        <label>Email:</label>
                        <input type="email" name="email" required placeholder="a@example.com">
                    </div>
                    <button type="submit">Them thanh vien</button>
                </form>
            </div>

            <div class="card">
                <h3><?= $editTask ? 'Sua cong viec' : 'Giao viec moi' ?></h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="save_task">
                    <?php if ($editTask): ?>
                        <input type="hidden" name="task_id" value="<?= $editTask['id'] ?>">
                    <?php endif; ?>
                    <div class="form-group">
                        <label>Tieu de cong viec:</label>
                        <input type="text" name="title" required value="<?= e($editTask['title'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Nguoi phu trach:</label>
                        <select name="member_id" required>
                            <option value="">-- Chon thanh vien --</option>
                            <?php foreach ($members as $m): ?>
                                <option value="<?= $m['id'] ?>" <?= ($editTask && $editTask['member_id'] == $m['id']) ? 'selected' : '' ?>>
                                    <?= e($m['name']) ?> (<?= e($m['email']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Han chot:</label>
                        <input type="date" name="due_date" required value="<?= e($editTask['due_date'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Trang thai:</label>
                        <select name="status">
                            <option value="todo" <?= ($editTask && $editTask['status'] === 'todo') ? 'selected' : '' ?>>Chua lam</option>
                            <option value="doing" <?= ($editTask && $editTask['status'] === 'doing') ? 'selected' : '' ?>>Dang lam</option>
                            <option value="done" <?= ($editTask && $editTask['status'] === 'done') ? 'selected' : '' ?>>Hoan thanh</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Mo ta chi tiet:</label>
                        <textarea name="description" rows="3"><?= e($editTask['description'] ?? '') ?></textarea>
                    </div>
                    <button type="submit"><?= $editTask ? 'Cap nhat cong viec' : 'Luu cong viec' ?></button>
                    <?php if ($editTask): ?><a href="index.php" style="margin-left: 10px;">Huy sua</a><?php endif; ?>
                </form>
            </div>
        </div>

        <div>
            <div class="card">
                <h3>Danh sach cong viec</h3>
                <div class="filter-links">
                    <a href="index.php" class="<?= empty($statusFilter) ? 'active' : '' ?>">Tat ca</a>
                    <a href="index.php?status=todo" class="<?= $statusFilter === 'todo' ? 'active' : '' ?>">Chua lam</a>
                    <a href="index.php?status=doing" class="<?= $statusFilter === 'doing' ? 'active' : '' ?>">Dang lam</a>
                    <a href="index.php?status=done" class="<?= $statusFilter === 'done' ? 'active' : '' ?>">Hoan thanh</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Tieu de</th>
                            <th>Nguoi lam</th>
                            <th>Han chot</th>
                            <th>Trang thai</th>
                            <th>Thao tac</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tasks)): ?>
                            <tr><td colspan="5" style="text-align:center;">Chua co cong viec nao.</td></tr>
                        <?php else: ?>
                            <?php foreach ($tasks as $t): ?>
                                <tr>
                                    <td><strong><?= e($t['title']) ?></strong></td>
                                    <td><?= e($t['member_name']) ?></td>
                                    <td><?= e($t['due_date']) ?></td>
                                    <td>
                                        <span class="badge badge-<?= e($t['status']) ?>">
                                            <?= $t['status'] === 'todo' ? 'Chua lam' : ($t['status'] === 'doing' ? 'Dang lam' : 'Hoan thanh') ?>
                                        </span>
                                        <?php if ($t['status'] !== 'done' && $t['due_date'] < $today): ?>
                                            <span class="badge badge-overdue">Qua han</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="index.php?edit_task=<?= $t['id'] ?>">Sua</a> |
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Ban co chac chan muon xoa?');">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="delete_task">
                                            <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                                            <button type="submit" style="background:none; border:none; color:red; padding:0; cursor:pointer; text-decoration:underline;">Xoa</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h3>Danh sach thanh vien</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Ho ten</th>
                            <th>Email</th>
                            <th>Thao tac</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($members)): ?>
                            <tr><td colspan="4" style="text-align:center;">Chua co thanh vien nao.</td></tr>
                        <?php else: ?>
                            <?php foreach ($members as $m): ?>
                                <tr>
                                    <td><?= $m['id'] ?></td>
                                    <td><?= e($m['name']) ?></td>
                                    <td><?= e($m['email']) ?></td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Xoa thanh vien nay?');">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="delete_member">
                                            <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                                            <button type="submit" style="background:none; border:none; color:red; padding:0; cursor:pointer; text-decoration:underline;">Xoa</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
