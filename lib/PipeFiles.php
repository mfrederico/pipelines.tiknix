<?php
/**
 * PipeFiles — the editor's bridge to an instance: build its dir, fire a run via its
 * OWN trigger endpoint (so the run executes on the instance — its runtime, DB, jail),
 * and read its run history read-only. File ops (list/get/save/validate) reuse core's
 * \app\Pipeline\Loader pointed at the instance dir. Every call takes an instance the
 * Edit controller has already ownership-checked via Sidecar\Access.
 */

namespace app;

use \Flight as Flight;

class PipeFiles {

    /** The instance's app root on disk (…/<slug>.<app>). */
    public static function instanceDir(array $inst): string {
        $parent = dirname(rtrim((string) Flight::get('sidecar.core_root'), '/'));   // /var/www/html/default
        $app = ($inst['app'] ?? '') !== '' ? $inst['app'] : 'tiknix';
        return $parent . '/' . $inst['slug'] . '.' . $app;
    }

    /** The instance's own config (baseurl, trigger_secret, db path). */
    private static function cfg(string $instanceDir): array {
        return @parse_ini_file($instanceDir . '/conf/config.ini', true) ?: [];
    }

    /** The instance's public base URL (host that serves /pipeline/api/<slug>). */
    public static function baseUrl(string $instanceDir): string {
        return rtrim((string) (self::cfg($instanceDir)['app']['baseurl'] ?? ''), '/');
    }

    /**
     * Fire a run on the instance via its trigger endpoint (bearer = the instance's
     * trigger_secret). Returns ['run_id'=>int]|['error'=>string]. The run executes on
     * the instance and records history in the instance's DB.
     */
    public static function triggerRun(string $instanceDir, string $slug, array $context = []): array {
        [$d, $code, $err] = self::post($instanceDir, '/pipeline/trigger/' . rawurlencode($slug), $context, 20);
        if ($err) return ['error' => $err];
        if ($code === 200 && !empty($d['run_id'])) return ['run_id' => (int) $d['run_id']];
        return ['error' => $d['message'] ?? "trigger failed (HTTP $code)"];
    }

    /**
     * Mint a pk_ REST test key ON the instance (its pipeapikey table), over the
     * [pipeline] trigger_secret — same trust path as Run/Debug, NOT broker.ini. The raw
     * key is returned once. $memberId is attribution only (the editor's SSO'd member).
     */
    public static function mintKey(string $instanceDir, int $memberId, string $label): array {
        [$d, $code, $err] = self::post($instanceDir, '/pipeline/mintkey', ['label' => $label, 'member_id' => $memberId], 15);
        if ($err) return ['error' => $err];
        if ($code === 200 && !empty($d['key'])) return ['key' => (string) $d['key']];
        return ['error' => $d['message'] ?? "mint failed (HTTP $code)"];
    }

    /**
     * Start a step-trace debug run on the instance. Returns the breakpoint payload
     * (run_id, status, steps[], bag, last/next step) or ['error'=>string]. Debugging
     * runs SYNCHRONOUSLY on the instance (the request blocks per step), so the
     * connection/db_query/agent steps hit the instance's own real data.
     */
    public static function debugStart(string $instanceDir, string $slug, array $context = []): array {
        [$d, $code, $err] = self::post($instanceDir, '/pipeline/debug/' . rawurlencode($slug), $context, 620);
        if ($err) return ['error' => $err];
        if ($code === 200 && isset($d['run_id'])) return $d;
        return ['error' => $d['message'] ?? "debug start failed (HTTP $code)"];
    }

    /** Deliver a message (or 'alarm') to a durable object on the instance. Returns the object result. */
    public static function deliver(string $instanceDir, string $slug, string $key, array $message, string $trigger): array {
        $path = '/pipeline/object/' . rawurlencode($slug) . '?key=' . rawurlencode($key) . '&trigger=' . rawurlencode($trigger);
        [$d, $code, $err] = self::post($instanceDir, $path, $message, 620);
        if ($err) return ['error' => $err];
        if ($code === 200 && is_array($d)) return $d;
        return ['error' => $d['message'] ?? "deliver failed (HTTP $code)"];
    }

    /** Advance ('step'), finish ('end'), or 'abort' a debug run; $patch is merged into the bag. */
    public static function debugStep(string $instanceDir, int $runId, string $action, array $patch = []): array {
        [$d, $code, $err] = self::post($instanceDir, '/pipeline/debugstep/' . $runId, ['action' => $action, 'patch' => (object) $patch], 620);
        if ($err) return ['error' => $err];
        if ($code === 200 && isset($d['run_id'])) return $d;
        return ['error' => $d['message'] ?? "debug step failed (HTTP $code)"];
    }

    /** POST JSON to an instance endpoint with the trigger_secret bearer. Returns [decoded, httpCode, errString]. */
    private static function post(string $instanceDir, string $path, array $body, int $timeout): array {
        $cfg = self::cfg($instanceDir);
        $base   = rtrim((string) ($cfg['app']['baseurl'] ?? ''), '/');
        $secret = (string) ($cfg['pipeline']['trigger_secret'] ?? '');
        if ($base === '' || $secret === '') return [null, 0, 'This instance has no [pipeline] trigger_secret configured.'];

        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body) ?: '{}',
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $secret],
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return [null, 0, $cerr ?: 'request failed'];
        return [is_string($resp) ? json_decode($resp, true) : null, $code, ''];
    }

    /** Read-only PDO to the instance's sqlite DB (for run polling), or null. */
    private static function db(string $instanceDir): ?\PDO {
        $cfg = self::cfg($instanceDir);
        $path = (string) ($cfg['database']['path'] ?? '');
        if (($cfg['database']['type'] ?? '') !== 'sqlite' || $path === '') return null;
        $abs = $path[0] === '/' ? $path : $instanceDir . '/' . $path;
        if (!is_file($abs)) return null;
        try { $pdo = new \PDO('sqlite:' . $abs); $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT); return $pdo; }
        catch (\Throwable $e) { return null; }
    }

    /** A run's status + its step-runs, from the instance DB. */
    public static function run(string $instanceDir, int $runId): ?array {
        $db = self::db($instanceDir);
        if (!$db) return null;
        $st = $db->prepare('SELECT id, slug, status, source, steps_total, steps_done, error, output_json, await_prompt FROM piperun WHERE id = ?');
        $st->execute([$runId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r) return null;
        $sst = $db->prepare('SELECT step_name, step_type, status, exit_code, duration_ms, stderr, output_json FROM pipesteprun WHERE run_id = ? ORDER BY id');
        $sst->execute([$runId]);
        $steps = [];
        foreach ($sst->fetchAll(\PDO::FETCH_ASSOC) as $s) {
            $steps[] = ['step' => $s['step_name'], 'type' => $s['step_type'], 'status' => $s['status'],
                'exit' => (int) $s['exit_code'], 'duration_ms' => (int) $s['duration_ms'],
                'stderr' => (string) $s['stderr'], 'output' => json_decode((string) $s['output_json'], true)];
        }
        return ['run_id' => (int) $r['id'], 'slug' => $r['slug'], 'status' => $r['status'],
            'steps_total' => (int) $r['steps_total'], 'steps_done' => (int) $r['steps_done'],
            'error' => (string) $r['error'], 'await_prompt' => (string) $r['await_prompt'],
            'output' => json_decode((string) $r['output_json'], true), 'steps' => $steps];
    }
}
