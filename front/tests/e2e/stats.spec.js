import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

/**
 * Statistics page (docs/DASHBOARD.md § 6, lot B): reached from the home page, period picker,
 * training-time chart, sessions, themes, repertoire health. The dashboard API is simulated.
 */

/** @param {number} days Y-m-d of today shifted by `days` */
function day(days) {
  const d = new Date()
  d.setUTCDate(d.getUTCDate() + days)
  return d.toISOString().slice(0, 10)
}

const MIN = 60_000

const THEMES = {
  from: day(-29),
  today: day(0),
  attempts: 20,
  successCount: 11,
  minAttempts: 5,
  themes: [
    { key: 'fork', attempts: 6, successCount: 5, successRate: 0.8333 },
    { key: 'endgame', attempts: 5, successCount: 3, successRate: 0.6 },
    { key: 'pin', attempts: 5, successCount: 1, successRate: 0.2 }
  ],
  strong: [
    { key: 'fork', attempts: 6, successCount: 5, successRate: 0.8333 },
    { key: 'endgame', attempts: 5, successCount: 3, successRate: 0.6 }
  ],
  weak: [{ key: 'pin', attempts: 5, successCount: 1, successRate: 0.2 }]
}

/**
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<number[]>} the `days` asked to /dashboard/training, in order
 */
async function simulateStats(page) {
  /** @type {number[]} */
  const asked = []
  await page.route('**/api/dashboard/activity**', route =>
    route.fulfill({
      json: {
        timezone: 'UTC',
        from: day(-83),
        today: day(0),
        days: [{ date: day(0), count: 3, successCount: 2, durationMs: 90_000 }],
        totals: {
          puzzle_rated: { count: 3, successCount: 2, durationMs: 90_000 }
        }
      }
    })
  )
  await page.route('**/api/dashboard/themes**', route =>
    route.fulfill({ json: THEMES })
  )
  await page.route('**/api/dashboard/training**', route => {
    asked.push(Number(new URL(route.request().url()).searchParams.get('days')))
    return route.fulfill({
      json: {
        from: day(-29),
        today: day(0),
        weeks: [
          {
            start: '2026-09-07',
            durationMs: { woodpecker: 0, repertoire: 0, puzzles: 0, free: 0 }
          },
          {
            start: '2026-09-14',
            durationMs: {
              woodpecker: 15 * MIN,
              repertoire: 10 * MIN,
              puzzles: 30 * MIN,
              free: 0
            }
          },
          {
            start: '2026-09-21',
            durationMs: {
              woodpecker: 0,
              repertoire: 0,
              puzzles: 20 * MIN,
              free: 45 * MIN
            }
          }
        ],
        totals: {
          woodpecker: { count: 10, durationMs: 15 * MIN },
          repertoire: { count: 8, durationMs: 10 * MIN },
          puzzles: { count: 40, durationMs: 50 * MIN },
          free: { count: 1, durationMs: 45 * MIN }
        },
        sessions: {
          closed: 3,
          completed: 2,
          abandoned: 1,
          expired: 0,
          playedMs: 90 * MIN,
          averageMs: 30 * MIN
        }
      }
    })
  })
  await page.route('**/api/dashboard/repertoire**', route =>
    route.fulfill({
      json: {
        from: day(-29),
        today: day(0),
        repertoires: 1,
        cards: { total: 40, new: 5, due: 7 },
        tests: { total: 12, succeeded: 9, successRate: 0.75 },
        fragile: [
          {
            repertoireId: '0192f0c4-0000-7000-8000-000000000001',
            repertoireName: 'Italienne',
            color: 'white',
            segmentId: '0192f0c4-0000-7000-8000-000000000002',
            label: {
              opening: { eco: 'B20', name: 'Sicilian Defense' },
              move: '1…c5'
            },
            tests: 4,
            recentFailureRate: 0.75
          }
        ]
      }
    })
  )
  return asked
}

test('from the home page: the weak-theme tip, then the statistics over a remembered period', async ({
  page,
  context
}) => {
  await signIn(context)
  const asked = await simulateStats(page)
  await page.goto('/#/')

  const tip = page.getByTestId('weak-theme-tip')
  await expect(tip).toContainText('Clouage')
  await expect(tip).toContainText('20 % de réussite')

  await page.getByTestId('dashboard-stats').click()
  await expect(page).toHaveURL(/#\/stats$/)
  await expect(page.getByTestId('period-30')).toHaveAttribute(
    'aria-checked',
    'true'
  )

  // Training time: three weeks, the empty one included; totals in the legend.
  const chart = page.getByTestId('training-time')
  await expect(chart.getByTestId('training-bar')).toHaveCount(3)
  await expect(chart.getByTestId('training-total')).toHaveText('2 h')
  await expect(chart.getByTestId('training-module-puzzles')).toContainText(
    '50 min'
  )
  await chart.getByTestId('training-bar').nth(1).hover()
  const tooltip = chart.getByTestId('training-tip')
  await expect(tooltip).toContainText('Semaine du 14 sept.')
  await expect(tooltip).toContainText('Total')
  await expect(tooltip).toContainText('55 min')
  await chart.getByTestId('training-table-toggle').click()
  await expect(
    chart.getByTestId('training-table').locator('tbody tr')
  ).toHaveCount(3)

  await expect(page.getByTestId('sessions-completed')).toContainText('2')
  await expect(page.getByTestId('sessions-average')).toContainText('30 min')

  await expect(page.getByTestId('themes-strong')).toContainText('Fourchette')
  await expect(page.getByTestId('themes-weak')).toContainText('Clouage')

  const health = page.getByTestId('repertoire-health')
  await expect(health.getByTestId('health-due')).toContainText('7')
  await expect(health.getByTestId('health-fragile')).toContainText(
    'Sicilian Defense · 1…c5'
  )

  // Another period: asked again, and remembered after a reload.
  await page.getByTestId('period-90').click()
  await expect(page.getByTestId('period-90')).toHaveAttribute(
    'aria-checked',
    'true'
  )
  await expect.poll(() => asked.at(-1)).toBe(90)
  await page.reload()
  await expect(page.getByTestId('period-90')).toHaveAttribute(
    'aria-checked',
    'true'
  )
  await expect.poll(() => asked.at(-1)).toBe(90)

  // A weak theme opens the rated puzzles filtered on it.
  await page.getByTestId('theme-train-pin').click()
  await expect(page).toHaveURL(/#\/puzzle$/)
  await expect(page.getByTestId('puzzle-theme-filter')).toContainText('Clouage')
})

test('a new user sees empty blocks, not broken charts', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/stats')
  await expect(page.getByTestId('training-empty')).toBeVisible()
  await expect(page.getByTestId('themes-empty')).toBeVisible()
  await expect(page.getByTestId('health-empty')).toBeVisible()
  await expect(page.getByTestId('sessions-completed')).toContainText('0')
})
