import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())

/** English piece letters → French, as the UI writes them in French. */
const french = san =>
  san.replace(/[KQRBN]/g, l => ({ K: 'R', Q: 'D', R: 'T', B: 'F', N: 'C' })[l])

/** The fewest jumps of a knight between two squares. */
function knightDistance(from, to) {
  const at = s => [s.charCodeAt(0) - 97, Number(s[1]) - 1]
  const seen = new Map([[from, 0]])
  const queue = [from]
  while (queue.length) {
    const square = queue.shift()
    if (square === to) return seen.get(square)
    const [f, r] = at(square)
    for (const [df, dr] of [
      [1, 2],
      [2, 1],
      [2, -1],
      [1, -2],
      [-1, -2],
      [-2, -1],
      [-2, 1],
      [-1, 2]
    ]) {
      const [nf, nr] = [f + df, r + dr]
      if (nf < 0 || nf > 7 || nr < 0 || nr > 7) continue
      const next = String.fromCharCode(97 + nf) + (nr + 1)
      if (!seen.has(next)) {
        seen.set(next, seen.get(square) + 1)
        queue.push(next)
      }
    }
  }
  return 0
}

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

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} step
 */
async function openStep(page, step) {
  await page.getByTestId(`path-step-${step}`).click()
  const served = page.waitForResponse(isRunNext)
  await page.getByTestId('step-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/?]+\?mindMap=1(&boss=\w+)?$/)
  return (await (await served).json()).item
}

/** @param {import('@playwright/test').Page} page */
async function stopRun(page) {
  await page.getByTestId('run-stop').click()
  await page
    .getByRole('dialog')
    .filter({ hasText: 'Terminer la séance ?' })
    .getByRole('button', { name: 'OK' })
    .click()
  await page
    .getByTestId('mind-outcome')
    .getByTestId('mind-outcome-back')
    .click()
}

// The paths and their judging are covered by PHPUnit (tests/Unit/Vision/TrackDrawerTest.php,
// tests/Functional/Vision/VisionTrackTest.php). Here, the section 3.1 as the player meets it.
test('vision: a piece followed on its path, then a knight’s jumps', async ({
  page,
  context
}) => {
  await signIn(context, {
    mindMapChapters: 2,
    mindMapSteps: ['3-0-boss', '3-1-1', '3-1-2']
  })
  await page.goto('/#/cerveau/visualization')

  // 3.1.1: the path written as the piece moves, then the piece it took.
  let item = await openStep(page, '3-1-1')
  expect([item.data.kind, item.data.announcedStyle]).toEqual([
    'track_capture',
    'path'
  ])
  const player = page.getByTestId('vision-player')
  await player.getByTestId('vision-memorized').click()
  await expect(player.getByTestId('vision-announced')).toContainText(
    french(item.data.announced[0].san)
  )
  await expect(player).toHaveAttribute('data-phase', 'ask')
  await expect(player.getByTestId('vision-line')).toContainText(
    item.data.announced.map(m => french(m.san)).join(', ')
  )
  const taken = squaresOf(item.data.fen)[item.data.target]
  let submitted = page.waitForResponse(isRunSubmission)
  await player.getByTestId(`recall-piece-${taken}`).click()
  expect((await (await submitted).json()).result.data.status).toBe('right')
  await expect(player.getByTestId('vision-result')).toContainText('Juste !')
  await stopRun(page)

  // 3.1.3: nothing to memorize, a choice of jumps.
  item = await openStep(page, '3-1-3')
  expect(item.data.ask).toBe('knight_jumps')
  await expect(player).toHaveAttribute('data-phase', 'ask')
  await expect(player.getByTestId('vision-question')).toContainText(
    `de ${item.data.target} à ${item.data.to}`
  )
  submitted = page.waitForResponse(isRunSubmission)
  await player
    .getByTestId(
      `vision-choice-${knightDistance(item.data.target, item.data.to)}`
    )
    .click()
  expect((await (await submitted).json()).result.data.status).toBe('right')
})
