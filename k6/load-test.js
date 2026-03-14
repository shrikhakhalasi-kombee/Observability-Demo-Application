import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

// ---------------------------------------------------------------------------
// Custom metrics
// ---------------------------------------------------------------------------
const errorRate        = new Rate('custom_error_rate');
const getProductsLatency = new Trend('custom_get_products_latency_ms', true);
const postOrderLatency   = new Trend('custom_post_order_latency_ms', true);
const totalRequests      = new Counter('custom_total_requests');

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
const BASE_URL = __ENV.BASE_URL || 'http://localhost:8083';

// Shared token + product pool populated in setup()
let sharedToken   = null;
let productIds    = [];

// ---------------------------------------------------------------------------
// Options: 100 VUs, minimum 5000 iterations, 5-minute cap
// ---------------------------------------------------------------------------
export const options = {
  scenarios: {
    load: {
      executor: 'shared-iterations',
      vus: 100,
      iterations: 5000,
      maxDuration: '5m',
    },
  },
  thresholds: {
    // Built-in k6 metrics
    http_req_duration:              ['p(95)<2000'],
    http_req_failed:                ['rate<0.05'],
    // Custom metrics
    custom_error_rate:              ['rate<0.05'],
    custom_get_products_latency_ms: ['p(95)<2000'],
    custom_post_order_latency_ms:   ['p(95)<3000'],
  },
};

// ---------------------------------------------------------------------------
// setup(): runs once before the test — register a user, seed product IDs
// ---------------------------------------------------------------------------
export function setup() {
  const headers = { 'Content-Type': 'application/json' };

  // Register a dedicated load-test user
  const email    = `loadtest_${Date.now()}@example.com`;
  const password = 'password123';

  const regRes = http.post(
    `${BASE_URL}/api/v1/register`,
    JSON.stringify({ name: 'Load Test', email, password }),
    { headers }
  );

  console.log(`setup: register status=${regRes.status} body=${regRes.body.substring(0, 200)}`);

  let token = null;

  if (regRes.status === 201) {
    token = JSON.parse(regRes.body)?.token ?? null;
  }

  // Fall back to login if register didn't return a token
  if (!token) {
    console.log(`setup: register failed or no token, trying login...`);
    const loginRes = http.post(
      `${BASE_URL}/api/v1/login`,
      JSON.stringify({ email, password }),
      { headers }
    );
    console.log(`setup: login status=${loginRes.status} body=${loginRes.body.substring(0, 200)}`);
    if (loginRes.status === 200) {
      token = JSON.parse(loginRes.body)?.token ?? null;
    }
  }

  if (!token) {
    console.error(`setup: could not obtain auth token — aborting. BASE_URL=${BASE_URL}`);
    return { token: null, productIds: [] };
  }

  const authHeaders = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

  // Create a small pool of products so POST /orders always has valid IDs
  const ids = [];
  for (let i = 0; i < 10; i++) {
    const pRes = http.post(
      `${BASE_URL}/api/v1/products`,
      JSON.stringify({
        name:        `LoadTest Product ${i}`,
        description: 'k6 seed product',
        price:       (Math.random() * 99 + 1).toFixed(2),
        stock:       9999,
      }),
      { headers: authHeaders }
    );
    if (pRes.status === 201) {
      const id = JSON.parse(pRes.body)?.data?.id;
      if (id) ids.push(id);
    }
  }

  console.log(`setup: token obtained, ${ids.length} seed products created`);
  return { token, productIds: ids };
}

// ---------------------------------------------------------------------------
// Default function: runs for every VU iteration
// ---------------------------------------------------------------------------
export default function (data) {
  if (!data.token) {
    sleep(1);
    return;
  }

  const authHeaders = {
    'Content-Type':  'application/json',
    Authorization:   `Bearer ${data.token}`,
  };

  // --- GET /api/v1/products -------------------------------------------
  const getRes = http.get(
    `${BASE_URL}/api/v1/products?page=1&per_page=15`,
    { headers: authHeaders, tags: { endpoint: 'GET /products' } }
  );

  const getOk = check(getRes, {
    'GET /products: status 200': (r) => r.status === 200,
    'GET /products: has data':   (r) => JSON.parse(r.body)?.data !== undefined,
  });

  getProductsLatency.add(getRes.timings.duration);
  errorRate.add(!getOk);
  totalRequests.add(1);

  // --- POST /api/v1/orders -------------------------------------------
  // Pick a random product from the seeded pool
  const productId = data.productIds[Math.floor(Math.random() * data.productIds.length)];

  const orderRes = http.post(
    `${BASE_URL}/api/v1/orders`,
    JSON.stringify({
      items: [{ product_id: productId, quantity: 1 }],
    }),
    { headers: authHeaders, tags: { endpoint: 'POST /orders' } }
  );

  const orderOk = check(orderRes, {
    'POST /orders: status 201': (r) => r.status === 201,
    'POST /orders: has order id': (r) => JSON.parse(r.body)?.data?.id !== undefined,
  });

  postOrderLatency.add(orderRes.timings.duration);
  errorRate.add(!orderOk);
  totalRequests.add(1);

  sleep(0.5);
}

// ---------------------------------------------------------------------------
// teardown(): summary log
// ---------------------------------------------------------------------------
export function teardown(data) {
  console.log(`teardown: test complete. Token used: ${data.token ? 'yes' : 'no'}`);
}
