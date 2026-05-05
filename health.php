<?php
// Public health endpoint for local_fastpix. HMAC-free (read-only liveness
// probe), rate-limited at 30 req/min/IP. Wraps gateway::health_probe()
// and emits a small JSON body. Never 500s.

define('NO_DEBUG_DISPLAY', true);
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');

$result = \local_fastpix\health\runner::run(getremoteaddr() ?: 'unknown');

http_response_code($result['http_code']);
header('Content-Type: application/json');
echo json_encode($result['body']);
die();
