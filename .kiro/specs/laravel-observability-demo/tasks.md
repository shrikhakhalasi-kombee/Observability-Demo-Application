# Implementation Plan: Laravel Observability Demo

## Overview

Nine sequential phases covering Laravel scaffolding through demo preparation. Each task lists its objective, files to create, implementation details, and expected output.

---

## Phase 1: Laravel Application Scaffolding

### Task 1.1 — Initialise Laravel 12 project

**Objective:** Bootstrap a clean Laravel 12 application with the required PHP packages and base configuration.

**Files to create:**

- `composer.json` (add `promphp/prometheus_client_php`, `open-telemetry/sdk`, `open-telemetry/exporter-otlp`, `open-telemetry/transport-grpc`)
- `.env.example` (document all env vars including `ANOMALY_DELAY_ENABLED`, `ANOMALY_DELAY_MS`, `ANOMALY_SLOW_QUERY_ENABLED`, `OTEL_EXPORTER_OTLP_ENDPOINT`, `OTEL_TRACES_SAMPLER_ARG`)
- `config/logging.php` (configure single JSON channel writing to `storage/logs/laravel.json.log`)
- `config/observability.php` (centralise OTel and metrics config values)
- `routes/api.php` (stub route groups for v1 auth, products, orders, metrics)

**Implementation details:**

- Run `composer create-project laravel/laravel:^12.0 .`
- Install Laravel Sanctum: `composer require laravel/sanctum`
- Add observability packages to `require` block in `composer.json`
- Set `APP_NAME=laravel-observability-demo` and `LOG_CHANNEL=json` in `.env.example`
- Configure `config/logging.php` to use a custom `json` channel with `JsonLogFormatter`
- Register `ObservabilityServiceProvider` in `bootstrap/providers.php`

**Expected output:** `composer install` succeeds; `php artisan route:list` shows stub routes; application boots without errors.

---

### Task 1.2 — Project folder structure

**Objective:** Create all application directories and empty placeholder files so the structure matches the design document.

**Files to create:**

- `app/Http/Controllers/AuthController.php` (stub)
- `app/Http/Controllers/ProductController.php` (stub)
- `app/Http/Controllers/OrderController.php` (stub)
- `app/Http/Controllers/MetricsController.php` (stub)
- `app/Http/Middleware/TraceMiddleware.php` (stub)
- `app/Http/Middleware/MetricsMiddleware.php` (stub)
- `app/Http/Middleware/RequestLogMiddleware.php` (stub)
- `app/Http/Middleware/AnomalyDelayMiddleware.php` (stub)
- `app/Services/ProductService.php` (stub)
- `app/Services/OrderService.php` (stub)
- `app/Providers/ObservabilityServiceProvider.php` (stub)
- `app/Logging/JsonLogFormatter.php` (stub)

**Implementation details:**

- Each stub returns a placeholder response or empty method body
- Register all middleware aliases in `bootstrap/app.php` using `withMiddleware()`
- Register `ObservabilityServiceProvider` in `bootstrap/providers.php`

**Expected output:** `php artisan about` lists all providers; no class-not-found errors on boot.

---

## Phase 2: Database Models and Migrations

### Task 2.1 — Migrations

**Objective:** Create all four database tables with correct columns, indexes, foreign keys, and soft-delete support.

**Files to create:**

- `database/migrations/xxxx_create_users_table.php`
- `database/migrations/xxxx_create_products_table.php`
- `database/migrations/xxxx_create_orders_table.php`
- `database/migrations/xxxx_create_order_items_table.php`

**Implementation details:**

- `users`: `id`, `name` VARCHAR(255), `email` VARCHAR(255) UNIQUE, `password`, timestamps
- `products`: `id`, `name` VARCHAR(255), `description` TEXT nullable, `price` DECIMAL(10,2), `stock` INT UNSIGNED default 0, `deleted_at` (soft delete), timestamps; add indexes on `name` and `price`
- `orders`: `id`, `user_id` FK→users, `status` ENUM('pending','completed','cancelled') default 'pending', `total_price` DECIMAL(10,2), timestamps; add indexes on `user_id`, `status`, `created_at`
- `order_items`: `id`, `order_id` FK→orders, `product_id` FK→products, `quantity` INT UNSIGNED, `unit_price` DECIMAL(10,2), timestamps
- _Requirements: 3.1, 3.6, 4.1_

**Expected output:** `php artisan migrate` runs cleanly; all four tables exist with correct schema in MySQL.

---

### Task 2.2 — Eloquent models

**Objective:** Implement all four Eloquent models with relationships, casts, and traits.

**Files to create/update:**

- `app/Models/User.php`
- `app/Models/Product.php`
- `app/Models/Order.php`
- `app/Models/OrderItem.php`

**Implementation details:**

- `User`: add `HasApiTokens` (Sanctum), `HasFactory`; define `hasMany(Order::class)`; fillable: `name`, `email`, `password`; cast `password` to `hashed`
- `Product`: add `SoftDeletes`, `HasFactory`; define `hasMany(OrderItem::class)`; fillable: `name`, `description`, `price`, `stock`; cast `price` to `decimal:2`
- `Order`: define `belongsTo(User::class)`, `hasMany(OrderItem::class)`; add computed `getTotalPriceAttribute()` summing items if not stored; fillable: `user_id`, `status`, `total_price`
- `OrderItem`: define `belongsTo(Order::class)`, `belongsTo(Product::class)`; fillable: `order_id`, `product_id`, `quantity`, `unit_price`
- _Requirements: 1.1, 2.1, 3.1, 3.6, 4.1_

**Expected output:** Tinker confirms relationships resolve; `Product::withTrashed()->get()` works; Sanctum token creation succeeds on `User`.

---

## Phase 3: Controllers and Business Logic

### Task 3.1 — Auth endpoints

**Objective:** Implement user registration and login with Sanctum token issuance and full form validation.

**Files to create/update:**

- `app/Http/Controllers/AuthController.php`
- `app/Http/Requests/RegisterRequest.php`
- `app/Http/Requests/LoginRequest.php`
- `routes/api.php` (add `POST /api/v1/register`, `POST /api/v1/login`)

**Implementation details:**

- `RegisterRequest`: rules `name` required string, `email` required email unique:users, `password` required min:8
- `LoginRequest`: rules `email` required email, `password` required string
- `register()`: create user, call `$user->createToken('api')->plainTextToken`, return 201 `{ data: {id,name,email}, token }`
- `login()`: use `Auth::attempt()`; on failure return 401 `{ message: 'Invalid credentials' }`; on success return 200 `{ token }`
- _Requirements: 1.1–1.4, 2.1–2.3_

**Expected output:** `POST /api/v1/register` returns 201 with token; duplicate email returns 422 with `errors.email`; `POST /api/v1/login` returns 200 with token; wrong password returns 401.

---

### Task 3.2 — Product CRUD

**Objective:** Implement full product CRUD with search/price-range filtering, pagination, and soft-delete.

**Files to create/update:**

- `app/Services/ProductService.php`
- `app/Http/Controllers/ProductController.php`
- `app/Http/Requests/StoreProductRequest.php`
- `app/Http/Requests/UpdateProductRequest.php`
- `app/Http/Resources/ProductResource.php`
- `routes/api.php` (add authenticated resource routes under `/api/v1/products`)

**Implementation details:**

- `StoreProductRequest` / `UpdateProductRequest`: `name` required string, `description` nullable string, `price` required numeric min:0, `stock` required integer min:0
- `ProductService::list(array $filters, int $page, int $perPage)`: build query with optional `WHERE name LIKE` or `description LIKE` for `search`, `WHERE price >= min_price`, `WHERE price <= max_price`; paginate with cap at 100; return `{ data, meta }`
- `ProductService::create/show/update/delete`: standard Eloquent operations; `delete` calls `$product->delete()` (soft)
- Controller delegates entirely to service; returns `ProductResource` with correct HTTP codes (201 create, 200 show/update, 204 delete, 404 not found)
- _Requirements: 3.1–3.8, 5.1–5.5, 6.1–6.2_

**Expected output:** All five product endpoints return correct status codes and response shapes; `?search=widget` filters correctly; `?min_price=5&max_price=20` returns only in-range products; deleted product returns 404.

---

### Task 3.3 — Order creation and listing

**Objective:** Implement order creation with transactional stock management and order listing with filters.

**Files to create/update:**

- `app/Services/OrderService.php`
- `app/Http/Controllers/OrderController.php`
- `app/Http/Requests/StoreOrderRequest.php`
- `app/Http/Resources/OrderResource.php`
- `routes/api.php` (add `POST /api/v1/orders`, `GET /api/v1/orders`)

**Implementation details:**

- `StoreOrderRequest`: `items` required array min:1; `items.*.product_id` required exists:products,id; `items.*.quantity` required integer min:1
- `OrderService::create()`: inside `DB::transaction()`, lock each product with `lockForUpdate()`, validate `quantity <= stock`, decrement stock, create `Order` and `OrderItem` records, compute `total_price = sum(quantity * price)`, return 201; on any exception rollback and return 500
- `OrderService::list()`: filter by `status` and date range (`created_from`, `created_to`); paginate
- Controller returns `OrderResource` with items eager-loaded
- _Requirements: 4.1–4.7, 6.3–6.4_

**Expected output:** `POST /api/v1/orders` creates order and decrements stock atomically; over-stock returns 422; non-existent product returns 422; empty items returns 422; `GET /api/v1/orders?status=pending` filters correctly.

---

## Phase 4: Observability Instrumentation

### Task 4.1 — Prometheus metrics

**Objective:** Register all Prometheus metrics and wire them into the HTTP and database layers.

**Files to create/update:**

- `app/Providers/ObservabilityServiceProvider.php`
- `app/Http/Middleware/MetricsMiddleware.php`
- `app/Http/Controllers/MetricsController.php`
- `routes/api.php` (add `GET /metrics` unauthenticated)

**Implementation details:**

- `ObservabilityServiceProvider::boot()`: initialise `CollectorRegistry` with `ApcuStorage`; register all 10 metrics from the design (counters, histograms, gauges); set `app_info{version, environment}` gauge immediately
- `MetricsMiddleware`: on request entry increment `app_active_requests`; on response decrement gauge and observe `http_request_duration_seconds{method, route, status_code}`
- `DB::listen` callback: parse query type (select/insert/update/delete) from SQL; increment `app_db_queries_total{query_type}`; observe `app_db_query_duration_seconds{query_type}`
- `AuthController`: increment `app_user_registrations_total` on successful register; increment `app_user_logins_total` on successful login
- `ProductController`: increment `app_product_requests_total{operation, status}` for each action
- `OrderService::create()`: increment `app_orders_created_total`; observe `app_order_value_dollars` with total_price
- `MetricsController::show()`: render registry via `RenderTextFormat::renderSamples()`; return response with `Content-Type: text/plain; version=0.0.4`
- _Requirements: 7.1–7.6, 1.6, 2.5, 3.10, 4.6_

**Expected output:** `GET /metrics` returns valid Prometheus text format; after a registration request `app_user_registrations_total` increments by 1; `http_request_duration_seconds` histogram has observations with correct labels.

---

### Task 4.2 — Structured JSON logging

**Objective:** Configure Monolog to emit structured JSON logs with trace correlation fields on every entry.

**Files to create/update:**

- `app/Logging/JsonLogFormatter.php`
- `config/logging.php` (add `json` channel)
- `app/Http/Middleware/RequestLogMiddleware.php`
- `app/Exceptions/Handler.php` (extend to log structured ERROR entries)

**Implementation details:**

- `JsonLogFormatter` extends `Monolog\Formatter\JsonFormatter`; override `format()` to merge context fields: `timestamp` (ISO 8601), `level`, `message`, `service=laravel-observability-demo`, `environment`, `trace_id`, `span_id`
- `config/logging.php`: add channel `json` using `StreamHandler` pointing to `storage/logs/laravel.json.log` with `JsonLogFormatter`; set as default channel
- `RequestLogMiddleware`: after response, log INFO with `method`, `uri`, `status_code`, `duration_ms`, `user_id` (nullable)
- `Handler::report()`: for unhandled exceptions log ERROR with `exception_class`, `message`, `file`, `line`, `trace_id`
- `DB::listen` callback (extend from 4.1): if `$query->time > 500` log WARNING with `query`, `bindings`, `duration_ms`, `trace_id`
- _Requirements: 8.1–8.6_

**Expected output:** Every log line in `storage/logs/laravel.json.log` is valid JSON; INFO entries contain `method` and `uri`; ERROR entries contain `exception_class`; slow queries emit WARNING with `duration_ms`.

---

### Task 4.3 — OpenTelemetry distributed tracing

**Objective:** Instrument every HTTP request and database query with OTel spans exported to Tempo via OTLP/gRPC.

**Files to create/update:**

- `app/Providers/ObservabilityServiceProvider.php` (extend with OTel bootstrap)
- `app/Http/Middleware/TraceMiddleware.php`

**Implementation details:**

- `ObservabilityServiceProvider`: initialise `TracerProvider` with `BatchSpanProcessor` → `OtlpGrpcExporter` targeting `OTEL_EXPORTER_OTLP_ENDPOINT` (default `tempo:4317`); configure `TraceIdRatioBasedSampler` from `OTEL_TRACES_SAMPLER_ARG` (1.0 for local/staging, 0.1 for production); store tracer in app container as singleton
- `TraceMiddleware`: start root span with `http.method`, `http.route`, `http.url`; inject `trace_id` and `span_id` into `Log::shareContext()`; after response set `http.status_code` on span; on exception set span status ERROR and record exception event; end span
- `DB::listen` callback: create child span `db.query` with `db.statement`, `db.system=mysql`, `db.duration_ms`; end span after query
- Named service spans in controllers/services: `auth.register{user.id, http.status_code}`, `auth.login{http.status_code}`, `product.{create|list|show|update|delete}{product.id}`, `order.create{order.id, order.item_count, order.total_price}`
- _Requirements: 9.1–9.6, 1.5, 2.4, 3.9, 4.5_

**Expected output:** Tempo UI shows traces with root HTTP span and child `db.query` spans; `trace_id` in log entries matches the Tempo trace ID; span status is ERROR for 5xx responses.

---

## Phase 5: Docker Infrastructure

### Task 5.1 — Dockerfile

**Objective:** Build a production-ready multi-stage Docker image for the Laravel application.

**Files to create:**

- `Dockerfile`
- `docker/php/entrypoint.sh`

**Implementation details:**

- Stage 1 (`vendor`): `FROM composer:2`; copy `composer.json` + `composer.lock`; run `composer install --no-dev --no-scripts --prefer-dist`
- Stage 2 (`app`): `FROM php:8.3-fpm`; install system deps: `libzip-dev libprotobuf-dev protobuf-compiler`; install PHP extensions: `pdo_mysql zip opcache`; install PECL extensions: `grpc protobuf apcu`; enable all extensions; copy vendor from stage 1; copy application source; `chown -R www-data:www-data storage bootstrap/cache`; set `ENTRYPOINT ["docker/php/entrypoint.sh"]`
- `entrypoint.sh`: `php artisan migrate --force && php artisan config:cache && exec php-fpm`
- _Requirements: 12.1, 12.7_

**Expected output:** `docker build -t laravel-obs .` succeeds; container starts, runs migrations, and PHP-FPM listens on port 9000.

---

### Task 5.2 — Docker Compose stack

**Objective:** Define all eight services with health checks, volumes, and inter-service networking.

**Files to create:**

- `docker-compose.yml`
- `docker/nginx/default.conf`
- `docker/prometheus/prometheus.yml`
- `docker/loki/loki-config.yml`
- `docker/tempo/tempo-config.yml`
- `docker/promtail/promtail-config.yml`
- `docker/grafana/provisioning/datasources/datasources.yml`
- `docker/grafana/provisioning/dashboards/dashboards.yml`

**Implementation details:**

- `app`: build from `Dockerfile`; expose 9000 (FastCGI) and 9001 (metrics HTTP server via separate `php artisan serve --port=9001` or nginx stub); mount `./storage/logs` as volume shared with promtail; health check: `curl -f http://localhost:9001/metrics`
- `nginx`: `image: nginx:alpine`; port `8080:80`; mount `docker/nginx/default.conf`; depends on `app`
- `nginx/default.conf`: `fastcgi_pass app:9000`; proxy `/metrics` to `app:9001`
- `mysql`: `image: mysql:8`; named volume `mysql_data`; health check: `mysqladmin ping -h localhost`; env vars for DB name/user/password
- `prometheus`: `image: prom/prometheus`; mount `docker/prometheus/prometheus.yml`; `prometheus.yml` scrape job: target `app:9001`, interval `15s`
- `loki`: `image: grafana/loki:latest`; mount `docker/loki/loki-config.yml`; port 3100
- `tempo`: `image: grafana/tempo:latest`; mount `docker/tempo/tempo-config.yml`; expose OTLP gRPC 4317 and HTTP 3200; `tempo-config.yml` enables OTLP receiver
- `promtail`: `image: grafana/promtail:latest`; mount `docker/promtail/promtail-config.yml` and `./storage/logs`; config scrapes `*.log` files, adds labels `app`, `environment`, `level`, pushes to `http://loki:3100/loki/api/v1/push`
- `grafana`: `image: grafana/grafana:latest`; port `3000:3000`; mount provisioning dirs and dashboards dir; `datasources.yml` defines Prometheus (url: `http://prometheus:9090`), Loki (url: `http://loki:3100`), Tempo (url: `http://tempo:3200`) with Loki→Tempo derived field on `trace_id`
- _Requirements: 12.2–12.6_

**Expected output:** `docker compose up -d` starts all 8 services; all health checks pass within 120 s; `curl http://localhost:8080/api/v1/products` returns a response; Grafana at `http://localhost:3000` shows all three data sources as green.

---

## Phase 6: Grafana Dashboards

### Task 6.1 — Application Overview dashboard

**Objective:** Create a Grafana dashboard showing real-time API health and business metrics.

**Files to create:**

- `docker/grafana/dashboards/application-overview.json`

**Implementation details:**

- Panel 1 — Request Rate (RPS): `rate(http_request_duration_seconds_count[1m])` — time series
- Panel 2 — Error Rate (%): `rate(http_request_duration_seconds_count{status_code=~"5.."}[1m]) / rate(http_request_duration_seconds_count[1m]) * 100` — stat panel with threshold red > 1%
- Panel 3 — Latency P50/P95/P99: `histogram_quantile(0.50|0.95|0.99, rate(http_request_duration_seconds_bucket[5m]))` — time series with three series
- Panel 4 — Active Requests: `app_active_requests` — gauge panel
- Panel 5 — Total Registrations: `app_user_registrations_total` — stat panel
- Panel 6 — Total Orders: `app_orders_created_total` — stat panel
- Set dashboard refresh to 10 s; add variable `$instance` for multi-instance support
- _Requirements: 14.1, 14.5_

**Expected output:** Dashboard loads in Grafana; all panels show data after running the k6 script; P99 panel spikes visibly when anomaly delay is enabled.

---

### Task 6.2 — Database Performance dashboard

**Objective:** Create a dashboard surfacing database query rates, latency, and slow query detection.

**Files to create:**

- `docker/grafana/dashboards/database-performance.json`

**Implementation details:**

- Panel 1 — Query Rate by Type: `rate(app_db_queries_total[1m])` grouped by `query_type` — time series
- Panel 2 — P95 Query Duration: `histogram_quantile(0.95, rate(app_db_query_duration_seconds_bucket[5m]))` — time series
- Panel 3 — Slow Query Count: `increase(app_db_query_duration_seconds_count{query_type="select"}[5m])` filtered to buckets > 0.5 s — bar chart
- Panel 4 — Active DB Connections: `mysql_global_status_threads_connected` (optional; show placeholder if mysqld_exporter not present)
- _Requirements: 14.2_

**Expected output:** Dashboard shows query rate breakdown; P95 duration spikes when slow query anomaly is active.

---

### Task 6.3 — Logs Explorer dashboard

**Objective:** Create a Loki-backed log explorer with level and trace_id filtering.

**Files to create:**

- `docker/grafana/dashboards/logs-explorer.json`

**Implementation details:**

- Dashboard variable `$level`: custom options INFO, WARNING, ERROR; default ALL (`.*`)
- Dashboard variable `$trace_id`: text box, default empty
- Panel 1 — Log Stream: LogQL query `{app="laravel-observability-demo"} | json | level =~ "$level" | trace_id =~ "$trace_id"` — logs panel
- Panel 2 — Log Volume: `sum by (level) (rate({app="laravel-observability-demo"} | json [1m]))` — bar chart
- _Requirements: 14.3_

**Expected output:** Logs panel shows JSON log lines; filtering by `level=ERROR` shows only error entries; pasting a `trace_id` filters to that trace's logs.

---

### Task 6.4 — Traces Explorer dashboard

**Objective:** Create a Tempo-backed trace explorer with clickable trace IDs linked from Loki logs.

**Files to create:**

- `docker/grafana/dashboards/traces-explorer.json`

**Implementation details:**

- Panel 1 — Trace Search: Tempo data source; search by service name `laravel-observability-demo`; display trace list with duration and span count
- Configure Loki data source derived field: field name `trace_id`, regex `"trace_id":"(\w+)"`, link URL `${__data.fields.trace_id}` pointing to Tempo data source
- Panel 2 — Trace Detail: node graph panel showing span hierarchy for selected trace
- _Requirements: 14.4_

**Expected output:** Clicking a `trace_id` value in the Logs Explorer opens the corresponding trace in Tempo; span waterfall shows HTTP root span with child `db.query` spans.

---

## Phase 7: Load Testing

### Task 7.1 — k6 load test script

**Objective:** Write a k6 script that generates at least 5 000 requests exercising all six API flows with thresholds and assertions.

**Files to create:**

- `k6/load-test.js`

**Implementation details:**

- Import `http`, `check`, `sleep` from k6 modules
- Read `BASE_URL` from `__ENV.BASE_URL` with default `http://localhost:8080`
- Define `options.scenarios.ramp_up` with `ramping-vus` executor: stages `[{duration:"30s",target:20},{duration:"2m",target:20},{duration:"30s",target:0}]` — produces ~21 600 requests at 20 VUs × 180 s × 6 requests/iteration
- Define `options.thresholds`: `http_req_duration: ["p(95)<2000"]`, `http_req_failed: ["rate<0.01"]`
- Default function flow per VU iteration:
  1. `POST /api/v1/register` with random email — `check` status 201
  2. `POST /api/v1/login` — `check` status 200, extract `token`
  3. `POST /api/v1/products` with Bearer token — `check` status 201, extract `product_id`
  4. `GET /api/v1/products?page=1&per_page=15` — `check` status 200
  5. `POST /api/v1/orders` with `items:[{product_id, quantity:1}]` — `check` status 201
  6. `GET /api/v1/orders` — `check` status 200
  7. `sleep(1)`
- Use `uuidv4()` helper or `Math.random()` to generate unique emails per VU
- _Requirements: 13.1–13.7_

**Expected output:** `k6 run k6/load-test.js` completes with ≥ 5 000 requests; end-of-test summary shows P50/P95/P99 for `http_req_duration`; both thresholds pass on a healthy stack; threshold failures are visible when anomalies are active.

---

## Phase 8: Anomaly Injection

### Task 8.1 — Artificial delay middleware

**Objective:** Implement a middleware that injects a configurable sleep on targeted routes, observable through metrics, traces, and logs.

**Files to create/update:**

- `app/Http/Middleware/AnomalyDelayMiddleware.php`
- `.env.example` (document `ANOMALY_DELAY_ENABLED`, `ANOMALY_DELAY_MS`, `ANOMALY_DELAY_ROUTES`)

**Implementation details:**

- Read `ANOMALY_DELAY_ENABLED` (bool, default false); if false call `$next($request)` immediately with zero overhead
- Read `ANOMALY_DELAY_MS` (int, default 2000) and `ANOMALY_DELAY_ROUTES` (comma-separated prefixes, default `api/v1/products`)
- Check if `$request->is(...$routes)` matches; if yes:
  1. `usleep($delayMs * 1000)`
  2. Retrieve active OTel span from context; set attribute `anomaly.delay_ms = $delayMs`
  3. `Log::warning('Anomaly delay injected', ['delay_ms' => $delayMs])`
- Register middleware in `bootstrap/app.php` after `TraceMiddleware` in the global middleware stack
- _Requirements: 10.1–10.5_

**Expected output:** With `ANOMALY_DELAY_ENABLED=true ANOMALY_DELAY_MS=2000`, product list requests take ≥ 2 s; Prometheus P99 latency exceeds 2 s; Tempo shows `anomaly.delay_ms=2000` attribute on root span; Loki shows WARNING log with `delay_ms`.

---

### Task 8.2 — Inefficient query injection

**Objective:** Inject a non-indexed full-table scan on the orders table during product list requests to demonstrate slow query detection.

**Files to create/update:**

- `app/Services/ProductService.php` (extend `list()` method)
- `.env.example` (document `ANOMALY_SLOW_QUERY_ENABLED`)

**Implementation details:**

- In `ProductService::list()`, after building the normal product query, check `config('observability.slow_query_enabled')` (reads `ANOMALY_SLOW_QUERY_ENABLED`)
- If enabled:
  1. Start child OTel span `db.slow_query`
  2. Execute `DB::select('SELECT * FROM orders WHERE YEAR(created_at) = YEAR(NOW())')` — no index on function expression, forces full table scan
  3. Record query duration; set span attribute `db.statement` with the SQL
  4. End span
  5. If duration > 500 ms: `Log::warning('Slow query executed', ['anomaly' => 'slow_query', 'duration_ms' => $duration])`
  6. Record duration in `app_db_query_duration_seconds{query_type="select"}`
- _Requirements: 11.1–11.5_

**Expected output:** With `ANOMALY_SLOW_QUERY_ENABLED=true` and sufficient order data, `GET /api/v1/products` triggers the slow query; Prometheus `app_db_query_duration_seconds` P95 exceeds 500 ms; Tempo shows `db.slow_query` child span; Loki shows WARNING with `anomaly=slow_query`.

---

## Phase 9: Demo Preparation

### Task 9.1 — Database seeder

**Objective:** Create a seeder that populates realistic demo data so dashboards are populated immediately on first run.

**Files to create:**

- `database/seeders/DemoSeeder.php`
- `database/seeders/DatabaseSeeder.php` (call DemoSeeder)

**Implementation details:**

- Create 10 users with `User::factory()->create()`
- Create 50 products with varied names, descriptions, prices (1.00–999.99), and stock (0–500)
- Create 200 orders distributed across users, each with 1–5 order items referencing existing products; set `status` randomly across `pending/completed/cancelled`; compute and store `total_price`
- Ensure stock values remain non-negative after seeding orders
- Run with `php artisan db:seed --class=DemoSeeder`

**Expected output:** After seeding, Grafana dashboards show non-zero counters; product list returns 50 products; order list returns 200 orders; k6 script can immediately create orders against seeded products.

---

### Task 9.2 — README and demo runbook

**Objective:** Write a concise README with setup instructions and a step-by-step demo script for presenting the observability stack.

**Files to create:**

- `README.md`

**Implementation details:**

- Prerequisites section: Docker Desktop ≥ 4.x, k6 installed
- Quick start: `docker compose up -d`, wait for health checks, `docker compose exec app php artisan db:seed`
- Service URLs table: App `http://localhost:8080`, Grafana `http://localhost:3000` (admin/admin), Prometheus `http://localhost:9090`, Loki `http://localhost:3100`
- Demo script section with numbered steps:
  1. Open Grafana → Application Overview; show baseline metrics
  2. Run `k6 run k6/load-test.js`; watch RPS and latency panels update live
  3. Enable delay anomaly: `docker compose exec app sh -c "echo ANOMALY_DELAY_ENABLED=true >> .env && php artisan config:clear"`; observe P99 spike in Application Overview within 30 s
  4. Enable slow query: set `ANOMALY_SLOW_QUERY_ENABLED=true`; open Database Performance dashboard; observe P95 query duration spike
  5. Open Logs Explorer; filter by `level=WARNING`; click a `trace_id` to jump to Tempo trace
  6. Show span waterfall in Tempo with `db.slow_query` child span
  7. Disable anomalies; show metrics return to baseline
- Anomaly toggle reference table: env var name, default value, effect

**Expected output:** A developer unfamiliar with the project can follow the README to run the full demo end-to-end in under 10 minutes.

---

### Task 9.3 — Environment configuration validation

**Objective:** Add a startup check that validates all required environment variables are set and logs a clear error if any are missing.

**Files to create/update:**

- `app/Providers/ObservabilityServiceProvider.php` (add `validateEnvironment()` call in `boot()`)

**Implementation details:**

- Define required env vars: `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `OTEL_EXPORTER_OTLP_ENDPOINT`
- On boot, check each with `env()`; if any missing, `Log::error('Missing required environment variable', ['var' => $name])` and throw `RuntimeException` to prevent silent misconfiguration
- Define optional vars with documented defaults: `ANOMALY_DELAY_ENABLED=false`, `ANOMALY_DELAY_MS=2000`, `ANOMALY_SLOW_QUERY_ENABLED=false`, `OTEL_TRACES_SAMPLER_ARG=1.0`
- Log INFO on boot listing active anomaly flags so demo state is always visible in logs

**Expected output:** Starting the container without `OTEL_EXPORTER_OTLP_ENDPOINT` set logs a clear ERROR and exits; starting with all vars set logs INFO with anomaly flag status; Loki shows the boot log entry.

---

## Task Checklist

- [x] 1.1 Initialise Laravel 12 project
- [x] 1.2 Project folder structure
- [x] 2.1 Migrations
- [x] 2.2 Eloquent models
- [x] 3.1 Auth endpoints
- [x] 3.2 Product CRUD
- [x] 3.3 Order creation and listing
- [x] 4.1 Prometheus metrics
- [x] 4.2 Structured JSON logging
- [x] 4.3 OpenTelemetry distributed tracing
- [x] 5.1 Dockerfile
- [x] 5.2 Docker Compose stack
- [x] 6.1 Application Overview dashboard
- [x] 6.2 Database Performance dashboard
- [x] 6.3 Logs Explorer dashboard
- [x] 6.4 Traces Explorer dashboard
- [x] 7.1 k6 load test script
- [x] 8.1 Artificial delay middleware
- [x] 8.2 Inefficient query injection
- [x] 9.1 Database seeder
- [x] 9.2 README and demo runbook
- [x] 9.3 Environment configuration validation
