import { expect, test } from '@playwright/test'
import { signIn, solve } from './helpers.js'

/**
 * The gift wheel (docs/SHOP.md, § 4 ter): offered after the celebration of the day's first
 * exercise, drawn by the server under the admin's odds (set through the « Gains » tab, so each
 * test knows its prize), crystals counted up, a gift's three chests, the skull, « Relancer » with a
 * ticket bought in place, the ticket spun from the store, « Ne plus me proposer » and the profile's
 * setting. Reduced motion: no rotation, a chest opens at the first tap.
 */

const DEFAULT_ODDS = {
  c5: 75,
  c30: 12,
  c300: 1,
  gift: 7,
  lose: 5,
  freezePercent: 50
}

/** The odds were changed by the test: put back after it (the e2e database is shared). */
let oddsChanged = false

/**
 * Sets the wheel's odds as an admin, through the « Gains » tab.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {Partial<typeof DEFAULT_ODDS>} odds the weights given, the others 0
 */
async function setOdds(browser, odds) {
  const values = {
    c5: 0,
    c30: 0,
    c300: 0,
    gift: 0,
    lose: 0,
    freezePercent: 50,
    ...odds
  }
  const context = await browser.newContext()
  try {
    const page = await context.newPage()
    await signIn(context, { admin: true })
    await page.goto('/#/admin/boutique')
    await page.getByTestId('shop-admin-rewards').click()
    const form = page.getByTestId('wheel-rule')
    // Quasar puts the test id on the native input itself.
    for (const [field, value] of Object.entries(values)) {
      await form.getByTestId(`wheel-rule-${field}`).fill(String(value))
    }
    const saved = page.waitForResponse(
      r =>
        r.url().endsWith('/api/admin/shop/wheel-rule') &&
        r.request().method() === 'PUT'
    )
    await form.getByTestId('wheel-rule-save').click()
    expect((await saved).status()).toBe(200)
  } finally {
    await context.close()
  }
  oddsChanged = true
}

test.afterEach(async ({ browser }) => {
  if (!oddsChanged) return
  await setOdds(browser, DEFAULT_ODDS)
  oddsChanged = false
})

/**
 * Solves the puzzle page's first puzzle, then « Puzzle suivant »: the streak's celebration,
 * acknowledged; the day's wheel comes next.
 *
 * @param {import('@playwright/test').Page} page
 */
async function firstPuzzleOfTheDay(page) {
  const started = page.waitForResponse(
    r =>
      r.url().endsWith('/api/puzzles/attempts') &&
      r.request().method() === 'POST'
  )
  await page.goto('/#/puzzle')
  const attempt = await (await started).json()
  const submitted = page.waitForResponse(r => r.url().endsWith('/submission'))
  await solve(page, attempt.puzzle)
  await submitted
  await expect(page.getByTestId('result-sheet')).toHaveAttribute(
    'data-kind',
    'win'
  )
  await page.getByTestId('puzzle-next').click()
  await page.getByTestId('streak-celebration-continue').click()
  const wheel = page.getByTestId('gift-wheel')
  await expect(wheel).toBeVisible()
  return wheel
}

/**
 * Touches the wheel's centre and returns the server's draw.
 *
 * @param {import('@playwright/test').Page} page
 * @param {() => Promise<unknown>} [spin] what spins it (default: the centre)
 */
async function spun(page, spin) {
  const drawn = page.waitForResponse(
    r =>
      r.url().endsWith('/api/shop/wheel/spins') &&
      r.request().method() === 'POST'
  )
  await (spin ? spin() : page.getByTestId('wheel-spin').click())
  const response = await drawn
  expect(response.status()).toBe(200)
  return response.json()
}

test('the day’s wheel after the celebration: 30 crystals, credited', async ({
  page,
  context,
  browser
}) => {
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await signIn(context, { streak: true })
  await setOdds(browser, { c30: 1 })

  const wheel = await firstPuzzleOfTheDay(page)
  await expect(wheel).toContainText('Roue des cadeaux')
  await expect(wheel.getByTestId('wheel-decline')).toBeVisible()
  const drawn = await spun(page)
  expect([drawn.prize, drawn.crowns, drawn.ticket]).toEqual(['c30', 30, false])
  await expect(wheel.getByTestId('wheel-gain-amount')).toHaveText(/\+\s*30/)
  await expect(wheel.getByTestId('wheel-wallet')).toHaveText(
    String(drawn.balance)
  )
  // Spun: nothing to decline any more.
  await expect(wheel.getByTestId('wheel-decline')).toHaveCount(0)

  // « Continuer » goes back to the puzzles.
  const next = page.waitForResponse(
    r =>
      r.url().endsWith('/api/puzzles/attempts') &&
      r.request().method() === 'POST'
  )
  await wheel.getByTestId('wheel-continue').click()
  await next
  await expect(wheel).toBeHidden()

  // Once a day: the dashboard has nothing more to show; the store counts the crystals.
  await page.goto('/#/')
  await page.reload()
  await expect(page.getByTestId('dashboard')).toBeVisible()
  await expect(page.getByTestId('gift-wheel')).toHaveCount(0)
  await page.goto('/#/boutique')
  await expect(page.getByTestId('shop-balance')).toHaveAttribute(
    'aria-label',
    `${drawn.balance} cristaux`
  )
})

test('a gift: three chests and a streak freeze; « Relancer » buys a ticket in place, the skull', async ({
  page,
  context,
  browser
}) => {
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await signIn(context, { streak: true, crowns: 50 })
  await setOdds(browser, { gift: 1, freezePercent: 100 })

  const wheel = await firstPuzzleOfTheDay(page)
  const drawn = await spun(page)
  expect([drawn.prize, drawn.gift, drawn.crowns]).toEqual(['gift', 'freeze', 0])
  // Three chests, the same gift in each: one tap opens it (reduced motion).
  await expect(wheel.getByTestId('wheel-chest-2')).toBeVisible()
  await wheel.getByTestId('wheel-chest-1').click()
  await expect(wheel.getByTestId('wheel-gift')).toHaveAttribute(
    'data-gift',
    'freeze'
  )
  await expect(wheel.getByTestId('wheel-gift-text')).toContainText(
    'GEL DE SÉRIE · 1 JOUR'
  )

  // No ticket: « Relancer » opens the store's sheet over the wheel; bought, the wheel spins.
  await setOdds(browser, { lose: 1 })
  await expect(wheel.getByTestId('wheel-again')).toHaveText(
    'Relancer · ticket à 20'
  )
  await wheel.getByTestId('wheel-again').click()
  const sheet = page.getByTestId('shop-sheet')
  await expect(sheet).toContainText('Ticket de roue')
  const again = await spun(page, () => sheet.getByTestId('shop-buy').click())
  expect([again.prize, again.crowns, again.ticket, again.tickets]).toEqual([
    'lose',
    0,
    true,
    0
  ])
  expect(again.balance).toBe(30)
  const lose = page.getByTestId('wheel-lose')
  await expect(lose).toContainText('Tête de mort')
  await lose.getByTestId('wheel-lose-close').click()
  await expect(lose).toBeHidden()
  await wheel.getByTestId('wheel-continue').click()
  await expect(wheel).toBeHidden()

  // The freeze is stored with the bought ones.
  await page.goto('/#/boutique')
  await expect(page.getByTestId('shop-balance')).toHaveAttribute(
    'aria-label',
    '30 cristaux'
  )
  await expect(page.getByTestId('shop-frozen')).toContainText(
    '1 jour de gel en stock'
  )
})

test('a ticket bought in the store spins the wheel from there', async ({
  page,
  context,
  browser
}) => {
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await signIn(context, { crowns: 50 })
  await setOdds(browser, { c5: 1 })

  await page.goto('/#/boutique')
  // No ticket yet: nothing to spin.
  await expect(page.getByTestId('shop-wheel-spin')).toHaveCount(0)
  await page.getByTestId('shop-offer-wheel_ticket').click()
  await page.getByTestId('shop-sheet').getByTestId('shop-buy').click()
  // The celebration goes by itself.
  await expect(page.getByTestId('shop-celebration')).toBeVisible()
  await expect(page.getByTestId('shop-celebration')).toBeHidden()

  await page.getByTestId('shop-wheel-spin').click()
  const wheel = page.getByTestId('gift-wheel')
  await expect(wheel).toBeVisible()
  // A ticket's wheel: nothing to decline.
  await expect(wheel.getByTestId('wheel-decline')).toHaveCount(0)
  const drawn = await spun(page)
  expect([
    drawn.prize,
    drawn.crowns,
    drawn.ticket,
    drawn.tickets,
    drawn.balance
  ]).toEqual(['c5', 5, true, 0, 35])
  await expect(wheel.getByTestId('wheel-gain-amount')).toHaveText(/\+\s*5/)
  await wheel.getByTestId('wheel-continue').click()
  await expect(wheel).toBeHidden()
  await expect(page.getByTestId('shop-balance')).toHaveAttribute(
    'aria-label',
    '35 cristaux'
  )
  await expect(page.getByTestId('shop-wheel-spin')).toHaveCount(0)
})

test('a wheel closed waits on the dashboard; « Ne plus me proposer » turns the profile’s setting off', async ({
  page,
  context
}) => {
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await signIn(context, { streak: true })

  // ✕: nothing lost, the puzzles go on; the header's wheel counts it and opens it again.
  const wheel = await firstPuzzleOfTheDay(page)
  await wheel.getByTestId('wheel-close').click()
  await expect(wheel).toBeHidden()
  await expect(page.getByTestId('header-wheel-count')).toHaveText('1')
  await page.getByTestId('header-wheel').click()
  await expect(wheel).toBeVisible()
  await expect(wheel.getByTestId('wheel-decline')).toBeVisible()
  await wheel.getByTestId('wheel-close').click()
  await expect(wheel).toBeHidden()

  // Not again in this tab; offered again on the dashboard after a reload.
  await page.goto('/#/')
  await expect(page.getByTestId('dashboard')).toBeVisible()
  await expect(page.getByTestId('gift-wheel')).toHaveCount(0)
  await page.reload()
  await expect(wheel).toBeVisible()

  await wheel.getByTestId('wheel-never').click()
  const declined = page.waitForResponse(
    r => r.url().endsWith('/decline') && r.request().method() === 'POST'
  )
  const preferences = page.waitForResponse(
    r =>
      r.url().endsWith('/api/profile/preferences') &&
      r.request().method() === 'PUT'
  )
  await page.getByRole('button', { name: 'Ne plus la proposer' }).click()
  expect((await declined).ok()).toBe(true)
  expect((await (await preferences).json()).dailyWheel).toBe(false)
  await expect(wheel).toBeHidden()

  await expect(page.getByTestId('header-wheel')).toHaveCount(0)
  await page.reload()
  await expect(page.getByTestId('dashboard')).toBeVisible()
  await expect(page.getByTestId('gift-wheel')).toHaveCount(0)
  await expect(page.getByTestId('header-wheel')).toHaveCount(0)

  // The profile turns it back on (for the next days).
  await page.goto('/#/profile')
  const toggle = page.getByTestId('daily-wheel-toggle')
  await expect(toggle).toHaveAttribute('aria-checked', 'false')
  await toggle.click()
  await expect(toggle).toHaveAttribute('aria-checked', 'true')
})

test('the header’s wheel and the menu’s: a ticket bought from the menu, its count, spun from the header', async ({
  page,
  context,
  browser
}) => {
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await signIn(context, { crowns: 50 })
  await setOdds(browser, { c5: 1 })

  await page.goto('/#/')
  await expect(page.getByTestId('crystals')).toBeVisible()
  await expect(page.getByTestId('header-wheel')).toHaveCount(0)

  // None to spin: the menu's wheel leads to the store's wheel section.
  await page.getByTestId('nav-burger').click()
  const entry = page.getByTestId('nav-wheel')
  await expect(entry).toContainText('Tente de gagner 300 cristaux')
  await expect(entry.getByTestId('nav-wheel-count')).toHaveCount(0)
  await entry.click()
  await expect(page).toHaveURL(/#\/boutique\?section=wheel$/)
  await expect(page.getByTestId('shop-section-wheel')).toContainText(
    'Tente de gagner 300 cristaux et de superbes cadeaux !'
  )
  await page.getByTestId('shop-offer-wheel_ticket').click()
  const sheet = page.getByTestId('shop-sheet')
  await expect(sheet).toContainText('Jusqu’à 300 cristaux à gagner')
  await sheet.getByTestId('shop-buy').click()
  await expect(page.getByTestId('shop-celebration')).toBeHidden()

  // One to spin: counted in the header and the menu.
  await expect(page.getByTestId('header-wheel-count')).toHaveText('1')
  await page.getByTestId('nav-burger').click()
  await expect(entry.getByTestId('nav-wheel-count')).toHaveText('1')
  await page.getByTestId('nav-close').click()

  await page.getByTestId('header-wheel').click()
  const wheel = page.getByTestId('gift-wheel')
  await expect(wheel).toBeVisible()
  const drawn = await spun(page)
  expect([drawn.prize, drawn.ticket, drawn.tickets]).toEqual(['c5', true, 0])
  await wheel.getByTestId('wheel-continue').click()
  await expect(wheel).toBeHidden()
  await expect(page.getByTestId('header-wheel')).toHaveCount(0)
})
