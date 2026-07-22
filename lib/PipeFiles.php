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

    /**
     * Fire a run on the instance via its trigger endpoint (bearer = the instance's
     * trigger_secret). Returns ['run_id'=>int]|['error'=>string]. The run executes on
     * the instance and records history in the instance's DB.
     */
    public static function triggerRun(string $instanceDir, string $slug, array $context = []): array {
        $cfg = self::cfg($instanceDir);
        $base   = rtrim((string) ($cfg['app']['baseurl'] ?? ''), '/');
        $secret = (string) ($cfg['pipeline']['trigger_secret'] ?? '');
        if ($base === '' || $secret === '') return ['error' => 'This instance has no [pipeline] trigger_secret configured.'];

        $ch = curl_init($base . '/pipeline/trigger/' . rawurlencode($slug));
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($context) ?: '{}',
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $secret],
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $d = is_string($resp) ? json_decode($resp, true) : null;
        if ($code === 200 && !empty($d['run_id'])) return ['run_id' => (int) $d['run_id']];
        return ['error' => $d['message'] ?? "trigger failed (HTTP $code)"];
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
