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

/** A square of the board holding nothing. */
function emptySquare(squares) {
  for (const file of 'abcdefgh')
    for (const rank of '12345678') if (!squares[file + rank]) return file + rank
  throw new Error('A full board.')
}

/**
 * Memorizes, waits for the question, answers it right or wrong; returns the server's verdict.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{data: Record<string, any>}} item
 * @param {boolean} right
 */
async function answer(page, item, right) {
  const player = page.getByTestId('vision-player')
  await expect(player).toHaveAttribute('data-phase', 'show')
  await player.getByTestId('vision-memorized').click()
  await expect(player.getByTestId('vision-veil')).toBeVisible()
  await expect(player).toHaveAttribute('data-phase', 'ask')
  const squares = squaresOf(item.data.fen)
  const submitted = page.waitForResponse(isRunSubmission)
  if (item.data.ask === 'where') {
    const holding = Object.keys(squares).filter(
      s => squares[s] === item.data.target
    )
    await player
      .getByTestId(`square-${right ? holding[0] : emptySquare(squares)}`)
      .click()
  } else {
    const piece = squares[item.data.target] ?? ''
    const given = right ? piece : piece ? '' : 'Q'
    if (given) await player.getByTestId(`recall-piece-${given}`).click()
    else await player.getByTestId('vision-empty').click()
  }
  return (await (await submitted).json()).result
}

// The judging (where, what, en prise, attackers, lives, path only) is covered by PHPUnit
// (tests/Functional/Vision/VisionPathTest.php). Here, the mini-boss 2.2 as the player meets it.
test('vision: the mini-boss 2.2, a position remembered then asked about', async ({
  page,
  context
}) => {
  await signIn(context, {
    mindMapChapters: 1,
    mindMapSteps: [
      '2-1-1',
      '2-1-2',
      '2-1-3',
      '2-1-4',
      '2-1-boss',
      '2-2-1',
      '2-2-2',
      '2-2-3',
      '2-2-4'
    ]
  })
  await page.goto('/#/cerveau/visualization')
  await page.getByTestId('path-step-2-2-boss').click()
  let served = page.waitForResponse(isRunNext)
  await page.getByTestId('step-detail').getByTestId('boss-fight').click()
  await expect(page).toHaveURL(/#\/training\/[^/?]+\?mindMap=1(&boss=\w+)?$/)
  let item = (await (await served).json()).item
  expect([item.type, item.data.kind, item.data.count, item.data.lives]).toEqual(
    ['vision', 'locate', 5, 3]
  )
  await expect(page.getByTestId('run-progress')).toHaveText('Question 1 sur 5')
  await expect(page.getByTestId('vision-lives')).toHaveAttribute(
    'data-lives',
    '3'
  )

  // Right: the next question comes by itself.
  served = page.waitForResponse(isRunNext)
  let result = await answer(page, item, true)
  expect(result.data.status).toBe('right')
  await expect(page.getByTestId('vision-result')).toContainText('Juste !')
  item = (await (await served).json()).item
  await expect(page.getByTestId('run-progress')).toHaveText('Question 2 sur 5')

  // Wrong: the correction on the board, a life lost, « Suivant ».
  result = await answer(page, item, false)
  expect(result.data.status).toBe('wrong')
  await expect(page.getByTestId('vision-result')).toContainText('Raté')
  await expect(page.getByTestId('vision-lives')).toHaveAttribute(
    'data-lives',
    '2'
  )
  served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-next').click()
  await served
  await expect(page.getByTestId('run-progress')).toHaveText('Question 3 sur 5')
})
