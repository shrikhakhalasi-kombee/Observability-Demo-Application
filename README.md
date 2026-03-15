# Laravel Observability Demo

A Laravel 12 application with a Livewire frontend, instrumented with Prometheus metrics, structured JSON logs (Loki), and distributed traces (Tempo) — all visualised in Grafana. Two injectable anomalies let you demonstrate live degradation detection.

---

## Repository Structure

```
.
├── app/                        # Laravel application code
│   ├── Http/
│   │   ├── Controllers/        # API + Web controllers (AuthController, OrderController, ProductController)
│   │   ├── Middleware/         # TraceMiddleware, MetricsMiddleware, RequestLogMiddleware, AnomalyDelayMiddleware
│   │   └── Resources/          # JSON API resources
│   ├── Livewire/               # Livewire components (ProductManager, OrderManager, DashboardStats)
│   ├── Models/                 # Eloquent models (User, Product, Order, OrderItem)
│   ├── Providers/
│   │   └── ObservabilityServiceProvider.php  # Registers OTel tracer, Prometheus metrics, DB listener
│   └── Services/               # OrderService, ProductService (business logic + metric recording)
├── bootstrap/
│   └── app.php                 # Middleware groups — web + api both get Trace/Metrics/Log middleware
├── config/
│   └── observability.php       # Centralised observability config (namespace, OTLP endpoint, anomaly flags)
├── docker/
│   ├── grafana/
│   │   ├── dashboards/         # Pre-built dashboard JSON exports (4 dashboards)
│   │   └── provisioning/       # Auto-provisioned datasources + dashboard loader
│   ├── loki/                   # Loki config (stream limits, retention)
│   ├── nginx/                  # Nginx reverse proxy config
│   ├── php/                    # PHP-FPM entrypoint script
│   ├── prometheus/             # Prometheus scrape config
│   ├── promtail/               # Promtail log shipping config
│   └── tempo/                  # Tempo trace storage config
├── k6/
│   └── load-test.js            # k6 load test — 100 VUs, 5000 iterations, custom metrics
├── database/
│   ├── migrations/             # Schema for users, products, orders, order_items
│   └── seeders/                # DemoSeeder — 50 products, 200 orders, demo users
├── resources/views/            # Blade + Livewire templates
├── routes/
│   ├── api.php                 # REST API routes under /api/v1
│   └── web.php                 # Web UI routes (Livewire pages)
├── docker-compose.yml          # Full 10-service stack definition
├── Dockerfile                  # PHP 8.3-FPM image with OTel + APCu extensions
├── .env.example                # Environment variable template
├── DEMO_WALKTHROUGH.md         # Step-by-step video recording script
└── README.md                   # This file
```

---

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                        Docker Network                        │
│                                                             │
│  ┌──────────┐    ┌──────────┐    ┌──────────────────────┐  │
│  │  nginx   │───▶│  app     │───▶│  mysql               │  │
│  │ :8083    │    │ php-fpm  │    │  :3306               │  │
│  └──────────┘    └────┬─────┘    └──────────────────────┘  │
│       ▲               │                                     │
│  HTTP │        metrics│logs│traces                          │
│       │               ▼                                     │
│  ┌────┴──────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐  │
│  │ k6 load   │  │prometheus│  │  loki    │  │  tempo   │  │
│  │ test      │  │  :9090   │  │  :3100   │  │  :3200   │  │
│  └───────────┘  └────┬─────┘  └────┬─────┘  └────┬─────┘  │
│                       │             │              │        │
│                       └─────────────┴──────────────┘        │
│                                     │                       │
│                               ┌─────▼─────┐                │
│                               │  grafana  │                 │
│                               │  :3000    │                 │
│                               └───────────┘                 │
└─────────────────────────────────────────────────────────────┘
```

| Service        | Role                                                                        |
| -------------- | --------------------------------------------------------------------------- |
| **nginx**      | Reverse proxy on port 8083, forwards HTTP to php-fpm                        |
| **app**        | Laravel 12 + Livewire, OTel SDK, Prometheus client, structured JSON logging |
| **mysql**      | Persistent data store (port 3310 on host)                                   |
| **phpmyadmin** | Database browser on port 8081                                               |
| **prometheus** | Scrapes `/metrics` every 5 s                                                |
| **loki**       | Receives structured JSON logs from promtail                                 |
| **tempo**      | Receives OTLP/HTTP traces from the app on port 4318                         |
| **promtail**   | Tails `storage/logs/laravel.json.log`, ships to Loki                        |
| **grafana**    | Unified dashboard UI — all four dashboards auto-provisioned                 |

---

## Grafana Dashboards

Four pre-provisioned dashboards at http://localhost:3000 (admin / admin):

| Dashboard                      | Datasource | Panels                                                                                                                                                          |
| ------------------------------ | ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Laravel Application Health** | Prometheus | Requests/min, Error Rate %, P50/P95/P99 Latency, Active Requests gauge, Total Registrations, Orders/min, Slowest Endpoints table, Active Users (logins last 5m) |
| **Database Performance**       | Prometheus | Query Rate by Type, P95 Query Duration, Slow Query Rate, DB Connections over time                                                                               |
| **Logs Explorer**              | Loki       | Log volume by level, full log stream (filterable by level + trace_id), Error & Warning log panel                                                                |
| **Traces Explorer**            | Tempo      | Trace search table (service, name, duration, trace ID), Slowest Spans table                                                                                     |

---

## Observability Implementation

### Metrics (Prometheus)

`MetricsMiddleware` records `http_request_duration_seconds` (histogram) on every request. `ObservabilityServiceProvider` registers all counters and gauges at boot. `OrderService` increments `app_orders_created_total` after each successful order. A DB listener records `app_db_query_duration_seconds` and `app_db_queries_total` for every query.

Key metrics:

| Metric                          | Type      | Description                                   |
| ------------------------------- | --------- | --------------------------------------------- |
| `http_request_duration_seconds` | Histogram | Request latency by method, route, status_code |
| `app_orders_created_total`      | Counter   | Successful order creations                    |
| `app_user_registrations_total`  | Counter   | Successful user registrations                 |
| `app_active_requests`           | Gauge     | In-flight requests                            |
| `app_db_query_duration_seconds` | Histogram | DB query latency by type                      |
| `app_db_queries_total`          | Counter   | DB query count by type                        |
| `app_order_value_dollars`       | Histogram | Order value distribution                      |

> **Active Users panel** uses `http_request_duration_seconds_count{method="post",route="login",status_code="302"}` — a 302 on POST /login is a successful web login. This is recorded by `MetricsMiddleware` in every PHP-FPM worker, making it reliable across all worker processes.

### Logs (Loki)

`RequestLogMiddleware` emits a structured JSON line per request:

```json
{
  "level": "INFO",
  "message": "request",
  "url": "/api/v1/orders",
  "method": "POST",
  "status": 201,
  "duration_ms": 45,
  "trace_id": "abc123..."
}
```

Promtail ships `storage/logs/laravel.json.log` to Loki with label `app="laravel-observability-demo"`. The `trace_id` field is a Grafana derived field — clicking it opens the corresponding Tempo trace.

### Traces (Tempo)

`TraceMiddleware` starts an OpenTelemetry root span per request using `SimpleSpanProcessor` (required for PHP-FPM — `BatchSpanProcessor` never flushes in a synchronous request lifecycle). Traces are exported via OTLP/HTTP to Tempo on port 4318.

Each order creation produces four nested spans:

```
order.controller          (full HTTP handler)
  └── order.business_logic
        └── order.database_query    (MySQL transaction — stock lock, insert)
              order.response_formatting
```

Span attributes include `db.item_count`, `order.total_price`, and anomaly markers when active.

---

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) ≥ 4.x
- [k6](https://k6.io/docs/get-started/installation/) for load testing

---

## Quick Start

```bash
# 1. Clone and configure
cp .env.example .env

# 2. Start the full stack
docker compose up -d

# 3. Wait for health checks (~60 s)
docker compose ps   # all services should show "healthy"

# 4. Seed demo data (50 products, 200 orders, demo users)
docker compose exec app php artisan db:seed --class=DemoSeeder
```

### Service URLs

| Service    | URL                   | Credentials       |
| ---------- | --------------------- | ----------------- |
| Web UI     | http://localhost:8083 | register or login |
| phpMyAdmin | http://localhost:8081 | auto-login (root) |
| Grafana    | http://localhost:3000 | admin / admin     |
| Prometheus | http://localhost:9090 | —                 |
| Loki       | http://localhost:3100 | —                 |
| Tempo      | http://localhost:3200 | —                 |

---

## Running the Load Test

```bash
k6 run k6/load-test.js
```

The script registers a user, seeds 10 products, then runs 100 VUs for 5000 iterations hitting `GET /api/v1/products` and `POST /api/v1/orders`. Expected results: 0% error rate, P95 < 500ms for GET, P95 < 2s for POST.

---

## Anomaly Injection

Two anomalies can be toggled at runtime without restarting the container:

### Latency anomaly — simulates slow downstream dependency

```bash
# Enable
docker compose exec app sh -c "echo ANOMALY_DELAY_ENABLED=true >> .env && php artisan config:clear"

# Disable
docker compose exec app sh -c "sed -i '/ANOMALY_/d' .env && php artisan config:clear"
```

Effect: random 1–3 s sleep injected before every POST /orders response. P99 latency spikes within 30 s on the Application Health dashboard.

### Slow query anomaly — simulates missing index / bad SQL

```bash
# Enable
docker compose exec app sh -c "echo ANOMALY_SLOW_QUERY_ENABLED=true >> .env && php artisan config:clear"
```

Effect: full table scan + N+1 per-row fetch on every order creation. P95 Query Duration spikes on the Database Performance dashboard.

### Restore baseline

```bash
docker compose exec app sh -c "sed -i '/ANOMALY_/d' .env && php artisan config:clear"
```

### Anomaly reference

| Variable                     | Default | Effect                                    |
| ---------------------------- | ------- | ----------------------------------------- |
| `ANOMALY_DELAY_ENABLED`      | `false` | Random sleep before POST /orders response |
| `ANOMALY_DELAY_MIN_MS`       | `1000`  | Lower bound of delay (ms)                 |
| `ANOMALY_DELAY_MAX_MS`       | `3000`  | Upper bound of delay (ms)                 |
| `ANOMALY_SLOW_QUERY_ENABLED` | `false` | Full table scan + N+1 on order creation   |

---

## Teardown

```bash
docker compose down -v   # removes all containers and the mysql_data volume
```

---

## Submission Checklist

- [x] Working Laravel 12 application with Livewire UI
- [x] Docker Compose stack — 9 services, all health-checked
- [x] Dockerfile — PHP 8.3-FPM with OTel, APCu, Prometheus extensions
- [x] Prometheus metrics — 7 custom metrics, `/metrics` endpoint
- [x] Loki logs — structured JSON, Promtail shipping, level + trace_id labels
- [x] Tempo traces — OTel PHP SDK, OTLP/HTTP, 4 nested spans per order
- [x] 4 Grafana dashboards — auto-provisioned JSON exports in `docker/grafana/dashboards/`
- [x] k6 load test — `k6/load-test.js`, 100 VUs, 5000 iterations, custom metrics
- [x] 2 injectable anomalies — latency delay + slow query, env-var controlled
- [x] Cross-signal correlation — trace_id links Loki logs → Tempo traces
- [x] phpMyAdmin — live database browser for demo
- [x] Demo walkthrough — `DEMO_WALKTHROUGH.md` with full video recording script
- [x] Screen recording video — committed at repository root
