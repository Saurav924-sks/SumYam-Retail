<?php
require_once __DIR__ . '/../helpers/response.php';

setApiHeaders();
sendJsonResponse(
    false,
    null,
    'Public database setup is disabled. Apply the reviewed migration from the command line.',
    410
);
