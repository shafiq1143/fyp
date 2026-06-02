<?php
declare(strict_types=1);

function handle_login(PDO $pdo): void
{
    $in = json_in();
    $email = trim((string) ($in['email'] ?? ''));
    $password = (string) ($in['password'] ?? '');
    if ($email === '' || $password === '') {
        json_out(['ok' => false, 'error' => 'Email and password required'], 422);
    }
    $st = $pdo->prepare('SELECT id, email, password_hash, role, full_name, is_active FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !(int) $u['is_active'] || !password_verify($password, $u['password_hash'])) {
        json_out(['ok' => false, 'error' => 'Invalid credentials'], 401);
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $u['id'];
    log_activity($pdo, (int) $u['id'], 'login', null);
    json_out([
        'ok' => true,
        'session_id' => session_id(),
        'user' => [
            'id' => (int) $u['id'],
            'email' => $u['email'],
            'role' => $u['role'],
            'full_name' => $u['full_name'],
        ],
    ]);
}

function handle_logout(PDO $pdo): void
{
    $uid = $_SESSION['user_id'] ?? null;
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    if ($uid) {
        log_activity($pdo, (int) $uid, 'logout', null);
    }
    json_out(['ok' => true]);
}

function handle_me(PDO $pdo): void
{
    if (empty($_SESSION['user_id'])) {
        json_out(['ok' => false, 'user' => null], 200);
    }
    $st = $pdo->prepare('SELECT id, email, role, full_name, phone, student_code, employee_code FROM users WHERE id = ? AND is_active = 1');
    $st->execute([(int) $_SESSION['user_id']]);
    $u = $st->fetch();
    if (!$u) {
        json_out(['ok' => false, 'user' => null], 200);
    }
    json_out(['ok' => true, 'user' => $u, 'session_id' => session_id()]);
}

function handle_admin_stats(PDO $pdo): void
{
    require_role($pdo, 'admin');
    $counts = [
        'students' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn(),
        'teachers' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='teacher'")->fetchColumn(),
        'courses' => (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn(),
        'classes' => (int) $pdo->query('SELECT COUNT(*) FROM school_classes')->fetchColumn(),
        'pending_results' => (int) $pdo->query("SELECT COUNT(*) FROM final_results WHERE status='pending_approval'")->fetchColumn(),
    ];
    json_out(['ok' => true, 'stats' => $counts]);
}

function handle_admin_users(PDO $pdo, string $method): void
{
    $me = require_role($pdo, 'admin');
    if ($method === 'GET') {
        $role = $_GET['role'] ?? null;
        $sql = 'SELECT id, email, role, full_name, phone, student_code, employee_code, is_active, created_at FROM users WHERE 1=1';
        $params = [];
        if ($role && in_array($role, ['teacher', 'student', 'admin'], true)) {
            $sql .= ' AND role = ?';
            $params[] = $role;
        }
        $sql .= ' ORDER BY created_at DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        json_out(['ok' => true, 'users' => $st->fetchAll()]);
    }
    if ($method === 'POST') {
        $in = json_in();
        $email = trim((string) ($in['email'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $role = (string) ($in['role'] ?? '');
        $full_name = trim((string) ($in['full_name'] ?? ''));
        if ($email === '' || $full_name === '' || !in_array($role, ['teacher', 'student'], true)) {
            json_out(['ok' => false, 'error' => 'Invalid payload'], 422);
        }
        if ($password === '') {
            $password = 'ChangeMe@123';
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $phone = isset($in['phone']) ? trim((string) $in['phone']) : null;
        $student_code = isset($in['student_code']) ? trim((string) $in['student_code']) : null;
        $employee_code = isset($in['employee_code']) ? trim((string) $in['employee_code']) : null;
        try {
            $st = $pdo->prepare('INSERT INTO users (email, password_hash, role, full_name, phone, student_code, employee_code) VALUES (?,?,?,?,?,?,?)');
            $st->execute([$email, $hash, $role, $full_name, $phone, $student_code, $employee_code]);
            $id = (int) $pdo->lastInsertId();
            log_activity($pdo, (int) $me['id'], 'user_create', "id=$id role=$role");

            if ($role === 'student' && $email !== '') {
                $subject = 'Welcome to College Management System';
                $body = "<h2>Welcome, $full_name!</h2>"
                      . "<p>Your student account has been created successfully.</p>"
                      . "<p><b>Email:</b> $email</p>"
                      . "<p><b>Password:</b> " . htmlspecialchars($password, ENT_QUOTES | ENT_SUBSTITUTE) . "</p>"
                      . "<p>Please log in and update your password immediately.</p>"
                      . "<hr/><p>This message was sent by your administrator.</p>";
                send_system_email($email, $subject, $body);
            }

            json_out(['ok' => true, 'id' => $id]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                json_out(['ok' => false, 'error' => 'Email or code already exists'], 409);
            }
            throw $e;
        }
    }
    if ($method === 'PATCH') {
        $in = json_in();
        $id = (int) ($in['id'] ?? 0);
        if ($id < 1) {
            json_out(['ok' => false, 'error' => 'id required'], 422);
        }
        $fields = [];
        $params = [];
        foreach (['full_name', 'phone', 'student_code', 'employee_code'] as $f) {
            if (array_key_exists($f, $in)) {
                $fields[] = "$f = ?";
                $params[] = $in[$f];
            }
        }
        if (isset($in['is_active'])) {
            $fields[] = 'is_active = ?';
            $params[] = (int) (bool) $in['is_active'];
        }
        if (isset($in['password']) && (string) $in['password'] !== '') {
            $fields[] = 'password_hash = ?';
            $params[] = password_hash((string) $in['password'], PASSWORD_DEFAULT);
        }
        if ($fields === []) {
            json_out(['ok' => false, 'error' => 'Nothing to update'], 422);
        }
        $params[] = $id;
        $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        log_activity($pdo, (int) $me['id'], 'user_update', "id=$id");
        json_out(['ok' => true]);
    }
    if ($method === 'DELETE') {
        $in = json_in();
        $id = (int) ($in['id'] ?? $_GET['id'] ?? 0);
        if ($id < 1 || $id === (int) $me['id']) {
            json_out(['ok' => false, 'error' => 'Invalid id'], 422);
        }
        $pdo->prepare('DELETE FROM users WHERE id = ? AND role != ?')->execute([$id, 'admin']);
        log_activity($pdo, (int) $me['id'], 'user_delete', "id=$id");
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

function handle_activity(PDO $pdo): void
{
    require_role($pdo, 'admin');
    $st = $pdo->query('SELECT a.id, a.user_id, a.action, a.details, a.created_at, u.email FROM activity_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 200');
    json_out(['ok' => true, 'items' => $st->fetchAll()]);
}

function handle_settings(PDO $pdo, string $method): void
{
    if ($method === 'GET') {
        require_role($pdo, 'admin', 'teacher', 'student');
        $st = $pdo->query('SELECT `key`, `value` FROM site_settings');
        $rows = $st->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = $r['value'];
        }
        json_out(['ok' => true, 'settings' => $out]);
    }
    if ($method === 'PATCH') {
        $me = require_role($pdo, 'admin');
        $in = json_in();
        foreach ($in as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            $pdo->prepare('INSERT INTO site_settings (`key`, `value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$k, (string) $v]);
        }
        log_activity($pdo, (int) $me['id'], 'settings_update', null);
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

function handle_classes_crud(PDO $pdo, string $method): void
{
    $me = require_role($pdo, 'admin', 'teacher', 'student');
    if ($method === 'GET') {
        $st = $pdo->query('SELECT * FROM school_classes ORDER BY name');
        json_out(['ok' => true, 'classes' => $st->fetchAll()]);
    }
    if ($method === 'POST') {
        require_role($pdo, 'admin');
        $in = json_in();
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            json_out(['ok' => false, 'error' => 'Name required'], 422);
        }
        $pdo->prepare('INSERT INTO school_classes (name, program, year_level) VALUES (?,?,?)')->execute([
            $name,
            $in['program'] ?? null,
            $in['year_level'] ?? null,
        ]);
        json_out(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    }
    if ($method === 'PATCH') {
        require_role($pdo, 'admin');
        $in = json_in();
        $id = (int) ($in['id'] ?? 0);
        if ($id < 1) {
            json_out(['ok' => false, 'error' => 'id required'], 422);
        }
        $pdo->prepare('UPDATE school_classes SET name=?, program=?, year_level=? WHERE id=?')->execute([
            $in['name'] ?? '',
            $in['program'] ?? null,
            $in['year_level'] ?? null,
            $id,
        ]);
        json_out(['ok' => true]);
    }
    if ($method === 'DELETE') {
        require_role($pdo, 'admin');
        $in = json_in();
        $id = (int) ($in['id'] ?? $_GET['id'] ?? 0);
        if ($id < 1) {
            json_out(['ok' => false, 'error' => 'id required'], 422);
        }
        $pdo->prepare('DELETE FROM school_classes WHERE id=?')->execute([$id]);
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

function handle_sections(PDO $pdo, string $method): void
{
    require_role($pdo, 'admin', 'teacher', 'student');
    $classId = (int) ($_GET['class_id'] ?? 0);
    if ($method === 'GET') {
        if ($classId < 1) {
            json_out(['ok' => false, 'error' => 'class_id required'], 422);
        }
        $st = $pdo->prepare('SELECT * FROM sections WHERE class_id = ? ORDER BY name');
        $st->execute([$classId]);
        json_out(['ok' => true, 'sections' => $st->fetchAll()]);
    }
    if ($method === 'POST') {
        require_role($pdo, 'admin');
        $in = json_in();
        $class_id = (int) ($in['class_id'] ?? 0);
        $name = trim((string) ($in['name'] ?? ''));
        $capacity = (int) ($in['capacity'] ?? 50);
        if ($class_id < 1 || $name === '') {
            json_out(['ok' => false, 'error' => 'class_id and name required'], 422);
        }
        $pdo->prepare('INSERT INTO sections (class_id, name, capacity) VALUES (?,?,?)')->execute([$class_id, $name, $capacity]);
        json_out(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    }
    if ($method === 'DELETE') {
        require_role($pdo, 'admin');
        $in = json_in();
        $id = (int) ($in['id'] ?? 0);
        if ($id < 1) {
            json_out(['ok' => false, 'error' => 'id required'], 422);
        }
        $pdo->prepare('DELETE FROM sections WHERE id=?')->execute([$id]);
        json_out(['ok' => true]);
    }
    if ($method === 'PATCH') {
        require_role($pdo, 'admin');
        $in = json_in();
        $id = (int) ($in['id'] ?? 0);
        if ($id < 1) {
            json_out(['ok' => false, 'error' => 'id required'], 422);
        }
        $fields = [];
        $params = [];
        if (isset($in['name'])) { $fields[] = 'name = ?'; $params[] = $in['name']; }
        if (isset($in['capacity'])) { $fields[] = 'capacity = ?'; $params[] = (int) $in['capacity']; }
        if ($fields === []) {
            json_out(['ok' => false, 'error' => 'Nothing to update'], 422);
        }
        $params[] = $id;
        $pdo->prepare('UPDATE sections SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

function handle_courses_crud(PDO $pdo, string $method): void
{
    require_role($pdo, 'admin', 'teacher', 'student');
    if ($method === 'GET') {
        $st = $pdo->query('SELECT * FROM courses ORDER BY code');
        json_out(['ok' => true, 'courses' => $st->fetchAll()]);
    }
    if ($method === 'POST') {
        require_role($pdo, 'admin');
        $in = json_in();
        $code = trim((string) ($in['code'] ?? ''));
        $title = trim((string) ($in['title'] ?? ''));
        if ($code === '' || $title === '') {
            json_out(['ok' => false, 'error' => 'code and title required'], 422);
        }
        $pdo->prepare('INSERT INTO courses (code, title, description, credits) VALUES (?,?,?,?)')->execute([
            $code,
            $title,
            $in['description'] ?? null,
            $in['credits'] ?? 3,
        ]);
        json_out(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    }
    if ($method === 'PATCH') {
        require_role($pdo, 'admin');
        $in = json_in();
        $id = (int) ($in['id'] ?? 0);
        if ($id < 1) {
            json_out(['ok' => false, 'error' => 'id required'], 422);
        }
        $pdo->prepare('UPDATE courses SET code=?, title=?, description=?, credits=? WHERE id=?')->execute([
            $in['code'] ?? '',
            $in['title'] ?? '',
            $in['description'] ?? null,
            $in['credits'] ?? 3,
            $id,
        ]);
        json_out(['ok' => true]);
    }
    if ($method === 'DELETE') {
        require_role($pdo, 'admin');
        $in = json_in();
        $id = (int) ($in['id'] ?? $_GET['id'] ?? 0);
        if ($id < 1) {
            json_out(['ok' => false, 'error' => 'id required'], 422);
        }
        $pdo->prepare('DELETE FROM courses WHERE id=?')->execute([$id]);
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

function handle_offerings(PDO $pdo, string $method): void
{
    $me = require_role($pdo, 'admin', 'teacher', 'student');
    if ($method === 'GET') {
        $sql = 'SELECT co.id, co.course_id, co.section_id, co.teacher_id, co.semester, co.academic_year,
                c.code AS course_code, c.title AS course_title,
                sc.name AS class_name, sec.name AS section_name,
                u.full_name AS teacher_name
                FROM course_offerings co
                JOIN courses c ON c.id = co.course_id
                JOIN sections sec ON sec.id = co.section_id
                JOIN school_classes sc ON sc.id = sec.class_id
                JOIN users u ON u.id = co.teacher_id
                WHERE 1=1';
        $params = [];
        if ($me['role'] === 'teacher') {
            $sql .= ' AND co.teacher_id = ?';
            $params[] = (int) $me['id'];
        }
        $sql .= ' ORDER BY co.academic_year DESC, co.semester, c.code';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        json_out(['ok' => true, 'offerings' => $st->fetchAll()]);
    }
    if ($method === 'POST') {
        require_role($pdo, 'admin');
        $in = json_in();
        $course_id = (int) ($in['course_id'] ?? 0);
        $section_id = (int) ($in['section_id'] ?? 0);
        $teacher_id = (int) ($in['teacher_id'] ?? 0);
        if ($course_id < 1 || $section_id < 1 || $teacher_id < 1) {
            json_out(['ok' => false, 'error' => 'course_id, section_id, teacher_id required'], 422);
        }
        $sem = trim((string) ($in['semester'] ?? 'Fall'));
        $year = trim((string) ($in['academic_year'] ?? '2025-2026'));
        try {
            $pdo->prepare('INSERT INTO course_offerings (course_id, section_id, teacher_id, semester, academic_year) VALUES (?,?,?,?,?)')
                ->execute([$course_id, $section_id, $teacher_id, $sem, $year]);
            json_out(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                json_out(['ok' => false, 'error' => 'Offering already exists for this course/section/term'], 409);
            }
            throw $e;
        }
    }
    if ($method === 'PATCH') {
        require_role($pdo, 'admin');
        $in = json_in();
        $id = (int) ($in['id'] ?? 0);
        if ($id < 1) {
            json_out(['ok' => false, 'error' => 'id required'], 422);
        }
        $fields = [];
        $params = [];
        if (isset($in['teacher_id'])) { $fields[] = 'teacher_id = ?'; $params[] = (int) $in['teacher_id']; }
        if (isset($in['semester'])) { $fields[] = 'semester = ?'; $params[] = $in['semester']; }
        if (isset($in['academic_year'])) { $fields[] = 'academic_year = ?'; $params[] = $in['academic_year']; }
        if ($fields === []) {
            json_out(['ok' => false, 'error' => 'Nothing to update'], 422);
        }
        $params[] = $id;
        $pdo->prepare('UPDATE course_offerings SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        json_out(['ok' => true]);
    }
    if ($method === 'DELETE') {
        require_role($pdo, 'admin');
        $in = json_in();
        $id = (int) ($in['id'] ?? $_GET['id'] ?? 0);
        if ($id < 1) {
            json_out(['ok' => false, 'error' => 'id required'], 422);
        }
        $pdo->prepare('DELETE FROM course_offerings WHERE id=?')->execute([$id]);
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

function handle_enrollments(PDO $pdo, string $method): void
{
    $me = require_role($pdo, 'admin', 'teacher', 'student');
    if ($method === 'GET') {
        if ($me['role'] === 'student') {
            $st = $pdo->prepare(
                'SELECT e.*, co.id AS offering_id, c.code, c.title, u.full_name AS teacher_name, co.semester, co.academic_year
                 FROM enrollments e
                 JOIN course_offerings co ON co.id = e.course_offering_id
                 JOIN courses c ON c.id = co.course_id
                 JOIN users u ON u.id = co.teacher_id
                 WHERE e.student_id = ?
                 ORDER BY e.requested_at DESC'
            );
            $st->execute([(int) $me['id']]);
            json_out(['ok' => true, 'enrollments' => $st->fetchAll()]);
        }
        if ($me['role'] === 'teacher') {
            $st = $pdo->prepare(
                'SELECT e.*, stu.full_name AS student_name, stu.email AS student_email, stu.student_code,
                 c.code, c.title, co.semester, co.academic_year
                 FROM enrollments e
                 JOIN course_offerings co ON co.id = e.course_offering_id
                 JOIN courses c ON c.id = co.course_id
                 JOIN users stu ON stu.id = e.student_id
                 WHERE co.teacher_id = ?
                 ORDER BY e.status, e.requested_at DESC'
            );
            $st->execute([(int) $me['id']]);
            json_out(['ok' => true, 'enrollments' => $st->fetchAll()]);
        }
        $st = $pdo->query(
            'SELECT e.*, stu.full_name AS student_name, c.code, c.title
             FROM enrollments e
             JOIN users stu ON stu.id = e.student_id
             JOIN course_offerings co ON co.id = e.course_offering_id
             JOIN courses c ON c.id = co.course_id
             ORDER BY e.requested_at DESC LIMIT 500'
        );
        json_out(['ok' => true, 'enrollments' => $st->fetchAll()]);
    }
    if ($method === 'POST') {
        $in = json_in();
        $action = (string) ($in['action'] ?? '');
        if ($action === 'request') {
            require_role($pdo, 'student');
            $oid = (int) ($in['course_offering_id'] ?? 0);
            if ($oid < 1) {
                json_out(['ok' => false, 'error' => 'course_offering_id required'], 422);
            }
            try {
                $pdo->prepare('INSERT INTO enrollments (student_id, course_offering_id, status) VALUES (?,?,?)')
                    ->execute([(int) $me['id'], $oid, 'pending']);
                log_activity($pdo, (int) $me['id'], 'enrollment_request', "offering=$oid");
                json_out(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate')) {
                    json_out(['ok' => false, 'error' => 'Already enrolled or pending'], 409);
                }
                throw $e;
            }
        }
        if ($action === 'decide') {
            require_role($pdo, 'teacher');
            $eid = (int) ($in['enrollment_id'] ?? 0);
            $status = (string) ($in['status'] ?? '');
            if ($eid < 1 || !in_array($status, ['approved', 'rejected'], true)) {
                json_out(['ok' => false, 'error' => 'enrollment_id and status required'], 422);
            }
            $st = $pdo->prepare(
                'SELECT e.id FROM enrollments e
                 JOIN course_offerings co ON co.id = e.course_offering_id
                 WHERE e.id = ? AND co.teacher_id = ?'
            );
            $st->execute([$eid, (int) $me['id']]);
            if (!$st->fetch()) {
                json_out(['ok' => false, 'error' => 'Not found'], 404);
            }
            $pdo->prepare('UPDATE enrollments SET status=?, decided_at=NOW() WHERE id=?')->execute([$status, $eid]);
            log_activity($pdo, (int) $me['id'], 'enrollment_decide', "id=$eid $status");
            json_out(['ok' => true]);
        }
        json_out(['ok' => false, 'error' => 'Unknown action'], 422);
    }
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

function handle_assignments(PDO $pdo, array $config, string $method): void
{
    $me = require_role($pdo, 'admin', 'teacher', 'student');
    if ($method === 'GET') {
        $oid = (int) ($_GET['course_offering_id'] ?? 0);
        if ($oid < 1) {
            json_out(['ok' => false, 'error' => 'course_offering_id required'], 422);
        }
        if ($me['role'] === 'teacher') {
            $chk = $pdo->prepare('SELECT 1 FROM course_offerings WHERE id=? AND teacher_id=?');
            $chk->execute([$oid, (int) $me['id']]);
            if (!$chk->fetch()) {
                json_out(['ok' => false, 'error' => 'Forbidden'], 403);
            }
        }
        if ($me['role'] === 'student') {
            $chk = $pdo->prepare("SELECT 1 FROM enrollments WHERE course_offering_id=? AND student_id=? AND status='approved'");
            $chk->execute([$oid, (int) $me['id']]);
            if (!$chk->fetch()) {
                json_out(['ok' => false, 'error' => 'Not enrolled'], 403);
            }
        }
        $st = $pdo->prepare('SELECT * FROM assignments WHERE course_offering_id = ? ORDER BY due_at DESC, id DESC');
        $st->execute([$oid]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            if (!empty($r['attachment_path'])) {
                $r['attachment_url'] = $config['upload_public_path'] . '/' . str_replace('\\', '/', $r['attachment_path']);
            }
            if ($me['role'] === 'student') {
                $su = $pdo->prepare('SELECT id, file_path, text_content, submitted_at, marks_obtained, feedback FROM assignment_submissions WHERE assignment_id = ? AND student_id = ?');
                $su->execute([(int) $r['id'], (int) $me['id']]);
                $sub = $su->fetch();
                if ($sub && !empty($sub['file_path'])) {
                    $sub['file_url'] = $config['upload_public_path'] . '/' . str_replace('\\', '/', $sub['file_path']);
                }
                $r['my_submission'] = $sub ?: null;
            }
        }
        unset($r);
        json_out(['ok' => true, 'assignments' => $rows]);
    }
    if ($method === 'POST') {
        require_role($pdo, 'teacher');
        $in = json_in();
        $oid = (int) ($in['course_offering_id'] ?? 0);
        $title = trim((string) ($in['title'] ?? ''));
        if ($oid < 1 || $title === '') {
            json_out(['ok' => false, 'error' => 'course_offering_id and title required'], 422);
        }
        $chk = $pdo->prepare('SELECT 1 FROM course_offerings WHERE id=? AND teacher_id=?');
        $chk->execute([$oid, (int) $me['id']]);
        if (!$chk->fetch()) {
            json_out(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        $type = (string) ($in['assignment_type'] ?? 'assignment');
        if (!in_array($type, ['assignment', 'quiz', 'paper'], true)) {
            $type = 'assignment';
        }
        $pdo->prepare(
            'INSERT INTO assignments (course_offering_id, title, description, assignment_type, due_at, total_marks, attachment_path) VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $oid,
            $title,
            $in['description'] ?? null,
            $type,
            !empty($in['due_at']) ? $in['due_at'] : null,
            $in['total_marks'] ?? 100,
            $in['attachment_path'] ?? null,
        ]);
        $aid = (int) $pdo->lastInsertId();
        log_activity($pdo, (int) $me['id'], 'assignment_create', "id=$aid");
        json_out(['ok' => true, 'id' => $aid]);
    }
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

function handle_assignment_upload(PDO $pdo, array $config): void
{
    $me = require_role($pdo, 'teacher');
    $oid = (int) ($_POST['course_offering_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    if ($oid < 1 || $title === '') {
        json_out(['ok' => false, 'error' => 'course_offering_id and title required'], 422);
    }
    $chk = $pdo->prepare('SELECT 1 FROM course_offerings WHERE id=? AND teacher_id=?');
    $chk->execute([$oid, (int) $me['id']]);
    if (!$chk->fetch()) {
        json_out(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    $type = (string) ($_POST['assignment_type'] ?? 'assignment');
    if (!in_array($type, ['assignment', 'quiz', 'paper'], true)) {
        $type = 'assignment';
    }
    $path = null;
    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        ensure_upload_dir($config['upload_dir'] . '/assignments');
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $safe = 'a_' . bin2hex(random_bytes(8)) . ($ext ? '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext) : '');
        $full = $config['upload_dir'] . '/assignments/' . $safe;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $full)) {
            json_out(['ok' => false, 'error' => 'Upload failed'], 500);
        }
        $path = 'assignments/' . $safe;
    }
    $pdo->prepare(
        'INSERT INTO assignments (course_offering_id, title, description, assignment_type, due_at, total_marks, attachment_path) VALUES (?,?,?,?,?,?,?)'
    )->execute([
        $oid,
        $title,
        $_POST['description'] ?? null,
        $type,
        !empty($_POST['due_at']) ? $_POST['due_at'] : null,
        $_POST['total_marks'] ?? 100,
        $path,
    ]);
    json_out(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
}

function handle_submission_upload(PDO $pdo, array $config): void
{
    $me = require_role($pdo, 'student');
    $aid = (int) ($_POST['assignment_id'] ?? 0);
    if ($aid < 1) {
        json_out(['ok' => false, 'error' => 'assignment_id required'], 422);
    }
    $st = $pdo->prepare(
        'SELECT a.id, a.course_offering_id FROM assignments a
         JOIN enrollments e ON e.course_offering_id = a.course_offering_id AND e.student_id = ? AND e.status = ?
         WHERE a.id = ?'
    );
    $st->execute([(int) $me['id'], 'approved', $aid]);
    $row = $st->fetch();
    if (!$row) {
        json_out(['ok' => false, 'error' => 'Not allowed'], 403);
    }
    $text = $_POST['text_content'] ?? null;
    $path = null;
    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        ensure_upload_dir($config['upload_dir'] . '/submissions');
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $safe = 's_' . bin2hex(random_bytes(8)) . ($ext ? '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext) : '');
        $full = $config['upload_dir'] . '/submissions/' . $safe;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $full)) {
            json_out(['ok' => false, 'error' => 'Upload failed'], 500);
        }
        $path = 'submissions/' . $safe;
    }
    $pdo->prepare(
        'INSERT INTO assignment_submissions (assignment_id, student_id, file_path, text_content) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), text_content = VALUES(text_content), submitted_at = CURRENT_TIMESTAMP'
    )->execute([$aid, (int) $me['id'], $path, $text]);
    log_activity($pdo, (int) $me['id'], 'assignment_submit', "assignment=$aid");
    json_out(['ok' => true]);
}

function handle_submissions_list(PDO $pdo, array $config): void
{
    $me = require_role($pdo, 'teacher');
    $aid = (int) ($_GET['assignment_id'] ?? 0);
    if ($aid < 1) {
        json_out(['ok' => false, 'error' => 'assignment_id required'], 422);
    }
    $st = $pdo->prepare(
        'SELECT a.course_offering_id FROM assignments a WHERE a.id = ? AND EXISTS (
            SELECT 1 FROM course_offerings co WHERE co.id = a.course_offering_id AND co.teacher_id = ?
        )'
    );
    $st->execute([$aid, (int) $me['id']]);
    if (!$st->fetch()) {
        json_out(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    $st = $pdo->prepare(
        'SELECT s.*, u.full_name, u.email FROM assignment_submissions s
         JOIN users u ON u.id = s.student_id WHERE s.assignment_id = ? ORDER BY s.submitted_at DESC'
    );
    $st->execute([$aid]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        if (!empty($r['file_path'])) {
            $r['file_url'] = $config['upload_public_path'] . '/' . str_replace('\\', '/', $r['file_path']);
        }
    }
    unset($r);
    json_out(['ok' => true, 'submissions' => $rows]);
}

function handle_grade_submission(PDO $pdo): void
{
    $me = require_role($pdo, 'teacher');
    $in = json_in();
    $sid = (int) ($in['submission_id'] ?? 0);
    if ($sid < 1) {
        json_out(['ok' => false, 'error' => 'submission_id required'], 422);
    }
    $st = $pdo->prepare(
        'SELECT s.id FROM assignment_submissions s
         JOIN assignments a ON a.id = s.assignment_id
         JOIN course_offerings co ON co.id = a.course_offering_id
         WHERE s.id = ? AND co.teacher_id = ?'
    );
    $st->execute([$sid, (int) $me['id']]);
    if (!$st->fetch()) {
        json_out(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    $pdo->prepare('UPDATE assignment_submissions SET marks_obtained=?, feedback=? WHERE id=?')->execute([
        $in['marks_obtained'] ?? null,
        $in['feedback'] ?? null,
        $sid,
    ]);

    $studentInfo = $pdo->prepare(
        'SELECT s.student_id, u.full_name, u.email, a.title, c.code, c.title AS course_title
         FROM assignment_submissions s
         JOIN users u ON u.id = s.student_id
         JOIN assignments a ON a.id = s.assignment_id
         JOIN course_offerings co ON co.id = a.course_offering_id
         JOIN courses c ON c.id = co.course_id
         WHERE s.id = ?'
    );
    $studentInfo->execute([$sid]);
    $info = $studentInfo->fetch();
    if ($info && $info['email']) {
        $courseName = $info['code'] . ' - ' . $info['course_title'];
        $marks = $in['marks_obtained'] !== null ? (float) $in['marks_obtained'] : 'N/A';
        $feedback = trim((string) ($in['feedback'] ?? ''));
        $subject = "Assignment Graded: {$info['title']}";
        $body = "<h2>Assignment Graded</h2>"
              . "<p>Dear <b>{$info['full_name']}</b>,</p>"
              . "<p>Your assignment <b>{$info['title']}</b> for <b>$courseName</b> has been graded.</p>"
              . "<p><b>Marks:</b> $marks</p>"
              . ($feedback !== '' ? "<p><b>Feedback:</b><br/>" . nl2br(htmlspecialchars($feedback, ENT_QUOTES | ENT_SUBSTITUTE)) . "</p>" : '')
              . "<hr/><p>This message was sent by your administrator.</p>";
        send_system_email($info['email'], $subject, $body);
    }

    json_out(['ok' => true]);
}

function handle_attendance(PDO $pdo, string $method): void
{
    $me = require_role($pdo, 'teacher', 'student');
    if ($method === 'GET') {
        $oid = (int) ($_GET['course_offering_id'] ?? 0);
        if ($oid < 1) {
            json_out(['ok' => false, 'error' => 'course_offering_id required'], 422);
        }
        if ($me['role'] === 'teacher') {
            $chk = $pdo->prepare('SELECT 1 FROM course_offerings WHERE id=? AND teacher_id=?');
            $chk->execute([$oid, (int) $me['id']]);
            if (!$chk->fetch()) {
                json_out(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $st = $pdo->prepare(
                'SELECT * FROM attendance WHERE course_offering_id = ? ORDER BY class_date DESC, student_id'
            );
            $st->execute([$oid]);
            json_out(['ok' => true, 'attendance' => $st->fetchAll()]);
        }
        $chk = $pdo->prepare("SELECT 1 FROM enrollments WHERE course_offering_id=? AND student_id=? AND status='approved'");
        $chk->execute([$oid, (int) $me['id']]);
        if (!$chk->fetch()) {
            json_out(['ok' => false, 'error' => 'Not enrolled'], 403);
        }
        $st = $pdo->prepare(
            'SELECT class_date, status FROM attendance WHERE course_offering_id = ? AND student_id = ? ORDER BY class_date'
        );
        $st->execute([$oid, (int) $me['id']]);
        $rows = $st->fetchAll();
        $total = count($rows);
        $present = 0;
        foreach ($rows as $r) {
            if (in_array($r['status'], ['present', 'late', 'excused'], true)) {
                $present++;
            }
        }
        $pct = $total > 0 ? round(100 * $present / $total, 1) : null;
        json_out(['ok' => true, 'records' => $rows, 'percentage' => $pct, 'sessions_marked' => $total]);
    }
    if ($method === 'POST') {
        require_role($pdo, 'teacher');
        $in = json_in();
        $oid = (int) ($in['course_offering_id'] ?? 0);
        $class_date = (string) ($in['class_date'] ?? '');
        $records = $in['records'] ?? [];
        if ($oid < 1 || $class_date === '' || !is_array($records)) {
            json_out(['ok' => false, 'error' => 'course_offering_id, class_date, records required'], 422);
        }
        $chk = $pdo->prepare('SELECT 1 FROM course_offerings WHERE id=? AND teacher_id=?');
        $chk->execute([$oid, (int) $me['id']]);
        if (!$chk->fetch()) {
            json_out(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        $ins = $pdo->prepare(
            'INSERT INTO attendance (course_offering_id, student_id, class_date, status, marked_by)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), marked_by = VALUES(marked_by)'
        );
        $studentStmt = $pdo->prepare('SELECT full_name, email FROM users WHERE id = ?');
        foreach ($records as $rec) {
            $sid = (int) ($rec['student_id'] ?? 0);
            $status = (string) ($rec['status'] ?? 'present');
            if ($sid < 1 || !in_array($status, ['present', 'absent', 'late', 'excused'], true)) {
                continue;
            }
            $en = $pdo->prepare("SELECT 1 FROM enrollments WHERE course_offering_id=? AND student_id=? AND status='approved'");
            $en->execute([$oid, $sid]);
            if (!$en->fetch()) {
                continue;
            }
            $ins->execute([$oid, $sid, $class_date, $status, (int) $me['id']]);

            $studentStmt->execute([$sid]);
            $student = $studentStmt->fetch();
            if ($student && $student['email']) {
                $courseInfo = $pdo->prepare('SELECT c.code, c.title FROM course_offerings co JOIN courses c ON c.id = co.course_id WHERE co.id = ?');
                $courseInfo->execute([$oid]);
                $c = $courseInfo->fetch();
                $courseName = $c ? ($c['code'] . ' - ' . $c['title']) : "Course #$oid";
                $subject = "Attendance Marked: $courseName";
                $body = "<h2>Attendance Update</h2>"
                      . "<p>Dear <b>{$student['full_name']}</b>,</p>"
                      . "<p>Your attendance for <b>$courseName</b> on <b>$class_date</b> has been marked as <b>$status</b>.</p>"
                      . "<hr/><p>This message was sent by your administrator.</p>";
                send_system_email($student['email'], $subject, $body);
            }
        }
        log_activity($pdo, (int) $me['id'], 'attendance_save', "offering=$oid date=$class_date");

        // Notify Admin about Attendance Update
        $admin = $pdo->query("SELECT email FROM users WHERE role='admin' LIMIT 1")->fetch();
        if ($admin) {
            $courseInfo = $pdo->prepare("SELECT c.code, c.title FROM course_offerings co JOIN courses c ON c.id = co.course_id WHERE co.id = ?");
            $courseInfo->execute([$oid]);
            $c = $courseInfo->fetch();
            $courseName = $c ? ($c['code'] . ' - ' . $c['title']) : "Course #$oid";
            
            $subject = "Attendance Report Updated: $courseName";
            $body = "<h2>Attendance Update</h2>"
                  . "<p>Teacher <b>{$me['full_name']}</b> has updated the attendance for <b>$courseName</b>.</p>"
                  . "<p><b>Session Date:</b> $class_date</p>"
                  . "<p><b>Total Records:</b> " . count($records) . "</p>"
                  . "<hr/><p>This is an automated notification from the College Management System.</p>";
            
            send_system_email($admin['email'], $subject, $body);
        }

        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

function handle_marks(PDO $pdo, string $method): void
{
    $me = require_role($pdo, 'teacher', 'student');
    if ($method === 'GET') {
        $oid = (int) ($_GET['course_offering_id'] ?? 0);
        if ($oid < 1) {
            json_out(['ok' => false, 'error' => 'course_offering_id required'], 422);
        }
        if ($me['role'] === 'student') {
            $chk = $pdo->prepare("SELECT 1 FROM enrollments WHERE course_offering_id=? AND student_id=? AND status='approved'");
            $chk->execute([$oid, (int) $me['id']]);
            if (!$chk->fetch()) {
                json_out(['ok' => false, 'error' => 'Not enrolled'], 403);
            }
            $st = $pdo->prepare('SELECT * FROM marks WHERE course_offering_id=? AND student_id=? ORDER BY created_at');
            $st->execute([$oid, (int) $me['id']]);
            json_out(['ok' => true, 'marks' => $st->fetchAll()]);
        }
        $chk = $pdo->prepare('SELECT 1 FROM course_offerings WHERE id=? AND teacher_id=?');
        $chk->execute([$oid, (int) $me['id']]);
        if (!$chk->fetch()) {
            json_out(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        $st = $pdo->prepare(
            'SELECT m.*, u.full_name AS student_name FROM marks m JOIN users u ON u.id = m.student_id WHERE m.course_offering_id = ? ORDER BY u.full_name, m.mark_type'
        );
        $st->execute([$oid]);
        json_out(['ok' => true, 'marks' => $st->fetchAll()]);
    }
    if ($method === 'POST') {
        require_role($pdo, 'teacher');
        $in = json_in();
        $oid = (int) ($in['course_offering_id'] ?? 0);
        $student_id = (int) ($in['student_id'] ?? 0);
        $mark_type = (string) ($in['mark_type'] ?? 'quiz');
        if ($oid < 1 || $student_id < 1 || !in_array($mark_type, ['quiz', 'assignment', 'paper'], true)) {
            json_out(['ok' => false, 'error' => 'Invalid payload'], 422);
        }
        $chk = $pdo->prepare('SELECT 1 FROM course_offerings WHERE id=? AND teacher_id=?');
        $chk->execute([$oid, (int) $me['id']]);
        if (!$chk->fetch()) {
            json_out(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        $pdo->prepare(
            'INSERT INTO marks (course_offering_id, student_id, mark_type, title, marks_obtained, max_marks, created_by) VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $oid,
            $student_id,
            $mark_type,
            trim((string) ($in['title'] ?? 'Assessment')),
            (float) ($in['marks_obtained'] ?? 0),
            (float) ($in['max_marks'] ?? 100),
            (int) $me['id'],
        ]);

        $studentStmt = $pdo->prepare('SELECT full_name, email FROM users WHERE id = ?');
        $studentStmt->execute([$student_id]);
        $student = $studentStmt->fetch();
        if ($student && $student['email']) {
            $courseInfo = $pdo->prepare('SELECT c.code, c.title FROM course_offerings co JOIN courses c ON c.id = co.course_id WHERE co.id = ?');
            $courseInfo->execute([$oid]);
            $c = $courseInfo->fetch();
            $courseName = $c ? ($c['code'] . ' - ' . $c['title']) : "Course #$oid";
            $title = trim((string) ($in['title'] ?? 'Assessment'));
            $subject = "New Marks Uploaded: $courseName";
            $body = "<h2>Marks Uploaded</h2>"
                  . "<p>Dear <b>{$student['full_name']}</b>,</p>"
                  . "<p>Your <b>$title</b> marks have been uploaded for <b>$courseName</b>.</p>"
                  . "<p><b>Score:</b> " . (float) ($in['marks_obtained'] ?? 0) . " / " . (float) ($in['max_marks'] ?? 100) . "</p>"
                  . "<hr/><p>This message was sent by your administrator.</p>";
            send_system_email($student['email'], $subject, $body);
        }

        json_out(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    }
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

function handle_final_results(PDO $pdo, string $method): void
{
    $me = require_role($pdo, 'admin', 'teacher', 'student');
    if ($method === 'GET') {
        $oid = (int) ($_GET['course_offering_id'] ?? 0);
        if ($oid < 1) {
            json_out(['ok' => false, 'error' => 'course_offering_id required'], 422);
        }
        if ($me['role'] === 'student') {
            $chk = $pdo->prepare("SELECT 1 FROM enrollments WHERE course_offering_id=? AND student_id=? AND status='approved'");
            $chk->execute([$oid, (int) $me['id']]);
            if (!$chk->fetch()) {
                json_out(['ok' => false, 'error' => 'Not enrolled'], 403);
            }
            $st = $pdo->prepare(
                'SELECT * FROM final_results WHERE course_offering_id=? AND student_id=? AND status = ?'
            );
            $st->execute([$oid, (int) $me['id'], 'approved']);
            $row = $st->fetch();
            json_out(['ok' => true, 'result' => $row ?: null]);
        }
        if ($me['role'] === 'teacher') {
            $chk = $pdo->prepare('SELECT 1 FROM course_offerings WHERE id=? AND teacher_id=?');
            $chk->execute([$oid, (int) $me['id']]);
            if (!$chk->fetch()) {
                json_out(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $st = $pdo->prepare(
                'SELECT fr.*, u.full_name AS student_name, u.email FROM final_results fr JOIN users u ON u.id = fr.student_id WHERE fr.course_offering_id = ? ORDER BY u.full_name'
            );
            $st->execute([$oid]);
            json_out(['ok' => true, 'results' => $st->fetchAll()]);
        }
        require_role($pdo, 'admin');
        if ($oid > 0) {
            $st = $pdo->prepare(
                'SELECT fr.*, u.full_name AS student_name, c.code AS course_code FROM final_results fr
                 JOIN users u ON u.id = fr.student_id
                 JOIN course_offerings co ON co.id = fr.course_offering_id
                 JOIN courses c ON c.id = co.course_id
                 WHERE fr.course_offering_id = ?
                 ORDER BY fr.status, fr.id DESC'
            );
            $st->execute([$oid]);
        } else {
            $st = $pdo->query(
                'SELECT fr.*, u.full_name AS student_name, c.code AS course_code FROM final_results fr
                 JOIN users u ON u.id = fr.student_id
                 JOIN course_offerings co ON co.id = fr.course_offering_id
                 JOIN courses c ON c.id = co.course_id
                 ORDER BY fr.status, fr.id DESC LIMIT 500'
            );
        }
        json_out(['ok' => true, 'results' => $st->fetchAll()]);
    }
    if ($method === 'POST') {
        $in = json_in();
        $action = (string) ($in['action'] ?? 'save');
        if ($action === 'save' || $action === 'upsert') {
            $t = require_role($pdo, 'teacher');
            $oid = (int) ($in['course_offering_id'] ?? 0);
            $student_id = (int) ($in['student_id'] ?? 0);
            if ($oid < 1 || $student_id < 1) {
                json_out(['ok' => false, 'error' => 'course_offering_id and student_id required'], 422);
            }
            $chk = $pdo->prepare('SELECT 1 FROM course_offerings WHERE id=? AND teacher_id=?');
            $chk->execute([$oid, (int) $t['id']]);
            if (!$chk->fetch()) {
                json_out(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $en = $pdo->prepare("SELECT 1 FROM enrollments WHERE course_offering_id=? AND student_id=? AND status='approved'");
            $en->execute([$oid, $student_id]);
            if (!$en->fetch()) {
                json_out(['ok' => false, 'error' => 'Student not approved in this course'], 422);
            }
            $total = (float) ($in['total_marks'] ?? 0);
            $grade = $in['grade'] ?? null;
            $notes = $in['teacher_notes'] ?? null;
            $chkRow = $pdo->prepare('SELECT id, status FROM final_results WHERE course_offering_id=? AND student_id=?');
            $chkRow->execute([$oid, $student_id]);
            $existing = $chkRow->fetch();
            if ($existing && !in_array($existing['status'], ['draft', 'rejected'], true)) {
                json_out(['ok' => false, 'error' => 'Result is pending approval or already approved'], 409);
            }
            if ($existing) {
                $pdo->prepare(
                    'UPDATE final_results SET total_marks=?, grade=?, teacher_notes=?, status=? WHERE id=?'
                )->execute([$total, $grade, $notes, 'draft', (int) $existing['id']]);
            } else {
                $pdo->prepare(
                    'INSERT INTO final_results (course_offering_id, student_id, total_marks, grade, status, teacher_notes) VALUES (?,?,?,?,?,?)'
                )->execute([$oid, $student_id, $total, $grade, 'draft', $notes]);
            }
            json_out(['ok' => true]);
        }
        if ($action === 'submit') {
            $t = require_role($pdo, 'teacher');
            $oid = (int) ($in['course_offering_id'] ?? 0);
            if ($oid < 1) {
                json_out(['ok' => false, 'error' => 'course_offering_id required'], 422);
            }
            $chk = $pdo->prepare('SELECT 1 FROM course_offerings WHERE id=? AND teacher_id=?');
            $chk->execute([$oid, (int) $t['id']]);
            if (!$chk->fetch()) {
                json_out(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $pdo->prepare(
                "UPDATE final_results SET status='pending_approval', submitted_at=NOW() WHERE course_offering_id=? AND status IN ('draft','rejected')"
            )->execute([$oid]);
            log_activity($pdo, (int) $t['id'], 'final_results_submit', "offering=$oid");

            // Notify Admin about Pending Results
            $admin = $pdo->query("SELECT email FROM users WHERE role='admin' LIMIT 1")->fetch();
            if ($admin) {
                $courseInfo = $pdo->prepare("SELECT c.code, c.title FROM course_offerings co JOIN courses c ON c.id = co.course_id WHERE co.id = ?");
                $courseInfo->execute([$oid]);
                $c = $courseInfo->fetch();
                $courseName = $c ? ($c['code'] . ' - ' . $c['title']) : "Course #$oid";

                $subject = "Final Results Pending Approval: $courseName";
                $body = "<h2>Results Submitted for Review</h2>"
                      . "<p>Teacher <b>{$t['full_name']}</b> has submitted final results for <b>$courseName</b>.</p>"
                      . "<p>Please log in to the Admin Dashboard to review and approve these results.</p>"
                      . "<hr/><p>College Management System Notification</p>";

                send_system_email($admin['email'], $subject, $body);
            }

            // Notify each student whose results are now pending approval.
            $studentResults = $pdo->prepare(
                "SELECT u.email, u.full_name, c.code, c.title, fr.grade, fr.total_marks
                 FROM final_results fr
                 JOIN users u ON u.id = fr.student_id
                 JOIN course_offerings co ON co.id = fr.course_offering_id
                 JOIN courses c ON c.id = co.course_id
                 WHERE fr.course_offering_id = ? AND fr.status = 'pending_approval'"
            );
            $studentResults->execute([$oid]);
            while ($student = $studentResults->fetch()) {
                if (!$student['email']) {
                    continue;
                }

                $courseName = $student['code'] . ' - ' . $student['title'];
                $subject = "Your Result for $courseName is Pending Approval";
                $body = "<h2>Final Result Submitted</h2>"
                      . "<p>Dear <b>{$student['full_name']}</b>,</p>"
                      . "<p>Your final result for <b>$courseName</b> has been submitted by your teacher and is pending admin approval.</p>"
                      . "<p><b>Grade:</b> {$student['grade']}</p>"
                      . "<p><b>Total Marks:</b> {$student['total_marks']}</p>"
                      . "<p>Once the admin approves the result, you will receive a follow-up email with the final decision.</p>"
                      . "<hr/><p>College Management System Notification</p>";

                send_system_email($student['email'], $subject, $body);
            }

            json_out(['ok' => true]);
        }
        if ($action === 'approve' || $action === 'reject') {
            $a = require_role($pdo, 'admin');
            $id = (int) ($in['id'] ?? 0);
            if ($id < 1) {
                json_out(['ok' => false, 'error' => 'id required'], 422);
            }
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            $pdo->prepare(
                "UPDATE final_results SET status=?, approved_at=NOW(), approved_by=? WHERE id=? AND status='pending_approval'"
            )->execute([$newStatus, (int) $a['id'], $id]);
            log_activity($pdo, (int) $a['id'], 'final_results_' . $action, "id=$id");

            // Notify Student about Result Decision
            $studentInfo = $pdo->prepare(
                "SELECT u.email, u.full_name, c.code, c.title, fr.grade, fr.total_marks 
                 FROM final_results fr 
                 JOIN users u ON u.id = fr.student_id 
                 JOIN course_offerings co ON co.id = fr.course_offering_id
                 JOIN courses c ON c.id = co.course_id
                 WHERE fr.id = ?"
            );
            $studentInfo->execute([$id]);
            $s = $studentInfo->fetch();

            if ($s && $s['email']) {
                $statusText = ($action === 'approve') ? "Approved" : "Returned/Rejected";
                $courseName = $s['code'] . ' - ' . $s['title'];
                
                $subject = "Your Result for $courseName has been $statusText";
                $body = "<h2>Final Result Update</h2>"
                      . "<p>Dear <b>{$s['full_name']}</b>,</p>"
                      . "<p>Your final result for <b>$courseName</b> has been processed.</p>"
                      . "<p><b>Status:</b> $statusText</p>";
                
                if ($action === 'approve') {
                    $body .= "<p><b>Grade:</b> {$s['grade']}</p>"
                           . "<p><b>Total Marks:</b> {$s['total_marks']}</p>";
                } else {
                    $body .= "<p>Your result has been returned for review. Please contact your instructor for details.</p>";
                }
                
                $body .= "<hr/><p>College Management System Notification</p>";
                
                send_system_email($s['email'], $subject, $body);
            }

            json_out(['ok' => true]);
        }
        json_out(['ok' => false, 'error' => 'Unknown action'], 422);
    }
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

function handle_student_offerings(PDO $pdo): void
{
    $me = require_role($pdo, 'student');
    $st = $pdo->query(
        'SELECT co.id, co.course_id, co.section_id, co.semester, co.academic_year,
         c.code, c.title, c.description, u.full_name AS teacher_name,
         sc.name AS class_name, sec.name AS section_name
         FROM course_offerings co
         JOIN courses c ON c.id = co.course_id
         JOIN users u ON u.id = co.teacher_id
         JOIN sections sec ON sec.id = co.section_id
         JOIN school_classes sc ON sc.id = sec.class_id
         ORDER BY co.academic_year DESC, c.code'
    );
    $all = $st->fetchAll();
    $enSt = $pdo->prepare('SELECT course_offering_id, status FROM enrollments WHERE student_id = ?');
    $enSt->execute([(int) $me['id']]);
    $enMap = [];
    foreach ($enSt->fetchAll() as $e) {
        $enMap[(int) $e['course_offering_id']] = $e['status'];
    }
    foreach ($all as &$row) {
        $oid = (int) $row['id'];
        $row['enrollment_status'] = $enMap[$oid] ?? null;
    }
    unset($row);
    json_out(['ok' => true, 'offerings' => $all]);
}

function handle_students_for_attendance(PDO $pdo): void
{
    $me = require_role($pdo, 'teacher');
    $oid = (int) ($_GET['course_offering_id'] ?? 0);
    if ($oid < 1) {
        json_out(['ok' => false, 'error' => 'course_offering_id required'], 422);
    }
    $chk = $pdo->prepare('SELECT 1 FROM course_offerings WHERE id=? AND teacher_id=?');
    $chk->execute([$oid, (int) $me['id']]);
    if (!$chk->fetch()) {
        json_out(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    $st = $pdo->prepare(
        "SELECT u.id, u.full_name, u.email, u.student_code FROM enrollments e
         JOIN users u ON u.id = e.student_id
         WHERE e.course_offering_id = ? AND e.status = 'approved' ORDER BY u.full_name"
    );
    $st->execute([$oid]);
    json_out(['ok' => true, 'students' => $st->fetchAll()]);
}

function handle_pending_results_admin(PDO $pdo): void
{
    require_role($pdo, 'admin');
    $st = $pdo->query(
        "SELECT fr.*, u.full_name AS student_name, c.code AS course_code, t.full_name AS teacher_name
         FROM final_results fr
         JOIN users u ON u.id = fr.student_id
         JOIN course_offerings co ON co.id = fr.course_offering_id
         JOIN courses c ON c.id = co.course_id
         JOIN users t ON t.id = co.teacher_id
         WHERE fr.status = 'pending_approval'
         ORDER BY fr.submitted_at DESC"
    );
    json_out(['ok' => true, 'results' => $st->fetchAll()]);
}

function handle_admin_enrollments(PDO $pdo, string $method): void
{
    $me = require_role($pdo, 'admin');
    if ($method === 'GET') {
        $st = $pdo->query(
            'SELECT e.*, stu.full_name AS student_name, stu.email AS student_email, stu.student_code,
             c.code AS course_code, c.title AS course_title, co.semester, co.academic_year,
             t.full_name AS teacher_name
             FROM enrollments e
             JOIN users stu ON stu.id = e.student_id
             JOIN course_offerings co ON co.id = e.course_offering_id
             JOIN courses c ON c.id = co.course_id
             JOIN users t ON t.id = co.teacher_id
             ORDER BY e.status, e.requested_at DESC
             LIMIT 500'
        );
        json_out(['ok' => true, 'enrollments' => $st->fetchAll()]);
    }
    if ($method === 'POST') {
        $in = json_in();
        $eid = (int) ($in['enrollment_id'] ?? 0);
        $status = (string) ($in['status'] ?? '');
        if ($eid < 1 || !in_array($status, ['approved', 'rejected'], true)) {
            json_out(['ok' => false, 'error' => 'enrollment_id and status required'], 422);
        }
        $pdo->prepare('UPDATE enrollments SET status=?, decided_at=NOW() WHERE id=?')->execute([$status, $eid]);
        log_activity($pdo, (int) $me['id'], 'admin_enrollment_decide', "id=$eid $status");
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}


