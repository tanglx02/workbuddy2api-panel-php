<?php
declare(strict_types=1);
namespace WB2A\Core;

/**
 * Runtime environment: paths, installed-state, config loading.
 */
final class Env
{
    public static string $dataDir = '';
    public static ?array $config = null;
    public static ?Store $store = null;
    public static ?string $secretKey = null;

    public static function bootstrap(): void
    {
        self::$dataDir = self::resolveDataDir();
        if (!is_dir(self::$dataDir)) { @mkdir(self::$dataDir, 0755, true); }
        // boot_time：首次访问时落库，此后持续复用 → 面板「运行时长」随时间增长
        if (self::store()->kvGet('boot_time', 0) === 0) {
            self::store()->kvSet('boot_time', time());
        }
    }

    /**
     * Resolve the writable data dir. Preference:
     * 1. WB2A_ROOT/data (existing install state wins)
     * 2. WB2A_ROOT/data if (or once) actually writable
     * 3. fallback: system temp dir (shared-hosting ACL rescue; flagged via data_dir_fallback marker)
     */
    public static function resolveDataDir(): string
    {
        $primary = WB2A_ROOT . '/data';
        // an already-initialized primary install always wins
        if (is_file($primary . '/config.json')) { return $primary; }
        if (is_dir($primary) && self::dirWritable($primary)) { return $primary; }
        if (!is_dir($primary)) { @mkdir($primary, 0775, true); }
        if (is_dir($primary) && self::dirWritable($primary)) { return $primary; }
        // fallback
        $fb = rtrim(sys_get_temp_dir(), '/\\') . '/wb2a-data-' . substr(md5(WB2A_ROOT), 0, 10);
        if (!is_dir($fb)) { @mkdir($fb, 0775, true); }
        if (is_dir($fb) && self::dirWritable($fb)) {
            @file_put_contents($primary . '/DATA_DIR_FALLBACK.txt', "This host denied write access to data/ for the web-server user.\nData is being stored in: $fb\nFix the data/ directory ACL (grant the IIS app-pool account Modify permission) and reinstall to store data inside the site.\n");
            return $fb;
        }
        return $primary; // keep original semantics: checks will report the failure
    }

    /** Real write probe (is_writable() is unreliable on shared hosting). */
    public static function dirWritable(string $dir): bool
    {
        clearstatcache(true, $dir);
        $probe = $dir . '/.wb2a_probe_' . bin2hex(random_bytes(4));
        $ok = @file_put_contents($probe, '1') !== false;
        if ($ok) { @unlink($probe); }
        return $ok;
    }

    public static function installed(): bool
    {
        return is_file(self::$dataDir . '/config.json') && is_file(self::$dataDir . '/secret.key');
    }

    public static function config(): array
    {
        if (self::$config === null) {
            self::$config = self::loadConfig();
        }
        return self::$config;
    }

    public static function loadConfig(): array
    {
        $file = self::$dataDir . '/config.json';
        $cfg = [];
        if (is_file($file)) {
            $cfg = json_decode((string)file_get_contents($file), true) ?: [];
        }
        return Config::withDefaults($cfg);
    }

    public static function saveConfig(array $cfg): void
    {
        self::$config = $cfg;
        Util::atomicWrite(self::$dataDir . '/config.json', json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function store(): Store
    {
        if (self::$store === null) {
            $cfg = self::config();
            try {
                self::$store = Store::open($cfg);
            } catch (\Throwable $e) {
                // DB connection failed: fall back to file storage so the panel
                // stays usable and the error is visible instead of a white page.
                self::$store = Store::openFileOnly();
                self::$store->lastDbError = $e->getMessage();
            }
        }
        return self::$store;
    }

    public static function secret(): string
    {
        if (self::$secretKey === null) {
            $file = self::$dataDir . '/secret.key';
            if (!is_file($file)) {
                Util::atomicWrite($file, Util::randomHex(32));
            }
            self::$secretKey = trim((string)file_get_contents($file));
        }
        return self::$secretKey;
    }

    /** Resolve request path relative to the app base (supports subdirectory installs). */
    public static function requestPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $up = parse_url($uri, PHP_URL_PATH) ?: '/';
        // Direct .php file hit (e.g. /install/index.php): route by its dir relative to root.
        if (substr($up, -4) === '.php' && is_file(WB2A_ROOT . $up)) {
            return trim(dirname($up), '/');
        }
        // IIS/fast-cgi PATH_INFO routing (e.g. /index.php/healthz): only trust
        // PATH_INFO when SCRIPT_NAME is the front controller itself, otherwise
        // servers emit partial PATH_INFO for requests under real directories.
        $pi = trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
        if ($pi !== '' && substr((string)($_SERVER['SCRIPT_NAME'] ?? ''), -10) === 'index.php') { return $pi; }
        // Sub-directory install: strip base from SCRIPT_NAME=index.php.
        $sn = $_SERVER['SCRIPT_NAME'] ?? '';
        if (substr($sn, -10) === 'index.php' && $up !== $sn) {
            $base = rtrim(dirname($sn), '/');
            if ($base !== '' && $base !== '/' && strpos($up, $base) === 0) {
                $up = substr($up, strlen($base));
            }
        }
        // Fallback for hosts exposing /index.php/... without PATH_INFO
        if (strpos($up, 'index.php/') === 0) { $up = substr($up, strlen('index.php/')); }
        // Query routing for hosts without rewrite support: /?/v1/models
        if ($up === '/' && ($q = $_SERVER['QUERY_STRING'] ?? '') !== '' && $q[0] === '/') {
            $pos = strpos($q, '&');
            $up = $pos === false ? $q : substr($q, 0, $pos);
        }
        return trim($up, '/');
    }
}
