import { expect, test } from '@playwright/test'
import { Chess } from 'chess.js'
import {
  clickSquare,
  fenAfter,
  playMove,
  rightDraw,
  signIn
} from './helpers.js'

/**
 * Opens the puzzle page and returns the attempt the API handed out (solution included).
 *
 * @param {import('@playwright/test').Page} page
 */
async function openPuzzle(page) {
  const started = page.waitForResponse(
    r =>
      r.url().endsWith('/api/puzzles/attempts') &&
      r.request().method() === 'POST'
  )
  await page.goto('/#/puzzle')
  const attempt = await (await started).json()
  // The opponent's first move is played automatically.
  await expect(page.getByTestId('puzzle-status')).toContainText(
    'Trouve le meilleur coup'
  )
  return attempt
}

test('solves a puzzle', async ({ page, context }) => {
  await signIn(context)
  const attempt = await openPuzzle(page)
  const moves = attempt.puzzle.moves

  const board = page.getByTestId('chess-board')
  let submitted = null
  for (let i = 1; i < moves.length; i += 2) {
    // Wait until the opponent's move is on the board (animation over) before playing.
    await expect(board).toHaveAttribute('data-fen', fenAfter(attempt.puzzle, i))
    if (i + 1 >= moves.length) {
      submitted = page.waitForResponse(r => r.url().endsWith('/submission'))
    }
    await playMove(page, moves[i])
  }
  expect((await (await submitted).json()).status).toBe('solved')

  // The result sheet: a success, with the XP the server announced.
  await expect(page.getByTestId('result-sheet')).toHaveAttribute(
    'data-kind',
    'win'
  )
  await expect(page.getByTestId('result-xp')).toHaveText('+10 XP')
  await expect(page.getByTestId('prof-bubble')).toContainText('BRAVO')
  await expect(page.getByTestId('puzzle-rating').first()).not.toContainText(
    '1500'
  )
})

/**
 * Opens the puzzle page with the clock paused as the puzzle arrives: its position is shown and the
 * opponent's first move (600 ms later) waits for `page.clock.resume()`. Returns the attempt.
 *
 * @param {import('@playwright/test').Page} page
 */
async function openPausedPuzzle(page) {
  await page.clock.install()
  /** @type {any} */
  let attempt = null
  await page.route('**/api/puzzles/attempts', async route => {
    if (route.request().method() !== 'POST') return route.fallback()
    const response = await route.fetch()
    attempt = await response.json()
    const now = await page.evaluate(() => Date.now())
    await page.clock.pauseAt(now + 10)
    await route.fulfill({ response })
  })
  await page.goto('/#/puzzle')
  await expect.poll(() => attempt).not.toBeNull()
  // The board is created with the puzzle's position, before any animation.
  await expect(page.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    attempt.puzzle.fen
  )
  return attempt
}

test('premoves the first move while the opponent plays: played as soon as the player may move', async ({
  page,
  context
}) => {
  await signIn(context)
  const attempt = await openPausedPuzzle(page)
  const board = page.getByTestId('chess-board')
  const moves = attempt.puzzle.moves

  await clickSquare(page, moves[1].slice(0, 2))
  await clickSquare(page, moves[1].slice(2, 4))
  // Kept, its two squares marked; the piece itself has not moved.
  await expect(board.locator('.cb-marker-premove')).toHaveCount(2)
  await expect(board).toHaveAttribute('data-fen', attempt.puzzle.fen)

  // The opponent plays, then the premove at once, as a move played by hand.
  let submitted =
    moves.length === 2
      ? page.waitForResponse(r => r.url().endsWith('/submission'))
      : null
  await page.clock.resume()
  for (let i = 3; i < moves.length; i += 2) {
    await expect(board).toHaveAttribute('data-fen', fenAfter(attempt.puzzle, i))
    if (i + 1 >= moves.length) {
      submitted = page.waitForResponse(r => r.url().endsWith('/submission'))
    }
    await playMove(page, moves[i])
  }
  expect((await (await submitted).json()).status).toBe('solved')
  await expect(board.locator('.cb-marker-premove')).toHaveCount(0)
  await expect(page.getByTestId('result-sheet')).toHaveAttribute(
    'data-kind',
    'win'
  )
})

test('draws arrows and circles with the right button, erased by a new position and by a left click', async ({
  page,
  context
}) => {
  await signIn(context)
  const attempt = await openPausedPuzzle(page)
  const board = page.getByTestId('chess-board')
  const arrow = board.locator('.arrows [data-arrow="a1a8"]')
  const circle = board.locator('[data-square="h1"] .marker-circle-success')

  // Green by default: an arrow dragged, a circle clicked in place.
  await rightDraw(page, 'a1', 'a8')
  await rightDraw(page, 'h1')
  await expect(arrow).toHaveClass(/arrow-success/)
  await expect(circle).toHaveCount(1)
  // The same arrow drawn again goes away.
  await rightDraw(page, 'a1', 'a8')
  await expect(arrow).toHaveCount(0)

  // The opponent's move brings a new position: the drawings go.
  await rightDraw(page, 'a1', 'a8')
  await expect(arrow).toHaveCount(1)
  await page.clock.resume()
  const position = new Chess(fenAfter(attempt.puzzle, 1))
  await expect(board).toHaveAttribute('data-fen', position.fen())
  await expect(arrow).toHaveCount(0)
  await expect(circle).toHaveCount(0)

  // A left click on the board erases them too.
  const empty = /** @type {string} */ (
    'abcdefgh'
      .split('')
      .flatMap(file => '12345678'.split('').map(rank => file + rank))
      .find(square => !position.get(/** @type {any} */ (square)))
  )
  await rightDraw(page, 'a1', 'a8')
  await rightDraw(page, 'h1')
  await expect(arrow).toHaveCount(1)
  await expect(circle).toHaveCount(1)
  await clickSquare(page, empty)
  await expect(arrow).toHaveCount(0)
  await expect(circle).toHaveCount(0)
  // Nothing was played.
  await expect(board).toHaveAttribute('data-fen', position.fen())
})

test('fails a puzzle, then sees the solution', async ({ page, context }) => {
  await signIn(context)
  const attempt = await openPuzzle(page)
  const position = new Chess(fenAfter(attempt.puzzle, 1))
  const wrong = position
    .moves({ verbose: true })
    .map(m => m.from + m.to + (m.promotion ?? ''))
    .find(uci => {
      const probe = new Chess(position.fen())
      probe.move({
        from: uci.slice(0, 2),
        to: uci.slice(2, 4),
        promotion: uci[4]
      })
      return (
        uci !== attempt.puzzle.moves[1] &&
        !probe.isCheckmate() &&
        uci.length === 4
      )
    })

  await expect(page.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    position.fen()
  )
  const submitted = page.waitForResponse(r => r.url().endsWith('/submission'))
  await playMove(page, wrong)

  await expect(page.getByTestId('puzzle-status')).toContainText(
    'Ce n’est pas le bon coup'
  )
  expect((await (await submitted).json()).status).toBe('failed')
  // The wrong move was taken back.
  await expect(page.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    position.fen()
  )

  await page.getByTestId('puzzle-solution').click()
  await expect(page.getByTestId('result-title')).toHaveText('Raté !', {
    timeout: 20_000
  })
  await expect(page.getByTestId('result-sheet')).toHaveAttribute(
    'data-kind',
    'miss'
  )
  await expect(page.getByTestId('result-xp')).toHaveText('+3 XP')
  await expect(page.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    fenAfter(attempt.puzzle, attempt.puzzle.moves.length)
  )
})

test('skips a puzzle: a failure once confirmed, then the next one', async ({
  page,
  context
}) => {
  await signIn(context)
  const attempt = await openPuzzle(page)

  await page.getByTestId('puzzle-skip').click()
  // Cancelling keeps the puzzle going.
  await page
    .getByRole('dialog')
    .getByRole('button', { name: 'Annuler' })
    .click()
  await expect(page.getByTestId('puzzle-skip')).toBeEnabled()

  await page.getByTestId('puzzle-skip').click()
  const submitted = page.waitForResponse(r => r.url().endsWith('/submission'))
  const started = page.waitForResponse(
    r =>
      r.url().endsWith('/api/puzzles/attempts') &&
      r.request().method() === 'POST'
  )
  await page.getByRole('dialog').getByRole('button', { name: 'Passer' }).click()

  const result = await (await submitted).json()
  expect(result.status).toBe('failed')
  expect(result.ratingDelta).toBeLessThan(0)
  const next = await (await started).json()
  expect(next.id).not.toBe(attempt.id)
  // No result sheet: straight to the next puzzle.
  await expect(page.getByTestId('result-sheet')).toHaveCount(0)
  await expect(page.getByTestId('puzzle-status')).toContainText(
    'Trouve le meilleur coup'
  )
})

test('picks themes and difficulty in the settings', async ({
  page,
  context
}) => {
  await signIn(context)
  await openPuzzle(page)

  await page.getByTestId('puzzle-settings').click()
  const dialog = page.getByTestId('puzzle-settings-dialog')
  await dialog
    .getByTestId('puzzle-settings-theme')
    .filter({ hasText: 'Clouage' })
    .click()
  await dialog.getByRole('button', { name: 'Plus difficile' }).click()
  await page.getByTestId('puzzle-settings-apply').click()

  await expect(dialog).toBeHidden()
  await expect(page.getByTestId('puzzle-theme-filter')).toHaveText('Clouage')
  await expect(page.getByTestId('puzzle-difficulty-filter')).toHaveText(
    'Plus difficile'
  )
})
