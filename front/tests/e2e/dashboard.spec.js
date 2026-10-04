import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

/**
 * Y-m-d of today shifted by `days` (the API answers in the user's timezone; the seeded user is in
 * UTC unless the browser reports another one, and only relative positions matter here).
 *
 * @param {number} days
 * @returns {string}
 */
function day(days) {
  const d = new Date()
  d.setUTCDate(d.getUTCDate() + days)
  return d.toISOString().slice(0, 10)
}

const SCREENSHOTS = process.env.DASHBOARD_SCREENSHOTS

test('a new user is welcomed and pointed to a first session', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/')
  await expect(page.getByTestId('dashboard-welcome')).toBeVisible()
  await expect(page.getByTestId('rating-card')).toHaveCount(0)
  await expect(page.getByTestId('module-row-finales')).toContainText('Bientôt')
  await page.getByTestId('welcome-session').click()
  await expect(page).toHaveURL(/#\/session\/new$/)
})

test('with history: heatmap, rating curve, modules, and the Lichess tab of an unlinked account', async ({
  page,
  context
}) => {
  await signIn(context)
  const today = day(0)
  await page.route('**/api/dashboard/activity**', route =>
    route.fulfill({
      json: {
        timezone: 'UTC',
        from: day(-83),
        today,
        days: [
          { date: day(-2), count: 3, successCount: 2, durationMs: 90_000 },
          { date: today, count: 25, successCount: 20, durationMs: 600_000 }
        ],
        totals: {
          puzzle_rated: { count: 28, successCount: 22, durationMs: 690_000 }
        }
      }
    })
  )
  await page.route('**/api/dashboard/rating-history**', route =>
    route.fulfill({
      json: {
        from: day(-89),
        today,
        points: [
          { date: day(-89), rating: 1500 },
          { date: day(-30), rating: 1580 },
          { date: day(-2), rating: 1612 }
        ]
      }
    })
  )

  await page.goto('/#/')
  await expect(page.getByTestId('dashboard')).toBeVisible()
  await expect(page.getByTestId('dashboard-welcome')).toHaveCount(0)

  const heatmap = page.getByTestId('activity-heatmap')
  await expect(heatmap.locator(`[data-date="${today}"]`)).toHaveAttribute(
    'data-level',
    '4'
  )
  await expect(heatmap.locator(`[data-date="${day(-2)}"]`)).toHaveAttribute(
    'data-level',
    '1'
  )
  await expect(heatmap).toContainText('2 jours actifs')

  await expect(page.getByTestId('rating-current')).toHaveText('1612')
  await expect(page.getByTestId('rating-delta')).toContainText('+112')
  await expect(page.getByTestId('module-row-puzzles')).toContainText(
    '22 résolus'
  )
  await expect(page.getByTestId('showcase-tag').first()).toBeVisible()

  if (SCREENSHOTS) {
    await page.screenshot({
      path: `${SCREENSHOTS}/dashboard-desktop.png`,
      fullPage: true
    })
    await page.setViewportSize({ width: 390, height: 844 })
    await page.screenshot({
      path: `${SCREENSHOTS}/dashboard-mobile.png`,
      fullPage: true
    })
    await page.setViewportSize({ width: 1280, height: 720 })
  }

  // The seeded user has no Lichess account: no curve, a link to the profile, nothing asked of Lichess.
  await page.getByTestId('rating-tab-blitz').click()
  await expect(page.getByTestId('rating-notice')).toContainText(
    'Lie ton compte Lichess'
  )
  await page.getByTestId('rating-tab-puzzles').click()
  await expect(page.getByTestId('rating-current')).toHaveText('1612')
})
