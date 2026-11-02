<?php
/**
 * የ JSON ምላሽ ማስተካከያ ሄልፐር (Response Helper)
 */

declare(strict_types=1);

function send_json(array $data, int $status_code = 200): void {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Device-Id');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function send_error(string $message, int $status_code = 400, ?array $extra = null): void {
    $payload = [
        'success' => false,
        'error'   => $message,
    ];
    if ($extra !== null) {
        $payload = array_merge($payload, $extra);
    }
    send_json($payload, $status_code);
}

function send_success(string $message, ?array $data = null, int $status_code = 200): void {
    $payload = [
        'success' => true,
        'message' => $message,
    ];
    if ($data !== null) {
        $payload['data'] = $data;
    }
    send_json($payload, $status_code);
}

function get_request_data(): array {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }
    }
    return $_POST;
}
