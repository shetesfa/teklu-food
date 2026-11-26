<?php
/**
 * የተጠቃሚዎች እና መሳሪያዎች ማረጋገጫ ሄልፐር (Authentication & Authorization)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/response.php';

// ሚስጥራዊ የቶከን ቁልፍ
define('AUTH_SECRET_KEY', 'Teklu_Getachew_Farm_Dairy_Secret_Key_2026_Ethio!#');

function generate_token(array $user_payload): string {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode(array_merge($user_payload, [
        'iat' => time(),
        'exp' => time() + (86400 * 30), // 30 ቀናት የሚቆይ
    ]));
    
    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, AUTH_SECRET_KEY, true);
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
}

function verify_token(?string $token): ?array {
    if (empty($token)) {
        return null;
    }
    
    // "Bearer <token>" ከሆነ ማስተካከል
    if (str_starts_with($token, 'Bearer ')) {
        $token = substr($token, 7);
    }
    
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    
    list($header64, $payload64, $sig64) = $parts;
    
    $expectedSig = hash_hmac('sha256', $header64 . "." . $payload64, AUTH_SECRET_KEY, true);
    $expectedSig64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($expectedSig));
    
    if (!hash_equals($expectedSig64, $sig64)) {
        return null;
    }
    
    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $payload64)), true);
    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
        return null;
    }
    
    return $payload;
}

function get_authenticated_user(): array {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    // በ GET/POST ውስጥ ካለ
    if (empty($authHeader) && isset($_REQUEST['token'])) {
        $authHeader = $_REQUEST['token'];
    }
    
    $payload = verify_token($authHeader);
    if (!$payload) {
        send_error('እባክዎ መጀመሪያ ይግቡ (Authentication Required)', 401);
    }
    
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT id, uuid, username, full_name, role, permissions, is_active FROM users WHERE id = :id");
    $stmt->execute([':id' => $payload['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user || (int)$user['is_active'] !== 1) {
        send_error('ተጠቃሚው አልተገኘም ወይም ታግዷል (User Inactive or Not Found)', 403);
    }
    
    $user['permissions'] = json_decode($user['permissions'] ?? '{}', true) ?: [];
    return $user;
}

/**
 * የተጠቃሚ ሚናዎች (Roles) — ደረጃ (hierarchy): super_admin > admin > admin_worker > worker
 * - super_admin: ሁሉም ነገር (everything) — user management, Backup, settings.
 * - admin: የቀን ተቀን ሞጁሎች + user management ለ worker/admin_worker ብቻ (Backup/settings የለም).
 * - admin_worker: POS, ምግብ add/edit, ክምችት view + receiving, ወጪ add, suppliers/customers view.
 * - worker: POS + ምግብ/ክምችት view ብቻ።
 * 'manager' የሚለው አሮጌ ሚና ወደ 'admin' ተቀይሯል (migration 002) — እዚህ እንደ admin ይቆጠራል።
 */
const ROLE_RANKS = [
    'worker'       => 1,
    'admin_worker' => 2,
    'admin'        => 3,
    'super_admin'  => 4,
];

function normalize_role(string $role): string {
    return $role === 'manager' ? 'admin' : $role;
}

function role_rank(string $role): int {
    $role = normalize_role($role);
    return ROLE_RANKS[$role] ?? 0;
}

/** ደረጃው >= admin (super_admin ወይም admin) መሆኑን ያረጋግጣል */
function require_admin(array $user): void {
    if (role_rank($user['role'] ?? '') < 3) {
        send_error('ይህ ክንውን ለአስተዳዳሪ (Admin) እና ከዚያ በላይ ብቻ የተፈቀደ ነው', 403);
    }
}

/** ደረጃው >= admin_worker (super_admin / admin / admin_worker) መሆኑን ያረጋግጣል */
function require_admin_worker(array $user): void {
    if (role_rank($user['role'] ?? '') < 2) {
        send_error('ይህ ክንውን ለአስተዳዳሪ ሠራተኛ (Admin Worker) እና ከዚያ በላይ ብቻ የተፈቀደ ነው', 403);
    }
}

function require_super_admin(array $user): void {
    if (normalize_role($user['role'] ?? '') !== 'super_admin') {
        send_error('ይህ ክንውን ለዋና አስተዳዳሪ (Super Admin) ብቻ የተፈቀደ ነው', 403);
    }
}

/**
 * በ role ላይ የተመሰረተ የፈቃድ ካርታ (role-based permissions).
 * የታወቀ permission ስም ከሆነ role ይወስናል፤ የማይታወቅ ስም ከሆነ (ለምሳሌ can_discount)
 * የድሮው per-user permissions JSON ይጠቅማል — role defaults govern, JSON stays.
 */
function check_permission(array $user, string $permission_name): bool {
    $role = normalize_role($user['role'] ?? '');
    if ($role === 'super_admin') {
        return true;
    }

    $rolePermissions = [
        // ሪፖርቶች (P&L) — admin ብቻ
        'can_view_pl'       => ['admin'],
        // ዕለታዊ ሂሳብ መዝጊያ — admin ብቻ
        'daily_closing'     => ['admin'],
        // የተጠቃሚዎች አስተዳደር — admin ብቻ (ተጨማሪ የዒላማ ሚና ገደብ በ can_manage_user ይፈተናል)
        'user_manage'       => ['admin'],
        // ምትኬ እና ቅንብሮች — super_admin ብቻ (ከላይ ተመልሷል)
        'backup'            => [],
        'settings'          => [],
        // ምግብ/ሬሲፒ መፍጠር እና ማስተካከል — admin_workerም ጭምር
        'food_manage'       => ['admin', 'admin_worker'],
        // ወጪ መመዝገብ — admin_workerም ጭምር
        'expenses_add'      => ['admin', 'admin_worker'],
        // እቃ መረከብ (goods receiving) — admin_workerም ጭምር
        'receiving'         => ['admin', 'admin_worker'],
        // ገንዘብ መውጣት — admin ብቻ
        'withdrawals'       => ['admin'],
        // አቅራቢ/ደንበኛ መፍጠር እና ማስተካከል — admin ብቻ
        'contacts_manage'   => ['admin'],
        // ምርት መፍጠር/ማስተካከል/ክምችት ማስተካከል — admin ብቻ
        'products_manage'   => ['admin'],
        // ሽያጭ (POS)፣ ምግብ/ክምችት/አቅራቢ/ደንበኛ ማየት — ሁሉም ሚናዎች
        'pos'               => ['admin', 'admin_worker', 'worker'],
        'food_view'         => ['admin', 'admin_worker', 'worker'],
        'stock_view'        => ['admin', 'admin_worker', 'worker'],
        'contacts_view'     => ['admin', 'admin_worker', 'worker'],
    ];

    if (array_key_exists($permission_name, $rolePermissions)) {
        return in_array($role, $rolePermissions[$permission_name], true);
    }

    // የማይታወቅ permission — የድሮው per-user JSON ይጠቅማል
    return !empty($user['permissions'][$permission_name]);
}

/**
 * አንድ አስተዳዳሪ (actor) ሌላ ተጠቃሚ (target) መፍጠር/ማስተካከል/መሰረዝ ይችላል?
 * - super_admin: ሁሉንም (ማንኛውንም role ጭምር)።
 * - admin: worker እና admin_worker አካውንቶችን ብቻ፤ የዒላማው role admin/super_admin
 *   ከሆነ ወይም አዲሱ role admin/super_admin ከሆነ አይፈቀድም።
 * - admin_worker/worker: የተጠቃሚ አስተዳደር በፍጹም አይፈቀድም።
 */
function can_manage_user(array $actor, array $target, ?string $newRole = null): bool {
    $actorRole = normalize_role($actor['role'] ?? '');
    if ($actorRole === 'super_admin') {
        return true;
    }
    if ($actorRole !== 'admin') {
        return false;
    }
    $targetRole = normalize_role($target['role'] ?? '');
    if (in_array($targetRole, ['admin', 'super_admin'], true)) {
        return false;
    }
    if ($newRole !== null && in_array(normalize_role($newRole), ['admin', 'super_admin'], true)) {
        return false;
    }
    return true;
}

/** can_manage_user ካልተፈቀደ 403 ይልካል */
function assert_can_manage_user(array $actor, array $target, ?string $newRole = null): void {
    if (!can_manage_user($actor, $target, $newRole)) {
        send_error('አስተዳዳሪ (Admin) የ worker እና የአስተዳዳሪ ሠራተኛ አካውንቶችን ብቻ ማስተዳደር ይችላል', 403);
    }
}
