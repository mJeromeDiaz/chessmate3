import { execFileSync } from 'node:child_process'
import { expect } from '@playwright/test'
import { Chess } from 'chess.js'

const API_DIR = new URL('../../../api/', import.meta.url).pathname

/**
 * A fresh signed-in user: the API's e2e-only seed command creates it and returns a refresh token,
 * set as the HttpOnly cookie the SPA uses to restore its session on load (the email 2FA login
 * itself is covered by the Phase 1 tests).
 *
 * The streak celebration (docs/GAMIFICATION.md, "Annonce de la série") and the gift wheel after it
 * (docs/SHOP.md, § 4 ter) would cover the page after the user's first exercise: unless `streak` is
 * asked, the API is answered "none pending" and "no wheel nor chest" (the wheel stays offered on
 * the server, unspun: no crystal). The team's announcements (docs/ANNOUNCEMENTS.md) would cover
 * every page while one is live in the e2e database: unless `announcements` is asked, the API is
 * answered "none to see".
 *
 * @param {import('@playwright/test').BrowserContext} context
 * @param {{admin?: boolean, password?: string, streak?: boolean, crowns?: number, mindMapChapters?: number, mindMapSteps?: string[], mindMapCapacity?: string, enginePuzzles?: boolean, announcements?: boolean}} [options] an admin
 *   (docs/EARLY_ACCESS.md); a password, for a test that also signs in through the login page;
 *   the real streak announcements, gift wheels and badge chests; crowns of the store (docs/SHOP.md); the first chapters of the
 *   visualisation path completed (docs/MIND_MAP.md), and these steps of it; the puzzles of the
 *   calculation path's chapter 3 added to the catalogue (docs/CALCULATION.md, § 2 quater); the
 *   real announcements, and their emails kept on for the account (off for the others)
 * @returns {Promise<{id: string, email: string}>}
 */
export async function signIn(
  context,
  {
    admin = false,
    password,
    streak = false,
    crowns = 0,
    mindMapChapters = 0,
    mindMapSteps = [],
    mindMapCapacity = '',
    enginePuzzles = false,
    announcements = false
  } = {}
) {
  if (!streak) {
    await context.route('**/api/gamification/streak/notice', route =>
      route.fulfill({ json: { pending: false } })
    )
    await context.route('**/api/shop/daily-rewards/unopened', route =>
      route.fulfill({ json: { member: [] } })
    )
  }
  if (!announcements) {
    await context.route('**/api/announcements', route =>
      route.fulfill({ json: { announcements: [] } })
    )
  }
  const args = ['-d', 'xdebug.mode=off', 'bin/console', 'app:e2e:seed-user']
  if (admin) args.push('--admin')
  if (password) args.push(`--password=${password}`)
  if (crowns > 0) args.push(`--crowns=${crowns}`)
  if (mindMapChapters > 0) args.push(`--mind-map-chapters=${mindMapChapters}`)
  if (mindMapSteps.length)
    args.push(`--mind-map-steps=${mindMapSteps.join(',')}`)
  if (mindMapCapacity) args.push(`--mind-map-capacity=${mindMapCapacity}`)
  if (enginePuzzles) args.push('--engine-puzzles')
  if (announcements) args.push('--announcement-emails')
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
 * A pending position in one of the user's repertoires, as an analysis of their games would leave
 * it (docs/REPERTOIRE.md, "En suspens"): the API's e2e-only command, no Lichess.
 *
 * @param {string} repertoireId
 * @param {string} path the SAN moves leading there, e.g. "e4 c5"
 * @returns {string} its id
 */
export function seedPending(repertoireId, path) {
  return execFileSync(
    'php',
    [
      '-d',
      'xdebug.mode=off',
      'bin/console',
      'app:e2e:seed-pending',
      repertoireId,
      path
    ],
    { cwd: API_DIR, env: { ...process.env, APP_ENV: 'e2e' } }
  )
    .toString()
    .trim()
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
 * Centre of a square on the board, in page coordinates.
 *
 * @param {import('@playwright/test').Locator} board
 * @param {string} square
 */
async function squareCenter(board, square) {
  const box = await board
    .locator(`[data-square="${square}"]`)
    .first()
    .boundingBox()
  if (!box) throw new Error(`square ${square} not on screen`)
  return { x: box.x + box.width / 2, y: box.y + box.height / 2 }
}

/**
 * Clicks a square with the mouse, without Playwright's actionability checks: they wait for
 * animation frames, which a paused clock (`page.clock.pauseAt`) stops.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} square
 */
export async function clickSquare(page, square) {
  const { x, y } = await squareCenter(page.getByTestId('chess-board'), square)
  await page.mouse.click(x, y)
}

/**
 * Draws with the right button as a user would: an arrow from `from` to `to`, a circle when both
 * are the same square (docs/PUZZLES.md § 6).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} from
 * @param {string} [to]
 */
export async function rightDraw(page, from, to = from) {
  const board = page.getByTestId('chess-board')
  const start = await squareCenter(board, from)
  const end = await squareCenter(board, to)
  await page.mouse.move(start.x, start.y)
  await page.mouse.down({ button: 'right' })
  await page.mouse.move(end.x, end.y, { steps: 5 })
  await page.mouse.up({ button: 'right' })
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
 * its content), after the session's chest when one comes first.
 *
 * @param {import('@playwright/test').Page} page
 * @param {number} [timeout] how long the run may take to end
 */
export async function closeRunEnd(page, timeout) {
  const dialog = page.getByTestId('run-end')
  // A session completed with crowns shows its chest first (docs/SHOP.md, § 4 bis): closed unopened.
  const chest = page.getByTestId('treasure-chest')
  await expect(dialog.or(chest)).toBeVisible(timeout ? { timeout } : undefined)
  if (await chest.isVisible()) await chest.getByTestId('chest-close').click()
  await expect(dialog).toBeVisible()
  await dialog.getByTestId('run-end-close').click()
  await expect(dialog).toBeHidden()
}
