import { expect, test } from '@playwright/test'
import { Chess } from 'chess.js'
import { playMove, signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'

/** English piece letters → French, as the UI shows and takes them in French. */
const french = san =>
  san.replace(/[KQRBN]/g, l => ({ K: 'R', Q: 'D', R: 'T', B: 'F', N: 'C' })[l])

/** A legal move of the position other than `uci`, with its SAN. */
function otherMove(fen, uci) {
  const move = new Chess(fen)
    .moves({ verbose: true })
    .find(m => m.from + m.to + (m.promotion ?? '') !== uci)
  if (!move) throw new Error('A single legal move.')
  return { uci: move.from + move.to + (move.promotion ?? ''), san: move.san }
}

/** a1 is dark. */
const colorOf = square =>
  (square.charCodeAt(0) - 97 + Number(square[1])) % 2 === 1 ? 'dark' : 'light'

/**
 * Answers the Jongleur's question as the player would, right or wrong.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{data: Record<string, any>}} item
 * @param {boolean} right
 */
async function answer(page, item, right) {
  const player = page.getByTestId('jongleur-player')
  const { question, square, fen, move } = item.data
  const other = square === 'a1' ? 'h8' : 'a1'
  if (question === 'find') {
    await player.getByTestId(`square-${right ? square : other}`).click()
  } else if (question === 'name') {
    const typed = right ? square : other
    await page.keyboard.press(typed[0])
    await page.keyboard.press(typed[1])
  } else if (question === 'color') {
    const color = colorOf(square)
    const chosen = right ? color : color === 'light' ? 'dark' : 'light'
    await player.getByTestId(`jongleur-${chosen}`).click()
  } else if (question === 'play') {
    await expect(page.getByTestId('jongleur-announced')).toHaveText(
      french(move.san)
    )
    await playMove(page, right ? move.uci : otherMove(fen, move.uci).uci)
  } else {
    const chess = new Chess(fen)
    const played = chess.move({
      from: move.uci.slice(0, 2),
      to: move.uci.slice(2, 4),
      promotion: move.uci[4]
    })
    const san = right ? played.san : otherMove(fen, move.uci).san
    await page.getByTestId('jongleur-input').fill(french(san))
    await page.getByTestId('jongleur-send').click()
  }
}

// The judging (time, lives, the fight's end, the rewards) is covered by PHPUnit
// (tests/Functional/MindMap/JongleurTest.php). Here, the fight as the player meets it.
test('jongleur: the chapter 1 boss, right answers, a question timed out, lost at the third miss', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/cerveau/visualization')

  // Every exercise of chapter 1 skipped: the boss opens.
  for (const key of [
    '1-1',
    '1-2',
    '1-3',
    '1-4',
    '1-5',
    '1-6',
    '1-7',
    '1-8',
    '1-9'
  ]) {
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

  await page.getByTestId('path-step-1-boss').click()
  let served = page.waitForResponse(isRunNext)
  await page.getByTestId('step-detail').getByTestId('boss-fight').click()
  await expect(page).toHaveURL(/#\/training\/[^/?]+\?mindMap=1(&boss=\w+)?$/)
  let item = (await (await served).json()).item
  expect(item.type).toBe('jongleur')

  const player = page.getByTestId('jongleur-player')
  const lives = page.getByTestId('jongleur-lives')
  await expect(page.getByTestId('run-progress')).toHaveText('Question 1 sur 20')
  await expect(lives).toHaveAttribute('data-lives', '3')

  // Two right answers: the next question comes by itself.
  for (let i = 0; i < 2; i++) {
    await expect(player).toHaveAttribute('data-question', item.data.question)
    // What is asked, on a banner above the board.
    await expect(page.getByTestId('jongleur-order')).toHaveAttribute(
      'data-question',
      item.data.question
    )
    served = page.waitForResponse(isRunNext)
    await answer(page, item, true)
    await expect(page.getByTestId('jongleur-result')).toHaveText('Juste !')
    item = (await (await served).json()).item
  }
  await expect(page.getByTestId('run-progress')).toHaveText('Question 3 sur 20')

  // Nothing answered: the time runs out, a life lost.
  served = page.waitForResponse(isRunNext)
  await expect(page.getByTestId('jongleur-result')).toHaveAttribute(
    'data-status',
    /wrong|late/,
    { timeout: 10_000 }
  )
  await expect(lives).toHaveAttribute('data-lives', '2')
  item = (await (await served).json()).item

  // Two misses: the Jongleur wins.
  served = page.waitForResponse(isRunNext)
  await answer(page, item, false)
  await expect(page.getByTestId('jongleur-result')).toContainText('Raté')
  await expect(lives).toHaveAttribute('data-lives', '1')
  item = (await (await served).json()).item
  await answer(page, item, false)

  const outcome = page.getByTestId('mind-outcome')
  await expect(outcome.getByTestId('mind-outcome-title')).toHaveText(
    'Le boss l’emporte'
  )
  await expect(outcome).toContainText('2 / 5 justes')
})
