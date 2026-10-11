<?php
declare(strict_types=1);
namespace WB2A\Core;

/**
 * Storage abstraction.
 *  - MySQL via PDO (primary, for shared hosting with a database)
 *  - SQLite via PDO (dev/test convenience; availability detected at install)
 *  - File storage (fallback: data/store.json with flock-based serialization)
 *
 * Accounts / state / logs / sticky bindings / usage buckets are all routed
 * through this class so business code never touches SQL directly.
 */
final class Store
{
    public ?string $lastDbError = null;
    private $pdo = null;
    private string $mode = 'file';
    private string $prefix = 'wb2a_';
    private string $file = '';

    public static function open(array $cfg): self
    {
        $s = new self();
        $mode = $cfg['storage']['mode'] ?? 'auto';
        $my = $cfg['storage']['mysql'] ?? [];
        if (($mode === 'mysql' || $mode === 'auto') && !empty($my['name']) && in_array('mysql', \PDO::getAvailableDrivers(), true)) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $my['host'] ?: 'localhost', (int)($my['port'] ?: 3306), $my['name']);
            $pdo = new \PDO($dsn, $my['user'] ?? '', $my['pass'] ?? '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]);
            $s->pdo = $pdo; $s->mode = 'mysql'; $s->prefix = $my['prefix'] ?? 'wb2a_';
            $s->migrate();
            return $s;
        }
        if (($mode === 'sqlite' || $mode === 'auto') && in_array('sqlite', \PDO::getAvailableDrivers(), true) && (defined('WB2A_ALLOW_SQLITE') || ($mode === 'sqlite'))) {
            $s->pdo = new \PDO('sqlite:' . Env::$dataDir . '/wb2a.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $s->mode = 'sqlite';
            $s->migrate();
            return $s;
        }
        if ($mode === 'mysql') {
            throw new \RuntimeException('MySQL 连接失败：请检查数据库配置或主机是否提供 MySQL');
        }
        return $s->openFileOnly();
    }

    public static function openFileOnly(): self
    {
        $s = new self();
        $s->mode = 'file';
        $s->file = Env::$dataDir . '/store.json';
        return $s;
    }

    public function mode(): string { return $this->mode; }

    public function describeError(): string { return (string)$this->lastDbError; }

    private function migrate(): void
    {
        $isMy = $this->mode === 'mysql';
        $T = $this->prefix;
        $stmts = [
            "CREATE TABLE IF NOT EXISTS {$T}kv (k VARCHAR(191) PRIMARY KEY, v MEDIUMTEXT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS {$T}accounts (uid VARCHAR(64) PRIMARY KEY, data MEDIUMTEXT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS {$T}sticky (skey VARCHAR(191) PRIMARY KEY, uid VARCHAR(64) NOT NULL, expire_at INT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS {$T}reqlog (id BIGINT AUTO_INCREMENT PRIMARY KEY, ev MEDIUMTEXT NOT NULL, ts INT NOT NULL, outcome VARCHAR(32) DEFAULT '', model VARCHAR(128) DEFAULT '', account VARCHAR(64) DEFAULT '', INDEX tsidx (ts))" . ($isMy ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''),
        ];
        if ($isMy) {
            $stmts[] = "CREATE TABLE IF NOT EXISTS {$T}usage (bkey VARCHAR(191) PRIMARY KEY, data MEDIUMTEXT NOT NULL)";
        } else {
            $stmts[] = "CREATE TABLE IF NOT EXISTS {$T}usage (bkey TEXT PRIMARY KEY, data TEXT NOT NULL)";
        }
        foreach ($stmts as $sql) { $this->pdo->exec($sql); }
    }

    // ---------- KV ----------
    public function kvGet(string $k, $def = null)
    {
        if ($this->mode === 'file') {
            $d = $this->fileRead();
            return $d['kv'][$k] ?? $def;
        }
        $st = $this->pdo->prepare("SELECT v FROM {$this->prefix}kv WHERE k = ?");
        $st->execute([$k]);
        $row = $st->fetch(\PDO::FETCH_NUM);
        return $row ? json_decode($row[0], true) : $def;
    }

    public function kvSet(string $k, $v): void
    {
        if ($this->mode === 'file') {
            $this->fileMutate(function (&$d) use ($k, $v) { $d['kv'][$k] = $v; });
            return;
        }
        $st = $this->pdo->prepare("REPLACE INTO {$this->prefix}kv (k, v) VALUES (?, ?)");
        $st->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
    }

    // ---------- Accounts ----------
    public function accountGet(string $uid): ?array
    {
        if ($this->mode === 'file') {
            $d = $this->fileRead();
            $v = $d['accounts'][$uid] ?? null;
            if ($v === null) { return null; }
            return is_array($v) ? $v : (json_decode((string)$v, true) ?: null);
        }
        $st = $this->pdo->prepare("SELECT data FROM {$this->prefix}accounts WHERE uid = ?");
        $st->execute([$uid]);
        $row = $st->fetch(\PDO::FETCH_NUM);
        return $row ? json_decode($row[0], true) : null;
    }

    public function accountPut(string $uid, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($this->mode === 'file') {
            $this->fileMutate(function (&$d) use ($uid, $json) { $d['accounts'][$uid] = $json; });
            return;
        }
        $st = $this->pdo->prepare("REPLACE INTO {$this->prefix}accounts (uid, data) VALUES (?, ?)");
        $st->execute([$uid, $json]);
    }

    public function accountDelete(string $uid): void
    {
        if ($this->mode === 'file') {
            $this->fileMutate(function (&$d) use ($uid) { unset($d['accounts'][$uid]); });
            return;
        }
        $st = $this->pdo->prepare("DELETE FROM {$this->prefix}accounts WHERE uid = ?");
        $st->execute([$uid]);
    }

    public function accountAll(): array
    {
        if ($this->mode === 'file') {
            $d = $this->fileRead();
            $out = [];
            foreach (($d['accounts'] ?? []) as $uid => $json) {
                $out[$uid] = json_decode((string)$json, true);
            }
            return $out;
        }
        $st = $this->pdo->query("SELECT uid, data FROM {$this->prefix}accounts");
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$uid, $json]) {
            $out[$uid] = json_decode((string)$json, true);
        }
        return $out;
    }

    // ---------- Sticky sessions ----------
    public function stickyGet(string $key): ?string
    {
        if ($this->mode === 'file') {
            $d = $this->fileRead();
            $b = $d['sticky'][$key] ?? null;
            if (!$b || $b['expire_at'] < time()) { return null; }
            return $b['uid'];
        }
        $st = $this->pdo->prepare("SELECT uid, expire_at FROM {$this->prefix}sticky WHERE skey = ?");
        $st->execute([$key]);
        $row = $st->fetch(\PDO::FETCH_NUM);
        if (!$row || (int)$row[1] < time()) { return null; }
        return $row[0];
    }

    public function stickySet(string $key, string $uid, int $ttl): void
    {
        $exp = time() + $ttl;
        if ($this->mode === 'file') {
            $this->fileMutate(function (&$d) use ($key, $uid, $exp) {
                $d['sticky'][$key] = ['uid' => $uid, 'expire_at' => $exp];
                // opportunistic GC
                foreach ($d['sticky'] as $k => $b) { if ($b['expire_at'] < time()) { unset($d['sticky'][$k]); } }
            });
            return;
        }
        $st = $this->pdo->prepare("REPLACE INTO {$this->prefix}sticky (skey, uid, expire_at) VALUES (?,?,?)");
        $st->execute([$key, $uid, $exp]);
        $this->pdo->prepare("DELETE FROM {$this->prefix}sticky WHERE expire_at < ?")->execute([time()]);
    }

    public function stickyDelete(string $key): void
    {
        if ($this->mode === 'file') {
            $this->fileMutate(function (&$d) use ($key) { unset($d['sticky'][$key]); });
            return;
        }
        $this->pdo->prepare("DELETE FROM {$this->prefix}sticky WHERE skey = ?")->execute([$key]);
    }

    public function stickyCount(): int
    {
        if ($this->mode === 'file') {
            $d = $this->fileRead();
            $n = 0;
            foreach (($d['sticky'] ?? []) as $b) { if ($b['expire_at'] >= time()) { $n++; } }
            return $n;
        }
        $st = $this->pdo->query("SELECT COUNT(*) FROM {$this->prefix}sticky WHERE expire_at >= " . time());
        return (int)$st->fetchColumn();
    }

    // ---------- Request log ----------
    public function reqlogInsert(array $event): void
    {
        $json = json_encode($event, JSON_UNESCAPED_UNICODE);
        $ts = (int)($event['ts'] ?? time());
        $outcome = substr((string)($event['outcome'] ?? ''), 0, 32);
        $model = substr((string)($event['model'] ?? ''), 0, 128);
        $account = substr((string)($event['account'] ?? ''), 0, 64);
        if ($this->mode === 'file') {
            $this->fileMutate(function (&$d) use ($json, $ts) {
                $d['reqlog'][] = [$ts, $json];
                if (count($d['reqlog']) > 5000) { $d['reqlog'] = array_slice($d['reqlog'], -5000); }
            });
            return;
        }
        $st = $this->pdo->prepare("INSERT INTO {$this->prefix}reqlog (ev, ts, outcome, model, account) VALUES (?,?,?,?,?)");
        $st->execute([$json, $ts, $outcome, $model, $account]);
    }

    public function reqlogQuery(int $limit, array $f = []): array
    {
        $limit = max(1, min(1000, $limit));
        if ($this->mode === 'file') {
            $d = $this->fileRead();
            $rows = array_reverse($d['reqlog'] ?? []);
            $out = [];
            foreach ($rows as [$ts, $json]) {
                $ev = json_decode((string)$json, true);
                if (!self::matchFilters($ev, $f)) { continue; }
                $out[] = $ev;
                if (count($out) >= $limit) { break; }
            }
            return $out;
        }
        $where = []; $args = [];
        foreach (['outcome' => 'outcome', 'model' => 'model', 'account' => 'account'] as $k => $col) {
            if (!empty($f[$k])) { $where[] = "$col LIKE ?"; $args[] = '%' . $f[$k] . '%'; }
        }
        if (!empty($f['from'])) { $where[] = 'ts >= ?'; $args[] = (int)$f['from']; }
        if (!empty($f['to'])) { $where[] = 'ts <= ?'; $args[] = (int)$f['to']; }
        $sql = "SELECT ev FROM {$this->prefix}reqlog";
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= " ORDER BY id DESC LIMIT $limit";
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$json]) {
            $ev = json_decode((string)$json, true);
            if (self::matchFilters($ev, $f)) { $out[] = $ev; }
        }
        return $out;
    }

    private static function matchFilters(array $ev, array $f): bool
    {
        foreach (['outcome', 'model', 'account', 'client_ip', 'user_agent'] as $k) {
            if (!empty($f[$k]) && stripos((string)($ev[$k] ?? ''), (string)$f[$k]) === false) { return false; }
        }
        if (!empty($f['from']) && ($ev['ts'] ?? 0) < (int)$f['from']) { return false; }
        if (!empty($f['to']) && ($ev['ts'] ?? 0) > (int)$f['to']) { return false; }
        return true;
    }

    // ---------- Usage buckets ----------
    public function usageAll(): array
    {
        if ($this->mode === 'file') {
            $d = $this->fileRead();
            $out = [];
            foreach (($d['usage'] ?? []) as $k => $json) { $out[$k] = json_decode((string)$json, true); }
            return $out;
        }
        $st = $this->pdo->query("SELECT bkey, data FROM {$this->prefix}usage");
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$k, $json]) { $out[$k] = json_decode((string)$json, true); }
        return $out;
    }

    public function usagePut(string $key, array $bucket): void
    {
        $json = json_encode($bucket, JSON_UNESCAPED_UNICODE);
        if ($this->mode === 'file') {
            $this->fileMutate(function (&$d) use ($key, $json) { $d['usage'][$key] = $json; });
            return;
        }
        $st = $this->pdo->prepare("REPLACE INTO {$this->prefix}usage (bkey, data) VALUES (?,?)");
        $st->execute([$key, $json]);
    }

    /**
     * Import a legacy file-store (store.json) into the current DB backend.
     * Used by the installer when switching file -> mysql/sqlite so existing
     * accounts/config survive the storage switch. Returns row counts.
     */
    public function importFromFile(string $file): array
    {
        if ($this->mode === 'file' || !is_file($file)) { return ['ok' => false, 'error' => '无旧文件存储可导入']; }
        $fp = fopen($file, 'r');
        if (!$fp) { return ['ok' => false, 'error' => '旧存储文件不可读']; }
        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $d = json_decode((string)$raw, true);
        if (!is_array($d)) { return ['ok' => false, 'error' => '旧存储文件格式无效']; }
        $c = ['kv' => 0, 'accounts' => 0, 'sticky' => 0, 'usage' => 0, 'reqlog' => 0];
        foreach (($d['kv'] ?? []) as $k => $v) {
            $this->pdo->prepare("REPLACE INTO {$this->prefix}kv (k, v) VALUES (?,?)")->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
            $c['kv']++;
        }
        foreach (($d['accounts'] ?? []) as $uid => $json) {
            $arr = is_array($json) ? $json : json_decode((string)$json, true);
            if (!is_array($arr)) { continue; }
            $this->pdo->prepare("REPLACE INTO {$this->prefix}accounts (uid, data) VALUES (?,?)")->execute([$uid, json_encode($arr, JSON_UNESCAPED_UNICODE)]);
            $c['accounts']++;
        }
        foreach (($d['sticky'] ?? []) as $k => $b) {
            if (!is_array($b) || ($b['expire_at'] ?? 0) < time()) { continue; }
            $this->pdo->prepare("REPLACE INTO {$this->prefix}sticky (skey, uid, expire_at) VALUES (?,?,?)")->execute([$k, (string)$b['uid'], (int)$b['expire_at']]);
            $c['sticky']++;
        }
        foreach (($d['usage'] ?? []) as $k => $json) {
            $arr = is_array($json) ? $json : json_decode((string)$json, true);
            if (!is_array($arr)) { continue; }
            $this->pdo->prepare("REPLACE INTO {$this->prefix}usage (bkey, data) VALUES (?,?)")->execute([$k, json_encode($arr, JSON_UNESCAPED_UNICODE)]);
            $c['usage']++;
        }
        foreach (array_slice($d['reqlog'] ?? [], -2000) as $row) {
            [$ts, $json] = $row;
            $ev = is_array($json) ? $json : (json_decode((string)$json, true) ?: []);
            $this->pdo->prepare("INSERT INTO {$this->prefix}reqlog (ev, ts, outcome, model, account) VALUES (?,?,?,?,?)")->execute([
                json_encode($ev, JSON_UNESCAPED_UNICODE), (int)$ts,
                substr((string)($ev['outcome'] ?? ''), 0, 32), substr((string)($ev['model'] ?? ''), 0, 128), substr((string)($ev['account'] ?? ''), 0, 64),
            ]);
            $c['reqlog']++;
        }
        return ['ok' => true, 'counts' => $c];
    }

    // ---------- File-mode internals ----------
    private function fileRead(): array
    {
        $empty = ['kv' => [], 'accounts' => [], 'sticky' => [], 'reqlog' => [], 'usage' => []];
        if (!is_file($this->file)) { return $empty; }
        $fp = fopen($this->file, 'r');
        if (!$fp) { return $empty; }
        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $d = json_decode((string)$raw, true) ?: [];
        $d += $empty;
        return $d;
    }

    private function fileMutate(callable $fn): void
    {
        $fp = fopen($this->file, 'c+');
        if (!$fp) { throw new \RuntimeException('数据目录不可写：' . Env::$dataDir); }
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $d = json_decode((string)$raw, true) ?: [];
        $d += ['kv' => [], 'accounts' => [], 'sticky' => [], 'reqlog' => [], 'usage' => []];
        $fn($d);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        @chmod($this->file, 0600);
    }
}
