import { expect, test } from '@playwright/test'
import { Chess } from 'chess.js'
import { closeRunEnd, fenAfter, playMove, signIn, solve } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())

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

// The sample puzzles cover the first tiers of the mates only (no mate in 4 or 5): a whole parcours,
// its bonus and its chest are PHPUnit's (ParcoursTest).
test('parcours: launched from its page, a tier without a clock, Lizy between tiers, the next one by itself, "Terminer" abandons it', async ({
  page,
  context
}) => {
  test.setTimeout(90_000)
  await signIn(context)
  await page.goto('/#/session')
  await page.getByTestId('plans-parcours').click()
  await expect(page).toHaveURL(/#\/parcours$/)

  const mates = page.getByTestId('parcours-theme-mates')
  await expect(mates).toContainText('Mat en 1')
  await expect(mates).toContainText('Mat en 5')
  await page.getByTestId('parcours-theme-opening').click()
  await expect(page.getByTestId('parcours-repertoires')).toContainText(
    'Il te faut un répertoire'
  )
  await expect(page.getByTestId('parcours-launch')).toBeDisabled()
  await mates.click()
  await page
    .getByTestId('parcours-effort')
    .getByRole('button', { name: 'Léger · 15' })
    .click()
  await expect(page.getByTestId('parcours-effort-line')).toHaveText(
    "3 par palier · 15 en tout · +25 XP à l'arrivée"
  )

  let served = page.waitForResponse(isRunNext)
  await page.getByTestId('parcours-launch').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+$/)
  let item = (await (await served).json()).item
  expect(item.type).toBe('parcours_puzzle')
  expect(item.data).toMatchObject({ tier: 0, position: 0, count: 3 })
  await expect(page.getByTestId('parcours-tier')).toHaveText('Palier 1/5')
  await expect(page.getByTestId('parcours-header')).toContainText('Mat en 1')
  await expect(page.getByTestId('run-progress')).toHaveText('1/3')
  await expect(page.getByTestId('parcours-zone')).toHaveText('Sous ton niveau')
  await expect(page.getByTestId('run-timer')).toHaveCount(0)

  // Solved: the next one comes by itself.
  served = page.waitForResponse(isRunNext)
  await solve(page, item.data.puzzle)
  item = (await (await served).json()).item
  expect(item.data.position).toBe(1)
  await expect(page.getByTestId('run-progress')).toHaveText('2/3')
  await expect(page.getByTestId('parcours-zone')).toHaveText('À ton niveau')

  // Missed: the solution is shown, the tier goes on with "Suivant".
  const puzzle = item.data.puzzle
  await expect(page.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    fenAfter(puzzle, 1)
  )
  const submitted = page.waitForResponse(isRunSubmission)
  await playMove(page, wrongMove(fenAfter(puzzle, 1), puzzle.moves[1]))
  expect((await (await submitted).json()).result.success).toBe(false)
  await expect(page.getByTestId('result-sheet')).toHaveAttribute(
    'data-kind',
    'miss'
  )
  served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-next').click()
  item = (await (await served).json()).item
  await expect(page.getByTestId('parcours-zone')).toHaveText(
    'Au-dessus de ton niveau'
  )

  // The tier's last puzzle: Lizy's banner, then the next tier starts by itself.
  const firstRun = page.url()
  await solve(page, item.data.puzzle)
  const banner = page.getByTestId('parcours-banner')
  await expect(banner).toBeVisible()
  await expect(banner.getByTestId('parcours-banner-done')).toHaveText(
    'Palier 1 terminé · 2/3'
  )
  await expect(banner).toContainText('Palier 2 : Mat en 2')
  await expect(page.getByTestId('run-end')).toBeHidden()
  await expect(page.getByTestId('parcours-tier')).toHaveText('Palier 2/5', {
    timeout: 10_000
  })
  expect(page.url()).not.toBe(firstRun)
  await expect(page.getByTestId('parcours-header')).toContainText('Mat en 2')
  await expect(page.getByTestId('run-progress')).toHaveText('1/3')

  // "Terminer": the whole parcours is abandoned; its result adds up what was played.
  await page.getByTestId('run-stop').click()
  await expect(
    page.getByRole('dialog').filter({ hasText: 'Terminer la séance ?' })
  ).toContainText('les paliers non joués sont perdus')
  await page.getByRole('button', { name: 'OK' }).click()
  const end = page.getByTestId('run-end')
  await expect(end.getByTestId('run-result-score')).toContainText('66')
  await closeRunEnd(page)
  await expect(page.getByTestId('parcours-banner')).toHaveCount(0)
  await expect(page.getByTestId('session-next')).toHaveCount(0)

  await page.getByTestId('session-open').click()
  await expect(page.getByTestId('session-status')).toHaveText('Abandonnée')
  await expect(page.getByTestId('session-tier').first()).toHaveText(
    'Palier 1 · Mat en 1'
  )
  await expect(page.getByTestId('session-tier').nth(4)).toHaveText(
    'Palier 5 · Mat en 5'
  )
  await expect(page.getByTestId('session-skip')).toHaveCount(0)
})
