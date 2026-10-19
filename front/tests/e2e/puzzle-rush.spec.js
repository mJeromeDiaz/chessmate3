import { expect, test } from '@playwright/test'
import { Chess } from 'chess.js'
import { closeRunEnd, fenAfter, playMove, signIn, solve } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())
const isPuzzleStart = r =>
  r.url().endsWith('/api/puzzles/attempts') && r.request().method() === 'POST'

/**
 * A legal first move of the player that is neither the solution nor a mate nor a promotion.
 *
 * @param {{fen: string, moves: string[]}} puzzle
 */
function wrongMove(puzzle) {
  const chess = new Chess(fenAfter(puzzle, 1))
  const move = chess.moves({ verbose: true }).find(m => {
    if (m.promotion || m.from + m.to === puzzle.moves[1]) return false
    chess.move(m)
    const mates = chess.isCheckmate()
    chess.undo()
    return !mates
  })
  if (!move) throw new Error('No wrong move available.')
  return move.from + move.to
}

test('rush: two puzzles solved and a miss, the next one at once; the score pays for precision', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/puzzle')
  await page.waitForResponse(isPuzzleStart)

  await page.getByTestId('rush-open').click()
  const dialog = page.getByTestId('rush-dialog')
  await expect(dialog).toContainText('Tous les thèmes')
  const choice = dialog.getByTestId('run-duration-choice')
  // The API's lengths only, 3 min first.
  await expect(choice.getByRole('button')).toHaveText([
    '2 min',
    '3 min',
    '5 min'
  ])
  await expect(dialog.getByRole('button', { name: 'Autre' })).toHaveCount(0)
  await choice.getByRole('button', { name: '2 min' }).click()
  const first = page.waitForResponse(isRunNext)
  await dialog.getByTestId('run-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+$/)
  let item = (await (await first).json()).item
  expect(item.type).toBe('rush_puzzle')
  await expect(page.getByTestId('run-progress')).toHaveText(
    '✓ 0 · ✗ 0 · précision —'
  )
  // No help in a rush.
  await expect(page.getByTestId('puzzle-hint')).toHaveCount(0)
  await expect(page.getByTestId('puzzle-solution')).toHaveCount(0)

  // Two solved: each time the next puzzle comes by itself, no result sheet.
  for (let i = 0; i < 2; i++) {
    const submitted = page.waitForResponse(isRunSubmission)
    const next = page.waitForResponse(isRunNext)
    await solve(page, item.data.puzzle)
    expect((await (await submitted).json()).result.success).toBe(true)
    item = (await (await next).json()).item
    await expect(page.getByTestId('result-sheet')).toHaveCount(0)
  }
  await expect(page.getByTestId('run-progress')).toHaveText(
    '✓ 2 · ✗ 0 · précision 100 %'
  )

  // A miss: counted, no second chance, the next puzzle at once (no solution played).
  const missedPuzzle = item.data.puzzle
  const submitted = page.waitForResponse(isRunSubmission)
  const next = page.waitForResponse(isRunNext)
  await expect(page.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    fenAfter(missedPuzzle, 1)
  )
  await playMove(page, wrongMove(missedPuzzle))
  const missed = (await (await submitted).json()).result
  expect([missed.success, missed.data.solved, missed.data.failed]).toEqual([
    false,
    2,
    1
  ])
  expect((await (await next).json()).item.id).not.toBe(item.id)
  await expect(page.getByTestId('run-progress')).toHaveText(
    '✓ 2 · ✗ 1 · précision 67 %'
  )
  await expect(page.getByTestId('run-progress')).not.toContainText('point')

  await page.getByTestId('run-stop').click()
  await page.getByRole('button', { name: 'OK' }).click()

  // The result: 67 % is below 80 %, and the miss wipes out both puzzles solved.
  const end = page.getByTestId('run-end')
  const result = end.getByTestId('run-result')
  await expect(result).toHaveAttribute('data-kind', 'fail')
  await expect(result.getByTestId('run-result-score')).toHaveText('0')
  await expect(result).toContainText('POINTS')
  const lines = result.getByTestId('run-result-line')
  await expect(lines).toContainText([
    'puzzles/min · 67 % de précision',
    'Score : 2 réussis − 2 × 1 = 0 points',
    'Hors records'
  ])
  // Nothing gained: no XP line.
  await expect(lines.filter({ hasText: 'XP' })).toHaveCount(0)

  // The miss is replayed from the result, client side only.
  /** @type {string[]} */
  const sent = []
  page.on('request', request => {
    if (request.method() !== 'GET') sent.push(request.url())
  })
  await end.getByTestId('run-end-fix').click()
  const fix = end.getByTestId('run-fix')
  await expect(fix.getByTestId('run-fix-count')).toHaveText('1')
  await fix.getByTestId('run-fix-start').click()
  const replay = end.getByTestId('run-end-replay')
  await solve(page, missedPuzzle, replay)
  await expect(replay.getByTestId('run-end-replay-result')).toHaveText(
    'Bien joué, c’est revu !'
  )
  await replay.getByTestId('run-end-replay-done').click()
  expect(sent).toEqual([])
  await closeRunEnd(page)

  await expect(page.getByTestId('run-items')).toHaveText('3')
  await expect(page.getByTestId('run-successes')).toHaveText('2')
  await expect(page.getByTestId('run-failures')).toHaveText('1')
  await expect(page.getByTestId('run-recap')).toContainText(
    'Score : 2 réussis − 2 × 1 = 0 points'
  )
  await page.getByTestId('run-back').click()
  await expect(page).toHaveURL(/#\/puzzle$/)

  // Stopped early: no record for 2 minutes.
  await page.getByTestId('rush-open').click()
  await expect(page.getByTestId('rush-record-120')).toHaveText(
    '2 min : pas encore de record'
  )
})

test('rush: a card of the Session Builder, 2, 3 or 5 minutes', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/session/new')
  const card = page.getByTestId('module-card-rush')
  await expect(card).toContainText('Puzzle Rush')
  await card.click()
  const settings = page.getByTestId('module-settings')
  // Its length: one of the API's, 3 minutes first.
  await expect(settings.getByTestId('duration-3')).toHaveAttribute(
    'aria-pressed',
    'true'
  )
  await expect(settings.getByTestId('duration-2')).toBeVisible()
  await expect(settings.getByTestId('duration-5')).toBeVisible()
  await expect(page.getByTestId('module-issue')).toHaveCount(0)
  await settings.getByTestId('module-save').click()
  await expect(settings).toBeHidden()
  await expect(page.getByTestId('program-item-issue')).toHaveCount(0)
})
