import { expect, test } from '@playwright/test'
import { Chess } from 'chess.js'
import { fenAfter, playMove, signIn } from './helpers.js'

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
