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

function require_super_admin(array $user): void {
    if ($user['role'] !== 'super_admin') {
        send_error('ይህ ክንውን ለዋና አስተዳዳሪ (Super Admin) ብቻ የተፈቀደ ነው', 403);
    }
}

function check_permission(array $user, string $permission_name): bool {
    if ($user['role'] === 'super_admin') {
        return true;
    }
    return !empty($user['permissions'][$permission_name]);
}
