# Requirements Document

## Introduction

The Laravel Observability Demo is a backend application built with Laravel 12 and MySQL that demonstrates production-grade observability practices. The system exposes a REST API covering user registration, authentication, product management, and order creation. Every layer of the application is instrumented to emit metrics (Prometheus), structured logs (Loki), and distributed traces (Tempo), all visualised through Grafana dashboards. The full stack runs via Docker Compose. A k6 load-testing script drives at least 5 000 requests against the API, and two deliberate anomalies — an artificial request delay and an inefficient database query — are injected to validate that the observability stack detects and surfaces degradation.

## Glossary

- **Application**: The Laravel 12 PHP backend service.
- **API**: The HTTP REST interface exposed by the Application.
- **User**: An authenticated human actor interacting with the API.
- **Product**: A catalogue item managed through CRUD operations.
- **Order**: A purchase record linking a User to one or more Products.
- **Metrics_Collector**: The Prometheus scrape target embedded in the Application.
- **Log_Shipper**: The Promtail agent that forwards Application log files to Loki.
- **Tracer**: The OpenTelemetry SDK instance inside the Application that emits spans to Tempo.
- **Grafana**: The dashboard and visualisation service.
- **Prometheus**: The time-series metrics storage and scrape engine.
- **Loki**: The log aggregation backend.
- **Tempo**: The distributed tracing backend.
- **Load_Runner**: The k6 script that generates synthetic traffic.
- **Anomaly_Injector**: The middleware and query components that introduce artificial degradation.
- **Paginator**: The Application component that returns paginated result sets.
- **Validator**: The Application component that enforces input rules on incoming requests.
- **Auth_Service**: The Application component responsible for registration and login.
- **Product_Service**: The Application component responsible for Product CRUD.
- **Order_Service**: The Application component responsible for Order creation.

---

## Requirements

### Requirement 1: User Registration

**User Story:** As a new visitor, I want to register an account, so that I can authenticate and use the API.

#### Acceptance Criteria

1. WHEN a POST request is received at `/api/v1/register` with a valid `name`, `email`, and `password`, THE Auth_Service SHALL create a User record and return a 201 response containing a bearer token and the created User's `id`, `name`, and `email`.
2. WHEN a registration request is received with an `email` that already exists in the database, THE Auth_Service SHALL return a 422 response with a field-level validation error identifying the duplicate email.
3. WHEN a registration request is received with a `password` shorter than 8 characters, THE Validator SHALL return a 422 response with a field-level error on the `password` field.
4. WHEN a registration request is received with a missing or malformed `email`, THE Validator SHALL return a 422 response with a field-level error on the `email` field.
5. WHEN a User is successfully registered, THE Tracer SHALL emit a span named `auth.register` containing the attributes `user.id` and `http.status_code`.
6. WHEN a User is successfully registered, THE Metrics_Collector SHALL increment the counter `app_user_registrations_total`.

---

### Requirement 2: User Login

**User Story:** As a registered user, I want to log in, so that I can receive a bearer token for subsequent requests.

#### Acceptance Criteria

1. WHEN a POST request is received at `/api/v1/login` with a valid `email` and `password` matching an existing User, THE Auth_Service SHALL return a 200 response containing a bearer token.
2. WHEN a login request is received with credentials that do not match any User, THE Auth_Service SHALL return a 401 response with an error message.
3. WHEN a login request is received with a missing `email` or `password` field, THE Validator SHALL return a 422 response with field-level errors.
4. WHEN a User successfully logs in, THE Tracer SHALL emit a span named `auth.login` containing the attribute `http.status_code`.
5. WHEN a User successfully logs in, THE Metrics_Collector SHALL increment the counter `app_user_logins_total`.

---

### Requirement 3: Product CRUD

**User Story:** As an authenticated user, I want to create, read, update, and delete products, so that I can manage the product catalogue.

#### Acceptance Criteria

1. WHEN an authenticated POST request is received at `/api/v1/products` with valid `name`, `description`, `price`, and `stock` fields, THE Product_Service SHALL persist the Product and return a 201 response with the created Product resource.
2. WHEN an authenticated GET request is received at `/api/v1/products`, THE Product_Service SHALL return a 200 response containing a paginated list of Products.
3. WHEN an authenticated GET request is received at `/api/v1/products/{id}` for an existing Product, THE Product_Service SHALL return a 200 response with the Product resource.
4. WHEN an authenticated GET request is received at `/api/v1/products/{id}` for a non-existent Product, THE Product_Service SHALL return a 404 response.
5. WHEN an authenticated PUT request is received at `/api/v1/products/{id}` with valid fields, THE Product_Service SHALL update the Product and return a 200 response with the updated resource.
6. WHEN an authenticated DELETE request is received at `/api/v1/products/{id}` for an existing Product, THE Product_Service SHALL soft-delete the Product and return a 204 response.
7. WHEN a product write request is received with a `price` value less than 0, THE Validator SHALL return a 422 response with a field-level error on the `price` field.
8. WHEN a product write request is received with a `stock` value less than 0, THE Validator SHALL return a 422 response with a field-level error on the `stock` field.
9. WHEN any Product endpoint is called, THE Tracer SHALL emit a span named `product.<operation>` where `<operation>` is one of `create`, `list`, `show`, `update`, or `delete`, containing the attribute `product.id` where applicable.
10. WHEN any Product endpoint is called, THE Metrics_Collector SHALL increment the counter `app_product_requests_total` labelled with `operation` and `status`.

---

### Requirement 4: Order Creation

**User Story:** As an authenticated user, I want to create an order, so that I can purchase products.

#### Acceptance Criteria

1. WHEN an authenticated POST request is received at `/api/v1/orders` with a valid `items` array where each item contains a `product_id` and `quantity`, THE Order_Service SHALL persist the Order and its line items, decrement Product stock accordingly, and return a 201 response with the Order resource including a computed `total_price`.
2. WHEN an order creation request references a `product_id` that does not exist, THE Order_Service SHALL return a 422 response identifying the invalid product.
3. WHEN an order creation request specifies a `quantity` that exceeds the available `stock` for a Product, THE Order_Service SHALL return a 422 response with a stock-availability error.
4. WHEN an order creation request is received with an empty `items` array, THE Validator SHALL return a 422 response with a field-level error on the `items` field.
5. WHEN an Order is successfully created, THE Tracer SHALL emit a span named `order.create` containing the attributes `order.id`, `order.item_count`, and `order.total_price`.
6. WHEN an Order is successfully created, THE Metrics_Collector SHALL increment the counter `app_orders_created_total` and record the order value in the histogram `app_order_value_dollars`.
7. WHEN an Order is successfully created, THE Order_Service SHALL wrap the stock decrement and order persistence in a single database transaction, and IF the transaction fails, THEN THE Order_Service SHALL roll back all changes and return a 500 response.

---

### Requirement 5: Pagination

**User Story:** As an API consumer, I want paginated responses for list endpoints, so that I can retrieve large datasets efficiently.

#### Acceptance Criteria

1. WHEN a GET request is received at a list endpoint with a `per_page` query parameter between 1 and 100, THE Paginator SHALL return a result set limited to that page size.
2. WHEN a GET request is received at a list endpoint with a `page` query parameter, THE Paginator SHALL return the corresponding page of results.
3. WHEN a GET request is received at a list endpoint without pagination parameters, THE Paginator SHALL default to `page=1` and `per_page=15`.
4. THE Paginator SHALL include `current_page`, `per_page`, `total`, `last_page`, `next_page_url`, and `prev_page_url` fields in every paginated response envelope.
5. WHEN a `per_page` value greater than 100 is supplied, THE Validator SHALL cap the value at 100 and return results accordingly.

---

### Requirement 6: Filtering

**User Story:** As an API consumer, I want to filter product and order lists, so that I can retrieve relevant subsets of data.

#### Acceptance Criteria

1. WHEN a GET request to `/api/v1/products` includes a `search` query parameter, THE Product_Service SHALL return only Products whose `name` or `description` contains the search string (case-insensitive).
2. WHEN a GET request to `/api/v1/products` includes a `min_price` or `max_price` query parameter, THE Product_Service SHALL return only Products whose `price` falls within the specified range.
3. WHEN a GET request to `/api/v1/orders` includes a `status` query parameter, THE Order_Service SHALL return only Orders matching that status.
4. WHEN a GET request to `/api/v1/orders` includes `created_from` or `created_to` date parameters, THE Order_Service SHALL return only Orders whose `created_at` falls within the specified range.
5. WHEN filter parameters are combined with pagination parameters, THE Paginator SHALL apply filters before paginating and reflect the filtered `total` in the response envelope.

---

### Requirement 7: Metrics Instrumentation

**User Story:** As a platform engineer, I want the application to expose Prometheus metrics, so that I can monitor system health and performance.

#### Acceptance Criteria

1. THE Metrics_Collector SHALL expose a `/metrics` endpoint in Prometheus text format that Prometheus can scrape.
2. THE Metrics_Collector SHALL record the histogram `http_request_duration_seconds` labelled with `method`, `route`, and `status_code` for every HTTP request processed by the Application.
3. THE Metrics_Collector SHALL maintain the gauge `app_active_requests` reflecting the number of requests currently being processed.
4. THE Metrics_Collector SHALL expose the counter `app_db_queries_total` labelled with `query_type` (`select`, `insert`, `update`, `delete`) incremented for every database query executed.
5. WHEN the Application starts, THE Metrics_Collector SHALL expose the gauge `app_info` labelled with `version` and `environment`.
6. THE Metrics_Collector SHALL expose the histogram `app_db_query_duration_seconds` labelled with `query_type` recording the execution time of every database query.

---

### Requirement 8: Structured Logging

**User Story:** As a platform engineer, I want structured JSON logs shipped to Loki, so that I can search and correlate log events.

#### Acceptance Criteria

1. THE Application SHALL emit all log entries in JSON format containing at minimum the fields `timestamp`, `level`, `message`, `trace_id`, `span_id`, `environment`, and `service`.
2. WHEN an HTTP request is received, THE Application SHALL log a structured entry at `INFO` level containing `method`, `uri`, `status_code`, `duration_ms`, and `user_id` (if authenticated).
3. WHEN an unhandled exception occurs, THE Application SHALL log a structured entry at `ERROR` level containing `exception_class`, `message`, `file`, `line`, and `trace_id`.
4. WHEN a database query exceeds 500 ms, THE Application SHALL log a structured entry at `WARNING` level containing `query`, `bindings`, `duration_ms`, and `trace_id`.
5. THE Log_Shipper SHALL forward all Application log files from the Laravel `storage/logs` directory to Loki with the labels `app`, `environment`, and `level`.
6. FOR ALL log entries that are part of a traced request, THE Application SHALL include the same `trace_id` in both the log entry and the active Tracer span, enabling log-to-trace correlation in Grafana.

---

### Requirement 9: Distributed Tracing

**User Story:** As a platform engineer, I want distributed traces sent to Tempo, so that I can analyse request latency and identify bottlenecks.

#### Acceptance Criteria

1. THE Tracer SHALL instrument every incoming HTTP request as a root span with attributes `http.method`, `http.route`, `http.status_code`, and `http.url`.
2. THE Tracer SHALL create child spans for every database query, labelled with `db.statement`, `db.system` (`mysql`), and `db.duration_ms`.
3. THE Tracer SHALL propagate trace context via the `traceparent` HTTP header on all outbound HTTP calls made by the Application.
4. THE Tracer SHALL export spans to Tempo using the OTLP/gRPC protocol.
5. WHEN a span is completed, THE Tracer SHALL set the span status to `ERROR` and record the exception if the operation resulted in an unhandled exception.
6. THE Tracer SHALL sample 100% of requests in the `local` and `staging` environments and 10% of requests in the `production` environment.

---

### Requirement 10: Anomaly Injection — Artificial Delay

**User Story:** As a demo operator, I want to inject artificial latency into specific requests, so that I can demonstrate how the observability stack detects slow endpoints.

#### Acceptance Criteria

1. WHEN the `ANOMALY_DELAY_ENABLED` environment variable is set to `true`, THE Anomaly_Injector SHALL introduce a configurable sleep of `ANOMALY_DELAY_MS` milliseconds (default 2000) before the response is returned for the affected route.
2. WHEN the artificial delay is active, THE Metrics_Collector SHALL record the inflated duration in `http_request_duration_seconds`, causing the P99 latency to exceed the configured threshold.
3. WHEN the artificial delay is active, THE Tracer SHALL include a span attribute `anomaly.delay_ms` on the affected root span.
4. WHEN the artificial delay is active, THE Application SHALL log a `WARNING` entry with the message `"Anomaly delay injected"` and the field `delay_ms`.
5. WHERE `ANOMALY_DELAY_ENABLED` is `false` or absent, THE Anomaly_Injector SHALL introduce no delay and add no overhead to request processing.

---

### Requirement 11: Anomaly Injection — Inefficient Query

**User Story:** As a demo operator, I want to trigger an inefficient database query, so that I can demonstrate how the observability stack surfaces slow queries.

#### Acceptance Criteria

1. WHEN the `ANOMALY_SLOW_QUERY_ENABLED` environment variable is set to `true`, THE Anomaly_Injector SHALL execute a non-indexed full-table scan on the `orders` table when the product list endpoint is called.
2. WHEN the inefficient query executes, THE Metrics_Collector SHALL record its duration in `app_db_query_duration_seconds`, causing the P95 query duration to exceed 500 ms under load.
3. WHEN the inefficient query executes, THE Tracer SHALL create a child span named `db.slow_query` with the attribute `db.statement` containing the executed SQL.
4. WHEN the inefficient query duration exceeds 500 ms, THE Application SHALL emit a `WARNING` log entry with the field `anomaly` set to `"slow_query"` and `duration_ms` set to the actual duration.
5. WHERE `ANOMALY_SLOW_QUERY_ENABLED` is `false` or absent, THE Anomaly_Injector SHALL not execute the inefficient query.

---

### Requirement 12: Docker Infrastructure

**User Story:** As a developer, I want the full stack to run with a single Docker Compose command, so that I can reproduce the demo environment locally.

#### Acceptance Criteria

1. THE Application SHALL be packaged as a Docker image built from a `Dockerfile` in the repository root using a multi-stage build with a PHP 8.3-FPM base image.
2. THE Docker Compose file SHALL define services for: `app` (Laravel), `nginx`, `mysql`, `prometheus`, `loki`, `tempo`, `grafana`, and `promtail`.
3. WHEN `docker compose up` is executed, ALL services SHALL reach a healthy state within 120 seconds as determined by their respective Docker health checks.
4. THE `mysql` service SHALL persist data using a named Docker volume so that data survives container restarts.
5. THE `prometheus` service SHALL be pre-configured with a scrape job targeting the Application's `/metrics` endpoint at a 15-second interval.
6. THE `grafana` service SHALL be pre-provisioned with Prometheus, Loki, and Tempo as data sources and SHALL load dashboard JSON files from a `./docker/grafana/dashboards` directory on startup.
7. WHEN the `app` container starts, THE Application SHALL automatically run database migrations before accepting traffic.

---

### Requirement 13: Load Testing

**User Story:** As a demo operator, I want a k6 load-testing script, so that I can generate realistic traffic and populate the observability dashboards.

#### Acceptance Criteria

1. THE Load_Runner SHALL execute a scenario that generates a minimum of 5 000 HTTP requests against the API within a single test run.
2. THE Load_Runner SHALL exercise the following endpoints in sequence: register, login, create product, list products, create order, and list orders.
3. THE Load_Runner SHALL assert that the HTTP status code for each request matches the expected value, and SHALL report assertion failures as k6 thresholds.
4. THE Load_Runner SHALL define a threshold such that 95% of requests complete within 2 000 ms.
5. THE Load_Runner SHALL define a threshold such that the error rate remains below 1%.
6. THE Load_Runner SHALL parameterise the target base URL via the `BASE_URL` environment variable, defaulting to `http://localhost:8080`.
7. WHEN the load test completes, THE Load_Runner SHALL output a summary including `http_req_duration` P50, P95, and P99 values and the total request count.

---

### Requirement 14: Grafana Dashboards

**User Story:** As a platform engineer, I want pre-built Grafana dashboards, so that I can immediately visualise application health during and after the load test.

#### Acceptance Criteria

1. THE Grafana instance SHALL include an "Application Overview" dashboard displaying: request rate (RPS), error rate (%), P50/P95/P99 latency, active requests gauge, and total registrations and orders counters.
2. THE Grafana instance SHALL include a "Database Performance" dashboard displaying: query rate by type, P95 query duration histogram, slow query count over time, and active DB connections.
3. THE Grafana instance SHALL include a "Logs Explorer" panel pre-configured to query Loki for Application logs filterable by `level` and `trace_id`.
4. THE Grafana instance SHALL include a "Traces Explorer" panel pre-configured to query Tempo and link trace IDs found in Loki log entries directly to the corresponding Tempo trace.
5. WHEN an anomaly is active, THE "Application Overview" dashboard SHALL visually surface the degradation through a latency spike visible in the P99 panel within 30 seconds of the anomaly being enabled.
