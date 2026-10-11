<?php
/**
 * Mock CodeBuddy upstream for integration tests.
 * Endpoints:
 *  POST /v2/chat/completions          -> SSE stream (or error per config)
 *  POST /v2/plugin/auth/state         -> device auth start
 *  GET  /v2/plugin/auth/token         -> poll
 *  POST /v2/billing/meter/daily-checkin
 *  POST /v2/billing/meter/get-user-resource
 *  GET  /v2/console/enterprises/personal/models
 */
$mode = getenv('MOCK_MODE') ?: 'ok';
$raw = file_get_contents('php://input');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
header('Content-Type: application/json');

if ($path === '/v2/chat/completions') {
    if ($mode === 'http429') { http_response_code(429); echo json_encode(['code' => 1, 'msg' => 'rate limited, too many requests']); exit; }
    if ($mode === 'http402') { http_response_code(402); echo json_encode(['code' => 1, 'msg' => '积分不足']); exit; }
    if ($mode === 'http404') { http_response_code(404); echo json_encode(['code' => 1, 'msg' => 'not found']); exit; }
    if ($mode === 'http500') { http_response_code(500); echo json_encode(['code' => 1, 'msg' => 'internal error']); exit; }
    if ($mode === 'sessiondead') { http_response_code(400); echo json_encode(['code' => 1, 'msg' => 'Offline user session not found']); exit; }
    if ($mode === 'badparams') { http_response_code(400); echo json_encode(['code' => 11101, 'msg' => 'Unmarshal chat params failed']); exit; }
    if ($mode === 'empty') {
        header('Content-Type: text/event-stream');
        echo "data: [DONE]\n\n";
        exit;
    }
    $j = json_decode($raw, true) ?: [];
    $model = $j['model'] ?? 'mock-model';
    header('Content-Type: text/event-stream');
    $frames = [
        ['id' => 'chatcmpl-mock', 'object' => 'chat.completion.chunk', 'created' => time(), 'model' => $model, 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'hello'], 'finish_reason' => null]], 'usage' => null],
        ['id' => 'chatcmpl-mock', 'object' => 'chat.completion.chunk', 'created' => time(), 'model' => $model, 'choices' => [['index' => 0, 'delta' => ['content' => ' world'], 'finish_reason' => null]], 'usage' => null],
    ];
    foreach ($frames as $f) { echo 'data: ' . json_encode($f) . "\n\n"; flush(); }
    $usage = ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7, 'credit' => 0.01];
    echo 'data: ' . json_encode(['id' => 'chatcmpl-mock', 'object' => 'chat.completion.chunk', 'created' => time(), 'model' => $model, 'choices' => [['index' => 0, 'delta' => new stdClass(), 'finish_reason' => 'stop']], 'usage' => $usage]) . "\n\n";
    flush();
    echo "data: [DONE]\n\n";
    exit;
}
if ($path === '/v2/billing/meter/daily-checkin') { echo json_encode(['code' => 0, 'msg' => 'ok', 'data' => ['credit' => 100]]); exit; }
if ($path === '/v2/billing/meter/get-user-resource') {
    echo json_encode(['code' => 0, 'msg' => 'ok', 'data' => ['accounts' => [['cycleCapacitySize' => 1000, 'capacityRemain' => 888, 'cycleEndTime' => time() + 86400 * 3]]]]);
    exit;
}
if ($path === '/v2/console/enterprises/personal/models') {
    echo json_encode(['code' => 0, 'data' => ['models' => [
        ['id' => 'mock-model', 'name' => 'Mock Model', 'credits' => 0, 'maxInputTokens' => 32000, 'maxOutputTokens' => 8192, 'supportsToolCall' => true],
    ]]]);
    exit;
}
if ($path === '/v2/plugin/auth/state') { echo json_encode(['code' => 0, 'data' => ['state' => 'st-' . bin2hex(random_bytes(6)), 'authUrl' => 'https://mock.example/login']]); exit; }
if ($path === '/v2/plugin/auth/token') { echo json_encode(['code' => 10006, 'msg' => 'not scanned yet']); exit; }
http_response_code(404);
echo json_encode(['code' => 1, 'msg' => 'mock: unknown path ' . $path]);
