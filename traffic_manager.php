<?php
// FILE: api/traffic_manager.php
// PURPOSE: Central traffic control layer for the Supriya Digitals gallery.
//
//  Protections provided:
//    1. Per-IP rate limiting  — max N requests per minute using a flat JSON file
//       (no Redis needed; safe on Synology NAS)
//    2. Global concurrency cap — if active visitors exceed MAX_CONCURRENT, new
//       heavy requests receive a 503 with Retry-After so the browser backs off
//    3. MySQL deadlock retry  — wraps PDO transactions and retries up to 3 times
//       on ER_LOCK_DEADLOCK (1213) with exponential back-off
//    4. Slow-read / large-request guard — rejects bodies > MAX_BODY_BYTES before
//       PHP even tries to parse them
//
//  HOW TO USE:
//    Include at the top of any API file that needs protection:
//
//       require_once 'traffic_manager.php';
//       TrafficManager::check();              // rate-limit + concurrency gate
//
//    For DB transactions with deadlock retry:
//
//       TrafficManager::runTransaction($pdo, function($pdo) {
//           $pdo->prepare(…)->execute(…);
//       });
//
// ──────────────────────────────────────────────────────────────────

class TrafficManager
{
    // ── Tuning constants ────────────────────────────────────────
    const MAX_REQUESTS_PER_MINUTE = 120;   // per unique IP
    const MAX_CONCURRENT          = 80;    // site-wide simultaneous viewers
    const MAX_BODY_BYTES          = 2097152; // 2 MB max POST body
    const DEADLOCK_MAX_RETRIES    = 3;
    const DEADLOCK_BASE_WAIT_MS   = 50;    // ms; doubles each retry

    // JSON files in the cache/ dir — same dir used by ping.php
    private static function rateLimitFile(): string {
        return __DIR__ . '/cache/rate_limit.json';
    }
    private static function concurrencyFile(): string {
        return __DIR__ . '/cache/active_users.json';
    }

    // ── 1. RATE LIMIT ───────────────────────────────────────────
    private static function getClientIp(): string {
        foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) {
                return trim(explode(',', $_SERVER[$h])[0]);
            }
        }
        return '0.0.0.0';
    }

    private static function checkRateLimit(): void
    {
        $ip   = self::getClientIp();
        $file = self::rateLimitFile();
        $now  = time();

        // Acquire exclusive lock on the rate-limit file
        $fh = fopen($file, 'c+');
        if (!$fh) return; // if we can't open, just let through

        flock($fh, LOCK_EX);

        $data = [];
        $raw  = stream_get_contents($fh);
        if ($raw) $data = json_decode($raw, true) ?: [];

        // Purge entries older than 60 s to keep the file small
        $cutoff = $now - 60;
        foreach ($data as $k => $v) {
            if (!is_array($v) || ($v['ts'] ?? 0) < $cutoff) unset($data[$k]);
        }

        // Update this IP's hit count
        if (!isset($data[$ip]) || ($data[$ip]['ts'] ?? 0) < $cutoff) {
            $data[$ip] = ['ts' => $now, 'hits' => 1];
        } else {
            $data[$ip]['hits']++;
        }

        $hits = $data[$ip]['hits'];

        // Rewrite file
        fseek($fh, 0);
        ftruncate($fh, 0);
        fwrite($fh, json_encode($data));
        flock($fh, LOCK_UN);
        fclose($fh);

        if ($hits > self::MAX_REQUESTS_PER_MINUTE) {
            http_response_code(429);
            header('Retry-After: 60');
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Too many requests. Please wait a moment and try again.',
                'retry_after' => 60
            ]);
            exit();
        }
    }

    // ── 2. CONCURRENCY GATE ─────────────────────────────────────
    private static function checkConcurrency(): void
    {
        $file = self::concurrencyFile();
        if (!file_exists($file)) return;

        $data = json_decode(@file_get_contents($file), true);
        if (!is_array($data)) return;

        $now    = time();
        $active = 0;
        foreach ($data as $lastSeen) {
            if ($now - $lastSeen < 120) $active++; // 2-minute window matches ping.php
        }

        if ($active > self::MAX_CONCURRENT) {
            http_response_code(503);
            header('Retry-After: 30');
            header('Content-Type: application/json');
            echo json_encode([
                'success'     => false,
                'message'     => 'The gallery is experiencing high traffic. Please try again in a few seconds.',
                'active'      => $active,
                'retry_after' => 30
            ]);
            exit();
        }
    }

    // ── 3. BODY SIZE GUARD ──────────────────────────────────────
    private static function checkBodySize(): void
    {
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > self::MAX_BODY_BYTES) {
            http_response_code(413);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Request body too large.'
            ]);
            exit();
        }
    }

    // ── PUBLIC: run all checks ──────────────────────────────────
    public static function check(bool $skipConcurrency = false): void
    {
        self::checkBodySize();
        self::checkRateLimit();
        if (!$skipConcurrency) self::checkConcurrency();
    }

    // ── 4. DEADLOCK-SAFE TRANSACTION ────────────────────────────
    /**
     * Run a callable inside a PDO transaction.
     * Automatically retries on MySQL deadlock (ER_LOCK_DEADLOCK 1213)
     * with exponential back-off.
     *
     * @param PDO      $pdo
     * @param callable $fn  Receives $pdo. Return value is passed through.
     * @return mixed
     */
    public static function runTransaction(PDO $pdo, callable $fn)
    {
        $attempt = 0;

        while (true) {
            try {
                $pdo->beginTransaction();
                $result = $fn($pdo);
                $pdo->commit();
                return $result;

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();

                // MySQL error 1213 = ER_LOCK_DEADLOCK
                $isDeadlock = ($e->errorInfo[1] ?? 0) == 1213
                           || strpos($e->getMessage(), 'Deadlock') !== false;

                if ($isDeadlock && $attempt < self::DEADLOCK_MAX_RETRIES) {
                    $waitMs = self::DEADLOCK_BASE_WAIT_MS * (2 ** $attempt);
                    usleep($waitMs * 1000);
                    $attempt++;
                    continue; // retry
                }

                // Not a deadlock, or retries exhausted — rethrow
                throw $e;
            }
        }
    }

    // ── ADMIN: return traffic stats ─────────────────────────────
    public static function getStats(): array
    {
        $now      = time();
        $rFile    = self::rateLimitFile();
        $cFile    = self::concurrencyFile();

        // Active viewers (last 120 s)
        $activeViewers = 0;
        $concData = json_decode(@file_get_contents($cFile), true) ?: [];
        foreach ($concData as $lastSeen) {
            if ($now - $lastSeen < 120) $activeViewers++;
        }

        // Rate-limit stats (last 60 s)
        $rateData      = json_decode(@file_get_contents($rFile), true) ?: [];
        $activeIps     = 0;
        $throttledIps  = 0;
        $totalReqMin   = 0;
        foreach ($rateData as $ip => $entry) {
            if (!is_array($entry) || ($entry['ts'] ?? 0) < $now - 60) continue;
            $activeIps++;
            $totalReqMin += $entry['hits'] ?? 0;
            if (($entry['hits'] ?? 0) > self::MAX_REQUESTS_PER_MINUTE) $throttledIps++;
        }

        return [
            'active_viewers'    => $activeViewers,
            'active_ips'        => $activeIps,
            'requests_per_min'  => $totalReqMin,
            'throttled_ips'     => $throttledIps,
            'max_concurrent'    => self::MAX_CONCURRENT,
            'max_rpm'           => self::MAX_REQUESTS_PER_MINUTE,
            'load_pct'          => $activeViewers > 0
                                    ? min(round(($activeViewers / self::MAX_CONCURRENT) * 100), 100)
                                    : 0,
        ];
    }
}
?>
