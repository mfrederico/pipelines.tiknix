<?php
/**
 * Edit — the pipeline editor. SSO'd, owner-scoped. Lists/edits pipeline FILES in the
 * instances you own (reusing core's \app\Pipeline\Loader pointed at the instance dir)
 * and runs them via each instance's own trigger endpoint (PipeFiles), watching the
 * run in the instance's DB. Every request re-derives the accessible instance set from
 * core (Sidecar\Access) and authorizes the selected instance — a slug is a lookup
 * hint, never authorization.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Sidecar\Kernel;
use app\Sidecar\Access;
use app\Sidecar\Sso;
use app\Pipeline\Loader;
use app\Pipeline\StepRegistry;

class Edit extends Control {

    /** GET /edit — the editor page. */
    public function index($params = []) {
        $s = Sso::session();
        if (!$s) { $this->requireLaunch(); return; }
        $access = new Access(Kernel::coreDb());
        $this->render('edit/index', [
            'instances'  => $access->instances((int) $s['member_id']),
            'email'      => $s['email'],
            'components' => StepRegistry::components(),
        ], false);
    }

    /** GET /edit/pipelines?inst=<slug> — the instance's pipelines. */
    public function pipelines($params = []) {
        [$s, $inst] = $this->guard();
        if (!$inst) return;
        $out = [];
        foreach ((new Loader(PipeFiles::instanceDir($inst)))->all() as $slug => $def) {
            $out[] = ['slug' => $slug, 'name' => (string) ($def['name'] ?? $slug),
                'steps' => count($def['steps'] ?? []), 'expose_as_tool' => (bool) ($def['expose_as_tool'] ?? false),
                'expose_as_api' => (bool) ($def['expose_as_api'] ?? false), 'cron' => (string) ($def['trigger']['cron'] ?? ''),
                'stateful' => (bool) ($def['stateful'] ?? false)];
        }
        usort($out, fn($a, $b) => strcmp($a['slug'], $b['slug']));
        Flight::json(['pipelines' => $out]);
    }

    /** GET /edit/connectors?inst=<slug> — the instance's connected connectors (NO secrets). */
    public function connectors($params = []) {
        [$s, $inst] = $this->guard();
        if (!$inst) return;
        $out = [];
        $core = Kernel::coreDb();
        if ($core) {
            try {
                $st = $core->prepare("SELECT connector_type, environment, external_name FROM connections
                    WHERE instance_id = ? AND enabled = 1 AND (revoked_at IS NULL OR revoked_at = '')
                    ORDER BY connector_type, environment");
                $st->execute([(int) $inst['id']]);
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $type  = (string) $r['connector_type'];
                    $style = self::connectorStyle($type);
                    $out[] = ['connector' => $type, 'environment' => (string) $r['environment'],
                        'name' => (string) ($r['external_name'] ?: $type), 'style' => $style,
                        'tool' => $style === 'graphql' ? 'graphql' : 'request'];
                }
            } catch (\Throwable $e) { /* connections table may be absent on some cores */ }
        }
        Flight::json(['connectors' => $out]);
    }

    /** How a connector's pipeline request is shaped: GraphQL (Shopify) vs REST (default). */
    private static function connectorStyle(string $type): string {
        return $type === 'shopify' ? 'graphql' : 'rest';
    }

    /** GET /edit/get?inst=<slug>&slug=<pipe> — one pipeline definition (pretty JSON). */
    public function get($params = []) {
        [$s, $inst] = $this->guard();
        if (!$inst) return;
        $def = (new Loader(PipeFiles::instanceDir($inst)))->get((string) Flight::request()->query->slug);
        if (!$def) { Flight::jsonError('Pipeline not found.', 404); return; }
        Flight::json(['def' => $def]);
    }

    /** POST /edit/validate — dry-validate a definition. */
    public function validate($params = []) {
        [$s, $inst] = $this->guard();
        if (!$inst) return;
        $def = $this->bodyDef();
        if ($def === null) { Flight::jsonError('Invalid JSON.', 400); return; }
        Flight::json(['valid' => !($e = Loader::validate($def)), 'errors' => $e]);
    }

    /** POST /edit/save — validate + write the pipeline file (ownership-checked). */
    public function save($params = []) {
        [$s, $inst] = $this->guard();
        if (!$inst) return;
        $def = $this->bodyDef();
        if ($def === null) { Flight::jsonError('Invalid JSON.', 400); return; }
        $errs = Loader::validate($def);
        if ($errs) { Flight::json(['ok' => false, 'errors' => $errs]); return; }
        $file = (new Loader(PipeFiles::instanceDir($inst)))->save($def);
        Flight::json(['ok' => true, 'slug' => $def['slug'], 'file' => 'pipelines/' . $def['slug'] . '.json']);
    }

    /** POST /edit/delete — remove a pipeline file. */
    public function delete($params = []) {
        [$s, $inst] = $this->guard();
        if (!$inst) return;
        $slug = (string) $this->getParam('slug');
        Flight::json(['ok' => (new Loader(PipeFiles::instanceDir($inst)))->delete($slug)]);
    }

    /** POST /edit/run — fire a run on the instance; returns run_id to watch. */
    public function run($params = []) {
        [$s, $inst] = $this->guard();
        if (!$inst) return;
        $slug = (string) $this->getParam('slug');
        $context = json_decode((string) $this->getParam('context'), true) ?: [];
        $res = PipeFiles::triggerRun(PipeFiles::instanceDir($inst), $slug, is_array($context) ? $context : []);
        if (!empty($res['error'])) { Flight::jsonError($res['error'], 400); return; }
        Flight::json(['run_id' => $res['run_id']]);
    }

    /** GET /edit/runstatus?inst=<slug>&run_id=<id> — poll a run (instance DB). */
    public function runstatus($params = []) {
        [$s, $inst] = $this->guard();
        if (!$inst) return;
        $r = PipeFiles::run(PipeFiles::instanceDir($inst), (int) Flight::request()->query->run_id);
        if (!$r) { Flight::jsonError('Run not found yet.', 404); return; }
        Flight::json($r);
    }

    /** POST /edit/deliver — deliver a message (or alarm) to a durable object; returns its result. */
    public function deliver($params = []) {
        [$s, $inst] = $this->guard();
        if (!$inst) return;
        $slug    = (string) $this->getParam('slug');
        $key     = (string) $this->getParam('key');
        $trigger = ((string) $this->getParam('trigger')) === 'alarm' ? 'alarm' : 'message';
        $message = json_decode((string) $this->getParam('message'), true);
        if ($key === '') { Flight::jsonError('An object key is required.', 400); return; }
        $res = PipeFiles::deliver(PipeFiles::instanceDir($inst), $slug, $key, is_array($message) ? $message : [], $trigger);
        if (!empty($res['error'])) { Flight::jsonError($res['error'], 400); return; }
        Flight::json($res + ['key' => $key, 'trigger' => $trigger]);
    }

    /** POST /edit/debug — start a step-trace debug run; returns the first breakpoint. */
    public function debug($params = []) {
        [$s, $inst] = $this->guard();
        if (!$inst) return;
        $slug = (string) $this->getParam('slug');
        $context = json_decode((string) $this->getParam('context'), true) ?: [];
        $res = PipeFiles::debugStart(PipeFiles::instanceDir($inst), $slug, is_array($context) ? $context : []);
        if (!empty($res['error'])) { Flight::jsonError($res['error'], 400); return; }
        Flight::json($res);
    }

    /** POST /edit/debugstep — advance/finish/abort a debug run (patch = injected data). */
    public function debugstep($params = []) {
        [$s, $inst] = $this->guard();
        if (!$inst) return;
        $runId  = (int) $this->getParam('run_id');
        $action = (string) ($this->getParam('action') ?: 'step');
        $patch  = json_decode((string) $this->getParam('patch'), true);
        $res = PipeFiles::debugStep(PipeFiles::instanceDir($inst), $runId, $action, is_array($patch) ? $patch : []);
        if (!empty($res['error'])) { Flight::jsonError($res['error'], 400); return; }
        Flight::json($res);
    }

    // ---- guards ------------------------------------------------------------

    /** Require session + an authorized instance ( ?inst, else first accessible ). */
    private function guard(): array {
        $s = Sso::session();
        if (!$s) { Flight::jsonError('Not signed in.', 401); return [null, null]; }
        $core = Kernel::coreDb();
        if (!$core) { Flight::jsonError('Core unavailable.', 503); return [$s, null]; }
        $access = new Access($core);
        $slug = (string) (Flight::request()->query->inst ?? $this->getParam('inst') ?? '');
        $inst = $slug !== '' ? $access->resolveInstance($slug, (int) $s['member_id']) : ($access->instances((int) $s['member_id'])[0] ?? null);
        if (!$inst) { Flight::jsonError('That instance was not found or you do not have access to it.', 403); return [$s, null]; }
        return [$s, $inst];
    }

    private function bodyDef(): ?array {
        $raw = (string) $this->getParam('def');
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }

    private function requireLaunch(): void {
        $coreUrl = rtrim((string) (Flight::get('sidecar.core_url') ?? ''), '/');
        if ($coreUrl !== '') { Flight::redirect($coreUrl . '/sidecar/launch/pipelines'); return; }
        Flight::halt(403, 'Launch the Pipeline Editor from your tiknix dashboard.');
    }
}
