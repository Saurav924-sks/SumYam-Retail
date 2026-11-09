<?php
// Retain the historical test entry point while routing it through the guarded,
// isolated HTTP integration suite. The suite refuses any DB_NAME except
// smartstore_test before connecting or making HTTP requests.
require __DIR__ . '/integration.php';
