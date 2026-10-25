import { expect, test } from '@playwright/test'
import { playMove, signIn } from './helpers.js'
import {
  ENGINE_TIMEOUT,
  isRunNext,
  isRunSubmission,
  noMaia,
  tablebaseDown,
  tablebaseOfPawnRace
} from './endgames.js'

/**
 * The free page of the endgames module, `/finales/libre` (docs/ENDGAMES.md, § 9): one lesson
 * chosen, « Parfait », a learn run. The server's judge, the XP and the refusals are covered by
 * PHPUnit (tests/Functional/Endgame), the rules of the game by Vitest (tests/unit/endgame.test.js);
 * here, a real run with Stockfish judging in the browser (the tablebase down, § 8).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} lesson
 * @param {'learn'|'review'} [mode]
 */
async function startRun(page, lesson, mode = 'learn') {
  await page.goto('/#/finales/libre')
  await expect(page.getByTestId('endgame-lessons')).toBeVisible()
  // The first theme's lessons are chosen by default: none of them, then the lesson.
  await page.getByTestId('endgame-family-all-basicMates').click()
  await expect(page.getByTestId('endgame-no-lesson')).toBeVisible()
  await page.getByTestId(`endgame-lesson-${lesson}`).click()
  await expect(page.getByTestId(`endgame-lesson-${lesson}`)).toHaveAttribute(
    'aria-pressed',
    'true'
  )

  if (mode === 'review')
    await page
      .getByTestId('endgame-mode')
      .getByRole('button', { name: 'Réviser' })
      .click()

  const slider = page.getByTestId('endgame-elo')
  await slider.focus()
  await slider.press('PageUp')
  await slider.press('PageUp')
  await expect(page.getByTestId('endgame-settings')).toContainText(
    'Adversaire : Parfait'
  )

  const served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/?]+$/)
  return (await (await served).json()).item
}

test('a pawn race won against « Parfait », Stockfish judging while Lichess is down', async ({
  page,
  context
}) => {
  test.setTimeout(120_000)
  await signIn(context)
  await tablebaseDown(page)
  const item = await startRun(page, 'square-promote')
  expect(item.type).toBe('endgame_position')
  expect(item.data).toMatchObject({
    lesson: 'square-promote',
    goal: 'promote',
    result: 'win',
    textbook: true,
    mode: 'learn',
    opponent: { perfect: true }
  })

  const player = page.getByTestId('endgame-player')
  await expect(page.getByTestId('endgame-lesson-title')).toBeVisible()
  await expect(page.getByTestId('endgame-notice')).toContainText(
    'Lichess ne répond pas : Stockfish juge tes coups.'
  )

  // The pawn runs: whatever the black king does, it never catches it.
  const submitted = page.waitForResponse(isRunSubmission, {
    timeout: ENGINE_TIMEOUT * 2
  })
  const board = player.getByTestId('chess-board')
  await playMove(page, 'a5a6')
  // The move judged and the king's reply played: White to move, the pawn on a6.
  await expect(board).toHaveAttribute('data-fen', /^8\/8\/P7\/\S+ w /, {
    timeout: ENGINE_TIMEOUT
  })
  await expect(player).toHaveAttribute('data-phase', 'player')
  await playMove(page, 'a6a7')
  await expect(board).toHaveAttribute('data-fen', /^8\/P7\/\S+ w /, {
    timeout: ENGINE_TIMEOUT
  })
  await expect(player).toHaveAttribute('data-phase', 'player')
  await playMove(page, 'a7a8q')
  const response = await submitted
  const sent = response.request().postDataJSON()
  expect(sent.moves.filter((_, i) => i % 2 === 0)).toEqual([
    'a5a6',
    'a6a7',
    'a7a8q'
  ])
  expect(sent.moves).toHaveLength(5)
  expect(sent.engine).toMatchObject({ right: true })

  const { result } = await response.json()
  expect(result.success).toBe(true)
  expect(result.data).toMatchObject({ status: 'won', engineJudged: true })
  await expect(player).toHaveAttribute('data-outcome', 'won')
  await expect(page.getByText('Gagné !')).toBeVisible()
  await expect(page.getByTestId('endgame-engine-judged')).toBeVisible()

  // The run goes on: the textbook position again (no variant in the e2e pool).
  const served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-next').click()
  const next = (await (await served).json()).item
  expect(next.data).toMatchObject({ lesson: 'square-promote', index: 2 })
  await expect(page.getByTestId('run-progress')).toContainText(
    'Position 2 · 1 réussie'
  )
})

test('a queen given away spoils the win: the position missed, the demonstration unavailable', async ({
  page,
  context
}) => {
  test.setTimeout(120_000)
  await signIn(context)
  await tablebaseDown(page)
  const item = await startRun(page, 'kqk')
  expect(item.data).toMatchObject({ lesson: 'kqk', goal: 'mate' })

  const player = page.getByTestId('endgame-player')
  const submitted = page.waitForResponse(isRunSubmission, {
    timeout: ENGINE_TIMEOUT * 2
  })
  // Qd4+?? Kxd4: a king against a king.
  await playMove(page, 'd1d4')
  const response = await submitted
  expect(response.request().postDataJSON()).toMatchObject({
    moves: ['d1d4'],
    engine: { right: false, mistakeAt: 0 }
  })
  const { result } = await response.json()
  expect(result.success).toBe(false)
  expect(result.data).toMatchObject({ status: 'spoiled', engineJudged: true })
  await expect(player).toHaveAttribute('data-outcome', 'spoiled')
  await expect(page.getByText('Le résultat s’envole')).toBeVisible()

  // The demonstration needs the tablebase: it says so (no Stockfish there).
  await page.getByTestId('endgame-demo-watch').click()
  await expect(page.getByTestId('endgame-demo-status')).toHaveAttribute(
    'data-status',
    'unavailable'
  )
  await expect(page.getByTestId('endgame-demo-status')).toContainText(
    'Lichess ne répond pas'
  )
})

test('the demonstration of a lesson plays its line up to the goal', async ({
  page,
  context
}) => {
  await signIn(context)
  await noMaia(page)
  await tablebaseOfPawnRace(page)
  await page.goto('/#/finales/libre')
  await page.getByTestId('endgame-demo-square-promote').click()

  const demo = page.getByTestId('endgame-demo')
  await expect(demo).toBeVisible()
  // Played by itself, a half-move a second: a6, the king, a7, the king, a8=D.
  await expect(demo.getByTestId('endgame-demo-status')).toHaveAttribute(
    'data-status',
    'done',
    { timeout: 15_000 }
  )
  const moves = demo.locator('.endgame-demo__san')
  await expect(moves).toHaveCount(5)
  await expect(moves.first()).toHaveText('a6')
  await expect(moves.last()).toHaveText('a8=D')
  await expect(demo.getByTestId('endgame-demo-status')).toContainText(
    'Fin de la démonstration.',
    { timeout: 15_000 }
  )

  // Back to the start (it plays again, a second later), then a half-move at a time (paused: the
  // pawn on a6, whether the king moved after it or not).
  await demo.getByTestId('endgame-demo-restart').click()
  await expect(demo.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    /^8\/8\/8\/P7\/8\/8\/5k2\/7K w/
  )
  await demo.getByTestId('endgame-demo-next').click()
  await expect(demo.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    /^8\/8\/P7\//
  )
  await demo.getByTestId('endgame-demo-close').click()
  await expect(demo).toBeHidden()
})

/**
 * The net of the pawn race (« square-promote », 8/8/8/P7/8/8/5k2/7K w): the pawn's capture b6 and
 * the king's g1, g2, h2.
 */
const PAWN_RACE_NET = 4

test('the net: shown from the start in learn mode, switched off and on', async ({
  page,
  context
}) => {
  await signIn(context)
  await tablebaseDown(page)
  const item = await startRun(page, 'square-promote')
  expect(item.data.net).toBe('shown')

  const board = page.getByTestId('endgame-player').getByTestId('chess-board')
  const net = page.getByTestId('endgame-net')
  await expect(net).toHaveAttribute('aria-pressed', 'true')
  await expect(board.locator('.cb-marker-net')).toHaveCount(PAWN_RACE_NET)
  for (const square of ['b6', 'g1', 'g2', 'h2'])
    await expect(
      board.locator(`[data-square="${square}"] .cb-marker-net`)
    ).toHaveCount(1)
  // Free outside the path: no warning.
  await expect(page.getByTestId('endgame-net-note')).toHaveCount(0)

  await net.click()
  await expect(net).toHaveAttribute('aria-pressed', 'false')
  await expect(board.locator('.cb-marker-net')).toHaveCount(0)
  await net.click()
  await expect(board.locator('.cb-marker-net')).toHaveCount(PAWN_RACE_NET)

  // Shown from the start: nothing to report.
  const submitted = page.waitForResponse(isRunSubmission)
  await page.getByTestId('endgame-pass').click()
  await page.getByRole('button', { name: 'Passer' }).last().click()
  expect((await submitted).request().postDataJSON().net).toBeUndefined()
})

test('the net: offered in review mode, switched on, it goes with the submission', async ({
  page,
  context
}) => {
  await signIn(context)
  await tablebaseDown(page)
  const item = await startRun(page, 'square-promote', 'review')
  expect(item.data).toMatchObject({ mode: 'review', net: 'offered' })

  const board = page.getByTestId('endgame-player').getByTestId('chess-board')
  const net = page.getByTestId('endgame-net')
  await expect(net).toHaveAttribute('aria-pressed', 'false')
  await expect(board.locator('.cb-marker-net')).toHaveCount(0)
  await net.click()
  await expect(board.locator('.cb-marker-net')).toHaveCount(PAWN_RACE_NET)

  const submitted = page.waitForResponse(isRunSubmission)
  await page.getByTestId('endgame-pass').click()
  await page.getByRole('button', { name: 'Passer' }).last().click()
  const response = await submitted
  expect(response.request().postDataJSON().net).toBe(true)
  expect((await response.json()).result.data.netUsed).toBe(true)
})

test('the net offered in the path warns that the pass will not count', async ({
  page,
  context
}) => {
  await signIn(context)
  await tablebaseDown(page)
  // The position as a path's third pass serves it (a count of positions); its server side is
  // covered by PHPUnit (EndgamePathTest).
  await page.route(/\/api\/training\/runs\/[^/]+\/next$/, async route => {
    const response = await route.fetch()
    const body = await response.json()
    body.item.data = { ...body.item.data, net: 'offered', count: 5 }
    await route.fulfill({ response, json: body })
  })
  await startRun(page, 'square-promote', 'review')

  const note = page.getByTestId('endgame-net-note')
  await expect(note).toContainText('ce passage ne comptera pas')
  await page.getByTestId('endgame-net').click()
  await expect(note).toContainText('Maillage utilisé')
})
