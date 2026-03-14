# Demo Walkthrough — Laravel Observability Demo

A presenter script for a live walkthrough of the full observability stack.
Each section includes what to say, what to show, and what the audience should see.

---

## 0. Before You Start

Run these commands at least 2 minutes before the demo begins.

```bash
# Start the full stack
docker compose up -d

# Wait for all health checks (every service should show "healthy")
docker compose ps

# Seed demo data — users, products, orders
docker compose exec app php artisan db:seed --class=DemoSeeder
```

Open these tabs in your browser before recording:

| Tab        | URL                   | Credentials                 |
| ---------- | --------------------- | --------------------------- |
| Web UI     | http://localhost:8080 | register or use seeded user |
| phpMyAdmin | http://localhost:8081 | auto-login (root)           |
| Grafana    | http://localhost:3000 | admin / admin               |
| Prometheus | http://localhost:9090 | —                           |

---

## 1. Application Features (3 min)

### 1a. Web UI walkthrough

**What to show:** http://localhost:8080

> This is a Laravel 12 application with a Livewire frontend — no separate
> SPA, no API calls from JavaScript, just reactive server-rendered components.

Navigate to Register → fill the form → submit:

> Registration validates in real time. Try submitting with a short password
> — you'll see inline field errors without a page reload. That's Livewire.

Log in and show the Dashboard:

> The dashboard gives an instant snapshot — total users, products, orders,
> and pending orders. The recent orders table updates live.

Navigate to Products:

> The product table has live search and price range filters. Type in the
> search box — results filter as you type with a 300ms debounce. The
> New Product button opens a modal form with validation. Edit and Delete
> are inline. Low stock items are highlighted in red.

Navigate to Orders:

> Orders can be filtered by status using the dropdown. The New Order modal
> lets you add multiple line items dynamically — click "+ Add item" to add
> another product row. Placing an order decrements stock atomically.
> Click View on any order to see the itemized breakdown in a detail modal.

### 1b. Database state

**What to show:** http://localhost:8081 (phpMyAdmin)

> phpMyAdmin gives us a visual view of the database. Open the
> laravel_observability database. Show the products table — 50 seeded
> products with varied prices and stock levels. Show the orders table —
> 200 seeded orders. Show order_items to demonstrate the relational structure.

> After placing an order from the UI, refresh the products table and show
> the stock column has decremented. This proves the transaction is working.

### 1c. API layer

**What to show:** http://localhost:8080/api/v1/products

> The same data is also exposed as a JSON REST API under /api/v1. The web
> UI and the API share the same service layer — ProductService, OrderService.
> The k6 load test hits the API directly, not the web UI.

**Key API endpoints:**

| Endpoint                | What it does                                  |
| ----------------------- | --------------------------------------------- |
| `POST /api/v1/register` | Creates a user, returns a Sanctum token       |
| `POST /api/v1/login`    | Returns a token                               |
| `GET /api/v1/products`  | Paginated list with search + price filters    |
| `POST /api/v1/products` | Create product                                |
| `POST /api/v1/orders`   | Creates an order, decrements stock atomically |
| `GET /metrics`          | Prometheus scrape endpoint                    |

---

## 2. Observability Architecture (3 min)

**What to say:**

> The stack implements all three pillars of observability — metrics, logs,
> and traces — and wires them together so you can jump between signals.

**Point to this diagram:**

```
Browser / k6
     │
     ▼
  nginx:8080
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
          └── Tempo (:3200)       ← OTLP/gRPC on port 4317
                    │
                    └── Grafana (:3000) — unified dashboard UI
```

> Every request passes through four middleware layers. TraceMiddleware starts
> an OpenTelemetry root span and injects the trace_id into Monolog's shared
> context. Every log line for this request carries that same trace_id — that's
> the link that lets us jump from a Loki log entry directly to the Tempo trace.

> Nothing here is a magic agent. It's explicit PHP code — middleware, service
> providers, a DB listener — that you can read, modify, and extend.

---

## 3. Grafana Dashboards (5 min)

Open http://localhost:3000 → Dashboards.

### 3a. Laravel Application Health

**What to show:** Dashboards → Laravel Application Health

Point to each panel:

- **Requests per Minute** — `rate(...[1m]) * 60`. Baseline after seeding is low.
  Once k6 runs this climbs to ~200 RPM.

- **Error Rate (%)** — ratio of 5xx to total. Green = below 1%. Watch this
  stay flat during normal load.

- **Latency P50 / P95 / P99** — histogram quantiles over 5 minutes. P50 is
  median experience. P99 is your worst 1%. The gap between them shows
  consistency. We'll watch P99 spike when we inject the delay anomaly.

- **Slowest Endpoints (P95)** — table sorted by P95 latency. `POST /api/v1/orders`
  appears here because it runs a DB transaction.

- **Active Users** — login rate over the last 5 minutes.

### 3b. Database Performance

**What to show:** Dashboards → Database Performance

> Query Rate by Type breaks down SELECT vs INSERT vs UPDATE over time.
> P95 Query Duration shows whether queries are getting slower. The Slow
> Queries panel will light up when we inject the slow query anomaly.
> Database Connections Over Time shows connection pool usage.

### 3c. Logs Explorer

**What to show:** Dashboards → Logs Explorer

> Every log line is structured JSON shipped to Loki by Promtail. The Level
> dropdown filters to WARNING or ERROR instantly. The Trace ID text box
> filters to a single request's logs.

Expand a log line:

> Notice the trace_id field. Grafana's derived fields feature turns it into
> a clickable link that opens the corresponding Tempo trace directly.

### 3d. Traces Explorer

**What to show:** Dashboards → Traces Explorer

> The top panel is a trace search filtered to our service. Click any row
> to open the waterfall. The Request Waterfall shows the full span hierarchy.
> The Slowest Spans table shows the top 10 spans by duration.

Click a POST /orders trace:

> Four nested spans: order.controller wraps everything. Inside it,
> order.business_logic contains order.database_query where the MySQL
> transaction runs. order.response_formatting is JSON serialisation.
> Each span carries attributes — db.item_count, order.total_price — that
> give you context without reading the code.

---

## 4. Load Testing (3 min)

Open `k6/load-test.js` briefly:

> The script has a setup phase that runs once — registers a user, gets a
> token, seeds 10 products. Then 100 virtual users run concurrently, each
> hitting GET /products and POST /orders. We stop after 5000 iterations.
> Three custom metrics track latency and error rate per endpoint separately.

Run the test:

```bash
k6 run k6/load-test.js
```

**While k6 is running, switch to Grafana:**

> Watch Requests per Minute climb to ~200. The P95 latency panel populates
> with real data. Slowest Endpoints shows POST /orders at the top.

**Switch to phpMyAdmin while k6 runs:**

> Refresh the orders table — you can watch new rows appearing in real time
> as k6 creates orders. The stock column on products is decrementing.

**After k6 finishes:**

> k6 prints a summary. P95 for GET /products should be under 500ms.
> POST /orders will be higher due to the transaction. Both
> custom_error_rate thresholds should be green.

---

## 5. Anomaly Injection (5 min)

> We have two injectable anomalies that simulate real production problems.
> Both are environment variable flags — zero overhead when disabled.

### 5a. Artificial Delay

> The first anomaly injects a random sleep between 1 and 3 seconds before
> every POST /orders request. This simulates a slow downstream dependency —
> a payment gateway, a fraud check, an external API call.

Keep k6 running. In a second terminal:

```bash
docker compose exec app sh -c \
  "echo ANOMALY_DELAY_ENABLED=true >> .env && php artisan config:clear"
```

Switch to Grafana — Application Health dashboard:

> Within 30 seconds the P95 and P99 latency panels spike visibly. Slowest
> Endpoints shows POST /orders jumping from ~100ms to 1–3 seconds. The
> error rate stays flat — requests succeed, they're just slow. That's an
> important distinction: latency degradation vs error degradation.

### 5b. Slow Query

> The second anomaly injects an inefficient database query inside the order
> creation path — a full table scan on products followed by an N+1 per-row
> fetch. This simulates a missing index or bad ORM-generated SQL.

```bash
docker compose exec app sh -c \
  "echo ANOMALY_SLOW_QUERY_ENABLED=true >> .env && php artisan config:clear"
```

Switch to Database Performance dashboard:

> P95 Query Duration spikes. The Slow Queries panel shows a non-zero rate.
> Both anomalies are now active simultaneously — this is what a real
> incident looks like before you know what's wrong.

**Show phpMyAdmin during anomaly:**

> In phpMyAdmin, run SHOW PROCESSLIST to see active queries. During the
> anomaly you'll see the full table scan query sitting in the process list
> with a non-trivial execution time.

---

## 6. Root Cause Analysis (5 min)

> We've simulated an incident. Latency is high, queries are slow. Let's
> walk through the diagnosis using the three pillars.

### Step 1 — Metrics surface the symptom

**What to show:** Application Health → Latency P95/P99

> The spike started at a specific timestamp. It's isolated to POST /orders
> — GET /products is unaffected. That immediately rules out network issues
> and connection pool exhaustion. The problem is specific to order creation.

### Step 2 — Logs give context

**What to show:** Logs Explorer → Level = WARNING

> Two types of WARNING entries appear: anomaly.delay_injected with delay_ms,
> and anomaly.slow_query_injected with duration_ms and rows_scanned. Both
> carry a trace_id. Click the trace_id on a slow_query entry.

### Step 3 — Traces show where time went

**What to show:** The Tempo waterfall that opened from the log link

> The order.controller span is 2.4 seconds total. Expand order.database_query
> — the anomaly.slow_query_duration_ms attribute shows exactly how long the
> bad query took. The anomaly.type attribute says "slow_query". The
> anomaly.delay_ms on the root span shows the artificial delay.

> In a real incident these attributes would be db.statement with the actual
> SQL, or an http.url showing which downstream service was slow. The pattern
> is identical: metrics surface the symptom, logs give timestamp and context,
> traces show exactly where the time went.

### Step 4 — Confirm in phpMyAdmin

**What to show:** http://localhost:8081

> Open the orders table and filter by created_at during the anomaly window.
> You can see the orders that were created slowly. Cross-reference the order
> IDs with the trace attributes to close the loop between database state and
> trace data.

### Step 5 — Restore baseline

```bash
docker compose exec app sh -c \
  "sed -i '/ANOMALY_/d' .env && php artisan config:clear"
```

Switch back to Grafana:

> Within one Prometheus scrape interval — about 15 seconds — latency returns
> to baseline. Slow Queries drops to zero. WARNING logs stop. The system
> is healthy again and the dashboards prove it.

---

## 7. Teardown

```bash
docker compose down -v   # removes all containers and the mysql_data volume
```

---

## Quick Reference

### Service URLs

| Service    | URL                   | Credentials       |
| ---------- | --------------------- | ----------------- |
| Web UI     | http://localhost:8080 | register / login  |
| phpMyAdmin | http://localhost:8081 | auto-login (root) |
| Grafana    | http://localhost:3000 | admin / admin     |
| Prometheus | http://localhost:9090 | —                 |
| Loki       | http://localhost:3100 | —                 |
| Tempo      | http://localhost:3200 | —                 |

### Anomaly Controls

| Variable                          | Effect                                    |
| --------------------------------- | ----------------------------------------- |
| `ANOMALY_DELAY_ENABLED=true`      | Random 1–3s delay on POST /orders         |
| `ANOMALY_DELAY_MIN_MS`            | Lower bound of delay range (default 1000) |
| `ANOMALY_DELAY_MAX_MS`            | Upper bound of delay range (default 3000) |
| `ANOMALY_SLOW_QUERY_ENABLED=true` | Full table scan + N+1 on order creation   |

### Enable an anomaly

```bash
docker compose exec app sh -c \
  "echo ANOMALY_DELAY_ENABLED=true >> .env && php artisan config:clear"
```

### Disable all anomalies

```bash
docker compose exec app sh -c \
  "sed -i '/ANOMALY_/d' .env && php artisan config:clear"
```

### Run load test

```bash
k6 run k6/load-test.js

# Against a different host
k6 run -e BASE_URL=http://your-host:8080 k6/load-test.js
```

### Useful phpMyAdmin queries during demo

```sql
-- Watch orders being created live
SELECT id, user_id, status, total_price, created_at
FROM orders ORDER BY created_at DESC LIMIT 20;

-- Check stock after load test
SELECT id, name, stock FROM products ORDER BY stock ASC;

-- See active queries during anomaly
SHOW PROCESSLIST;
```
