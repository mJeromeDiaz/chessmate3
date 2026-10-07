import { execFileSync } from 'node:child_process'
import { expect } from '@playwright/test'
import { Chess } from 'chess.js'

const API_DIR = new URL('../../../api/', import.meta.url).pathname

/**
 * A fresh signed-in user: the API's e2e-only seed command creates it and returns a refresh token,
 * set as the HttpOnly cookie the SPA uses to restore its session on load (the email 2FA login
 * itself is covered by the Phase 1 tests).
 *
 * The streak celebration (docs/GAMIFICATION.md, "Annonce de la série") would cover the page after
 * the user's first exercise: unless `streak` is asked, the API is answered "none pending".
 *
 * @param {import('@playwright/test').BrowserContext} context
 * @param {{admin?: boolean, password?: string, streak?: boolean}} [options] an admin
 *   (docs/EARLY_ACCESS.md); a password, for a test that also signs in through the login page;
 *   the real streak announcements
 * @returns {Promise<{id: string, email: string}>}
 */
export async function signIn(
  context,
  { admin = false, password, streak = false } = {}
) {
  if (!streak) {
    await context.route('**/api/gamification/streak/notice', route =>
      route.fulfill({ json: { pending: false } })
    )
  }
  const args = ['-d', 'xdebug.mode=off', 'bin/console', 'app:e2e:seed-user']
  if (admin) args.push('--admin')
  if (password) args.push(`--password=${password}`)
  const output = execFileSync('php', args, {
    cwd: API_DIR,
    env: { ...process.env, APP_ENV: 'e2e' }
  })
  const { id, email, refreshToken } = JSON.parse(output.toString().trim())
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
  return { id, email }
}

/**
 * Runs the API's worker on the `async` queue for a moment, as production's would: the emails
 * (invitations, address verification) leave through it. Nothing runs it in e2e otherwise.
 *
 * @param {number} [seconds] how long the worker stays up (it handles everything queued meanwhile)
 */
export function consumeQueue(seconds = 3) {
  execFileSync(
    'php',
    [
      '-d',
      'xdebug.mode=off',
      'bin/console',
      'messenger:consume',
      'async',
      `--time-limit=${seconds}`,
      '--quiet'
    ],
    { cwd: API_DIR, env: { ...process.env, APP_ENV: 'e2e' } }
  )
}

/**
 * Plays a UCI move by clicking the origin then the destination square.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} uci
 * @param {import('@playwright/test').Locator} [root] where the board is (a dialog), the page by default
 */
export async function playMove(page, uci, root) {
  const board = (root ?? page).getByTestId('chess-board')
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

/**
 * Plays the player's moves of a puzzle, waiting for each opponent reply to be on the board.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{fen: string, moves: string[]}} puzzle
 * @param {import('@playwright/test').Locator} [root] where the board is (a dialog), the page by default
 */
export async function solve(page, puzzle, root) {
  const board = (root ?? page).getByTestId('chess-board')
  for (let i = 1; i < puzzle.moves.length; i += 2) {
    await expect(board).toHaveAttribute('data-fen', fenAfter(puzzle, i))
    await playMove(page, puzzle.moves[i], root)
  }
}

/**
 * FEN after the first `count` moves of the puzzle line.
 *
 * @param {{fen: string, moves: string[]}} puzzle
 * @param {number} count
 */
export function fenAfter(puzzle, count) {
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

/**
 * Closes the end-of-run review that opens over the page when a run is over (its own tests check
 * its content).
 *
 * @param {import('@playwright/test').Page} page
 * @param {number} [timeout] how long the run may take to end
 */
export async function closeRunEnd(page, timeout) {
  const dialog = page.getByTestId('run-end')
  await expect(dialog).toBeVisible(timeout ? { timeout } : undefined)
  await dialog.getByTestId('run-end-close').click()
  await expect(dialog).toBeHidden()
}
