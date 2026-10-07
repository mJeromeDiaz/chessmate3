import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())

/** A square other than `square`. */
const wrong = square => (square === 'a1' ? 'h8' : 'a1')

// A whole series lasts 5 minutes: its validation is covered by PHPUnit
// (tests/Functional/Blindfold/CoordinatesRunTest.php). Here, a series stopped early.
test('coordinates: answers judged at once, sent before "Terminer", ribbon at the end', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/coordinates')
  await expect(
    page.getByTestId('orientation-white').getByTestId('orientation-status')
  ).toHaveText('À valider')

  await page
    .getByTestId('coordinates-orientation')
    .getByRole('button', { name: 'Noirs' })
    .click()
  await expect(page.getByTestId('run-duration-choice')).toHaveCount(0)
  const served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+$/)
  const item = (await (await served).json()).item
  expect(item.type).toBe('coordinates_series')
  expect(item.data.orientation).toBe('black')
  const squares = item.data.squares

  // Batches leave every 3 s and before "Terminer": the last verdict covers every answer.
  /** @type {{data: {answerCount: number, successCount: number}}[]} */
  const results = []
  page.on('response', async r => {
    if (isRunSubmission(r) && r.ok()) results.push((await r.json()).result)
  })

  const player = page.getByTestId('coordinates-player')
  // Five answers, the third one wrong.
  for (let i = 0; i < 5; i++) {
    await expect(page.getByTestId('coordinates-target')).toHaveText(squares[i])
    const clicked = i === 2 ? wrong(squares[i]) : squares[i]
    await player.getByTestId(`square-${clicked}`).click()
    if (i === 2) {
      await expect(page.getByTestId('coordinates-feedback')).toHaveText(
        `Raté : c’était ${squares[i]}`
      )
    }
  }
  await expect(page.getByTestId('coordinates-counts')).toContainText(
    '5 réponses'
  )
  await expect(page.getByTestId('coordinates-counts')).toContainText('4 justes')

  // "Terminer": the answers not sent yet go first.
  await page.getByTestId('run-stop').click()
  // The board keeps cm-chessboard's (hidden) promotion dialog: the confirmation is the other one.
  await expect(
    page.getByRole('dialog').filter({ hasText: 'Terminer la séance ?' })
  ).toContainText('une série arrêtée avant la fin ne valide pas')
  await page.getByRole('button', { name: 'OK' }).click()

  const end = page.getByTestId('run-end')
  await expect(end).toBeVisible()
  await expect
    .poll(() => results.at(-1)?.data)
    .toEqual({
      results: expect.any(Array),
      answerCount: 5,
      successCount: 4
    })
  await expect(end.getByTestId('run-end-title')).toBeVisible()
  await expect(end).toContainText('SÉRIE TERMINÉE · NOIRS')
  await expect(end.getByTestId('coordinate-cell')).toHaveCount(5)
  await expect(
    end.locator('[data-testid="coordinate-cell"][data-status="fail"]')
  ).toHaveCount(1)
  await end.getByTestId('coordinate-cell').nth(2).click()
  await expect(end.getByTestId('coordinate-picked')).toContainText(
    `#3 · ${squares[2]} → ${wrong(squares[2])}`
  )
  await expect(end.getByTestId('run-end-square')).toContainText(squares[2])
  await end.getByTestId('run-end-close').click()

  await expect(page.getByTestId('run-recap')).toContainText(
    'Série arrêtée avant la fin : elle ne valide pas.'
  )
  await page.getByTestId('run-back').click()
  await expect(page).toHaveURL(/#\/coordinates$/)
  await expect(page.getByTestId('orientation-black')).toContainText('1 série')
  await expect(
    page.getByTestId('orientation-black').getByTestId('orientation-status')
  ).toHaveText('À valider')

  const history = page.getByTestId('coordinates-history')
  await expect(history).toContainText('4 justes sur 5')
  await history.getByText('4 justes sur 5').click()
  await expect(history.getByTestId('coordinate-cell')).toHaveCount(5)
})
