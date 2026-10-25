import { expect, test } from '@playwright/test'
import { Chess } from 'chess.js'
import { playMove, signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())

/** Stockfish loads in a worker: give it time on a cold start. */
const ENGINE_TIMEOUT = 40_000

/**
 * The position after UCI moves.
 *
 * @param {string} fen
 * @param {string[]} moves
 */
function after(fen, moves) {
  const chess = new Chess(fen)
  for (const uci of moves)
    chess.move({
      from: uci.slice(0, 2),
      to: uci.slice(2, 4),
      promotion: uci[4]
    })
  return chess
}

/**
 * A line of `plies` half-moves from `start` (cut at a mate), each the first legal move.
 *
 * @param {string} fen
 * @param {string[]} start
 * @param {number} plies
 */
function completeLine(fen, start, plies) {
  const line = [...start]
  const chess = after(fen, line)
  while (line.length < plies && !chess.isGameOver()) {
    const move = chess.moves({ verbose: true })[0]
    chess.move(move)
    line.push(move.lan)
  }
  return line
}

/**
 * Opens a step of chapter 3 of the calculation path, the steps before it completed.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').BrowserContext} context
 * @param {string} step
 * @param {string[]} done
 */
async function openStep(page, context, step, done) {
  await signIn(context, {
    mindMapCapacity: 'calculation',
    mindMapChapters: 2,
    mindMapSteps: done,
    enginePuzzles: true
  })
  await page.goto('/#/cerveau/calculation')
  await page.getByTestId(`path-step-${step}`).click()
  const served = page.waitForResponse(isRunNext)
  await page.getByTestId('step-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/?]+\?mindMap=1(&boss=\w+)?$/)
  return (await (await served).json()).item
}

/**
 * The next line of the run, once the one before is judged.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Promise<import('@playwright/test').Response>} served
 */
async function nextItem(page, served) {
  const next = page.getByTestId('run-next')
  if (await next.isVisible()) await next.click()
  return (await (await served).json()).item
}

// What the server accepts (shape, legality, time) is covered by PHPUnit
// (tests/Functional/Calculation/CalculationPathTest.php), the verdict's rules by Vitest
// (tests/unit/calculation.test.js); here, the real Stockfish in the browser.
test('calculation 3.1: the decoy drawn by the engine, the best reply chosen', async ({
  page,
  context
}) => {
  const item = await openStep(page, context, '3-1', [])
  expect([item.data.kind, item.data.engine]).toEqual(['reply', true])
  const [first, reply] = item.data.solution

  const player = page.getByTestId('calculation-player')
  await expect(player).toHaveAttribute('data-phase', 'choose')
  await expect(page.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    after(item.data.fen, [first]).fen()
  )
  const choices = player.getByTestId('calculation-choice')
  await expect(choices).toHaveCount(2, { timeout: ENGINE_TIMEOUT })
  const offered = await choices.evaluateAll(buttons =>
    buttons.map(b => b.getAttribute('data-uci'))
  )
  expect(offered).toContain(reply)
  expect(new Set(offered).size).toBe(2)

  const submitted = page.waitForResponse(isRunSubmission)
  await player.locator(`[data-uci="${reply}"]`).click()
  const response = await submitted
  expect(response.request().postDataJSON()).toMatchObject({
    choice: reply,
    engine: { right: true }
  })
  const result = (await response.json()).result
  expect([result.success, result.data.status]).toEqual([true, 'right'])
})

test('calculation 3.2: a line against each of the two replies, the second started by itself', async ({
  page,
  context
}) => {
  const item = await openStep(page, context, '3-2', ['3-1'])
  expect(item.data.kind).toBe('two_replies')
  const solution = item.data.solution
  const player = page.getByTestId('calculation-player')
  await expect(player).toHaveAttribute('data-phase', 'think')
  await player.getByTestId('calculation-ready').click()
  await expect(player).toHaveAttribute('data-phase', 'answer')
  await expect(player).toHaveAttribute('data-line', '1')

  for (let i = 0; i < solution.length; i++) {
    await playMove(page, solution[i])
    if (i < solution.length - 1)
      await expect(player).toHaveAttribute('data-played', String(i + 1))
  }

  // The second line: the first move and the other reply played by themselves.
  await expect(player).toHaveAttribute('data-line', '2')
  await expect(player).toHaveAttribute('data-played', '2')
  const other = new Chess(after(item.data.fen, solution.slice(0, 1)).fen())
    .moves({ verbose: true })
    .map(m => m.lan)
    .find(uci => uci !== solution[1])
  const second = completeLine(
    item.data.fen,
    [solution[0], other],
    solution.length
  )
  const submitted = page.waitForResponse(isRunSubmission, {
    timeout: ENGINE_TIMEOUT
  })
  for (const uci of second.slice(2)) await playMove(page, uci)
  const response = await submitted
  const body = response.request().postDataJSON()
  expect(body.lines).toEqual([solution, second])
  expect(typeof body.engine.right).toBe('boolean')
  expect(['right', 'wrong']).toContain(
    (await response.json()).result.data.status
  )
})

test('calculation 3.4: the solution right at once, another line judged by Stockfish', async ({
  page,
  context
}) => {
  let item = await openStep(page, context, '3-4', ['3-1', '3-2', '3-3'])
  expect(item.data.kind).toBe('intermezzo')
  const player = page.getByTestId('calculation-player')

  // The solution: right, the engine never waited for.
  await player.getByTestId('calculation-ready').click()
  await expect(player).toHaveAttribute('data-phase', 'answer')
  let submitted = page.waitForResponse(isRunSubmission)
  let served = page.waitForResponse(isRunNext)
  for (const uci of item.data.solution) await playMove(page, uci)
  let response = await submitted
  expect(response.request().postDataJSON()).toMatchObject({
    moves: item.data.solution,
    engine: { right: true }
  })
  expect((await response.json()).result.data.status).toBe('right')

  // Another first move: the line goes to Stockfish before it is sent.
  item = await nextItem(page, served)
  const solution = item.data.solution
  const first = new Chess(item.data.fen)
    .moves({ verbose: true })
    .map(m => m.lan)
    .find(uci => uci !== solution[0])
  const line = completeLine(item.data.fen, [first], solution.length)
  await expect(player).toHaveAttribute('data-phase', 'think')
  await player.getByTestId('calculation-ready').click()
  await expect(player).toHaveAttribute('data-phase', 'answer')
  submitted = page.waitForResponse(isRunSubmission, { timeout: ENGINE_TIMEOUT })
  for (const uci of line) await playMove(page, uci)
  response = await submitted
  const body = response.request().postDataJSON()
  expect(body.moves).toEqual(line)
  expect(typeof body.engine.right).toBe('boolean')
  const status = (await response.json()).result.data.status
  expect(['right', 'wrong']).toContain(status)
  await expect(player.getByTestId('calculation-engine-down')).toHaveCount(0)
})

// The puzzles drawn vary (many samples start with a check or a capture among others): a decoy is
// chosen; refuted by the engine (most are), a failure at once, else its line goes on.
test('calculation: the mini-boss of chapter 3, a decoy, then the combination that holds', async ({
  page,
  context
}) => {
  await signIn(context, {
    mindMapCapacity: 'calculation',
    mindMapChapters: 2,
    mindMapSteps: ['3-1', '3-2', '3-3', '3-4'],
    enginePuzzles: true
  })
  await page.goto('/#/cerveau/calculation')
  await page.getByTestId('path-step-3-mini').click()
  let served = page.waitForResponse(isRunNext)
  await page.getByTestId('step-detail').getByTestId('boss-fight').click()
  let item = (await (await served).json()).item
  expect([item.data.kind, item.data.combos, item.data.lives]).toEqual([
    'combo',
    3,
    3
  ])

  const player = page.getByTestId('calculation-player')
  await expect(player).toHaveAttribute('data-phase', 'combo')
  const combos = player.getByTestId('calculation-combo')
  await expect(combos).toHaveCount(3, { timeout: ENGINE_TIMEOUT })
  const offered = await combos.evaluateAll(buttons =>
    buttons.map(b => b.getAttribute('data-uci'))
  )
  expect(offered).toContain(item.data.solution[0])
  const decoy = offered.find(uci => uci !== item.data.solution[0])
  const submitted = page.waitForResponse(isRunSubmission, {
    timeout: ENGINE_TIMEOUT
  })
  served = page.waitForResponse(isRunNext, { timeout: ENGINE_TIMEOUT })
  await player.locator(`[data-uci="${decoy}"]`).click()
  await expect(player).toHaveAttribute(
    'data-phase',
    /^(answer|sending|complete)$/
  )
  let first
  if ((await player.getAttribute('data-phase')) === 'answer') {
    // As good as the solution for the engine: its line goes on.
    const line = completeLine(item.data.fen, [decoy], item.data.solution.length)
    for (const uci of line.slice(1)) await playMove(page, uci)
    first = (await (await submitted).json()).result
  } else {
    first = (await (await submitted).json()).result
    expect([
      first.data.status,
      first.data.mistakeAt,
      first.data.answer.length,
      first.data.lives
    ]).toEqual(['wrong', 0, 1, 2])
  }

  // The solution's first move: played by itself, the rest of the line to play.
  if (!first.success) await page.getByTestId('run-next').click()
  item = (await (await served).json()).item
  const solution = item.data.solution
  await expect(combos).toHaveCount(3, { timeout: ENGINE_TIMEOUT })
  await player.locator(`[data-uci="${solution[0]}"]`).click()
  await expect(player).toHaveAttribute('data-phase', 'answer')
  await expect(player).toHaveAttribute('data-played', '1')
  const lineSent = page.waitForResponse(isRunSubmission)
  for (const uci of solution.slice(1)) await playMove(page, uci)
  const response = await lineSent
  expect(response.request().postDataJSON().moves).toEqual(solution)
  const result = (await response.json()).result
  expect([result.success, result.data.status]).toEqual([true, 'right'])
})

// The Avocat du diable: an idea of the player's own (the first legal move, rarely the best), then
// the solution. An idea that falls says so on the sheet; his face follows the verdicts.
test('calculation: the Avocat du diable judges the idea, then its refutation', async ({
  page,
  context
}) => {
  await signIn(context, {
    mindMapCapacity: 'calculation',
    mindMapChapters: 2,
    enginePuzzles: true
  })
  await page.goto('/#/cerveau/calculation')
  await page.getByTestId('path-step-3-boss').click()
  let served = page.waitForResponse(isRunNext)
  await page.getByTestId('step-detail').getByTestId('boss-fight').click()
  let item = (await (await served).json()).item
  expect([item.data.kind, item.data.count, item.data.lives]).toEqual([
    'advocate',
    5,
    3
  ])

  const player = page.getByTestId('calculation-player')
  await expect(player).toHaveAttribute('data-kind', 'advocate')
  await expect(player).toHaveAttribute('data-mood', 'focus')
  const first = new Chess(item.data.fen)
    .moves({ verbose: true })
    .map(m => m.lan)
    .find(uci => uci !== item.data.solution[0])
  const idea = completeLine(item.data.fen, [first], item.data.solution.length)
  await page.getByTestId('calculation-ready').click()
  await expect(player).toHaveAttribute('data-phase', 'answer')
  let submitted = page.waitForResponse(isRunSubmission, {
    timeout: ENGINE_TIMEOUT
  })
  served = page.waitForResponse(isRunNext, { timeout: ENGINE_TIMEOUT })
  for (const uci of idea) await playMove(page, uci)
  let response = await submitted
  expect(typeof response.request().postDataJSON().engine.right).toBe('boolean')
  let result = (await response.json()).result
  expect(['right', 'wrong']).toContain(result.data.status)
  if (result.data.status === 'wrong') {
    await expect(player).toHaveAttribute('data-mood', 'joy')
    if (result.data.mistakeAt === 0)
      await expect(page.getByTestId('calculation-result')).toContainText(
        'Ton idée ne tenait pas'
      )
    await page.getByTestId('run-next').click()
  }

  // The solution: right, whatever the engine says.
  item = (await (await served).json()).item
  const solution = item.data.solution
  await page.getByTestId('calculation-ready').click()
  await expect(player).toHaveAttribute('data-phase', 'answer')
  submitted = page.waitForResponse(isRunSubmission)
  for (const uci of solution) await playMove(page, uci)
  response = await submitted
  result = (await response.json()).result
  expect([result.success, result.data.status]).toEqual([true, 'right'])
  await expect(player).toHaveAttribute('data-mood', 'sadness')
})
