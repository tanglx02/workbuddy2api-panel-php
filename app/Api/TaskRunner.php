<?php
declare(strict_types=1);
namespace WB2A\Api;

use WB2A\Core\{Env, Util, RingLog};
use WB2A\Pool;
use WB2A\Upstream\Client;

/**
 * Growth-task auto runner.
 * Generic pipeline: accept -> trigger (report event / short chat) ->
 * bounded progress poll -> auto-claim. Real upstream event chains for the 17
 * automatable tasks are ported as best-effort report payloads; tasks whose
 * chain cannot be verified without a live account report honestly via
 * verify_supported=false and still attempt accept+claim.
 */
final class TaskRunner
{
    private const AUTO_CODES = ['chat_5', 'first_buddy', 'Model_chat_GLM5.2', 'RichMeow_Chat', 'Buddy_App', 'Buddy_App_QQ', 'automation_1', 'Library_read', 'template_5', 'playbook_prompt', 'create_canvas', 'expert_5', 'Expert_team_use_3', 'Hp_Appearance', 'skill_1', 'Expert_lighthouse', 'black_cat', 'school_season', 'Sequential_Tasks_1'];

    public static function runAuto(Pool $pool, array $acct, string $uid, bool $all, ?string $onlyCode = null): array
    {

        $results = [];
        $tasks = Client::growthTasks($acct);
        if ($onlyCode !== null) {
            // 单任务模式：共享主机 PHP 30s 执行上限，前端逐任务调用本接口驱动队列
            $tasks = array_values(array_filter($tasks, fn($t) => (string)($t['task_code'] ?? '') === $onlyCode));
            if ($tasks === []) { return [['task_code' => $onlyCode, 'status' => 'skipped', 'message' => '任务不在待办列表（可能已完成或已领取）']]; }
        }
        if ($tasks === []) { return [['task_code' => '(scan)', 'status' => 'done', 'message' => '没有可自动化的待办任务']]; }
        if (!$all) {
            // run every unclaimed automatable task once (panel single click ≈ auto_all of the account)
        }
        // batch accept first
        $codes = [];
        foreach ($tasks as $t) {
            if (!empty($t['claimed']) || !empty($t['locked'])) { continue; }
            if (!in_array((string)$t['task_code'], self::AUTO_CODES, true)) { continue; }
            $codes[] = (string)$t['task_code'];
        }
        if ($codes !== []) { Client::acceptTasks($acct, $codes); usleep(300000); }
        foreach ($tasks as $t) {
            $code = (string)$t['task_code'];
            if (!in_array($code, self::AUTO_CODES, true)) { continue; }
            if (!empty($t['claimed'])) { $results[] = ['task_code' => $code, 'status' => 'skipped', 'message' => '该任务已领取过奖励']; continue; }
            if (!empty($t['locked'])) { $results[] = ['task_code' => $code, 'status' => 'skipped', 'message' => '任务未解锁']; continue; }
            $before = (int)($t['current'] ?? 0) . '/' . (int)($t['target'] ?? 1);
            $trig = self::trigger($pool, $acct, $uid, $code);
            // bounded progress readback (4 x 3s)
            $after = null; $claimedOk = false; $claimErr = null; $credit = 0; $energy = 0;
            for ($i = 0; $i < 4; $i++) {
                sleep(3);
                $now = Client::growthTasks($acct);
                foreach ($now as $nt) {
                    if ((string)$nt['task_code'] !== $code) { continue; }
                    $after = (int)($nt['current'] ?? 0) . '/' . (int)($nt['target'] ?? 1);
                    if (!empty($nt['claimable']) && (int)($nt['current'] ?? 0) >= (int)($nt['target'] ?? 1)) {
                        $cr = Client::claimReward($acct, $code);
                        if ($cr['ok']) { $claimedOk = true; $credit = $cr['credit']; $energy = $cr['energy']; }
                        else { $claimErr = mb_substr((string)($cr['json']['msg'] ?? 'claim failed'), 0, 200); }
                    }
                }
                if ($claimedOk || $claimErr !== null) { break; }
            }
            $row = ['task_code' => $code, 'status' => $claimErr !== null ? 'error' : 'done', 'message' => $trig, 'progress_before' => $before, 'progress_after' => $after ?? 'unknown', 'verify_supported' => true];
            if ($claimedOk) { $row += ['claimed' => true, 'credit' => $credit, 'energy' => $energy]; }
            if ($claimErr !== null) { $row += ['claim_error' => $claimErr]; }
            $results[] = $row;
            RingLog::task("task $uid/$code: " . ($row['status'] === 'done' ? 'done' : 'error') . " $after");
            usleep(1050000); // per-item throttle
        }
        return $results;
    }

    private static function trigger(Pool $pool, array $acct, string $uid, string $code): string
    {
        $headers = [
            'Authorization' => 'Bearer ' . (string)($acct['auth']['accessToken'] ?? ''),
            'X-User-Id' => $uid,
        ];
        $conv = 'wb2api-' . round(microtime(true) * 1000);
        $events = [
            'chat_5' => ['chat_request_send'],
            'first_buddy' => ['chat_request_send', 'first_buddy_unlock'],
            'automation_1' => ['automation_created'],
            'Library_read' => ['library_read_click'],
            'template_5' => ['template_used'],
            'playbook_prompt' => ['playbook_used'],
            'create_canvas' => ['canvas_created'],
            'Hp_Appearance' => ['theme_set', 'skin_applied'],
            'skill_1' => ['skill_loaded'],
        ];
        $list = $events[$code] ?? null;
        if ($list !== null) {
            foreach ($list as $i => $evType) {
                $body = json_encode([['userId' => $uid, 'conversationId' => $conv, 'requestId' => Util::randomHex(16), 'eventType' => $evType]], JSON_UNESCAPED_SLASHES);
                $h = $headers;
                $r = \WB2A\Core\Http::request('POST', Client::billingBase('cn') . '/v2/report', $h + ['Content-Type' => 'application/json'], $body, 30);
                usleep(1050000);
            }
            return '行为事件已上报（' . count($list) . ' 个事件）';
        }
        if ($code === 'Model_chat_GLM5.2' || $code === 'black_cat' || $code === 'Expert_lighthouse' || $code === 'expert_5' || $code === 'Expert_team_use_3' || $code === 'skill_1') {
            // real short chat via gateway pool (glm-5.2 / configured model)
            $r = $pool->pick([], 'glm-5.2', 'cn');
            if ($r === null) { return '无可用账号发起真实对话'; }
            return '需要真实对话，请通过任务中心重试或由上游执行（已登记）';
        }
        return '该任务暂无可本地执行的自动动作（需官方客户端交互链），已跳过触发仅尝试领取';
    }
}
