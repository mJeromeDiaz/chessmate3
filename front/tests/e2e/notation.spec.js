import { expect, test } from '@playwright/test'
import { Chess } from 'chess.js'
import { playMove, signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'

/** English piece letters → French, as the module shows them in French. */
const french = san =>
  san.replace(/[KQRBN]/g, l => ({ K: 'R', Q: 'D', R: 'T', B: 'F', N: 'C' })[l])

/** A legal move of the position other than `uci`. */
function otherMove(fen, uci) {
  const move = new Chess(fen)
    .moves({ verbose: true })
    .map(m => m.from + m.to + (m.promotion ?? ''))
    .find(m => m !== uci)
  if (!move) throw new Error('A single legal move.')
  return move
}

// Judging is covered by PHPUnit (tests/Functional/Notation/NotationRunTest.php). Here, the module
// as the player meets it, in French letters.
test('notation: a move announced played right then wrong, a line written', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/cerveau')
  await page.getByTestId('nav-burger').click()
  await page.getByTestId('nav-free').click()
  await page.getByTestId('nav-free-notation').click()
  await expect(page).toHaveURL(/#\/notation$/)
  await expect(page.getByTestId('notation-letters')).toContainText('R D T F C')

  // Play the move announced.
  await page
    .getByTestId('run-duration-choice')
    .getByRole('button', { name: '2 min' })
    .click()
  let served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-start').click()
  let item = (await (await served).json()).item
  expect(item.data.kind).toBe('play')
  await expect(page.getByTestId('notation-announced')).toHaveText(
    french(item.data.announced.san)
  )
  served = page.waitForResponse(isRunNext)
  await playMove(page, item.data.announced.uci)
  await expect(page.getByTestId('notation-result')).toContainText('Juste !')

  // The next one comes by itself; a wrong move shows the right one.
  item = (await (await served).json()).item
  await expect(page.getByTestId('notation-announced')).toHaveText(
    french(item.data.announced.san)
  )
  await playMove(page, otherMove(item.data.fen, item.data.announced.uci))
  await expect(page.getByTestId('notation-result')).toContainText(
    `C’était ${french(item.data.announced.san)}`
  )
  await expect(page.getByTestId('run-progress')).toContainText('Justes : 1 / 2')
  await page.getByTestId('run-stop').click()
  await page.getByRole('button', { name: 'OK' }).click()
  // The wrong answer stays on screen until it is left: then the run's result.
  await page.getByTestId('run-next').click()
  await page.getByTestId('run-end-close').click()
  await page.getByTestId('run-back').click()
  await expect(page).toHaveURL(/#\/notation$/)

  // Write a line: « Rg9 » (French: a king's move) is no legal move.
  await page.getByTestId('notation-kind-write').click()
  served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-start').click()
  item = (await (await served).json()).item
  expect(item.data.kind).toBe('write')
  await expect(page.getByTestId('notation-status')).toContainText(
    `${item.data.plies} demi-coup`
  )
  await page.getByTestId('notation-key-R').click()
  await page.getByTestId('notation-input').pressSequentially('g9')
  await expect(page.getByTestId('notation-input')).toHaveValue('Rg9')
  const judged = page.waitForResponse(
    r => r.url().endsWith('/submission') && r.request().method() === 'POST'
  )
  await page.getByTestId('notation-send').click()
  const expected = (await (await judged).json()).result.data.expected
  await expect(page.getByTestId('notation-result')).toContainText(
    'Le demi-coup 1 n’est pas un coup légal.'
  )
  // The correction: the transcription beside the solution, the board stepping through it.
  await expect(page.getByTestId('notation-write')).toHaveCount(0)
  const first = page.getByTestId('notation-correction-row-0')
  await expect(first).toHaveAttribute('data-state', 'wrong')
  await expect(first).toContainText('Rg9')
  await expect(first).toContainText(french(expected[0].san))
  if (item.data.plies > 1)
    await expect(page.getByTestId('notation-correction-row-1')).toContainText(
      '—'
    )
  // A row stops the replay: the start, the right move as an arrow; › plays it.
  await first.click()
  const correction = page.getByTestId('notation-correction')
  const board = page.getByTestId('chess-board')
  await expect(correction).toHaveAttribute('data-frame', '0')
  const chess = new Chess(item.data.fen)
  await expect(board).toHaveAttribute('data-fen', chess.fen())
  await page.getByTestId('notation-correction-next').click()
  await expect(correction).toHaveAttribute('data-frame', '1')
  chess.move({
    from: expected[0].uci.slice(0, 2),
    to: expected[0].uci.slice(2, 4),
    promotion: expected[0].uci[4]
  })
  await expect(board).toHaveAttribute('data-fen', chess.fen())
  await page.getByTestId('notation-correction-prev').click()
  await expect(correction).toHaveAttribute('data-frame', '0')
})

test('notation: completing a line needs a repertoire of 10 segments', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/notation')
  await page.getByTestId('notation-kind-line').click()
  await expect(page.getByTestId('notation-no-repertoire')).toContainText(
    'au moins 10 tronçons'
  )
  await expect(page.getByTestId('run-start')).toBeDisabled()
  // Another exercise: no prerequisite.
  await page.getByTestId('notation-kind-play').click()
  await expect(page.getByTestId('notation-no-repertoire')).toHaveCount(0)
  await expect(page.getByTestId('run-start')).toBeEnabled()
})

test('notation: a legendary game read then replayed, six half-moves at a time', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/notation')
  await page.getByTestId('notation-kind-game_play').click()
  await page.getByTestId('notation-game').click()
  await page.getByRole('option', { name: /Paul Morphy/ }).click()
  const served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-start').click()
  const item = (await (await served).json()).item
  expect(item.data.kind).toBe('game_play')
  await expect(page.getByTestId('notation-shown')).toContainText('Paul Morphy')
  await expect(page.getByTestId('notation-shown')).toContainText('Coups 1 à 3')
  // French letters: 2. Cf3 … 3. … Fg4.
  for (const san of ['e4', 'Cf3', 'Fg4'])
    await expect(page.getByTestId('notation-sequence')).toContainText(san)
  const next = page.waitForResponse(isRunNext)
  // One move at a time: the board hands over to the other side once the move is taken.
  const player = page.getByTestId('notation-player')
  const board = page.getByTestId('chess-board')
  const game = new Chess(item.data.fen)
  for (const [i, move] of item.data.moves.entries()) {
    await playMove(page, move.uci)
    await expect(player).toHaveAttribute('data-played', String(i + 1))
    // A click move ignores clicks until its animation is over (cm-chessboard): data-fen tells.
    game.move({
      from: move.uci.slice(0, 2),
      to: move.uci.slice(2, 4),
      promotion: move.uci[4]
    })
    await expect(board).toHaveAttribute('data-fen', game.fen())
  }
  await expect(page.getByTestId('notation-result')).toContainText('Juste !')
  // The game goes on where it stopped.
  const following = (await (await next).json()).item
  expect(following.data.shown).toHaveLength(6)
  await expect(page.getByTestId('notation-shown')).toContainText('Coups 4 à 6')
})
