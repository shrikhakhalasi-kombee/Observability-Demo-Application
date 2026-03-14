# Laravel Observability Demo

A Laravel 12 REST API instrumented with Prometheus metrics, structured JSON logs (Loki), and distributed traces (Tempo) — all visualised in Grafana. Two injectable anomalies let you demonstrate live degradation detection.

---

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                        Docker Network                        │
│                                                             │
│  ┌──────────┐    ┌──────────┐    ┌──────────────────────┐  │
│  │  nginx   │───▶│  app     │───▶│  mysql               │  │
│  │ :80      │    │ php-fpm  │    │  :3306               │  │
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

- **nginx** — reverse proxy, forwards HTTP to php-fpm, serves `/metrics`
- **app** — Laravel 12 API with OTel SDK, Prometheus client, structured JSON logging
- **mysql** — persistent data store
- **prometheus** — scrapes `/metrics` every 5 s
- **loki** — receives JSON logs via promtail
- **tempo** — receives OTLP traces from the app
- **grafana** — unified dashboard UI connected to all three backends
- **promtail** — tails Laravel log files and ships to Loki

---

## Grafana Dashboards

Four pre-provisioned dashboards are available at http://localhost:3000 (admin/admin):

| Dashboard                      | Datasource | What it shows                                                                                                        |
| ------------------------------ | ---------- | -------------------------------------------------------------------------------------------------------------------- |
| **Laravel Application Health** | Prometheus | RPS, error rate %, P50/P95/P99 latency, active requests, total orders/registrations, slowest endpoints, active users |
| **Database Performance**       | Prometheus | Query duration histogram, slow query rate, connection pool usage                                                     |
| **Logs Explorer**              | Loki       | Full log stream with level/trace_id filters, log volume by level, error logs, login failures, validation errors      |
| **Traces Explorer**            | Tempo      | Trace search waterfall, span hierarchy node graph, span duration distribution, slowest service spans                 |

---

## Observability

This project demonstrates the three pillars of observability:

### Metrics (Prometheus)

The app exposes a `/metrics` endpoint scraped by Prometheus every 5 seconds. Key metrics:

- `http_request_duration_seconds` — histogram of request latency labelled by route and status code
- `app_orders_created_total` — counter incremented on every successful order
- `app_user_logins_total` / `app_user_registrations_total` — auth event counters
- `app_active_requests` — gauge tracking in-flight requests

### Logs (Loki)

Every request emits a structured JSON log line via `RequestLogMiddleware`:

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

Promtail tails `storage/logs/laravel.json.log` and ships entries to Loki with the `app="laravel-observability-demo"` label.

### Traces (Tempo)

The app uses the OpenTelemetry PHP SDK to emit OTLP traces. Each order creation produces four nested spans:

1. `order.controller` — full HTTP handler duration
2. `order.business_logic` — service layer including metrics recording
3. `order.database_query` — DB transaction (stock check, order insert)
4. `order.response_formatting` — JSON resource serialisation

The `trace_id` is injected into every log entry, enabling one-click navigation from a Loki log line to the corresponding Tempo trace.

---

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) ≥ 4.x
- [k6](https://k6.io/docs/get-started/installation/) (load testing)

---

## Quick Start

```bash
# 1. Start the full stack
docker compose up -d

# 2. Wait for all health checks to pass (~60 s)
docker compose ps   # all services should show "healthy"

# 3. Seed demo data
docker compose exec app php artisan db:seed
```

### Service URLs

| Service    | URL                   | Credentials |
| ---------- | --------------------- | ----------- |
| App (API)  | http://localhost:8080 | —           |
| Grafana    | http://localhost:3000 | admin/admin |
| Prometheus | http://localhost:9090 | —           |
| Loki       | http://localhost:3100 | —           |
| Tempo      | http://localhost:3200 | —           |

---

## Demo Script

### 1. Baseline metrics

Open Grafana → **Application Overview** dashboard. You should see non-zero RPS, latency, and order/registration counters from the seeded data.

### 2. Generate load

```bash
k6 run k6/load-test.js
```

Watch the **Application Overview** dashboard update live — RPS climbs to ~20, P50/P95/P99 latency panels populate, and order/registration counters increment.

### 3. Inject latency anomaly

In a second terminal, enable the delay while k6 is still running:

```bash
docker compose exec app sh -c "echo ANOMALY_DELAY_ENABLED=true >> .env && php artisan config:clear"
```

Within ~30 s the **P99 latency** panel in Application Overview spikes visibly. The Tempo trace for any product request will show an `anomaly.delay_ms=2000` attribute on the root span.

### 4. Inject slow query anomaly

```bash
docker compose exec app sh -c "echo ANOMALY_SLOW_QUERY_ENABLED=true >> .env && php artisan config:clear"
```

Open the **Database Performance** dashboard. The **P95 Query Duration** panel will spike as the full-table scan on `orders` executes on every product list request.

### 5. Correlate logs → traces

Open the **Logs Explorer** dashboard. Set the `level` variable to `WARNING`. You'll see entries for both anomalies. Click a `trace_id` value to jump directly to the corresponding Tempo trace.

### 6. Inspect the span waterfall

In Tempo, expand the trace. You'll see:

```
HTTP root span  (GET /api/v1/products)
  ├── db.query        (normal product query)
  └── db.slow_query   (anomaly full-table scan)
```

The `db.slow_query` span carries the `db.statement` attribute with the executed SQL.

### 7. Restore baseline

```bash
docker compose exec app sh -c "sed -i '/ANOMALY_/d' .env && php artisan config:clear"
```

Within one Prometheus scrape interval (~15 s) latency and query duration return to baseline.

---

## Anomaly Reference

| Environment Variable         | Default           | Effect                                                                 |
| ---------------------------- | ----------------- | ---------------------------------------------------------------------- |
| `ANOMALY_DELAY_ENABLED`      | `false`           | Injects a sleep before the response on product routes                  |
| `ANOMALY_DELAY_MS`           | `2000`            | Duration of the injected delay in milliseconds                         |
| `ANOMALY_DELAY_ROUTES`       | `api/v1/products` | Comma-separated route prefixes that receive the delay                  |
| `ANOMALY_SLOW_QUERY_ENABLED` | `false`           | Executes a non-indexed full-table scan on `orders` during product list |

---

## Teardown

```bash
docker compose down -v   # removes containers and the mysql_data volume
```
