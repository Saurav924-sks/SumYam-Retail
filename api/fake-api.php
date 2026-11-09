<?php
require_once __DIR__ . '/../helpers/response.php';

setApiHeaders();
sendJsonResponse(
    false,
    null,
    'External and mock catalog imports are disabled in live mode. Use the separate static demo to explore fictional catalog data.',
    410
);
