import { expect, test } from '@playwright/test'
import { Chess } from 'chess.js'
import { playMove, signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'

/** English piece letters → French, as the module shows them in French. */
const french = san =>
  san.replace(/[KQRBN]/g, l => ({ K: 'R', Q: 'D', R: 'T', B: 'F', N: 'C' })[l])

/** A legal move of the position other than `uci`. */
function otherMove(fen, uci) {
  const move = new Chess(fen)
    .moves({ verbose: true })
    .map(m => m.from + m.to + (m.promotion ?? ''))
    .find(m => m !== uci)
  if (!move) throw new Error('A single legal move.')
  return move
}

// Judging is covered by PHPUnit (tests/Functional/Notation/NotationRunTest.php). Here, the module
// as the player meets it, in French letters.
test('notation: a move announced played right then wrong, a line written', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/cerveau')
  await page
    .getByRole('navigation')
    .getByRole('link', { name: 'Notation' })
    .click()
  await expect(page).toHaveURL(/#\/notation$/)
  await expect(page.getByTestId('notation-letters')).toContainText('R D T F C')

  // Play the move announced.
  await page
    .getByTestId('run-duration-choice')
    .getByRole('button', { name: '2 min' })
    .click()
  let served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-start').click()
  let item = (await (await served).json()).item
  expect(item.data.kind).toBe('play')
  await expect(page.getByTestId('notation-announced')).toHaveText(
    french(item.data.announced.san)
  )
  served = page.waitForResponse(isRunNext)
  await playMove(page, item.data.announced.uci)
  await expect(page.getByTestId('notation-result')).toContainText('Juste !')

  // The next one comes by itself; a wrong move shows the right one.
  item = (await (await served).json()).item
  await expect(page.getByTestId('notation-announced')).toHaveText(
    french(item.data.announced.san)
  )
  await playMove(page, otherMove(item.data.fen, item.data.announced.uci))
  await expect(page.getByTestId('notation-result')).toContainText(
    `C’était ${french(item.data.announced.san)}`
  )
  await expect(page.getByTestId('run-progress')).toContainText('Justes : 1 / 2')
  await page.getByTestId('run-stop').click()
  await page.getByRole('button', { name: 'OK' }).click()
  // The wrong answer stays on screen until it is left: then the run's result.
  await page.getByTestId('run-next').click()
  await page.getByTestId('run-end-close').click()
  await page.getByTestId('run-back').click()
  await expect(page).toHaveURL(/#\/notation$/)

  // Write a line: « Rg9 » (French: a king's move) is no legal move.
  await page.getByTestId('notation-kind-write').click()
  served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-start').click()
  item = (await (await served).json()).item
  expect(item.data.kind).toBe('write')
  await expect(page.getByTestId('notation-status')).toContainText(
    `${item.data.plies} demi-coup`
  )
  await page.getByTestId('notation-key-R').click()
  await page.getByTestId('notation-input').pressSequentially('g9')
  await expect(page.getByTestId('notation-input')).toHaveValue('Rg9')
  await page.getByTestId('notation-send').click()
  await expect(page.getByTestId('notation-result')).toContainText(
    'Le demi-coup 1 n’est pas un coup légal.'
  )
  await expect(page.getByTestId('notation-result')).toContainText(
    'La bonne ligne'
  )
})

test('notation: completing a line needs a repertoire of 10 segments', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/notation')
  await page.getByTestId('notation-kind-line').click()
  await expect(page.getByTestId('notation-no-repertoire')).toContainText(
    'au moins 10 tronçons'
  )
  await expect(page.getByTestId('run-start')).toBeDisabled()
  // Another exercise: no prerequisite.
  await page.getByTestId('notation-kind-play').click()
  await expect(page.getByTestId('notation-no-repertoire')).toHaveCount(0)
  await expect(page.getByTestId('run-start')).toBeEnabled()
})
