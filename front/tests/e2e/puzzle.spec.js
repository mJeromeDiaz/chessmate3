import { execFileSync } from 'node:child_process'
import { expect, test } from '@playwright/test'
import { Chess } from 'chess.js'

const API_DIR = new URL('../../../api/', import.meta.url).pathname

/**
 * A fresh signed-in user: the API's e2e-only seed command creates it and returns a refresh token,
 * set as the HttpOnly cookie the SPA uses to restore its session on load (the email 2FA login
 * itself is covered by the Phase 1 tests).
 *
 * @param {import('@playwright/test').BrowserContext} context
 */
async function signIn(context) {
  const output = execFileSync(
    'php',
    ['-d', 'xdebug.mode=off', 'bin/console', 'app:e2e:seed-user'],
    { cwd: API_DIR, env: { ...process.env, APP_ENV: 'e2e' } }
  )
  const { refreshToken } = JSON.parse(output.toString().trim())
  await context.addCookies([
    {
      name: 'refresh_token',
      value: refreshToken,
      domain: 'localhost',
      path: '/api/auth',
      httpOnly: true,
      sameSite: 'Strict'
    }
  ])
}

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
    'Trouvez le meilleur coup'
  )
  return attempt
}

/**
 * Plays a UCI move by clicking the origin then the destination square.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} uci
 */
async function playMove(page, uci) {
  const board = page.getByTestId('chess-board')
  await board
    .locator(`[data-square="${uci.slice(0, 2)}"]`)
    .first()
    .click()
  await board
    .locator(`[data-square="${uci.slice(2, 4)}"]`)
    .first()
    .click()
  if (uci.length === 5) {
    await board
      .getByRole('dialog', { name: 'Choose promotion piece' })
      .locator(`[role="button"][data-piece$="${uci[4]}"]`)
      .click()
  }
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

  await expect(page.getByTestId('puzzle-result')).toContainText('Réussi')
  await expect(page.getByTestId('puzzle-rating').first()).not.toContainText(
    '1500'
  )
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
  await expect(page.getByTestId('puzzle-result')).toContainText('Échoué', {
    timeout: 20_000
  })
  await expect(page.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    fenAfter(attempt.puzzle, attempt.puzzle.moves.length)
  )
})

/**
 * FEN after the first `count` moves of the puzzle line.
 *
 * @param {{fen: string, moves: string[]}} puzzle
 * @param {number} count
 */
function fenAfter(puzzle, count) {
  const chess = new Chess(puzzle.fen)
  for (const uci of puzzle.moves.slice(0, count)) {
    chess.move({
      from: uci.slice(0, 2),
      to: uci.slice(2, 4),
      promotion: uci[4]
    })
  }
  return chess.fen()
}
