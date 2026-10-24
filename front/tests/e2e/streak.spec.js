import { expect, test } from '@playwright/test'
import { signIn, solve } from './helpers.js'

/**
 * The streak (docs/GAMIFICATION.md, § 4): the celebration after the day's first exercise, once, then
 * the gift wheel of the day and a streak badge's chest (docs/SHOP.md, § 4 ter); the header flame; the "streak in danger" reminder settings (docs/NOTIFICATIONS.md, § 5).
 */

/**
 * Opens the puzzle page and returns the attempt the API handed out (solution included).
 *
 * @param {import('@playwright/test').Page} page
 * @param {() => Promise<unknown>} open what opens the next puzzle
 */
async function nextPuzzle(page, open) {
  const started = page.waitForResponse(
    r =>
      r.url().endsWith('/api/puzzles/attempts') &&
      r.request().method() === 'POST'
  )
  await open()
  return (await started).json()
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {{puzzle: {fen: string, moves: string[]}}} attempt
 */
async function solveAndWait(page, attempt) {
  const submitted = page.waitForResponse(r => r.url().endsWith('/submission'))
  await solve(page, attempt.puzzle)
  await submitted
  await expect(page.getByTestId('result-sheet')).toHaveAttribute(
    'data-kind',
    'win'
  )
}

test('the first puzzle of the day is celebrated, once', async ({
  page,
  context
}) => {
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await signIn(context, { streak: true })
  const first = await nextPuzzle(page, () => page.goto('/#/puzzle'))
  // Nothing played yet today: the header flame waits.
  await expect(page.getByTestId('streak')).toHaveClass(/streak-chip--waiting/)
  await solveAndWait(page, first)

  // "Puzzle suivant": the celebration comes first.
  await page.getByTestId('puzzle-next').click()
  const celebration = page.getByTestId('streak-celebration')
  await expect(celebration).toBeVisible()
  await expect(page.getByTestId('streak-celebration-count')).toHaveText('1')
  await expect(page.getByTestId('streak-celebration-message')).toContainText(
    'Une série est née'
  )
  await expect(page.getByTestId('streak-celebration-next')).toHaveText(
    'Prochain palier : 3 jours · encore 2'
  )
  await expect(page.getByTestId('streak-celebration-badge')).toHaveCount(0)

  const acknowledged = page.waitForResponse(r =>
    r.url().endsWith('/api/gamification/streak/notice/acknowledgement')
  )
  await page.getByTestId('streak-celebration-continue').click()
  expect((await acknowledged).status()).toBe(204)
  await expect(celebration).toBeHidden()

  // Then the day's gift wheel (docs/SHOP.md, § 4 ter, played in gift-wheel.spec.js): declined.
  const wheel = page.getByTestId('gift-wheel')
  await expect(wheel).toBeVisible()
  const second = await nextPuzzle(page, () =>
    wheel.getByTestId('wheel-decline').click()
  )
  await expect(wheel).toBeHidden()
  // The flame is lit, without a reload.
  await expect(page.getByTestId('streak')).not.toHaveClass(
    /streak-chip--waiting/
  )
  await expect(page.getByTestId('streak-count')).toHaveText('1')

  // The second puzzle of the day goes straight to the next one.
  await solveAndWait(page, second)
  await nextPuzzle(page, () => page.getByTestId('puzzle-next').click())
  await expect(celebration).toHaveCount(0)
  await expect(page.getByTestId('gift-wheel')).toHaveCount(0)
})

test('a celebration left unseen shows on the dashboard, with its badge', async ({
  page,
  context
}) => {
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await signIn(context, { streak: true })
  const today = new Date().toISOString().slice(0, 10)
  let acknowledged = false
  await page.route('**/api/gamification/streak/notice', route =>
    route.fulfill({
      json: acknowledged
        ? { pending: false }
        : {
            pending: true,
            streak: 7,
            previousStreak: 6,
            badge: 'on_fire',
            week: [true, true, true, true, true, true, true],
            nextMilestone: 14,
            localDate: today
          }
    })
  )
  await page.route(
    '**/api/gamification/streak/notice/acknowledgement',
    route => {
      acknowledged = true
      return route.fulfill({ status: 204 })
    }
  )
  // The badge's day: the wheel, then 70 crystals for 7 days in the badge's chest.
  const chest = {
    id: '0192f0a0-0000-7000-8000-000000000007',
    localDate: today,
    streak: 7,
    badgeDays: 7,
    dailyCrowns: 0,
    badgeCrowns: 70,
    crowns: 70,
    opened: false,
    wheel: 'offered',
    wheelOpen: true,
    balance: 70
  }
  let chestOpened = false
  await page.route('**/api/shop/daily-rewards/unopened', route =>
    route.fulfill({ json: { member: chestOpened ? [] : [chest] } })
  )
  await page.route('**/api/shop/daily-rewards/*/open', route => {
    chestOpened = true
    return route.fulfill({ json: { ...chest, opened: true } })
  })

  await page.goto('/#/')
  await expect(page.getByTestId('streak-celebration')).toBeVisible()
  await expect(page.getByTestId('streak-celebration-count')).toHaveText('7')
  await expect(page.getByTestId('streak-celebration-badge')).toHaveText(
    'Nouveau badge : En feu'
  )
  await expect(page.getByTestId('streak-celebration-message')).toContainText(
    'Une semaine complète'
  )
  await page.getByTestId('streak-celebration-continue').click()
  await expect(page.getByTestId('streak-celebration')).toBeHidden()
  expect(acknowledged).toBe(true)

  // The wheel first; closed (✕), the badge's chest is still given.
  const wheel = page.getByTestId('gift-wheel')
  await expect(wheel).toBeVisible()
  await wheel.getByTestId('wheel-close').click()
  await expect(wheel).toBeHidden()

  const daily = page.getByTestId('daily-chest')
  await expect(daily).toBeVisible()
  await expect(daily).toContainText('7 joursde série !')
  await expect(daily.getByTestId('chest-amount')).toHaveText('+70')
  await expect(daily).toContainText('Le bonus du badge 7 jours')
  await daily.getByTestId('chest-results').click()
  await expect(daily).toBeHidden()
  expect(chestOpened).toBe(true)

  await page.reload()
  await expect(page.getByTestId('dashboard')).toBeVisible()
  await expect(page.getByTestId('streak-celebration')).toHaveCount(0)
  await expect(page.getByTestId('gift-wheel')).toHaveCount(0)
  await expect(page.getByTestId('daily-chest')).toHaveCount(0)
})

test('the streak reminder is set in the profile', async ({ page, context }) => {
  await signIn(context)
  await page.goto('/#/profile')
  const section = page.getByTestId('streak-reminder')
  const toggle = section.getByTestId('streak-reminder-toggle')
  // On by default, at 20 h, push only.
  await expect(toggle).toHaveAttribute('aria-checked', 'true')
  // The select's test id lands on its inner input.
  await expect(section.getByTestId('streak-reminder-hour')).toHaveValue('20 h')

  const saved = () =>
    page.waitForResponse(
      r =>
        r.url().endsWith('/api/gamification/streak/reminder') &&
        r.request().method() === 'PUT'
    )
  let put = saved()
  await section.getByTestId('streak-reminder-hour').click()
  await page.getByRole('option', { name: '22 h' }).click()
  expect((await (await put).json()).hour).toBe(22)

  put = saved()
  await section.getByTestId('streak-reminder-email').click()
  expect((await (await put).json()).email).toBe(true)

  put = saved()
  await toggle.click()
  expect((await (await put).json()).enabled).toBe(false)
  await expect(section.getByTestId('streak-reminder-hour')).toHaveCount(0)

  await page.reload()
  await expect(
    page.getByTestId('streak-reminder').getByTestId('streak-reminder-toggle')
  ).toHaveAttribute('aria-checked', 'false')
})
