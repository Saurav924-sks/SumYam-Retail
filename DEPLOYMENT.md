# SumYam Retail — Vercel Deployment Guide

This guide prepares the existing PHP application for Vercel's Container Runtime using FrankenPHP and Caddy. It does not deploy the project or apply database migrations. The Container Runtime is currently documented by Vercel as beta; review the platform limits and current setup instructions before production use: [Vercel Container Runtime](https://vercel.com/docs/functions/runtimes/container).

## Runtime design

- The root `Dockerfile` uses the Debian Bookworm FrankenPHP image with PHP 8.4, PDO MySQL, phpredis, and OPcache.
- FrankenPHP classic mode is used. Worker mode is not enabled, so the current request-per-lifecycle PHP behavior is retained.
- Caddy binds to `PORT`, defaulting to port 80. The application is behind Vercel TLS termination; Caddy does not issue or terminate public HTTPS certificates in this container.
- Caddy routes only the checked-in frontend assets and explicitly listed API scripts. All other paths return 404. Configuration, helpers, SQL source, tests, bootstrap scripts, and documentation are not HTTP routes.
- The runtime image copies only the frontend, required API controllers, `config/database.php`, and `helpers/response.php`. Test code, migration sources, `bin/`, documentation, and local files are left out.

The runtime is configured with `APP_ENV=production`. In production, PHP sessions require a TLS `REDIS_URL`; a missing Redis setting or unavailable Redis service fails the session request without falling back to local files. PHP's native phpredis session handler uses a SumYam-specific key prefix, bounded connection/read timeouts, TLS peer and hostname verification, and explicit session locking. The selected session handler supports TLS stream options and session locking when configured; see the [phpredis session handler documentation](https://github.com/phpredis/phpredis#php-session-handler).

## Public demonstration on Vercel Hobby

This deployment can run as a **public, non-commercial demonstration only** without MySQL or Redis. Deploy the root Dockerfile, then open the deployment URL with `?demo=1` (for example, `https://your-deployment.vercel.app/?demo=1`). The existing browser demo adapter handles startup and data requests using fictional records; it does not call PHP API endpoints or initialize PHP sessions. Demo changes are stored only in each visitor's browser when local storage is available, and visitors can change their own demo data.

No `DB_*`, `DB_SSL_CA_PEM`, or `REDIS_URL` values are needed for this mode. Do not add production credentials or real business/customer data. The existing demo UI is intentionally unauthenticated, but exposes only fictional, browser-local demo data. This does not change PHP API authentication: direct live API requests still require their existing backend services and authentication, and fail closed when those services are not configured. Do not use this demo setup for commercial or real store operations.

## Vercel project setup

1. Use the project root as the Vercel project root so Vercel finds `Dockerfile` and `.dockerignore`.
2. Select the Container framework preset if Vercel detects another framework. The container runtime routes requests to the server listening on `PORT`; it defaults to port 80. See the [Container Runtime Dockerfile and port documentation](https://vercel.com/docs/functions/runtimes/container#dockerfile).
3. For the public demo, leave database and Redis variables unset. For database-backed operation, configure the applicable environment variables below in Vercel's encrypted environment settings. Do not put secrets in source files, build arguments, deployment logs, or `vercel.json`.
4. For database-backed operation, expose Vercel System Environment Variables so the runtime receives `VERCEL`, `VERCEL_ENV`, and `VERCEL_URL`. The browser-only demo does not need these values for its data flow.
5. For database-backed operation, set `APP_BASE_URL` to the production HTTPS origin. Preview deployments use the system-provided `VERCEL_URL`, validated as a Vercel deployment hostname; client-supplied `Host` and forwarded-host headers are not used to derive the canonical origin.
6. Only for database-backed operation, ensure the external MySQL provider accepts connections from the deployment environment. Vercel Container Runtime currently documents networking limitations for features such as Secure Compute and Static IP; confirm that the selected database network and plan can reach each other.
7. Build and verify a preview before promoting a deployment to production. This repository has not been deployed as part of this change.

## Environment variables

| Name | Required use | Notes |
| --- | --- | --- |
| `APP_ENV` | Set to `production` for container operation | Enables fail-closed Redis, origin, database, TLS, and logging behavior. The Docker image sets this default. |
| `APP_BASE_URL` | Production | Canonical HTTPS origin, for example `https://retail.example.com`. Must contain only the origin and optional `/` path; no credentials, query, or fragment. Production same-origin mutation checks compare against it rather than trusting forwarded headers or `Host`. |
| `DB_HOST` | Production | External MySQL hostname covered by the provider certificate. |
| `DB_PORT` | Production | MySQL TLS port. |
| `DB_NAME` | Production | Existing application database name; retain the configured production database identifier. |
| `DB_USER` | Production | Restricted runtime database account. |
| `DB_PASS` | Production | Runtime database password. Store as a protected secret. |
| `DB_SSL_CA_PEM` | Production | PEM-encoded CA certificate or CA bundle provided by the database provider. The app writes it to a private temporary file for the PDO connection, enables server-certificate verification, then removes the file. Do not use a private key here. |
| `REDIS_URL` | Production | TLS Redis URL in `rediss://user:password@host:port` form. Store as a protected secret. The app rejects non-TLS schemes for production sessions. |
| `PORT` | Supplied by Vercel | Caddy uses this value and defaults to `80` when unset. Do not hardcode a Vercel port. |
| `VERCEL` | Supplied by Vercel | `1` marks the trusted Vercel runtime and enables the Vercel client-IP header path. |
| `SMARTSTORE_TIMEZONE` | Optional | PHP timezone; defaults to `UTC`. |
| `VERCEL_ENV` | Supplied by Vercel | Automatically marks Vercel production as production even if `APP_ENV` was overridden. |
| `VERCEL_URL` | Supplied by Vercel | Used only for a Preview deployment's canonical origin, and only when `VERCEL=1` and `VERCEL_ENV=preview`. Vercel provides the generated hostname without a scheme; the app accepts only `*.vercel.app` hostnames and applies HTTPS. |

`SMARTSTORE_LOG_DIR` is retained for local development only. In production, exceptions are written to PHP stderr as sanitized event class/context; detailed exception text, credentials, and stack traces are not written by the application logger. Caddy access logs go to stdout. PHP's production ini disables display of errors and directs PHP error logging to stderr.

## MySQL and TLS

Create the application database and restricted runtime user using the existing migration and database setup instructions in [README.md](README.md). The Docker image does not contain or apply migrations. Use a separate migration identity for schema work; the running application only needs the DML privileges required by its APIs.

For production, set all of `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, and `DB_SSL_CA_PEM`. The application refuses a PDO connection when any required value is absent or the CA PEM is malformed. PDO receives the CA file and explicitly enables server-certificate verification. Certificate verification is never disabled as a connection workaround. PDO uses a 5-second connection timeout and, with mysqlnd, a 10-second network read timeout. The production runtime requires that read-timeout setting to be available. Local development may continue using the existing non-TLS local MySQL defaults when `APP_ENV` is not `production` and `DB_SSL_CA_PEM` is unset.

Do not put provider CA contents in a source file. The CA certificate is configuration material, not a database credential; still keep it in the provider's protected environment configuration and do not print it in diagnostics.

## Redis sessions

Use the TLS connection URL supplied by the Redis provider as `REDIS_URL`. For Upstash, use its TLS-enabled `rediss://` URL and its configured ACL username/password. The application converts it into a phpredis native session path and requires:

- phpredis loaded in the image;
- certificate and hostname verification enabled;
- connection and read timeouts;
- the prefix `sumyam_retail:session:`;
- `redis.session.locking_enabled=1` with bounded lock retries;
- no local filesystem fallback in production.

If Redis is missing, malformed, unreachable, or unable to start a session, authentication endpoints return a generic service error. No Redis password is included in application logs or API responses. Local non-production PHP continues to use filesystem sessions.

## HTTPS, origins, and login throttling

Production and Preview session cookies use `Secure`, `HttpOnly`, and the existing `SameSite=Strict` policy. Vercel terminates public TLS; the application marks cookies secure from its deployment runtime configuration rather than trusting a client-provided `X-Forwarded-Proto` header. Production mutating requests must send an origin matching `APP_BASE_URL`; Preview requests must match the HTTPS origin derived from the validated `VERCEL_URL`. Both continue to pass the existing CSRF token check.

On Vercel (`VERCEL=1`), login throttling uses `X-Forwarded-For` only when it contains exactly one valid IP address; missing, malformed, or comma-separated values reject the login attempt before database work. Vercel documents that it overwrites `X-Forwarded-For` with the public client IP to prevent spoofing in the direct Vercel ingress path. If another proxy is placed in front of Vercel, verify its effect on the header before relying on this behavior. Outside Vercel, login throttling continues to use the validated `REMOTE_ADDR`. The existing throttle window and attempt-count behavior are unchanged. See [Vercel Request Headers](https://vercel.com/docs/headers/request-headers#x-forwarded-for).

## First administrator

`bin/create-user.php` is intentionally excluded from the web image. Run it once from a trusted maintenance workstation or controlled one-off job with PHP 8.2+ and `pdo_mysql`, network access to the production database, the same verified database CA configuration, and the required environment variables. Do not run it from an HTTP request or expose it through Caddy. The CLI script inserts one user; it is not an upsert.

Supply `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, and `DB_SSL_CA_PEM` securely, then set the bootstrap values and invoke the existing CLI command:

```sh
export APP_ENV=production
export SMARTSTORE_BOOTSTRAP_EMAIL='admin@your-domain.example'
export SMARTSTORE_BOOTSTRAP_NAME='Store Administrator'
export SMARTSTORE_BOOTSTRAP_ROLE=admin
read -rs "SMARTSTORE_BOOTSTRAP_PASSWORD?Initial administrator password (12+ characters): "; print
export SMARTSTORE_BOOTSTRAP_PASSWORD
php bin/create-user.php
unset SMARTSTORE_BOOTSTRAP_EMAIL SMARTSTORE_BOOTSTRAP_NAME SMARTSTORE_BOOTSTRAP_ROLE SMARTSTORE_BOOTSTRAP_PASSWORD
```

Have the database and PEM values injected by a protected secret manager or read them into the process from private files; do not paste credentials into shell history. Use a unique email and run the command only once. This guide does not create an administrator or access a production database.

## Local Docker build and smoke checks

Requirements: Docker Engine/Desktop with a running daemon and `curl`. These local checks require no database or Redis credentials and do not create business data.

```sh
docker build -t sumyam-retail:local .
docker run --detach --rm --name sumyam-retail-smoke \
  --publish 127.0.0.1:8080:80 \
  --env APP_ENV=development \
  sumyam-retail:local
```

Check the frontend assets:

```sh
set -eu
base=http://127.0.0.1:8080
for path in / /style.css /index.js /static-demo.js /favicon.svg; do
  code=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "$base$path")
  test "$code" = 200 || { echo "$path returned $code"; exit 1; }
done
```

Check private paths (all must be 404):

```sh
set -eu
base=http://127.0.0.1:8080
for path in /config/database.php /helpers/response.php /database/migrations/001_initial_schema.sql \
  /tests/integration.php /bin/create-user.php /.git/config /.env /README.md /ABOUT.md \
  /api/setup.php /index.php; do
  code=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "$base$path")
  test "$code" = 404 || { echo "$path returned $code instead of 404"; exit 1; }
done
```

Verify production does not fall back to file sessions when `REDIS_URL` is absent. This makes no database call and writes no business data:

```sh
docker run --detach --rm --name sumyam-retail-no-redis \
  --publish 127.0.0.1:8081:80 \
  --env APP_ENV=production \
  --env APP_BASE_URL=https://retail.example.invalid \
  sumyam-retail:local
curl --include 'http://127.0.0.1:8081/api/auth.php?action=session'
```

The expected result is a sanitized HTTP 500 JSON response stating that authentication is unavailable; the session must not be created on disk. Stop the non-production smoke container with `docker stop sumyam-retail-smoke` when done. The `--rm` containers are removed automatically on stop.

The checked-in database integration suite is separate and writes fixtures only to `smartstore_test`. Do not run it against `smartstore_db`; production deploy checks must not run the integration suite.

## Security review checklist

- Confirm the Vercel project uses the Container Runtime and serves traffic on the injected `PORT`.
- Confirm every production/preview environment has only the required secret variables and an environment-specific `APP_BASE_URL`.
- Confirm the MySQL TLS hostname matches the configured `DB_HOST` and the provider CA chain is correct.
- Confirm Redis URL uses TLS and that login/logout and simultaneous same-session requests succeed with session locking.
- Confirm cookies include `Secure`, `HttpOnly`, and `SameSite=Strict` in production.
- Confirm cross-origin mutations fail and same-origin mutations still require CSRF.
- Confirm the private-path 404 checks above pass on a preview deployment.
- Review Vercel logs for sanitized errors and verify actual client IP behavior before relying on per-IP login throttling.
- Verify the app's login, role permissions, checkout, purchase receipt, stock audit, and reports against a controlled non-production database before promotion.

## Redeploy and rollback

Deploy a preview first with the Vercel CLI after project linking and environment setup:

```sh
vercel deploy
```

Promote only after the preview smoke and security checks pass. Production deploys require an explicit operator action; this guide does not run deployment commands. If a production deployment regresses, use the Vercel dashboard's Instant Rollback or the linked project's CLI command `vercel rollback`, then verify rollback completion with `vercel rollback status`. Vercel documents that rollback changes production routing to a previously deployed image and that later promotion may be needed to restore normal production auto-assignment: [Rollback documentation](https://vercel.com/docs/cli/rollback).

Rollback changes application code routing; it does not undo database writes or schema changes. This deployment configuration does not run migrations automatically. Keep database backups and schema rollout plans separate from image redeployments.

## Verification status

The image and runtime checks must be run in the local environment with Docker available. No Vercel deployment, production database connection, external Redis connection, production-user bootstrap, or real forwarded-IP check is performed by this project change. A successful local image build does not establish production database, Redis, TLS, or Vercel network connectivity.
