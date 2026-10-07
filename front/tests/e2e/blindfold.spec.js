import { expect, test } from '@playwright/test'
import { Chess } from 'chess.js'
import { fenAfter, signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'

/**
 * A legal move of the side to move that is neither `expected` nor a mate (a mistake).
 *
 * @param {string} fen
 * @param {string} expected UCI
 */
function wrongMove(fen, expected) {
  const move = new Chess(fen).moves({ verbose: true }).find(m => {
    if (m.from + m.to === expected.slice(0, 4)) return false
    const probe = new Chess(fen)
    probe.move(m)
    return !probe.isCheckmate()
  })
  return /** @type {import('chess.js').Move} */ (move).lan
}

/**
 * Clicks a move on the empty board (start square, then arrival square), and its piece when it
 * promotes.
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

/**
 * Memorizes at once, then plays the player's moves from `from` (an index in `moves`), waiting
 * for each opponent reply.
 *
 * @param {import('@playwright/test').Locator} player
 * @param {{moves: string[]}} puzzle
 * @param {number} [from]
 */
async function solveBlind(player, puzzle, from = 1) {
  for (let i = from; i < puzzle.moves.length; i += 2) {
    await clickMove(player, puzzle.moves[i])
    if (i + 1 < puzzle.moves.length) {
      // The reply is played: the moves so far are the solution's first i + 2.
      await expect(player).toHaveAttribute('data-moves', String(i + 2))
    }
  }
}

/** @param {import('@playwright/test').Locator} player */
async function memorize(player) {
  await expect(player).toHaveAttribute('data-phase', 'show')
  await player.getByTestId('blindfold-memorized').click()
  await expect(player.getByTestId('blindfold-veil')).toBeVisible()
}

test('blindfold puzzles: open without coordinates, solved from memory, with a peek, recap and results', async ({
  page,
  context
}) => {
  // A brand new user, no coordinates series: blindfold puzzles are open.
  await signIn(context)
  await page.goto('/#/blindfold')
  const card = page.getByTestId('blindfold-puzzles')
  await expect(card).toBeVisible()
  await expect(card.getByTestId('blindfold-puzzles-total')).toHaveText(
    'Aucun puzzle joué.'
  )
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

  let served = page.waitForResponse(isRunNext)
  await card.getByTestId('run-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+$/)
  let item = (await (await served).json()).item
  expect(item.type).toBe('blindfold_puzzle')
  expect(item.data).toMatchObject({
    level: 'easy',
    length: 2,
    visibleSeconds: 5,
    hiddenSeconds: 3,
    peeks: 1
  })

  // First puzzle, solved without a mistake: the position is hidden, the board stays empty.
  const player = page.getByTestId('blindfold-player')
  await memorize(player)
  await expect(player).toHaveAttribute('data-phase', 'play')
  await expect(player.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    '8/8/8/8/8/8/8/8 w - - 0 1'
  )
  await solveBlind(player, item.data.puzzle)
  await expect(page.getByTestId('result-sheet')).toHaveAttribute(
    'data-kind',
    'win'
  )
  await expect(page.getByTestId('result-xp')).toHaveText('+12 XP')
  await expect(page.getByTestId('prof-bubble')).toContainText('NOCTIS · BRAVO')
  await expect(player).toHaveAttribute('data-phase', 'complete')

  // Second puzzle: a mistake, the current position shown again, then solved "with help".
  served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-next').click()
  item = (await (await served).json()).item
  const puzzle = item.data.puzzle
  await memorize(player)
  await clickMove(player, wrongMove(fenAfter(puzzle, 1), puzzle.moves[1]))
  await expect(page.getByTestId('blindfold-status')).toContainText('Coup d’œil')
  await expect(player.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    fenAfter(puzzle, 1)
  )
  await memorize(player)
  await solveBlind(player, puzzle)
  await expect(page.getByTestId('result-sheet')).toHaveAttribute(
    'data-kind',
    'help'
  )
  await expect(page.getByTestId('result-sub')).toContainText(
    'Trouvé avec un coup d’œil'
  )
  await expect(page.getByTestId('result-xp')).toHaveText('+6 XP')

  // "Terminer": the third puzzle on screen is not counted.
  served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-next').click()
  await served
  await page.getByTestId('run-stop').click()
  await expect(
    page.getByRole('dialog').filter({ hasText: 'Terminer la séance ?' })
  ).toContainText('Le puzzle en cours ne sera pas compté.')
  await page.getByRole('button', { name: 'OK' }).click()

  const end = page.getByTestId('run-end')
  await expect(end).toBeVisible()
  await expect(end).toContainText('2 puzzles en')
  await expect(
    end.getByTestId('run-end-stat').filter({ hasText: 'Avec coup d’œil' })
  ).toContainText('1')
  await expect(
    end.getByTestId('run-end-grid').locator('[data-status]')
  ).toHaveCount(2)
  await expect(end.getByTestId('run-end-missed-item')).toHaveCount(1)
  await end.getByTestId('run-end-close').click()

  await expect(page.getByTestId('run-recap')).toContainText(
    'Résolus de mémoire : 1 · avec un coup d’œil : 1 · ratés : 0.'
  )
  await expect(page.getByTestId('run-back')).toHaveText(
    'Retour au jeu à l’aveugle'
  )
  await page.getByTestId('run-back').click()
  await expect(page).toHaveURL(/#\/blindfold$/)
  await expect(
    page.getByTestId('blindfold-puzzles').getByTestId('blindfold-puzzles-total')
  ).toHaveText('1 résolu sur 2 (50 %) · 1 avec coup d’œil')
  await expect(page.getByTestId('blindfold-stats-easy')).toContainText(
    '1 résolu sur 2'
  )
})

test('blindfold puzzles: the session builder card is playable without coordinates', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/session/new')
  await page.getByTestId('module-card-aveugle').click()
  const settings = page.getByTestId('module-settings')
  await expect(settings.getByTestId('field-niveau')).toBeVisible()
  await expect(page.getByTestId('module-issue')).toHaveCount(0)
  await settings.getByTestId('module-save').click()
  await expect(settings).toBeHidden()
  await expect(page.getByTestId('program-item-issue')).toHaveCount(0)
})
