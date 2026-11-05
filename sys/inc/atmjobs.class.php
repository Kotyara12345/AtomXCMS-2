<?php
/** Durable maintenance queue, processed in bounded slices by normal requests. */
class AtmJobs
{
    public static function directory()
    {
        $path = getenv('ATM_JOB_DIR');
        return $path ? rtrim($path, '/\\') : ROOT . '/sys/tmp/jobs';
    }

    private static function prepare()
    {
        $dir = self::directory();
        foreach (array('', '/pending', '/working', '/done', '/failed') as $part) {
            if (!is_dir($dir . $part) && !@mkdir($dir . $part, 0700, true) && !is_dir($dir . $part)) throw new RuntimeException('Cannot create job spool');
        }
        $marker = $dir . '/.legacy-migrated';
        if (!getenv('ATM_JOB_DIR') && !is_file($marker) && (string)ini_get('open_basedir') === '') {
            $root = str_replace('\\', '/', realpath(ROOT) ?: ROOT);
            foreach (array_unique(array(ROOT, $root, DIRECTORY_SEPARATOR === '\\' ? strtolower($root) : $root)) as $identity) {
                $legacy = dirname($root) . '/atomx-jobs-' . substr(hash('sha256', $identity), 0, 12);
                foreach (array('pending','working','done','failed') as $state) foreach ((array)@glob($legacy . '/' . $state . '/*.json') as $file) {
                    $job = json_decode((string)file_get_contents($file), true);
                    $id = pathinfo($file, PATHINFO_FILENAME);
                    if (!is_array($job) || !preg_match('/^[a-f0-9]{64}$/', $id)) continue;
                    $target = $dir . '/' . $state . '/' . $id . '.php';
                    if (!is_file($target)) self::write($target, $job);
                }
            }
            file_put_contents($marker, '1');
        }
        return $dir;
    }

    private static function write($path, $job)
    {
        $data = "<?php http_response_code(404); exit; ?>\n" . json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        // Even an interrupted write must never leave a publicly readable payload.
        $tmp = dirname($path) . '/.job-' . bin2hex(random_bytes(12)) . '.php';
        try {
            if (file_put_contents($tmp, $data, LOCK_EX) !== strlen($data) || !rename($tmp, $path)) throw new RuntimeException('Cannot persist job');
            @chmod($path, 0600);
        } finally { if (is_file($tmp)) @unlink($tmp); }
    }

    public static function read($path)
    {
        $data = (string)file_get_contents($path);
        $separator = strpos($data, "\n");
        return json_decode($separator === false ? '' : substr($data, $separator + 1), true);
    }

    public static function enqueue($type, $payload, $key = null, $once = false)
    {
        if (!in_array($type, array('search.rebuild','newsletter.material','newsletter.delivery'), true)) throw new InvalidArgumentException('Unknown job type');
        $dir = self::prepare();
        $id = hash('sha256', $type . ':' . ($key === null ? bin2hex(random_bytes(16)) : $key));
        $lock = fopen($dir . '/queue.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock job spool');
        try {
            if (is_file($dir . '/pending/' . $id . '.php') || ($once && (is_file($dir . '/working/' . $id . '.php') || is_file($dir . '/done/' . $id . '.php')))) return $id;
            self::write($dir . '/pending/' . $id . '.php', array('id'=>$id,'type'=>$type,'payload'=>$payload,'attempts'=>0,'available'=>time()));
            return $id;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** One worker owns processing; interrupted jobs are recovered on its next run. */
    public static function run($handler, $limit = 2, $seconds = 3)
    {
        $deadline = microtime(true) + max(0.1, (float)$seconds);
        $dir = self::prepare();
        $worker = fopen($dir . '/worker.lock', 'c');
        if (!$worker || !flock($worker, LOCK_EX | LOCK_NB)) { if ($worker) fclose($worker); return 0; }
        $processed = 0;
        try {
            foreach (glob($dir . '/working/*.php') as $path) {
                $target = $dir . '/pending/' . basename($path);
                if (!is_file($target)) rename($path, $target); else unlink($path);
            }
            $files = glob($dir . '/pending/*.php');
            usort($files, function ($a, $b) { return filemtime($a) <=> filemtime($b); });
            foreach ($files as $path) {
                if ($processed >= max(1, (int)$limit) || microtime(true) >= $deadline) break;
                $job = self::read($path);
                if (!is_array($job) || !isset($job['type'], $job['payload'], $job['available'], $job['attempts'])) { rename($path, $dir . '/failed/' . basename($path)); continue; }
                if ($job['available'] > time()) continue;
                $working = $dir . '/working/' . basename($path);
                if (!rename($path, $working)) continue;
                try {
                    $next = $handler($job['type'], $job['payload']);
                    if (is_array($next)) {
                        $job['payload'] = $next;
                        self::write($path, $job);
                        unlink($working);
                        $processed++;
                        continue;
                    }
                    $job['finished'] = time();
                    // Receipts contain no recipient addresses or unsubscribe tokens.
                    self::write($dir . '/done/' . basename($path), array('id'=>$job['id'],'type'=>$job['type'],'finished'=>$job['finished']));
                    unlink($working);
                } catch (Throwable $e) {
                    $job['attempts']++;
                    $job['available'] = time() + min(3600, 60 * (2 ** min(6, $job['attempts'] - 1)));
                    $job['error'] = get_class($e);
                    if ($job['attempts'] >= 5) self::write($dir . '/failed/' . basename($path), $job);
                    elseif (!is_file($path)) self::write($path, $job);
                    unlink($working);
                    error_log('AtomX background job failed: ' . $job['type'] . ', attempt ' . $job['attempts']);
                }
                $processed++;
            }
            foreach (glob($dir . '/done/*.php') as $path) if (filemtime($path) < time() - 7 * 86400) @unlink($path);
        } finally { flock($worker, LOCK_UN); fclose($worker); }
        return $processed;
    }

    public static function maintenance()
    {
        if (!is_dir(self::directory() . '/pending')) return;
        if (!glob(self::directory() . '/pending/*.php') && !glob(self::directory() . '/working/*.php')) return;
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        try {
            self::run(function ($type, $payload) {
                if ($type === 'search.rebuild') {
                    if (!Config::read('active', 'search')) return null;
                    require_once ROOT . '/modules/search/index.php';
                    $module = new SearchModule(array('search','index'));
                    return $module->rebuildIndexStep($payload);
                }
                if ($type === 'newsletter.material') return atm_subscription_process_batch($payload);
                if ($type === 'newsletter.delivery') { atm_subscription_deliver_job($payload); return null; }
                throw new RuntimeException('Unknown maintenance task');
            }, 2, 3);
        } catch (Throwable $e) { error_log('AtomX maintenance unavailable: ' . get_class($e)); }
    }
}
