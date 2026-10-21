import { expect, test } from '@playwright/test'
import { Chess } from 'chess.js'
import { playMove, signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isReady = r => /\/api\/training\/runs\/[^/]+\/ready$/.test(r.url())
const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())

/**
 * A legal move that mates (`mate`), or one that does not.
 *
 * @param {string} fen
 * @param {boolean} mate
 */
function moveOf(fen, mate) {
  for (const move of new Chess(fen).moves({ verbose: true })) {
    const chess = new Chess(fen)
    chess.move(move)
    if (chess.isCheckmate() === mate)
      return move.from + move.to + (move.promotion ?? '')
  }
  throw new Error(`No ${mate ? 'mating' : 'quiet'} move in ${fen}.`)
}

// The drawing, the judging and the server's clock are covered by PHPUnit
// (tests/Functional/Calculation/CalculationPathTest.php). Here, a lesson of mates in 1 as the
// player meets it: a frozen board, « Je réponds », the line played, its verdict.
test('calculation: a mate in 1 thought over on a frozen board, then played', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/cerveau/calculation')
  await page.getByTestId('path-step-1-1').click()
  let served = page.waitForResponse(isRunNext)
  await page.getByTestId('step-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/?]+\?mindMap=1(&boss=\w+)?$/)
  let item = (await (await served).json()).item
  expect(item.type).toBe('calculation')

  const player = page.getByTestId('calculation-player')
  await expect(player).toHaveAttribute('data-phase', 'think')
  await expect(player.getByTestId('calculation-mate')).toHaveText('Mat en 1')
  await expect(page.getByTestId('run-progress')).toHaveText('Ligne 1 sur 10')

  // Right: the mate played once « Je réponds » is clicked.
  let ready = page.waitForResponse(isReady)
  await player.getByTestId('calculation-ready').click()
  expect((await ready).status()).toBe(200)
  await expect(player).toHaveAttribute('data-phase', 'answer')
  let submitted = page.waitForResponse(isRunSubmission)
  served = page.waitForResponse(isRunNext)
  await playMove(page, moveOf(item.data.fen, true))
  let result = (await (await submitted).json()).result
  expect([result.success, result.data.status]).toEqual([true, 'right'])
  await expect(player.getByTestId('calculation-result')).toContainText(
    'Ligne juste'
  )

  // Wrong: a move that does not mate, then the clock sends it.
  item = (await (await served).json()).item
  await expect(player).toHaveAttribute('data-phase', 'think')
  ready = page.waitForResponse(isReady)
  await player.getByTestId('calculation-ready').click()
  await ready
  submitted = page.waitForResponse(isRunSubmission)
  await playMove(page, moveOf(item.data.fen, false))
  result = (await (await submitted).json()).result
  expect([result.success, result.data.status, result.data.mistakeAt]).toEqual([
    false,
    'wrong',
    0
  ])
  await expect(player.getByTestId('calculation-result')).toContainText(
    'Solution :'
  )
  await expect(page.getByTestId('run-next')).toBeVisible()
})

// Its streak, tempo and verdicts are covered by PHPUnit; here, the fight as the player meets it.
test('calculation: the Métronome, a line of unknown length and its face', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/cerveau/calculation')
  await page.getByTestId('path-chapter-boss-1').click()
  const served = page.waitForResponse(isRunNext)
  await page.getByTestId('boss-intro').getByTestId('boss-fight').click()
  await expect(page).toHaveURL(/#\/training\/[^/?]+\?mindMap=1(&boss=\w+)?$/)
  const item = (await (await served).json()).item
  expect([item.type, item.data.mate, item.data.thinkSeconds]).toEqual([
    'calculation',
    null,
    40
  ])

  const player = page.getByTestId('calculation-player')
  await expect(player).toHaveAttribute('data-mood', 'neutral')
  await expect(player.getByTestId('calculation-mate')).toHaveCount(0)
  await expect(page.getByTestId('run-progress')).toHaveText('Série 0 sur 12')
  await expect(page.getByTestId('calculation-lives')).toHaveAttribute(
    'data-lives',
    '3'
  )

  // « Je réponds », then nothing played: the clock sends a pass, too late.
  const submitted = page.waitForResponse(isRunSubmission)
  await player.getByTestId('calculation-ready').click()
  const result = (await (await submitted).json()).result
  expect([result.success, result.data.status, result.data.lives]).toEqual([
    false,
    'late',
    2
  ])
  await expect(player).toHaveAttribute('data-mood', 'pride')
  await expect(page.getByTestId('calculation-lives')).toHaveAttribute(
    'data-lives',
    '2'
  )
})

// Solved first, the opponent answering, then replayed from memory at once: the mini-boss of
// chapter 2 (its judging is covered by PHPUnit).
test('calculation: the mini-boss of chapter 2, solved then replayed', async ({
  page,
  context
}) => {
  await signIn(context, {
    mindMapCapacity: 'calculation',
    mindMapChapters: 1,
    mindMapSteps: ['2-1', '2-2', '2-3', '2-4', '2-5', '2-6', '2-7', '2-8']
  })
  await page.goto('/#/cerveau/calculation')
  await page.getByTestId('path-step-2-mini').click()
  const served = page.waitForResponse(isRunNext)
  await page.getByTestId('boss-intro').getByTestId('boss-fight').click()
  await expect(page).toHaveURL(/#\/training\/[^/?]+\?mindMap=1(&boss=\w+)?$/)
  const item = (await (await served).json()).item
  expect([item.data.solve, item.data.lives]).toEqual([true, 3])
  const solution = item.data.solution

  // Solving: the player's moves, the opponent answering by itself.
  const player = page.getByTestId('calculation-player')
  await expect(player).toHaveAttribute('data-phase', 'solve')
  for (let i = 0; i < solution.length; i += 2) {
    await playMove(page, solution[i])
    await expect(player).toHaveAttribute(
      'data-played',
      String(Math.min(i + 2, solution.length))
    )
  }

  // Back to the start: the whole line from memory, both sides.
  await expect(player).toHaveAttribute('data-phase', 'answer')
  await expect(player).toHaveAttribute('data-played', '0')
  const submitted = page.waitForResponse(isRunSubmission)
  const board = page.getByTestId('chess-board')
  const chess = new Chess(item.data.fen)
  for (let i = 0; i < solution.length; i++) {
    await playMove(page, solution[i])
    await expect(player).toHaveAttribute('data-played', String(i + 1))
    // The board ready for the next half-move (its position set).
    chess.move({
      from: solution[i].slice(0, 2),
      to: solution[i].slice(2, 4),
      promotion: solution[i][4]
    })
    if (i < solution.length - 1)
      await expect(board).toHaveAttribute('data-fen', chess.fen())
  }
  const result = (await (await submitted).json()).result
  expect([result.success, result.data.status]).toEqual([true, 'right'])
})

// Its questions and lives are covered by PHPUnit (tests/Functional/Vision/VisionCounterTest.php);
// here, the long line announced while its position stays in sight.
test('calculation: the Compteur, a long line announced on the position in sight', async ({
  page,
  context
}) => {
  await signIn(context, {
    mindMapCapacity: 'calculation',
    mindMapChapters: 1
  })
  await page.goto('/#/cerveau/calculation')
  await page.getByTestId('path-chapter-boss-2').click()
  const served = page.waitForResponse(isRunNext)
  await page.getByTestId('boss-intro').getByTestId('boss-fight').click()
  const item = (await (await served).json()).item
  expect([item.type, item.data.kind, item.data.keepShown]).toEqual([
    'vision',
    'counter',
    true
  ])

  const player = page.getByTestId('vision-player')
  await player.getByTestId('vision-memorized').click()
  await expect(player).toHaveAttribute('data-phase', 'announce', {
    timeout: 5_000
  })
  await expect(player.getByTestId('vision-veil')).toHaveCount(0)
  await expect(page.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    item.data.fen
  )
  await expect(player.getByTestId('vision-line')).toBeVisible()
  await expect(player).toHaveAttribute('data-phase', 'ask', { timeout: 20_000 })
})
