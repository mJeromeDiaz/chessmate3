import { expect, test } from '@playwright/test'
import { Chess } from 'chess.js'
import { signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())

/** The pieces of a FEN by square. */
function squaresOf(fen) {
  const squares = {}
  fen
    .split(' ')[0]
    .split('/')
    .forEach((rank, index) => {
      let file = 0
      for (const char of rank) {
        if (/\d/.test(char)) file += Number(char)
        else squares[String.fromCharCode(97 + file++) + (8 - index)] = char
      }
    })
  return squares
}

/** The position the moves announced reach. */
function reached(item) {
  const chess = new Chess(item.data.fen)
  for (const { uci } of item.data.announced)
    chess.move({
      from: uci.slice(0, 2),
      to: uci.slice(2, 4),
      promotion: uci[4]
    })
  return chess.fen()
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} step
 */
async function openStep(page, step) {
  await page.getByTestId(`path-step-${step}`).click()
  const served = page.waitForResponse(isRunNext)
  await page.getByTestId('step-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/?]+\?mindMap=1$/)
  return (await (await served).json()).item
}

// The lines and their judging are covered by PHPUnit (tests/Unit/Vision/UpdateDrawerTest.php,
// tests/Functional/Vision/VisionUpdateTest.php). Here, the section 3.2 as the player meets it.
test('vision: a position rebuilt after a move, then the piece gone', async ({
  page,
  context
}) => {
  await signIn(context, {
    mindMapChapters: 2,
    mindMapSteps: [
      '3-0-boss',
      '3-1-1',
      '3-1-2',
      '3-1-3',
      '3-1-4',
      '3-1-5',
      '3-1-boss',
      '3-2-1',
      '3-2-2'
    ]
  })
  await page.goto('/#/cerveau/visualization')

  // 3.2.1: shown, hidden, a move announced, the position it reaches rebuilt.
  let item = await openStep(page, '3-2-1')
  expect([item.type, item.data.announced.length]).toEqual([
    'blindfold_recall',
    1
  ])
  const recall = page.getByTestId('recall-player')
  await recall.getByTestId('recall-memorized').click()
  await expect(recall.getByTestId('recall-announced')).toBeVisible()
  await expect(recall).toHaveAttribute('data-phase', 'build')
  for (const [square, piece] of Object.entries(squaresOf(reached(item)))) {
    await recall.getByTestId(`recall-piece-${piece}`).click()
    await recall.getByTestId(`square-${square}`).click()
  }
  let submitted = page.waitForResponse(isRunSubmission)
  await recall.getByTestId('recall-done').click()
  expect((await (await submitted).json()).result.data.status).toBe('exact')
  await page.getByTestId('run-stop').click()
  await page
    .getByRole('dialog')
    .filter({ hasText: 'Terminer la séance ?' })
    .getByRole('button', { name: 'OK' })
    .click()
  // The correction stays on screen until « Voir le résultat ».
  await page.getByTestId('run-next').click()
  await page
    .getByTestId('mind-outcome')
    .getByTestId('mind-outcome-back')
    .click()

  // 3.2.3: which piece is gone, from the palette.
  item = await openStep(page, '3-2-3')
  expect(item.data.ask).toBe('missing')
  const player = page.getByTestId('vision-player')
  await player.getByTestId('vision-memorized').click()
  await expect(player).toHaveAttribute('data-phase', 'ask', { timeout: 15_000 })
  await expect(player.getByTestId('vision-question')).toHaveText(
    'Quelle pièce a disparu ?'
  )
  const before = Object.values(squaresOf(item.data.fen))
  const after = Object.values(squaresOf(reached(item)))
  for (const piece of after) before.splice(before.indexOf(piece), 1)
  submitted = page.waitForResponse(isRunSubmission)
  await player.getByTestId(`recall-piece-${before[0]}`).click()
  expect((await (await submitted).json()).result.data.status).toBe('right')
  await expect(player.getByTestId('vision-result')).toContainText('Juste !')
})
