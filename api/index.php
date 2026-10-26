<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/database.php';

function out(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}
function requireLogin(): array {
    if (empty($_SESSION['user'])) out(401, ['success'=>false,'message'=>'กรุณาเข้าสู่ระบบ']);
    return $_SESSION['user'];
}
function requireRole(array $user, string ...$roles): void {
    if (!in_array($user['role'] ?? '', $roles, true)) out(403, ['success'=>false,'message'=>'ไม่มีสิทธิ์ใช้งาน']);
}
function classroomForTeacher(PDO $pdo, int $classroomId, int $teacherId): array {
    $q = $pdo->prepare('SELECT classroom_id, teacher_id, class_name, join_code, description, created_at FROM classrooms WHERE classroom_id = ? AND teacher_id = ?');
    $q->execute([$classroomId, $teacherId]);
    $row = $q->fetch();
    if (!$row) out(404, ['success'=>false,'message'=>'ไม่พบห้องเรียนหรือไม่มีสิทธิ์']);
    return $row;
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
$input = body();

try {
    if ($method === 'POST' && $action === 'login') {
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $password = (string)($input['password'] ?? '');
        if ($email === '' || $password === '') out(422, ['success'=>false,'message'=>'กรอกอีเมลและรหัสผ่าน']);
        $q = $pdo->prepare('SELECT user_id, full_name, email, password_hash, role FROM users WHERE email = ? LIMIT 1');
        $q->execute([$email]);
        $row = $q->fetch();
        if (!$row || !password_verify($password, $row['password_hash']) || !in_array($row['role'], ['teacher','student'], true)) {
            out(401, ['success'=>false,'message'=>'อีเมลหรือรหัสผ่านไม่ถูกต้อง']);
        }
        session_regenerate_id(true);
        unset($row['password_hash']);
        $_SESSION['user'] = $row;
        out(200, ['success'=>true,'user'=>$row]);
    }

    if ($method === 'POST' && $action === 'register_student') {
        $name = trim((string)($input['full_name'] ?? ''));
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $password = (string)($input['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            out(422, ['success'=>false,'message'=>'กรอกชื่อ อีเมลที่ถูกต้อง และรหัสผ่านอย่างน้อย 8 ตัว']);
        }
        $q = $pdo->prepare('INSERT INTO users (full_name,email,password_hash,role) VALUES (?,?,?,?)');
        try { $q->execute([$name,$email,password_hash($password, PASSWORD_DEFAULT),'student']); }
        catch (PDOException $e) {
            if ($e->getCode() === '23000') out(409, ['success'=>false,'message'=>'อีเมลนี้ถูกใช้แล้ว']);
            throw $e;
        }
        out(201, ['success'=>true,'message'=>'สมัครบัญชีนักเรียนสำเร็จ กรุณาเข้าสู่ระบบ']);
    }

    if ($method === 'POST' && $action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        out(200, ['success'=>true]);
    }

    if ($method === 'GET' && $action === 'me') {
        $user = requireLogin();
        out(200, ['success'=>true,'user'=>$user]);
    }

    $user = requireLogin();

    if ($method === 'GET' && $action === 'skills') {
        requireRole($user, 'student');
        $q = $pdo->prepare('SELECT skills_text FROM user_custom_skills WHERE user_id = ? LIMIT 1');
        $q->execute([$user['user_id']]);
        $row = $q->fetch();
        out(200, ['success'=>true, 'skills_text'=>(string)($row['skills_text'] ?? '')]);
    }

    if ($method === 'POST' && $action === 'save_skills') {
        requireRole($user, 'student');
        $skillsText = trim((string)($input['skills_text'] ?? ''));
        if (mb_strlen($skillsText, 'UTF-8') > 2000) {
            out(422, ['success'=>false, 'message'=>'ทักษะต้องมีความยาวไม่เกิน 2000 ตัวอักษร']);
        }
        $q = $pdo->prepare(
            'INSERT INTO user_custom_skills (user_id, skills_text) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE skills_text = VALUES(skills_text)'
        );
        $q->execute([$user['user_id'], $skillsText !== '' ? $skillsText : null]);
        out(200, ['success'=>true, 'message'=>$skillsText === '' ? 'ล้างข้อมูลทักษะแล้ว' : 'บันทึกทักษะแล้ว']);
    }

    if ($method === 'GET' && $action === 'classrooms') {
        if ($user['role'] === 'teacher') {
            $q = $pdo->prepare('SELECT classroom_id, class_name, join_code, description, created_at FROM classrooms WHERE teacher_id = ? ORDER BY classroom_id DESC');
            $q->execute([$user['user_id']]);
        } else {
            $q = $pdo->prepare('SELECT c.classroom_id, c.class_name, c.description, c.created_at FROM classrooms c JOIN enrollments e ON e.classroom_id=c.classroom_id WHERE e.student_id=? ORDER BY c.classroom_id DESC');
            $q->execute([$user['user_id']]);
        }
        out(200, ['success'=>true,'data'=>$q->fetchAll()]);
    }

    // ครูลบห้องเรียนของตัวเองเท่านั้น และลบข้อมูลลูกก่อนข้อมูลแม่เพื่อป้องกัน FK #1451
    if ($method === 'POST' && $action === 'delete_classroom') {
        requireRole($user, 'teacher');
        $classroomId = (int)($input['classroom_id'] ?? 0);
        if ($classroomId < 1) out(422, ['success'=>false,'message'=>'รหัสห้องเรียนไม่ถูกต้อง']);
        classroomForTeacher($pdo, $classroomId, (int)$user['user_id']);
        try {
            $pdo->beginTransaction();
            // ratings อาจอ้างถึง run และ group จึงลบก่อน groups/runs
            $q = $pdo->prepare('DELETE r FROM ratings r INNER JOIN randomizationruns rr ON rr.run_id = r.run_id WHERE rr.classroom_id = ?');
            $q->execute([$classroomId]);
            $q = $pdo->prepare('DELETE gm FROM groupmembers gm INNER JOIN classgroups cg ON cg.group_id = gm.group_id INNER JOIN randomizationruns rr ON rr.run_id = cg.run_id WHERE rr.classroom_id = ?');
            $q->execute([$classroomId]);
            $q = $pdo->prepare('DELETE cg FROM classgroups cg INNER JOIN randomizationruns rr ON rr.run_id = cg.run_id WHERE rr.classroom_id = ?');
            $q->execute([$classroomId]);
            $q = $pdo->prepare('DELETE FROM randomizationruns WHERE classroom_id = ?');
            $q->execute([$classroomId]);
            $q = $pdo->prepare('DELETE FROM enrollments WHERE classroom_id = ?');
            $q->execute([$classroomId]);
            $q = $pdo->prepare('DELETE FROM classrooms WHERE classroom_id = ? AND teacher_id = ?');
            $q->execute([$classroomId, (int)$user['user_id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        out(200, ['success'=>true,'message'=>'ลบห้องเรียนและข้อมูลที่เกี่ยวข้องแล้ว']);
    }

    // เปลี่ยนได้เฉพาะชื่อห้องเรียน รายละเอียดห้องไม่เปิดให้แก้ไขหลังสร้าง
    if ($method === 'POST' && $action === 'rename_classroom') {
        requireRole($user, 'teacher');
        $classroomId = (int)($input['classroom_id'] ?? 0);
        $name = trim((string)($input['class_name'] ?? ''));
        if ($classroomId < 1 || $name === '') out(422, ['success'=>false,'message'=>'กรุณาระบุชื่อห้องเรียน']);
        if (mb_strlen($name, 'UTF-8') > 100) out(422, ['success'=>false,'message'=>'ชื่อห้องเรียนต้องไม่เกิน 100 ตัวอักษร']);
        classroomForTeacher($pdo, $classroomId, (int)$user['user_id']);
        $duplicate = $pdo->prepare('SELECT classroom_id FROM classrooms WHERE LOWER(TRIM(class_name)) = LOWER(TRIM(?)) AND classroom_id <> ? LIMIT 1');
        $duplicate->execute([$name, $classroomId]);
        if ($duplicate->fetch()) out(409, ['success'=>false,'message'=>'ชื่อห้องเรียนนี้มีอยู่แล้ว กรุณาตั้งชื่อห้องอื่น']);
        $q = $pdo->prepare('UPDATE classrooms SET class_name = ? WHERE classroom_id = ? AND teacher_id = ?');
        $q->execute([$name, $classroomId, (int)$user['user_id']]);
        out(200, ['success'=>true,'message'=>'เปลี่ยนชื่อห้องเรียนแล้ว']);
    }

    // ครูเจ้าของห้องสามารถแก้ไขรายละเอียดห้องเรียนได้
    if ($method === 'POST' && $action === 'update_classroom_description') {
        requireRole($user, 'teacher');
        $classroomId = (int)($input['classroom_id'] ?? 0);
        $description = trim((string)($input['description'] ?? ''));
        if ($classroomId < 1) out(422, ['success'=>false,'message'=>'รหัสห้องเรียนไม่ถูกต้อง']);
        if (mb_strlen($description, 'UTF-8') > 2000) out(422, ['success'=>false,'message'=>'รายละเอียดต้องไม่เกิน 2000 ตัวอักษร']);
        classroomForTeacher($pdo, $classroomId, (int)$user['user_id']);
        $q = $pdo->prepare('UPDATE classrooms SET description = ? WHERE classroom_id = ? AND teacher_id = ?');
        $q->execute([$description !== '' ? $description : null, $classroomId, (int)$user['user_id']]);
        out(200, ['success'=>true,'message'=>'บันทึกรายละเอียดห้องเรียนแล้ว','data'=>['description'=>$description]]);
    }

    // ครูนำสมาชิกออกจากห้องนี้เท่านั้น บัญชีผู้ใช้ยังคงอยู่
    if ($method === 'POST' && $action === 'remove_member') {
        requireRole($user, 'teacher');
        $classroomId = (int)($input['classroom_id'] ?? 0);
        $studentId = (int)($input['student_id'] ?? 0);
        if ($classroomId < 1 || $studentId < 1) out(422, ['success'=>false,'message'=>'ข้อมูลห้องเรียนหรือนักเรียนไม่ถูกต้อง']);
        classroomForTeacher($pdo, $classroomId, (int)$user['user_id']);
        $q = $pdo->prepare('SELECT enrollment_id FROM enrollments WHERE classroom_id = ? AND student_id = ? LIMIT 1');
        $q->execute([$classroomId, $studentId]);
        $enrollmentId = $q->fetchColumn();
        if (!$enrollmentId) out(404, ['success'=>false,'message'=>'ไม่พบนักเรียนในห้องนี้']);
        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare('DELETE FROM groupmembers WHERE enrollment_id = ?');
            $q->execute([(int)$enrollmentId]);
            $q = $pdo->prepare('DELETE FROM enrollments WHERE enrollment_id = ? AND classroom_id = ?');
            $q->execute([(int)$enrollmentId, $classroomId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        out(200, ['success'=>true,'message'=>'นำสมาชิกออกจากห้องแล้ว โดยไม่ลบบัญชีนักเรียน']);
    }

    if ($method === 'POST' && $action === 'create_classroom') {
        requireRole($user, 'teacher');
        $name = trim((string)($input['class_name'] ?? ''));
        if ($name === '') out(422, ['success'=>false,'message'=>'กรอกชื่อห้องเรียน']);
        if (mb_strlen($name, 'UTF-8') > 100) out(422, ['success'=>false,'message'=>'ชื่อห้องเรียนต้องไม่เกิน 100 ตัวอักษร']);
        // ป้องกันชื่อห้องซ้ำทั้งระบบ โดยไม่สนตัวพิมพ์เล็ก/ใหญ่และช่องว่างหัวท้าย
        $duplicate = $pdo->prepare('SELECT classroom_id FROM classrooms WHERE LOWER(TRIM(class_name)) = LOWER(TRIM(?)) LIMIT 1');
        $duplicate->execute([$name]);
        if ($duplicate->fetch()) out(409, ['success'=>false,'message'=>'ชื่อห้องเรียนนี้มีอยู่แล้ว กรุณาตั้งชื่อห้องอื่น']);
        $description = trim((string)($input['description'] ?? ''));
        if (mb_strlen($description, 'UTF-8') > 2000) out(422, ['success'=>false,'message'=>'รายละเอียดงานต้องไม่เกิน 2000 ตัวอักษร']);
        $code = strtoupper(bin2hex(random_bytes(4)));
        $q = $pdo->prepare('INSERT INTO classrooms (teacher_id,class_name,join_code,description) VALUES (?,?,?,?)');
        try {
            $q->execute([$user['user_id'],$name,$code,$description !== '' ? $description : null]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') out(409, ['success'=>false,'message'=>'ชื่อห้องเรียนนี้มีอยู่แล้ว กรุณาตั้งชื่อห้องอื่น']);
            throw $e;
        }
        out(201, ['success'=>true,'classroom_id'=>(int)$pdo->lastInsertId(),'join_code'=>$code]);
    }

    if ($method === 'POST' && $action === 'join_classroom') {
        requireRole($user, 'student');
        $code = strtoupper(trim((string)($input['join_code'] ?? '')));
        if ($code === '') out(422, ['success'=>false,'message'=>'กรอกรหัสเข้าห้อง']);
        $q = $pdo->prepare('SELECT classroom_id FROM classrooms WHERE join_code=? LIMIT 1');
        $q->execute([$code]);
        $classroom = $q->fetch();
        if (!$classroom) out(404, ['success'=>false,'message'=>'ไม่พบรหัสห้องเรียน']);
        $existing = $pdo->prepare('SELECT enrollment_id FROM enrollments WHERE classroom_id = ? AND student_id = ? LIMIT 1');
        $existing->execute([$classroom['classroom_id'], $user['user_id']]);
        if ($existing->fetch()) out(409, ['success'=>false,'message'=>'คุณเข้าร่วมห้องนี้แล้ว']);
        try {
            $q = $pdo->prepare('INSERT INTO enrollments (classroom_id,student_id) VALUES (?,?)');
            $q->execute([$classroom['classroom_id'],$user['user_id']]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') out(409, ['success'=>false,'message'=>'คุณเข้าร่วมห้องนี้แล้ว']);
            throw $e;
        }
        out(201, ['success'=>true,'message'=>'เข้าร่วมห้องเรียนสำเร็จ']);
    }

    if ($method === 'GET' && $action === 'members') {
        $classroomId = (int)($_GET['classroom_id'] ?? 0);
        if ($user['role'] === 'teacher') {
            classroomForTeacher($pdo,$classroomId,(int)$user['user_id']);
        } else {
            $q = $pdo->prepare('SELECT 1 FROM enrollments WHERE classroom_id=? AND student_id=?');
            $q->execute([$classroomId,$user['user_id']]);
            if (!$q->fetch()) out(403,['success'=>false,'message'=>'คุณไม่ได้อยู่ในห้องนี้']);
        }
        // Return each student's custom skills with the roster for both roles.
        // Teachers need this for grouping; students can also see classmates' skills.
        $q = $pdo->prepare("SELECT u.user_id, e.enrollment_id, u.full_name, COALESCE(ucs.skills_text, '') AS skills_text FROM enrollments e JOIN users u ON u.user_id=e.student_id LEFT JOIN user_custom_skills ucs ON ucs.user_id=u.user_id WHERE e.classroom_id=? ORDER BY u.full_name, e.enrollment_id");
        $q->execute([$classroomId]);
        out(200,['success'=>true,'data'=>$q->fetchAll()]);
    }

    if ($method === 'POST' && $action === 'randomize') {
        requireRole($user, 'teacher');
        $classroomId = (int)($input['classroom_id'] ?? 0);
        $groupSize = (int)($input['group_size'] ?? 4);
        if ($groupSize < 2 || $groupSize > 20) out(422,['success'=>false,'message'=>'ขนาดกลุ่มต้องอยู่ระหว่าง 2 ถึง 20 คน']);
        classroomForTeacher($pdo,$classroomId,(int)$user['user_id']);
        $q = $pdo->prepare('SELECT enrollment_id FROM enrollments WHERE classroom_id=? ORDER BY enrollment_id');
        $q->execute([$classroomId]);
        $enrollments = $q->fetchAll(PDO::FETCH_COLUMN);
        if (!$enrollments) out(422,['success'=>false,'message'=>'ยังไม่มีนักเรียนในห้องนี้']);
        shuffle($enrollments);
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('INSERT INTO randomizationruns (classroom_id,created_by,method,target_value) VALUES (?,?,?,?)');
            $q->execute([$classroomId,$user['user_id'],'random',$groupSize]);
            $runId = (int)$pdo->lastInsertId();
            $groupInsert = $pdo->prepare('INSERT INTO classgroups (run_id,group_number) VALUES (?,?)');
            $memberInsert = $pdo->prepare('INSERT INTO groupmembers (group_id,enrollment_id) VALUES (?,?)');
            foreach (array_chunk($enrollments,$groupSize) as $index=>$members) {
                $groupInsert->execute([$runId,$index+1]);
                $groupId = (int)$pdo->lastInsertId();
                foreach ($members as $enrollmentId) $memberInsert->execute([$groupId,$enrollmentId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        out(201,['success'=>true,'run_id'=>$runId,'group_count'=>(int)ceil(count($enrollments)/$groupSize),'member_count'=>count($enrollments)]);
    }

    if ($method === 'GET' && $action === 'groups') {
        $classroomId = (int)($_GET['classroom_id'] ?? 0);
        if ($user['role'] === 'teacher') {
            classroomForTeacher($pdo,$classroomId,(int)$user['user_id']);
        } else {
            $q = $pdo->prepare('SELECT 1 FROM enrollments WHERE classroom_id=? AND student_id=?');
            $q->execute([$classroomId,$user['user_id']]);
            if (!$q->fetch()) out(403,['success'=>false,'message'=>'คุณไม่ได้อยู่ในห้องนี้']);
        }
        $q = $pdo->prepare('SELECT rr.run_id,rr.created_at,rr.method,rr.target_value FROM randomizationruns rr WHERE rr.classroom_id=? ORDER BY rr.run_id DESC LIMIT 1');
        $q->execute([$classroomId]);
        $run = $q->fetch();
        if (!$run) out(200,['success'=>true,'run'=>null,'data'=>[]]);
        $q = $pdo->prepare('SELECT cg.group_id,cg.group_number,u.user_id,u.full_name FROM classgroups cg JOIN groupmembers gm ON gm.group_id=cg.group_id JOIN enrollments e ON e.enrollment_id=gm.enrollment_id JOIN users u ON u.user_id=e.student_id WHERE cg.run_id=? ORDER BY cg.group_number,u.full_name');
        $q->execute([$run['run_id']]);
        $rows = $q->fetchAll();
        $groups = [];
        foreach ($rows as $row) {
            $gid = (int)$row['group_id'];
            if (!isset($groups[$gid])) $groups[$gid] = ['group_id'=>$gid,'group_number'=>(int)$row['group_number'],'members'=>[]];
            $groups[$gid]['members'][] = ['user_id'=>(int)$row['user_id'],'full_name'=>$row['full_name']];
        }
        out(200,['success'=>true,'run'=>$run,'data'=>array_values($groups)]);
    }

    if ($method === 'POST' && $action === 'rate') {
        $type = (string)($input['rating_type'] ?? '');
        $score = (int)($input['score'] ?? 0);
        $runId = (int)($input['run_id'] ?? 0);
        $targetUserId = isset($input['target_user_id']) && $input['target_user_id'] !== '' ? (int)$input['target_user_id'] : null;
        $targetGroupId = isset($input['target_group_id']) && $input['target_group_id'] !== '' ? (int)$input['target_group_id'] : null;
        $comment = trim((string)($input['comment'] ?? ''));
        if ($score < 1 || $score > 5 || $runId < 1) out(422,['success'=>false,'message'=>'คะแนนต้องอยู่ระหว่าง 1–5 และต้องเลือกผลการสุ่ม']);
        if (($targetUserId === null) === ($targetGroupId === null)) out(422,['success'=>false,'message'=>'เลือกผู้ถูกประเมินหรือกลุ่มเพียงอย่างเดียว']);
        $q = $pdo->prepare('SELECT classroom_id FROM randomizationruns WHERE run_id=?');
        $q->execute([$runId]);
        $run = $q->fetch();
        if (!$run) out(404,['success'=>false,'message'=>'ไม่พบผลการสุ่ม']);
        $classroomId = (int)$run['classroom_id'];
        if ($user['role'] === 'teacher') {
            classroomForTeacher($pdo,$classroomId,(int)$user['user_id']);
            $ratingType = 'teacher';
        } else {
            if ($targetUserId === null) out(403,['success'=>false,'message'=>'นักเรียนประเมินได้เฉพาะเพื่อนในกลุ่ม']);
            $q = $pdo->prepare('SELECT gm1.group_id FROM groupmembers gm1 JOIN enrollments e1 ON e1.enrollment_id=gm1.enrollment_id WHERE gm1.group_id IN (SELECT group_id FROM classgroups WHERE run_id=?) AND e1.student_id=?');
            $q->execute([$runId,$user['user_id']]);
            $myGroups = $q->fetchAll(PDO::FETCH_COLUMN);
            if (!$myGroups) out(403,['success'=>false,'message'=>'คุณไม่มีสมาชิกในกลุ่มของผลการสุ่มนี้']);
            $q = $pdo->prepare('SELECT 1 FROM groupmembers gm JOIN classgroups cg ON cg.group_id=gm.group_id JOIN enrollments e ON e.enrollment_id=gm.enrollment_id WHERE cg.run_id=? AND e.student_id=? LIMIT 1');
            $q->execute([$runId,$targetUserId]);
            if (!$q->fetch()) out(403,['success'=>false,'message'=>'ประเมินได้เฉพาะนักเรียนที่อยู่ในผลการสุ่มของห้องนี้']);
            if ($targetUserId === (int)$user['user_id']) out(422,['success'=>false,'message'=>'ไม่สามารถประเมินตนเองได้']);
            $ratingType = 'peer';
        }

        // การประเมินรายคนต้องผูกกับกลุ่มที่นักเรียนคนนั้นอยู่จริงในผลการสุ่มนี้
        // ไม่ใช้กลุ่มที่ผู้ประเมินเปิดอยู่เป็นตัวกำหนดกลุ่มเป้าหมาย
        if ($targetUserId !== null) {
            $q = $pdo->prepare('SELECT cg.group_id FROM classgroups cg JOIN groupmembers gm ON gm.group_id = cg.group_id JOIN enrollments e ON e.enrollment_id = gm.enrollment_id WHERE cg.run_id = ? AND e.student_id = ? LIMIT 1');
            $q->execute([$runId, $targetUserId]);
            $actualGroupId = $q->fetchColumn();
            if ($actualGroupId === false) out(422,['success'=>false,'message'=>'ไม่พบนักเรียนในกลุ่มของผลการสุ่มนี้']);
            $targetGroupId = (int)$actualGroupId;
        }

        $q = $pdo->prepare('INSERT INTO ratings (run_id,reviewer_id,target_user_id,target_group_id,rating_type,score,comment) VALUES (?,?,?,?,?,?,?)');
        try { $q->execute([$runId,$user['user_id'],$targetUserId,$targetGroupId,$ratingType,$score,$comment !== '' ? $comment : null]); }
        catch (PDOException $e) {
            if ($e->getCode() === '23000') out(409,['success'=>false,'message'=>'บันทึกการประเมินไม่สำเร็จ อาจมีการประเมินซ้ำ']);
            throw $e;
        }
        out(201,['success'=>true,'message'=>'บันทึกการประเมินแล้ว']);
    }

    // ครูแก้ไขได้เฉพาะคะแนน/ความคิดเห็นที่ตนเองเคยให้ไว้ในห้องของตน
    if ($method === 'POST' && $action === 'update_rating') {
        requireRole($user, 'teacher');
        $ratingId = (int)($input['rating_id'] ?? 0);
        $score = (int)($input['score'] ?? 0);
        $comment = trim((string)($input['comment'] ?? ''));
        if ($ratingId < 1 || $score < 1 || $score > 5) out(422, ['success'=>false,'message'=>'คะแนนต้องอยู่ระหว่าง 1–5']);
        if (mb_strlen($comment, 'UTF-8') > 500) out(422, ['success'=>false,'message'=>'ความคิดเห็นต้องไม่เกิน 500 ตัวอักษร']);
        $q = $pdo->prepare("SELECT r.rating_id, rr.classroom_id FROM ratings r INNER JOIN randomizationruns rr ON rr.run_id = r.run_id WHERE r.rating_id = ? AND r.reviewer_id = ? AND r.rating_type = 'teacher' LIMIT 1");
        $q->execute([$ratingId, (int)$user['user_id']]);
        $rating = $q->fetch();
        if (!$rating) out(404, ['success'=>false,'message'=>'ไม่พบคะแนนที่คุณเป็นผู้ประเมิน หรือไม่มีสิทธิ์แก้ไข']);
        classroomForTeacher($pdo, (int)$rating['classroom_id'], (int)$user['user_id']);
        $q = $pdo->prepare("UPDATE ratings SET score = ?, comment = ? WHERE rating_id = ? AND reviewer_id = ? AND rating_type = 'teacher'");
        $q->execute([$score, $comment !== '' ? $comment : null, $ratingId, (int)$user['user_id']]);
        out(200, ['success'=>true,'message'=>'แก้ไขคะแนนและความคิดเห็นแล้ว']);
    }

    if ($method === 'GET' && $action === 'rating_results') {
        $classroomId = (int)($_GET['classroom_id'] ?? 0);
        if ($classroomId < 1) out(422, ['success'=>false,'message'=>'ไม่พบห้องเรียน']);

        if ($user['role'] === 'teacher') {
            classroomForTeacher($pdo, $classroomId, (int)$user['user_id']);
        } else {
            $q = $pdo->prepare('SELECT 1 FROM enrollments WHERE classroom_id=? AND student_id=? LIMIT 1');
            $q->execute([$classroomId, $user['user_id']]);
            if (!$q->fetch()) out(403, ['success'=>false,'message'=>'คุณไม่ได้อยู่ในห้องนี้']);
        }

        $q = $pdo->prepare('SELECT run_id, created_at FROM randomizationruns WHERE classroom_id=? ORDER BY run_id DESC LIMIT 1');
        $q->execute([$classroomId]);
        $run = $q->fetch();
        if (!$run) out(200, ['success'=>true, 'run'=>null, 'data'=>[]]);

        // ครูเห็นการประเมินทั้งหมด รวมถึงการประเมินของครูเองและของนักเรียน
        // นักเรียนเห็นเฉพาะผลประเมินจากครู ไม่เปิดเผยการประเมินระหว่างเพื่อน
        $sql = "SELECT r.rating_id, r.rating_type, r.score, r.comment, r.created_at, r.reviewer_id,
                       reviewer.full_name AS reviewer_name,
                       target.full_name AS target_name,
                       cg.group_number AS target_group_number
                FROM ratings r
                LEFT JOIN users reviewer ON reviewer.user_id = r.reviewer_id
                LEFT JOIN users target ON target.user_id = r.target_user_id
                LEFT JOIN classgroups cg ON cg.group_id = COALESCE(
                    r.target_group_id,
                    (SELECT cg2.group_id FROM classgroups cg2
                     JOIN groupmembers gm2 ON gm2.group_id = cg2.group_id
                     JOIN enrollments e2 ON e2.enrollment_id = gm2.enrollment_id
                     WHERE cg2.run_id = r.run_id AND e2.student_id = r.target_user_id
                     LIMIT 1)
                )
                WHERE r.run_id = ?";
        if ($user['role'] === 'student') $sql .= " AND r.rating_type = 'teacher'";
        $sql .= ' ORDER BY r.created_at DESC, r.rating_id DESC';
        $q = $pdo->prepare($sql);
        $q->execute([$run['run_id']]);
        out(200, ['success'=>true, 'run'=>$run, 'data'=>$q->fetchAll()]);
    }

    if ($method === 'GET' && $action === 'rating_summary') {
        $runId = (int)($_GET['run_id'] ?? 0);
        $q = $pdo->prepare('SELECT classroom_id FROM randomizationruns WHERE run_id=?');
        $q->execute([$runId]);
        $run = $q->fetch();
        if (!$run) out(404,['success'=>false,'message'=>'ไม่พบผลการสุ่ม']);
        if ($user['role'] === 'teacher') {
            classroomForTeacher($pdo,(int)$run['classroom_id'],(int)$user['user_id']);
        } else {
            $q = $pdo->prepare('SELECT 1 FROM enrollments WHERE classroom_id=? AND student_id=?');
            $q->execute([$run['classroom_id'],$user['user_id']]);
            if (!$q->fetch()) out(403,['success'=>false,'message'=>'คุณไม่ได้อยู่ในห้องนี้']);
        }
        $q = $pdo->prepare('SELECT target_user_id, target_group_id, AVG(score) AS average_score, COUNT(*) AS total FROM ratings WHERE run_id=? GROUP BY target_user_id,target_group_id');
        $q->execute([$runId]);
        out(200,['success'=>true,'data'=>$q->fetchAll()]);
    }

    out(404,['success'=>false,'message'=>'ไม่พบ API endpoint']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log((string)$e);
    out(500,['success'=>false,'message'=>'เกิดข้อผิดพลาดภายในระบบ กรุณาตรวจสอบโครงสร้างฐานข้อมูลและ PHP error log']);
}
