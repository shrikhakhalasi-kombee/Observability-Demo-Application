# Demo Walkthrough — Laravel Observability Demo

A step-by-step guide for running a live demo of the full observability stack.

---

## Before You Start

```bash
# Start the full stack
docker compose up -d

# Wait for all health checks (~60 s)
docker compose ps

# Seed demo data — 50 products, 200 orders, demo users
docker compose exec app php artisan db:seed --class=DemoSeeder
```

Open these tabs before starting:

| Tab        | URL                   | Credentials                 |
| ---------- | --------------------- | --------------------------- |
| Web UI     | http://localhost:8083 | register or use seeded user |
| phpMyAdmin | http://localhost:8081 | auto-login (root)           |
| Grafana    | http://localhost:3000 | admin / admin               |
| Prometheus | http://localhost:9090 | —                           |

---

## 1. Application Walkthrough

### Web UI — http://localhost:8083

**Register / Login**
Navigate to Register, fill the form, submit. Livewire validates inline — no page reload. After registering you land on the Products page.

**Products**

- Live search with 300ms debounce
- Price range filters
- New Product button opens a modal with validation
- Edit and Delete are inline
- Low stock items highlighted in red

**Orders**

- Filter by status via dropdown
- New Order modal supports multiple line items — click "+ Add item"
- Placing an order decrements stock atomically inside a DB transaction
- Click View on any order to see the itemized breakdown

**Dashboard**
Live snapshot of total users, products, orders, and pending orders.

### Database — http://localhost:8081 (phpMyAdmin)

Open `laravel_observability` database:

- `products` — 50 seeded rows with varied prices and stock levels
- `orders` — seeded orders
- `order_items` — relational line items per order

After placing an order from the UI, refresh the `products` table — the stock column will have decremented, confirming the transaction worked.

### API Layer

The same data is exposed as a REST API under `/api/v1`. The web UI and API share the same service layer (`ProductService`, `OrderService`). The k6 load test hits the API directly.

| Endpoint                | Description                                   |
| ----------------------- | --------------------------------------------- |
| `POST /api/v1/register` | Creates a user, returns a Sanctum token       |
| `POST /api/v1/login`    | Returns a Sanctum token                       |
| `GET /api/v1/products`  | Paginated list with search + price filters    |
| `POST /api/v1/products` | Create a product                              |
| `POST /api/v1/orders`   | Creates an order, decrements stock atomically |
| `GET /metrics`          | Prometheus scrape endpoint                    |

---

## 2. Observability Architecture

```
Browser / k6
     │
     ▼
  nginx:8083
     │
     ▼
Laravel (PHP-FPM)
  ├── TraceMiddleware        → starts OTel root span, injects trace_id into logs
  ├── AnomalyDelayMiddleware → conditional random sleep (demo only)
  ├── RequestLogMiddleware   → structured JSON log line per request
  └── MetricsMiddleware      → records http_request_duration_seconds
          │
          ├── Prometheus (:9090)  ← scrapes /metrics every 5s
          ├── Loki (:3100)        ← Promtail tails storage/logs/laravel.json.log
          └── Tempo (:3200)       ← OTLP/HTTP on port 4318
                    │
                    └── Grafana (:3000)
```

Every request passes through four middleware layers. `TraceMiddleware` starts an OTel root span and injects the `trace_id` into Monolog's shared context — every log line for that request carries the same `trace_id`. That's the link that lets you jump from a Loki log entry directly to the Tempo trace.

`SimpleSpanProcessor` is used instead of `BatchSpanProcessor` because PHP-FPM ends the process after each request — batch processors never get a chance to flush.

---

## 3. Grafana Dashboards

Open http://localhost:3000 → Dashboards. All four are auto-provisioned.

### Laravel Application Health

| Panel                         | Query                                                                                              | What it shows                                        |
| ----------------------------- | -------------------------------------------------------------------------------------------------- | ---------------------------------------------------- |
| Requests per Minute           | `rate(http_request_duration_seconds_count[1m]) * 60`                                               | Live RPS — climbs to ~200 during k6                  |
| Error Rate (%)                | 5xx / total × 100, `or vector(0)` on numerator                                                     | Stays 0% under normal load; turns red at 1%          |
| Latency P50/P95/P99           | `histogram_quantile(0.50/0.95/0.99, ...)` over 5m                                                  | P99 spikes visibly during delay anomaly              |
| Active Requests               | `sum(app_active_requests)`                                                                         | In-flight requests gauge                             |
| Total Registrations           | `sum(app_user_registrations_total)`                                                                | Cumulative registration counter                      |
| Orders Created/min            | `rate(app_orders_created_total[1m]) * 60`                                                          | Order throughput                                     |
| Slowest Endpoints             | `topk(10, histogram_quantile(0.95, ...)) by (route)`                                               | POST /api/v1/orders appears here                     |
| Active Users (logins last 5m) | `increase(http_request_duration_seconds_count{method="post",route="login",status_code="302"}[5m])` | Successful web logins — 302 = authenticated redirect |

### Database Performance

| Panel                    | What it shows                                                            |
| ------------------------ | ------------------------------------------------------------------------ |
| Query Rate by Type       | SELECT / INSERT / UPDATE / DELETE breakdown over time                    |
| P95 Query Duration       | 95th percentile query latency by type — spikes during slow query anomaly |
| Slow Query Rate          | Queries exceeding threshold — non-zero during anomaly                    |
| DB Connections Over Time | Connection pool usage                                                    |

### Logs Explorer

| Panel                | What it shows                                                 |
| -------------------- | ------------------------------------------------------------- |
| Log Volume by Level  | Bar chart — INFO dominant, WARNING appears during anomaly     |
| Full Log Stream      | Filterable by Level variable and Trace ID text box            |
| Error & Warning Logs | Always filtered to ERROR/WARNING regardless of Level variable |

Expand any log line — the `trace_id` field is a Grafana derived field that links directly to the corresponding Tempo trace.

### Traces Explorer

| Panel         | What it shows                                                      |
| ------------- | ------------------------------------------------------------------ |
| Trace Search  | Recent traces — Trace ID, start time, service, operation, duration |
| Slowest Spans | Top spans by duration across all recent traces                     |

Click any trace row to open the span waterfall in Tempo. Each order creation shows four nested spans:

```
order.controller          (full HTTP handler)
  └── order.business_logic
        └── order.database_query    (MySQL transaction)
        order.response_formatting   (JSON serialisation)
```

Span attributes include `db.item_count`, `order.total_price`, and anomaly markers when active.

---

## 4. Load Testing

```bash
k6 run k6/load-test.js
```

The script:

1. **Setup** (runs once) — registers a user, gets a Sanctum token, creates 10 products
2. **Main loop** — 100 VUs × 5000 iterations: `GET /api/v1/products` then `POST /api/v1/orders`
3. **Thresholds** — P95 GET < 2s, error rate < 1%

While k6 runs, watch in Grafana:

- Requests per Minute climbs to ~200
- P95 latency panel populates with real data
- POST /api/v1/orders tops the Slowest Endpoints table

In phpMyAdmin, refresh the orders table — new rows appear in real time as k6 creates orders.

Expected k6 summary: 0% error rate, P95 GET < 500ms, both thresholds green.

---

## 5. Anomaly Injection

### Latency anomaly — simulates slow downstream dependency

```bash
# Enable — injects random 1–3s sleep before every POST /orders response
docker compose exec app sh -c "echo ANOMALY_DELAY_ENABLED=true >> .env && php artisan config:clear"
```

**What to observe in Grafana (Application Health):**

- P99 latency spikes from ~300ms to 1–3s within 30 seconds
- P95 also climbs
- Error Rate stays 0% — requests succeed, they're just slow
- Slowest Endpoints: POST /api/v1/orders jumps to 1–3s P95

This is latency degradation, not error degradation — an important distinction.

### Slow query anomaly — simulates missing index / bad SQL

```bash
# Enable — injects full table scan + N+1 per-row fetch on every order creation
docker compose exec app sh -c "echo ANOMALY_SLOW_QUERY_ENABLED=true >> .env && php artisan config:clear"
```

**What to observe in Grafana (Database Performance):**

- P95 Query Duration spikes
- Slow Query Rate shows non-zero rate
- In phpMyAdmin: run `SHOW PROCESSLIST` to see the full table scan query in the process list

### Restore baseline

```bash
docker compose exec app sh -c "sed -i '/ANOMALY_/d' .env && php artisan config:clear"
```

Within ~15 seconds (one Prometheus scrape interval) latency and query duration return to baseline.

---

## 6. Root Cause Analysis — Logs → Traces Correlation

With both anomalies active:

**Step 1 — Metrics narrow the scope**
Application Health → Latency P95/P99: spike is isolated to POST /api/v1/orders. GET /products is unaffected. Rules out network issues and connection pool exhaustion.

**Step 2 — Logs give context**
Logs Explorer → Level = WARNING. Two entry types appear:

- `anomaly.delay_injected` — shows `delay_ms` value + `trace_id`
- `anomaly.slow_query_injected` — shows `duration_ms`, `rows_scanned` + `trace_id`

Click the `trace_id` derived field link on any entry.

**Step 3 — Trace shows where time went**
The Tempo waterfall opens directly from the log link. `order.controller` shows 2+ seconds total. Expand `order.database_query`:

- `anomaly.slow_query_duration_ms` — exact query duration
- `anomaly.type` — "slow_query"
- Root span: `anomaly.delay_ms` — the artificial delay

Both anomalies are visible in a single trace.

**Step 4 — Confirm in database**
phpMyAdmin → orders table: the order exists and was created successfully — just slowly. Closes the loop between observability data and actual database state.

**Step 5 — Restore and confirm recovery**

```bash
docker compose exec app sh -c "sed -i '/ANOMALY_/d' .env && php artisan config:clear"
```

Watch Grafana: within 15 seconds P95/P99 return to baseline, Slow Query Rate drops to zero, WARNING logs stop.

---

## Quick Reference

### Anomaly commands

```bash
# Enable delay anomaly
docker compose exec app sh -c "echo ANOMALY_DELAY_ENABLED=true >> .env && php artisan config:clear"

# Enable slow query anomaly
docker compose exec app sh -c "echo ANOMALY_SLOW_QUERY_ENABLED=true >> .env && php artisan config:clear"

# Disable all anomalies
docker compose exec app sh -c "sed -i '/ANOMALY_/d' .env && php artisan config:clear"
```

### Useful SQL

```sql
-- Watch orders being created live
SELECT id, user_id, status, total_price, created_at
FROM orders ORDER BY created_at DESC LIMIT 20;

-- Check stock after load test
SELECT id, name, stock FROM products ORDER BY stock ASC;

-- See active queries during anomaly
SHOW PROCESSLIST;
```

### Teardown

```bash
docker compose down -v
```
