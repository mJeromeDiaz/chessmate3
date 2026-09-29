import { execFileSync } from 'node:child_process'
import { expect } from '@playwright/test'
import { Chess } from 'chess.js'

const API_DIR = new URL('../../../api/', import.meta.url).pathname

/**
 * A fresh signed-in user: the API's e2e-only seed command creates it and returns a refresh token,
 * set as the HttpOnly cookie the SPA uses to restore its session on load (the email 2FA login
 * itself is covered by the Phase 1 tests).
 *
 * @param {import('@playwright/test').BrowserContext} context
 */
export async function signIn(context) {
  const output = execFileSync(
    'php',
    ['-d', 'xdebug.mode=off', 'bin/console', 'app:e2e:seed-user'],
    {
      cwd: API_DIR,
      env: { ...process.env, APP_ENV: 'e2e' }
    }
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
 * Plays a UCI move by clicking the origin then the destination square.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} uci
 */
export async function playMove(page, uci) {
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

/**
 * Plays the player's moves of a puzzle, waiting for each opponent reply to be on the board.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{fen: string, moves: string[]}} puzzle
 */
export async function solve(page, puzzle) {
  const board = page.getByTestId('chess-board')
  for (let i = 1; i < puzzle.moves.length; i += 2) {
    await expect(board).toHaveAttribute('data-fen', fenAfter(puzzle, i))
    await playMove(page, puzzle.moves[i])
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
