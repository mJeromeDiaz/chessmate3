import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())

/**
 * The squares of a FEN: square => FEN piece letter.
 *
 * @param {string} fen
 * @returns {Record<string, string>}
 */
function squaresOf(fen) {
  /** @type {Record<string, string>} */
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

/**
 * Skips the memorizing, places `squares` with the palette, sends the rebuild.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Record<string, string>} squares
 */
async function rebuildAndSend(page, squares) {
  const player = page.getByTestId('recall-player')
  await expect(player).toHaveAttribute('data-phase', 'show')
  await player.getByTestId('recall-memorized').click()
  await expect(player).toHaveAttribute('data-phase', 'build')
  for (const [square, piece] of Object.entries(squares)) {
    await player.getByTestId(`recall-piece-${piece}`).click()
    await player.getByTestId(`square-${square}`).click()
  }
  const submitted = page.waitForResponse(isRunSubmission)
  await player.getByTestId('recall-done').click()
  return (await (await submitted).json()).result
}

// The judging (exact rebuilds only, lives counted in pieces, levels not open yet, options refused
// from the client) is covered by PHPUnit (tests/Functional/MindMap/RecallPathTest.php). Here, the
// chapter 2 as the player meets it.
test('mind map chapter 2: a recall lesson, then the mini-boss 2.1 without coordinates', async ({
  page,
  context
}) => {
  await signIn(context, { mindMapChapters: 1 })
  await page.goto('/#/cerveau/visualization')

  // Lesson 2.1.1: « Position 1 sur 5 », with coordinates.
  await page.getByTestId('path-step-2-1-1').click()
  let served = page.waitForResponse(isRunNext)
  await page.getByTestId('step-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/?]+\?mindMap=1(&boss=\w+)?$/)
  let item = (await (await served).json()).item
  expect([item.type, item.data.level, item.data.count]).toEqual([
    'blindfold_recall',
    1,
    5
  ])
  await expect(page.getByTestId('run-progress')).toHaveText('Position 1 sur 5')
  await expect(page.getByTestId('recall-lives')).toHaveCount(0)
  const player = page.getByTestId('recall-player')
  await expect(player.locator('.coordinate').first()).toBeAttached()
  const result = await rebuildAndSend(page, squaresOf(item.data.fen))
  expect(result.data.status).toBe('exact')
  served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-next').click()
  await served
  await expect(page.getByTestId('run-progress')).toHaveText('Position 2 sur 5')

  // Stopped: not enough positions for a pass.
  await page.getByTestId('run-stop').click()
  await page
    .getByRole('dialog')
    .filter({ hasText: 'Terminer la séance ?' })
    .getByRole('button', { name: 'OK' })
    .click()
  const outcome = page.getByTestId('mind-outcome')
  await expect(outcome.getByTestId('mind-outcome-title')).toHaveText(
    'Pas encore'
  )
  await outcome.getByTestId('mind-outcome-back').click()

  // The section's exercises skipped: its mini-boss opens.
  for (const key of ['2-1-1', '2-1-2', '2-1-3', '2-1-4']) {
    await page.getByTestId(`path-step-${key}`).click()
    await page.getByTestId('step-skip').click()
    await page
      .getByRole('dialog')
      .filter({ hasText: 'Passer cet exercice ?' })
      .getByRole('button', { name: 'OK' })
      .click()
    await expect(page.getByTestId(`path-step-${key}`)).toHaveAttribute(
      'data-status',
      'skipped'
    )
  }
  await page.getByTestId('path-step-2-1-boss').click()
  served = page.waitForResponse(isRunNext)
  await page.getByTestId('step-detail').getByTestId('boss-fight').click()
  item = (await (await served).json()).item
  expect([item.data.level, item.data.lives, item.data.coordinates]).toEqual([
    2,
    3,
    false
  ])
  await expect(page.getByTestId('run-progress')).toHaveText('Position 1 sur 3')
  await expect(page.getByTestId('recall-lives')).toHaveAttribute(
    'data-lives',
    '3'
  )
  await expect(player.locator('.coordinate')).toHaveCount(0)

  // Two pieces forgotten: one life left.
  const squares = Object.entries(squaresOf(item.data.fen))
  const missed = await rebuildAndSend(
    page,
    Object.fromEntries(squares.slice(2))
  )
  expect(missed.data.missing).toBe(2)
  await expect(page.getByTestId('recall-lives')).toHaveAttribute(
    'data-lives',
    '1'
  )
})
