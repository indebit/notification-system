# Notification System API

Internal HTTP API that accepts notification requests, persists them, delivers them through a configurable webhook provider asynchronously, and exposes status, metrics, and health checks.

## Overview

This service sits behind other internal backends: callers POST notifications (SMS, email, or push) and poll or subscribe for outcomes. It does not choose campaigns or audiences; it validates input, stores state, pushes work to Redis queues, and records delivery attempts.

MySQL holds notifications, templates, and per-attempt logs. Redis backs queues, cache, sessions, Horizon metadata, and rate limits. Laravel Horizon runs workers in Docker; Laravel Reverb is available for real-time status broadcasts.

## How it works

A client hits the JSON API under `/api`. Form requests validate the payload, then controllers hand off to services. `NotificationService` writes the row (and resolves idempotency or templates), then dispatches `SendNotificationJob` onto a priority queue unless `scheduled_at` defers sending. Horizon workers pick up jobs, apply Redis-backed rate limits and exception throttling middleware, then `DeliveryService` POSTs to the configured provider URL. A `202` response with a `messageId` marks the notification delivered and appends an accepted row to `notification_logs`; anything else or a transport error marks failure, logs it, and may trigger queue retries. `NotificationStatusChanged` is broadcast when status changes so WebSocket clients can update.

## Tech stack

| Package / tool | Role |
| --- | --- |
| Laravel 13, PHP 8.4 | Web framework and runtime (see `Dockerfile`; `composer.json` allows PHP 8.3+ locally) |
| MySQL | Primary data store |
| Redis | Queues, cache, Horizon, rate limiting |
| Laravel Horizon | Queue workers, metrics, `/horizon` dashboard |
| Laravel Reverb | WebSocket server for broadcasting |
| Scribe (`knuckleswtf/scribe`) | Generates OpenAPI + Postman artifacts consumed by Swagger UI at `/docs` (`public/scribe`) |
| Pest | Feature and smoke tests |
| PHPStan + Larastan | Static analysis at level 6 (`phpstan.neon`) |
| Laravel Pint | Code style |
| Docker Compose | App, Nginx, MySQL, Redis, Horizon, Reverb |

## Getting started

1. Clone the repository and copy the environment file: `cp .env.example .env` (or rely on Compose `environment` blocks and override as needed).
2. Set `NOTIFICATION_PROVIDER_URL` in `.env` (or replace the placeholder in `docker-compose.yml` for the `app` and `horizon` services) to a unique URL from [webhook.site](https://webhook.site). Configure that inspector to respond with **HTTP 202** and JSON shaped like:

```json
{
  "messageId": "550e8400-e29b-41d4-a716-446655440000",
  "status": "accepted",
  "timestamp": "2026-05-01T12:00:00Z"
}
```

3. From the project root: `docker compose up -d` (or `docker-compose up -d`).
4. HTTP entrypoint: `http://localhost:8000` (Nginx → PHP-FPM). Horizon UI: `http://localhost:8000/horizon`. API docs: `http://localhost:8000/docs`. Reverb listens on `http://localhost:8080` for WebSocket clients (see `REVERB_*` and `VITE_REVERB_*` in `.env.example`).
5. Run the test suite inside the app container: `docker compose exec app php artisan test`

## API endpoints

Full request and response examples live in the interactive docs at `/docs` and in `docs/Notification System API.postman_collection.json`.

### Notifications

| Method | Path | Description |
| --- | --- | --- |
| POST | `/api/notifications` | Create a notification (optional `idempotency_key`, `scheduled_at`, `template_name` / `template_variables`) |
| POST | `/api/notifications/batch` | Create up to 1000 notifications sharing a new `batch_id` |
| GET | `/api/notifications` | Paginated list with filters (`status`, `channel`, `batch_id`, date range, `per_page`) |
| GET | `/api/notifications/{notification}` | Single notification including `logs` |
| GET | `/api/notifications/batch/{batchId}` | All notifications in a batch |
| PATCH | `/api/notifications/{notification}/cancel` | Cancel while still `pending` |
| POST | `/api/test/broadcast` | Debug-only sample broadcast for Reverb verification |

### Observability

| Method | Path | Description |
| --- | --- | --- |
| GET | `/api/health` | Database, Redis, and Horizon supervisor checks |
| GET | `/api/metrics` | Pending counts by priority, status histogram, success rates from logs, latency (average + p95), throughput |

### Templates

| Method | Path | Description |
| --- | --- | --- |
| POST | `/api/templates` | Create a named template per channel with `{{placeholder}}` bodies |
| GET | `/api/templates` | Paginated template list |
| GET | `/api/templates/{template}` | Fetch one template |

## Architecture decisions

The codebase stays in default Laravel folders with controllers, form requests, jobs, and Eloquent models. The problem is a single bounded context (notify, deliver, observe), so a heavier DDD layout would add friction without buying much separation.

Controllers stay thin by delegating to `NotificationService`, `DeliveryService`, `TemplateService`, and `MetricsService`. That keeps HTTP concerns in one layer and makes orchestration and edge cases testable without bootstrapping the whole HTTP stack for every case.

Channel, priority, status, and log outcomes are PHP backed enums persisted as strings. That gives exhaustiveness checks in PHP without MySQL enum migrations every time a value changes.

Notification primary keys are UUIDs (`HasUuids`) returned directly to clients. That avoids sequential IDs leaking volume information and removes an extra lookup when correlating API traffic to rows.

Each delivery attempt is a row in `notification_logs` instead of a JSON blob on the notification. Logs stay queryable for metrics, p95 latency, and auditing, and the main row stays smaller.

Per-channel throughput is enforced with `RateLimitedWithRedis` on the job, backed by named limiters in `AppServiceProvider`. The cap applies at dequeue time, so the HTTP layer and domain services stay unaware of Redis token buckets.

`ThrottlesExceptionsWithRedis` backs off when the provider or network throws repeatedly, giving a circuit-breaker style guard alongside Laravel’s `#[Backoff(5, 30, 120, 600)]` and `#[MaxExceptions(3)]`.

`CorrelationIdMiddleware` accepts or generates `X-Correlation-ID`, pushes it into `Log::shareContext()`, and echoes it on the response. Jobs then align log context around the notification id. Nothing writes correlation IDs into MySQL; they are for log pipelines only.

Horizon replaces ad hoc `queue:work` processes in Docker: supervisors, failed job inspection, and metrics are built in, and the dashboard matches what operators expect from Laravel.

Reverb is the first-party WebSocket server; `NotificationStatusChanged` already implemented `ShouldBroadcast`, so wiring Reverb meant configuration and Compose rather than adopting a third-party broker.

## Delivery and retry logic

When the provider returns **202** and JSON containing `messageId`, the notification moves to `delivered`, stores the external id, bumps `attempt_count`, writes an `accepted` `notification_logs` row, and fires `NotificationStatusChanged`.

Non-202 responses or thrown client errors mark the notification `failed`, capture the error text and body on the log row, emit the same event, and throw so the queue can react. Jobs are configured for up to **five** attempts with delays of **5s, 30s, 2 minutes, and 10 minutes** between tries, plus a 30s handler timeout. `ThrottlesExceptionsWithRedis(5, 60)` reduces churn when failures spike.

If a user cancels while a job is still pending, the row becomes `cancelled`. The job reloads the model and exits without calling the provider unless the status is still `pending` or mid-flight `processing`, so cancelled work does not hit the webhook.

Optional `idempotency_key` values are unique in the database. A repeat POST with the same key returns the original notification without enqueueing duplicate work.

Every attempt—accepted or failed—is recorded in `notification_logs` with latency, payload snippets, and errors so `/api/metrics` and manual SQL both have a complete trail.

## Observability

Logs use Monolog’s `JsonFormatter` on the default channel, so entries are structured lines suitable for centralized logging. Correlation IDs from the middleware (and notification ids inside jobs) populate shared context instead of ad hoc string concatenation.

`GET /api/health` probes PDO, `Redis::ping()`, and Horizon’s supervisor repository, returning **503** if any dependency is unhealthy.

`GET /api/metrics` aggregates pending notifications by priority queue, status counts, hourly and daily success rates from `notification_logs`, rolling average and p95 latency, average latency per channel, and throughput over a short sliding window.

## Bonus features

- Scheduled send: `scheduled_at` on notifications plus `notifications:process-scheduled` Artisan command on the every-minute scheduler.
- Template CRUD with `{{variable}}` substitution when creating notifications.
- GitHub Actions workflow with parallel **tests** (MySQL + Redis services) and **code-quality** (Pint dry-run + PHPStan) jobs.
- Reverb broadcasting of `NotificationStatusChanged` on `notifications.{batch_id}` or `notifications.{notification_id}` channels.
- Swagger UI at `/docs`, backed by Scribe-generated OpenAPI and Postman artifacts under `public/scribe`.

## Testing

There are **28** Pest tests (including two placeholder examples). Feature coverage includes notification CRUD validation, idempotency, batch limits, filters, cancellation rules, template APIs, and the scheduled-notification command dispatch behavior. Queue and HTTP clients are faked where appropriate so tests stay fast and deterministic.

Run them locally with `php artisan test` or inside Docker via `docker compose exec app php artisan test`.

## Environment variables

| Variable | Purpose |
| --- | --- |
| `DB_*` | MySQL connection (Compose defaults match `docker-compose.yml`) |
| `REDIS_*` | Cache, queue, Horizon, rate limiters |
| `QUEUE_CONNECTION` | Must be `redis` for Horizon-backed processing |
| `NOTIFICATION_PROVIDER_URL` | Webhook endpoint for outbound delivery |
| `NOTIFICATION_PROVIDER_TIMEOUT` / `NOTIFICATION_PROVIDER_CONNECT_TIMEOUT` | HTTP client limits (`config/services.php`) |
| `REVERB_APP_*`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | Server-side publishing to Reverb |
| `VITE_REVERB_*` | Browser-facing host/port/scheme for Echo clients |

## Project structure

```
app/
├── Console/Commands/ProcessScheduledNotifications.php
├── Enums/
├── Events/NotificationStatusChanged.php
├── Http/
│   ├── Controllers/NotificationController.php, ObservabilityController.php, TemplateController.php
│   ├── Middleware/CorrelationIdMiddleware.php
│   ├── Requests/
│   └── Resources/
├── Jobs/SendNotificationJob.php
├── Models/Notification.php, NotificationLog.php, NotificationTemplate.php
└── Services/DeliveryService.php, HealthService.php, MetricsService.php, NotificationService.php, TemplateService.php
routes/api.php
tests/Feature/NotificationTest.php, TemplateControllerTest.php, ProcessScheduledNotificationsCommandTest.php
```
