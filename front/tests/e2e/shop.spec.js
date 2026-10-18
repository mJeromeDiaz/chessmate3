import { expect, test } from '@playwright/test'
import { Chess } from 'chess.js'
import { fenAfter, signIn, solve } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isPardon = r =>
  r.url().endsWith('/pardon') && r.request().method() === 'POST'

/**
 * A legal move of the side to move that is neither `expected` nor a mate (a mistake).
 *
 * @param {string} fen
 * @param {string} expected UCI
 */
function wrongMove(fen, expected) {
  const move = new Chess(fen).moves({ verbose: true }).find(m => {
    if (m.from + m.to === expected.slice(0, 4) || m.promotion) return false
    const probe = new Chess(fen)
    probe.move(m)
    return !probe.isCheckmate()
  })
  return /** @type {import('chess.js').Move} */ (move).lan
}

/**
 * Clicks a move on the blindfold player's empty board.
 *
 * @param {import('@playwright/test').Locator} player
 * @param {string} uci
 */
async function clickMove(player, uci) {
  await expect(player).toHaveAttribute('data-phase', 'play')
  await player.getByTestId(`square-${uci.slice(0, 2)}`).click()
  await player.getByTestId(`square-${uci.slice(2, 4)}`).click()
  if (uci.length === 5) {
    await player.getByTestId(`blindfold-promote-${uci[4]}`).click()
  }
}

/** @param {import('@playwright/test').Locator} player */
async function memorize(player) {
  await expect(player).toHaveAttribute('data-phase', 'show')
  await player.getByTestId('blindfold-memorized').click()
}

/**
 * The store's purchase sheet, open in place: buys one.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} name the offer's name
 */
async function buyInPlace(page, name) {
  const sheet = page.getByTestId('shop-sheet')
  await expect(sheet).toContainText(name)
  await expect(sheet).toContainText('En stock : 0')
  await sheet.getByTestId('shop-buy').click()
  await expect(sheet).toBeHidden()
  await expect(page.getByText(`${name} : c’est dans ton stock.`)).toBeVisible()
}

test('store: an admin grants crowns, the player buys a freeze', async ({
  page,
  context,
  browser
}) => {
  const player = await signIn(context)

  const adminContext = await browser.newContext()
  const adminPage = await adminContext.newPage()
  await signIn(adminContext, { admin: true })
  await adminPage.goto('/#/admin/boutique')
  await adminPage.getByTestId('shop-admin-players').click()
  await adminPage.getByTestId('shop-player-search').fill(player.email)
  await adminPage
    .getByTestId('shop-player-row')
    .filter({ hasText: player.email })
    .click()
  const detail = adminPage.getByTestId('shop-player')
  // Quasar puts the test id on the native input itself.
  await detail.getByTestId('shop-adjust-delta').fill('250')
  await detail.getByTestId('shop-adjust-reason').fill('Concours')
  await detail.getByTestId('shop-adjust').click()
  await expect(detail.getByTestId('shop-player-balance')).toContainText('250')
  await expect(detail.getByTestId('shop-ledger-row').first()).toContainText(
    'Concours'
  )
  await adminContext.close()

  await page.goto('/#/boutique')
  const balance = page.getByTestId('shop-balance')
  await expect(balance).toHaveAttribute('aria-label', '250 cristaux')
  await page.getByTestId('shop-offer-freeze_1').click()
  const sheet = page.getByTestId('shop-sheet')
  await expect(sheet).toContainText('Gel 1 jour')
  await sheet.getByTestId('shop-buy').click()
  await expect(page.getByTestId('shop-celebration')).toBeVisible()
  await expect(balance).toHaveAttribute('aria-label', '190 cristaux')
  await expect(
    page.getByTestId('shop-offer-freeze_1').getByTestId('shop-stock')
  ).toHaveText('×1')
  await expect(page.getByTestId('shop-frozen')).toContainText(
    '1 jour de gel en stock'
  )
})

test('rated puzzle: a miss pardoned on its sheet, the pardon bought in place', async ({
  page,
  context
}) => {
  await signIn(context, { crowns: 200 })
  await page.goto('/#/puzzle')
  const submitted = page.waitForResponse(r => r.url().endsWith('/submission'))
  await page.getByTestId('puzzle-solution').click()
  const missed = await (await submitted).json()
  expect(missed.status).toBe('failed')
  expect(missed.ratingDelta).toBeLessThan(0)
  await expect(page.getByTestId('result-title')).toHaveText('Raté !', {
    timeout: 20_000
  })

  // None held: the store's sheet opens in place.
  await page.getByTestId('puzzle-pardon').click()
  await buyInPlace(page, 'Erreur pardonnée')
  await expect(page.getByTestId('puzzle-pardon')).toHaveText('Pardonner · 1')

  const pardoned = page.waitForResponse(isPardon)
  await page.getByTestId('puzzle-pardon').click()
  const answer = await (await pardoned).json()
  expect([answer.pardoned, answer.pardons]).toEqual([true, 0])
  await expect(
    page.getByText('Pardonné : ton classement est revenu.')
  ).toBeVisible()
  await expect(page.getByTestId('puzzle-pardon')).toHaveCount(0)
  await expect(page.getByTestId('puzzle-retry')).toBeVisible()
})

test('blindfold: a pardon after a mistake, bought in place, the puzzle ends solved', async ({
  page,
  context
}) => {
  await signIn(context, { crowns: 200 })
  await page.goto('/#/blindfold')
  const card = page.getByTestId('blindfold-puzzles')
  await card
    .getByTestId('blindfold-level')
    .getByRole('button', { name: /Facile/ })
    .click()
  await card
    .getByTestId('blindfold-length')
    .getByRole('button', { name: '2 coups', exact: true })
    .click()
  await card
    .getByTestId('blindfold-visible')
    .getByRole('button', { name: '5 s', exact: true })
    .click()
  const served = page.waitForResponse(isRunNext)
  await card.getByTestId('run-start').click()
  const item = (await (await served).json()).item
  expect([item.data.pardoned, item.data.pardons]).toEqual([false, 0])
  const puzzle = item.data.puzzle

  const player = page.getByTestId('blindfold-player')
  await memorize(player)
  await expect(player.getByTestId('blindfold-pardon')).toHaveCount(0)
  await clickMove(player, wrongMove(fenAfter(puzzle, 1), puzzle.moves[1]))
  await expect(player.getByTestId('blindfold-pardon')).toHaveText(
    'Pardonner · 0'
  )

  // None held: bought in place, the clock running on; then spent.
  await player.getByTestId('blindfold-pardon').click()
  await buyInPlace(page, 'Erreur pardonnée')
  await expect(player.getByTestId('blindfold-pardon')).toHaveText(
    'Pardonner · 1'
  )
  const pardoned = page.waitForResponse(isPardon)
  await player.getByTestId('blindfold-pardon').click()
  expect((await (await pardoned).json()).data).toMatchObject({
    pardon: 'play',
    pardons: 0
  })
  await expect(player.getByTestId('blindfold-pardon')).toHaveCount(0)
  await expect(player.getByTestId('blindfold-message')).toContainText(
    'Pardonné'
  )

  // The peek given by the mistake runs out (shown 5 s, hidden 3 s), then the solution from
  // memory: solved, all its XP.
  await expect(player).toHaveAttribute('data-phase', 'play', {
    timeout: 15_000
  })
  for (let i = 1; i < puzzle.moves.length; i += 2) {
    await clickMove(player, puzzle.moves[i])
    if (i + 1 < puzzle.moves.length) {
      await expect(player).toHaveAttribute('data-moves', String(i + 2))
    }
  }
  await expect(page.getByTestId('result-sheet')).toHaveAttribute(
    'data-kind',
    'win'
  )
  await expect(page.getByTestId('result-xp')).toHaveText('+12 XP')
})

test('a completed session opens a chest of crowns, then the results and the store', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/session/new')
  await page.getByTestId('session-title').fill('Coffre')
  await page.getByTestId('module-card-puzzles').click()
  const settings = page.getByTestId('module-settings')
  await settings.getByTestId('field-duree').locator('input').fill('5')
  await settings.getByTestId('module-save').click()
  await expect(settings).toBeHidden()

  const served = page.waitForResponse(isRunNext)
  await page.getByTestId('session-launch').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+$/)
  const item = (await (await served).json()).item
  await solve(page, item.data.puzzle)
  await expect(page.getByTestId('run-progress')).toContainText('1 puzzles')
  // At least 15 s for the puzzle: some crowns (1 minute per exercise at most, docs/SHOP.md).
  await page.waitForTimeout(15_000)
  await page.getByTestId('run-stop').click()
  await page.getByRole('button', { name: 'OK' }).click()
  await expect(page.getByRole('button', { name: 'OK' })).toBeHidden()

  // The chest comes before the results: three taps open it.
  const chest = page.getByTestId('treasure-chest')
  await expect(chest).toBeVisible()
  await expect(chest).toContainText('RÉCOMPENSE DE SESSION')
  await expect(page.getByTestId('run-end')).toBeHidden()
  const opened = page.waitForResponse(
    r =>
      /\/api\/shop\/rewards\/[^/]+\/open$/.test(r.url()) &&
      r.request().method() === 'POST'
  )
  for (let i = 0; i < 3; i++)
    await chest.getByTestId('chest-hit').click({ force: true })
  const reward = await (await opened).json()
  expect(reward.crowns).toBeGreaterThan(0)
  expect(reward.tier).toBe('perfect')
  await expect(chest).toContainText('Quel')
  await expect(chest).not.toContainText(/champion/i)
  await expect(chest.getByTestId('chest-amount')).toHaveText(
    `+${reward.crowns}`
  )
  await expect(chest.getByTestId('chest-wallet')).toHaveText(
    String(reward.balance)
  )
  expect(reward.balance).toBe(reward.crowns)

  // "Voir mes résultats": the run's result.
  await chest.getByTestId('chest-results').click()
  await expect(chest).toBeHidden()
  const result = page.getByTestId('run-end')
  await expect(result).toBeVisible()
  await result.getByTestId('run-end-close').click()

  // The session's recap says it, without the chest again.
  await page.getByTestId('session-open').click()
  await expect(page.getByTestId('session-reward')).toContainText(
    `+${reward.crowns} cristaux`
  )
  await expect(page.getByTestId('treasure-chest')).toHaveCount(0)

  // The store tells how crowns are earned.
  await page.goto('/#/boutique')
  await expect(page.getByTestId('shop-balance')).toContainText(
    String(reward.crowns)
  )
  await expect(page.getByTestId('shop-earn')).toContainText(
    'Gagner des cristaux'
  )
})

test('a chest missed at the end of the run waits on the dashboard', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/session/new')
  await page.getByTestId('session-title').fill('Coffre oublié')
  await page.getByTestId('module-card-puzzles').click()
  const settings = page.getByTestId('module-settings')
  await settings.getByTestId('field-duree').locator('input').fill('5')
  await settings.getByTestId('module-save').click()
  await expect(settings).toBeHidden()

  const served = page.waitForResponse(isRunNext)
  await page.getByTestId('session-launch').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+$/)
  const item = (await (await served).json()).item
  await solve(page, item.data.puzzle)
  await expect(page.getByTestId('run-progress')).toContainText('1 puzzles')
  await page.waitForTimeout(15_000)
  const stopped = page.waitForResponse(
    r => /\/api\/training\/runs\/[^/]+\/stop$/.test(r.url()) && r.ok()
  )
  await page.getByTestId('run-stop').click()
  await page.getByRole('button', { name: 'OK' }).click()
  await stopped
  // Left at once: a chest only counts as seen once hit or closed (docs/SHOP.md, § 4 bis).
  await page.goto('/#/boutique')
  await expect(page.getByTestId('shop-balance')).toBeVisible()

  // The dashboard shows it; "Voir le bilan" leads to its session.
  await page.goto('/#/')
  const chest = page.getByTestId('treasure-chest')
  await expect(chest).toBeVisible()
  await expect(chest).toContainText('RÉCOMPENSE DE SESSION')
  for (let i = 0; i < 3; i++)
    await chest.getByTestId('chest-hit').click({ force: true })
  await chest.getByTestId('chest-results').click()
  await expect(page).toHaveURL(/#\/session\/[^/]+$/)
  await expect(page.getByTestId('session-reward')).toContainText('cristaux')

  // Seen: not again.
  await page.goto('/#/')
  await expect(page.getByTestId('dashboard')).toBeVisible()
  await expect(page.getByTestId('treasure-chest')).toHaveCount(0)
})
