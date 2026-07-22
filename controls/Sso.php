<?php
/** Sso — identity boundary (shared Sidecar Kit base). Verifies the handoff token,
 *  re-checks the `pipelines` feature grant vs core, establishes the session. */

namespace app;

class Sso extends \app\Sidecar\Sso {}
