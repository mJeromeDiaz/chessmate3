import { defineConfig, devices } from '@playwright/test'

/**
 * End-to-end tests against the real API (APP_ENV=e2e: its own database, ChessMateGo_e2e) and the
 * Quasar dev server. Prepare the database once with `npm run e2e:prepare`.
 */
const API_DIR = '../api'

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  timeout: 60_000,
  use: {
    baseURL: 'http://localhost:9000',
    trace: 'retain-on-failure'
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: [
    {
      // EGPCS: the built-in server must expose APP_ENV to Symfony ($_ENV), or it boots in dev.
      command: `APP_ENV=e2e php -d xdebug.mode=off -d variables_order=EGPCS -S localhost:8000 -t ${API_DIR}/public`,
      url: 'http://localhost:8000/api/puzzles/themes',
      // 401 without a token: the API is up.
      ignoreHTTPSErrors: true,
      reuseExistingServer: false,
      timeout: 60_000
    },
    {
      command: 'API_URL=http://localhost:8000 npx quasar dev --port 9000',
      url: 'http://localhost:9000',
      reuseExistingServer: false,
      timeout: 120_000
    }
  ]
})
