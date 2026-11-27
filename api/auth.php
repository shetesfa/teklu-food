<?php
/**
 * የተጠቃሚዎች የማረጋገጫ API (Authentication API)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/audit.php';

$pdo = get_db_connection();
$action = $_GET['action'] ?? 'login';

// 1. መግቢያ (Login)
if ($action === 'login') {
    $data = get_request_data();
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';
    $device_uuid = trim($data['device_uuid'] ?? '');
    $device_name = trim($data['device_name'] ?? 'Mobile Phone');

    if (empty($username) || empty($password)) {
        send_error('የተጠቃሚ ስም እና የይለፍ ቃል ያስገቡ (Username & Password Required)');
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        send_error('የተሳሳተ የተጠቃሚ ስም ወይም የይለፍ ቃል (Invalid Credentials)', 401);
    }

    if ((int)$user['is_active'] !== 1) {
        send_error('ይህ አካውንት ታግዷል (Account Inactive)', 403);
    }

    // መሣሪያውን መመዝገብ ወይም ማደስ
    if (!empty($device_uuid)) {
        $stmtDev = $pdo->prepare("
            INSERT INTO devices (device_uuid, user_id, device_name, is_trusted, last_sync_at, created_at)
            VALUES (:d_uuid, :u_id, :d_name, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), device_name = VALUES(device_name), last_sync_at = NOW()
        ");
        $stmtDev->execute([
            ':d_uuid' => $device_uuid,
            ':u_id'   => $user['id'],
            ':d_name' => $device_name
        ]);
    }

    $permissions = json_decode($user['permissions'] ?? '{}', true) ?: [];
    $token_payload = [
        'user_id'     => $user['id'],
        'username'    => $user['username'],
        'full_name'   => $user['full_name'],
        'role'        => $user['role'],
        'device_uuid' => $device_uuid
    ];
    $token = generate_token($token_payload);

    log_audit($user['id'], 'USER_LOGIN', 'users', (string)$user['id'], null, ['username' => $username, 'device' => $device_name]);

    send_success('እንኳን በደህና መጡ! (Login Successful)', [
        'token'       => $token,
        'user'        => [
            'id'          => $user['id'],
            'uuid'        => $user['uuid'],
            'username'    => $user['username'],
            'full_name'   => $user['full_name'],
            'role'        => $user['role'],
            'permissions' => $permissions
        ]
    ]);
}

// 2. የአሁኑ ተጠቃሚ መረጃ (Me)
if ($action === 'me') {
    $user = get_authenticated_user();
    send_success('የተጠቃሚ መረጃ (User Info)', ['user' => $user]);
}

// 3. የተጠቃሚዎች ዝርዝር (Super Admin + Admin)
// Admin የሚያየው worker እና admin_worker አካውንቶችን ብቻ ነው።
if ($action === 'users') {
    $user = get_authenticated_user();
    require_admin($user);

    if (normalize_role($user['role']) === 'admin') {
        $stmt = $pdo->query("SELECT id, uuid, username, full_name, role, permissions, is_active, created_at FROM users WHERE role IN ('worker', 'admin_worker') ORDER BY id ASC");
    } else {
        $stmt = $pdo->query("SELECT id, uuid, username, full_name, role, permissions, is_active, created_at FROM users ORDER BY id ASC");
    }
    $users = $stmt->fetchAll();
    foreach ($users as &$u) {
        $u['permissions'] = json_decode($u['permissions'] ?? '{}', true) ?: [];
    }

    send_success('የተጠቃሚዎች ዝርዝር', ['users' => $users]);
}

// 4. አዲስ ተጠቃሚ መፍጠር (Super Admin + Admin)
// Admin የሚፈጥረው worker እና admin_worker ብቻ ነው — admin/super_admin መፍጠር አይችልም።
if ($action === 'create_user') {
    $user = get_authenticated_user();
    require_admin($user);

    $data = get_request_data();
    $username = trim($data['username'] ?? '');
    $full_name = trim($data['full_name'] ?? '');
    $password = $data['password'] ?? '';
    $role = $data['role'] ?? 'worker';
    $permissions = $data['permissions'] ?? [];

    if (empty($username) || empty($full_name) || empty($password)) {
        send_error('ሁሉንም አስፈላጊ መረጃዎች ያስገቡ');
    }

    if (!in_array($role, ['super_admin', 'admin', 'admin_worker', 'worker'], true)) {
        send_error('ትክክለኛ ያልሆነ ሚና (Invalid Role)');
    }

    // Admin admin/super_admin role መስጠት አይችልም
    if (normalize_role($user['role']) === 'admin' && in_array($role, ['admin', 'super_admin'], true)) {
        send_error('አስተዳዳሪ (Admin) የ admin ወይም የ super_admin አካውንት መፍጠር አይችልም', 403);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $uuid = 'u-' . bin2hex(random_bytes(8));

    try {
        $stmt = $pdo->prepare("
            INSERT INTO users (uuid, username, password_hash, full_name, role, permissions, is_active, created_at)
            VALUES (:uuid, :username, :hash, :full_name, :role, :permissions, 1, NOW())
        ");
        $stmt->execute([
            ':uuid'        => $uuid,
            ':username'    => $username,
            ':hash'        => $hash,
            ':full_name'   => $full_name,
            ':role'        => $role,
            ':permissions' => json_encode($permissions, JSON_UNESCAPED_UNICODE)
        ]);

        $new_id = (int)$pdo->lastInsertId();
        log_audit($user['id'], 'USER_CREATE', 'users', (string)$new_id, null, ['username' => $username, 'role' => $role]);

        send_success('ተጠቃሚው በተሳካ ሁኔታ ተፈጥሯል', ['user_id' => $new_id, 'uuid' => $uuid], 201);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            send_error('ይህ የተጠቃሚ ስም ቀድሞ ተይዟል (Username already exists)');
        }
        send_error('ስህተት ተከስቷል: ' . $e->getMessage());
    }
}

// 5. ፈቃድ፣ ሚና እና ሁኔታ ማስተካከል (Super Admin + Admin)
// Admin የሚያስተካክለው worker እና admin_worker አካውንቶችን ብቻ ነው፤
// role ወደ admin/super_admin መቀየር አይችልም።
if ($action === 'update_permissions') {
    $user = get_authenticated_user();
    require_admin($user);

    $data = get_request_data();
    $target_user_id = (int)($data['user_id'] ?? 0);
    $permissions = $data['permissions'] ?? [];
    $is_active = isset($data['is_active']) ? (int)$data['is_active'] : null;
    $new_role = isset($data['role']) ? trim($data['role']) : null;

    if ($target_user_id <= 0) {
        send_error('ትክክለኛ የተጠቃሚ መለያ አልተገለጸም');
    }

    if ($new_role !== null && $new_role !== '' && !in_array($new_role, ['super_admin', 'admin', 'admin_worker', 'worker'], true)) {
        send_error('ትክክለኛ ያልሆነ ሚና (Invalid Role)');
    }

    // የቆየውን መረጃ ማግኘት
    $stmtOld = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmtOld->execute([':id' => $target_user_id]);
    $oldUser = $stmtOld->fetch();
    if (!$oldUser) {
        send_error('ተጠቃሚው አልተገኘም');
    }

    // የአስተዳዳሪ ገደብ: admin/super_admin አካውንት መንካት ወይም role ወደ admin/super_admin መቀየር አይቻልም
    assert_can_manage_user($user, $oldUser, $new_role ?: null);

    // permissions የሚለው መስክ ሲላክ ብቻ ይዘምናል — role ብቻ ሲቀየር (ለምሳሌ ከ UI)
    // ያለውን permissions JSON እንዳይጠፋ እንከላከላለን
    $fields = [];
    $params = [':id' => $target_user_id];

    if (array_key_exists('permissions', $data)) {
        $fields[] = "permissions = :permissions";
        $params[':permissions'] = json_encode($permissions, JSON_UNESCAPED_UNICODE);
    }

    if ($new_role !== null && $new_role !== '') {
        $fields[] = "role = :role";
        $params[':role'] = $new_role;
    }

    if ($is_active !== null) {
        $fields[] = "is_active = :is_active";
        $params[':is_active'] = $is_active;
    }

    if (empty($fields)) {
        send_error('ምንም የሚቀየር መስክ አልተገለጸም');
    }

    $sql = "UPDATE users SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    log_audit($user['id'], 'USER_UPDATE_PERMISSIONS', 'users', (string)$target_user_id, $oldUser, $params);

    send_success('የተጠቃሚ ፈቃድ ተስተካክሏል');
}

// 6. ተጠቃሚ መሰረዝ (Super Admin + Admin)
// Admin የሚሰርዘው worker እና admin_worker አካውንቶችን ብቻ ነው፤ የራሱን አካውንት ማንም መሰረዝ አይችልም።
if ($action === 'delete_user') {
    $user = get_authenticated_user();
    require_admin($user);

    $data = get_request_data();
    $target_user_id = (int)($data['user_id'] ?? 0);

    if ($target_user_id <= 0) {
        send_error('ትክክለኛ የተጠቃሚ መለያ አልተገለጸም');
    }

    if ($target_user_id === (int)$user['id']) {
        send_error('የራስዎን አካውንት መሰረዝ አይችሉም', 403);
    }

    $stmtOld = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmtOld->execute([':id' => $target_user_id]);
    $oldUser = $stmtOld->fetch();
    if (!$oldUser) {
        send_error('ተጠቃሚው አልተገኘም');
    }

    assert_can_manage_user($user, $oldUser);

    // የንግድ እንቅስቃሴ ማረጋገጫ — ይህ ተጠቃሚ ሽያጭ / ግዢ / ክፍያ / ወጪ / ማውጫ /
    // የክምችት እንቅስቃሴ / የዕለት መዝጊያ ከመዘገበ፣ እነዚያ መዝገቦች የፋይናንስ
    // ታሪክ ስለሆኑ መሰረዝ አይቻልም። (እነዚህ ሰንጠረዦች users(id) ላይ ያለ
    // CASCADE የሌለው FOREIGN KEY አላቸው።)
    $businessReferences = [
        ['sales', 'created_by'],
        ['purchases', 'created_by'],
        ['payments', 'created_by'],
        ['expenses', 'created_by'],
        ['withdrawals', 'created_by'],
        ['stock_movements', 'authorized_by'],
        ['daily_closings', 'closed_by'],
        ['daily_closings', 'reopened_by'],
    ];
    foreach ($businessReferences as [$refTable, $refColumn]) {
        $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM {$refTable} WHERE {$refColumn} = :id");
        $stmtChk->execute([':id' => $target_user_id]);
        if ((int)$stmtChk->fetchColumn() > 0) {
            send_error('ይህ ተጠቃሚ የተመዘገበ የንግድ እንቅስቃሴ ስላለው መሰረዝ አይቻልም (This user has recorded business activity and cannot be deleted)');
        }
    }

    // የመግቢያ / የመሣሪያ ታሪክ (audit_logs + devices) የተጠቃሚው የግል መዝገብ
    // ነው — ከተጠቃሚው ጋር አብሮ ይሰረዛል። audit_logs ላይ users(id) የሚያመለክት
    // ያለ CASCADE FOREIGN KEY ስላለ፣ መጀመሪያ እነዚህን መሰረዝ አለብን፤
    // አለበለዚያ MySQL 1451 ስህተት ይጥላል። devices ላይ ON DELETE CASCADE
    // ቢኖርም፣ በግልጽ እንሰርዘዋለን።
    try {
        $pdo->beginTransaction();

        $stmtDel = $pdo->prepare("DELETE FROM audit_logs WHERE user_id = :id");
        $stmtDel->execute([':id' => $target_user_id]);

        $stmtDel = $pdo->prepare("DELETE FROM devices WHERE user_id = :id");
        $stmtDel->execute([':id' => $target_user_id]);

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute([':id' => $target_user_id]);

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('delete_user failed for user ' . $target_user_id . ': ' . $e->getMessage());
        // 1451 = የውጭ ቁልፍ ገደብ (foreign key constraint) — በፍተሻና
        // በመሰረዝ መካከል አዲስ መዝገብ ከተፈጠረ ሊከሰት ይችላል።
        if ((int)($e->errorInfo[1] ?? 0) === 1451) {
            send_error('ይህ ተጠቃሚ የተመዘገበ የንግድ እንቅስቃሴ ስላለው መሰረዝ አይቻልም (This user has recorded business activity and cannot be deleted)');
        }
        send_error('ተጠቃሚውን መሰረዝ አልተሳካም (Failed to delete user)');
    }

    log_audit($user['id'], 'USER_DELETE', 'users', (string)$target_user_id, $oldUser, null);

    send_success('ተጠቃሚው ተሰርዟል');
}

send_error('ያልታወቀ ክንውን (Invalid Action)', 404);
