import { expect, test } from '@playwright/test'

/**
 * Guests (docs/GUEST.md) against the real API: « Essayer sans compte » from the landing page, the
 * guest's home and the Zombie arriving in the arena, the menu without the shop, an account's page
 * sending to the sign-up page, then a run of Brillant ou Blunder whose end tells the XP an account would have kept, with the Lichess
 * sign-up. The API's side (whitelist, quotas, crystals, promotion, purge): PHPUnit
 * (tests/Functional/Guest).
 */

/** The fixtures' Brillant ou Blunder samples (brilliancy.spec.js): the move shown, what it is. */
const KINDS = {
  h8h4: 'brilliant',
  e5e1: 'brilliant',
  f3e5: 'blunder',
  h7g5: 'blunder'
}

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'

test('a guest plays without an account, and learns what an account would have kept', async ({
  page
}) => {
  await page.goto('/#/')
  await page.getByTestId('landing-try').first().click()

  // Its home: the sign-up first, the free modules, what an account opens locked.
  const home = page.getByTestId('guest-home')
  await expect(home).toBeVisible()
  await expect(home.getByTestId('guest-module-brilliancy')).toBeVisible()
  await expect(home.getByTestId('guest-locked-repertoire')).toBeVisible()
  await expect(page.getByTestId('header-create-account')).toBeVisible()

  // The arena is open: the Zombie arrives, as for any new player.
  const arrival = page.getByTestId('arena-arrival')
  await expect(arrival).toHaveAttribute('data-boss', 'zombie')
  // Announced once: it does not come back on the next pages.
  const announced = page.waitForResponse(r =>
    r.url().endsWith('/api/arena/unlocks/announce')
  )
  await arrival.getByTestId('arena-arrival-close').click()
  await announced
  await expect(arrival).toBeHidden()

  // The menu: no shop, no wheel; the account's entry is the sign-up.
  await page.getByTestId('nav-burger').click()
  const menu = page.getByTestId('nav-menu')
  await expect(menu.getByTestId('nav-create-account')).toBeVisible()
  await expect(menu.getByTestId('nav-shop')).toHaveCount(0)
  await expect(menu.getByTestId('nav-profile')).toHaveCount(0)
  await menu.getByTestId('nav-close').click()

  // An account's page: the sign-up page instead.
  await page.goto('/#/repertoire')
  await expect(page).toHaveURL(/#\/creer-un-compte$/)
  await expect(page.getByTestId('guest-account-lichess')).toHaveAttribute(
    'href',
    /\/api\/auth\/oauth\/lichess\/redirect$/
  )

  // A run of Brillant ou Blunder: no XP shown along the way.
  await page.goto('/#/brillant-ou-blunder')
  let served = page.waitForResponse(isRunNext)
  await page.getByTestId('brilliancy-settings').getByTestId('run-start').click()
  let item = (await (await served).json()).item
  const player = page.getByTestId('brilliancy-player')
  for (let i = 0; i < 4; i++) {
    const kind = KINDS[item.data.position.move.uci]
    expect(kind).toBeTruthy()
    await expect(player.getByTestId('brilliancy-move')).toContainText(
      item.data.position.move.san
    )
    await player.getByTestId(`brilliancy-choice-${kind}`).click()
    await expect(page.getByTestId('result-sheet')).toBeVisible()
    await expect(page.getByTestId('result-xp')).toHaveCount(0)
    await player.getByTestId('brilliancy-next').click()
    if (i < 3) {
      served = page.waitForResponse(isRunNext)
      await page.getByTestId('run-next').click()
      item = (await (await served).json()).item
    }
  }

  // The four samples played, the run closes: what an account would have kept (4 XP each).
  await page.getByTestId('run-next').click()
  await expect(page.getByTestId('run-end')).toBeVisible()
  const missed = page.getByTestId('guest-missed')
  await expect(missed).toBeVisible()
  await expect(missed).toContainText('+16 XP')
  await expect(missed).toContainText('Tu aurais pu gagner 16 XP')
  await expect(missed.getByTestId('guest-missed-lichess')).toHaveAttribute(
    'href',
    /\/api\/auth\/oauth\/lichess\/redirect$/
  )
  await missed.getByTestId('guest-missed-later').click()
  await expect(missed).toBeHidden()
})
