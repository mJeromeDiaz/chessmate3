import { defineConfig, devices } from '@playwright/test'

/**
 * End-to-end tests against the real API (APP_ENV=e2e: its own database, ChessMateGo_e2e) and the
 * Quasar dev server. Prepare the database once with `npm run e2e:prepare`.
 */
const API_DIR = '../api'
// Dedicated ports: the usual dev servers (8000, 9000) may be running meanwhile.
const API_PORT = 8100
const FRONT_PORT = 9100

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  timeout: 60_000,
  use: {
    baseURL: `http://localhost:${FRONT_PORT}`,
    trace: 'retain-on-failure'
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: [
    {
      // EGPCS: the built-in server must expose APP_ENV to Symfony ($_ENV), or it boots in dev.
      // index.php as the router script: without one, the built-in server answers a 404 of its own
      // to any missing path with an extension (the calendar's `.ics` routes) instead of Symfony.
      command: `APP_ENV=e2e php -d xdebug.mode=off -d variables_order=EGPCS -S localhost:${API_PORT} -t ${API_DIR}/public ${API_DIR}/public/index.php`,
      url: `http://localhost:${API_PORT}/api/puzzles/themes`,
      // 401 without a token: the API is up.
      ignoreHTTPSErrors: true,
      reuseExistingServer: false,
      timeout: 60_000
    },
    {
      command: `API_URL=http://localhost:${API_PORT} npx quasar dev --port ${FRONT_PORT}`,
      url: `http://localhost:${FRONT_PORT}`,
      reuseExistingServer: false,
      timeout: 120_000
    }
  ]
})
