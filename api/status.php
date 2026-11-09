<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

setApiHeaders();

$test = testDBConnection();
if (!$test['connected']) {
    sendJsonResponse(false, ['status' => 'database_unreachable'], 'Database is unavailable.', 503);
}

sendJsonResponse(true, ['status' => 'healthy'], 'Database connection is healthy.');
