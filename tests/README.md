# Safe integration tests

Run the database-free deployment hardening checks with:

```sh
php tests/test_deployment_hardening.php
```

These verify production/preview origin selection and same-origin enforcement,
login throttling IP resolution, and MySQL timeout options without connecting to
MySQL or Redis.

`tests/test_api.php` and `tests/integration.php` run the same HTTP integration
suite. The runner refuses to start unless `DB_NAME` is exactly
`smartstore_test`, verifies `SELECT DATABASE()` reports that exact name, and
then starts a private PHP server that inherits the test database configuration.
It never targets the regular `smartstore_db` server.

## Prerequisites

1. An administrator creates the separate test database:

   ```sql
   CREATE DATABASE `smartstore_test`
     CHARACTER SET utf8mb4
     COLLATE utf8mb4_unicode_ci;
   ```

2. Apply the schema to that database from the repository root. The MySQL client
   prompts for the migration account's password:

   ```sh
   mysql --host=127.0.0.1 --port=3306 --user=<migration-account> --password --database=smartstore_test < database/migrations/001_initial_schema.sql
   ```

3. Provision a dedicated runtime/test account with DML privileges on the test
   database only. Inject its password through a secret manager or protected
   environment; do not put a real password in this file or shell history:

   ```sql
   CREATE USER 'smartstore_test_runner'@'127.0.0.1'
     IDENTIFIED BY '<inject a secret securely>';
   GRANT SELECT, INSERT, UPDATE, DELETE
     ON `smartstore_test`.* TO 'smartstore_test_runner'@'127.0.0.1';
   ```

   The test runner uses these credentials for fixtures and the HTTP API. Do not
   point the account at `smartstore_db` or grant it access to that database.

Supply `DB_HOST`, `DB_PORT`, `DB_USER`, and `DB_PASS` from a secure local
environment or secret manager, then run:

```sh
DB_NAME=smartstore_test php tests/test_api.php
```

The runner refuses to proceed if any migration table is missing; it does not
apply migrations automatically. It creates run-unique fixture users, products,
one customer, and one supplier in `smartstore_test`, exercises production API
authentication, CSRF, and role checks over HTTP, and removes only rows linked to
those generated fixture IDs. It restores the test database's prior
`login_attempts` row for loopback IP and uses a private temporary PHP session
directory. It does not create fixtures in the live database.

The strict-mode rollback cases are skipped if MySQL's global SQL mode is not
strict, because a permissive mode could clamp out-of-range numeric writes.
