import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())

/** A square other than `square`. */
const wrong = square => (square === 'a1' ? 'h8' : 'a1')

// A series lasts 1, 2, 5 or 10 minutes: its validation is covered by PHPUnit
// (tests/Functional/Coordinates/CoordinatesRunTest.php). Here, a series stopped early.
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
  // The series' own lengths only, 2 minutes first.
  const durations = page.getByTestId('run-duration-choice')
  await expect(durations.getByRole('button')).toHaveText([
    '1 min',
    '2 min',
    '5 min',
    '10 min'
  ])
  await expect(
    durations.getByRole('button', { name: '2 min' })
  ).toHaveAttribute('aria-pressed', 'true')
  await durations.getByRole('button', { name: '1 min' }).click()
  const served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+$/)
  const item = (await (await served).json()).item
  expect(item.type).toBe('coordinates_series')
  expect(item.data.orientation).toBe('black')
  expect(item.data.rules).toEqual({
    seriesSeconds: 60,
    minAnswers: 10,
    minSuccessRate: 0.95
  })
  const squares = item.data.squares
  expect(squares).toHaveLength(180)

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
  // 4 out of 5: "Presque parfait"; a square has nothing to replay, the next lesson is offered.
  const result = end.getByTestId('run-result')
  await expect(result).toHaveAttribute('data-kind', 'close')
  await expect(result.getByTestId('run-result-score')).toHaveText('80')
  await expect(end.getByTestId('run-end-fix')).toHaveCount(0)
  await expect(end.getByTestId('run-end-next')).toHaveText('Leçon suivante →')
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

/** a1 is dark, h1 light. */
const colorOf = square =>
  (square.charCodeAt(0) - 97 + Number(square[1])) % 2 === 1 ? 'dark' : 'light'

test('coordinates: the name and colour modes, answered on their keypad and buttons', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/coordinates')
  await expect(page.getByTestId('mode-color')).toContainText('0 série')

  // Colour: no board, « Claire » / « Foncée », the keyboard too.
  await page
    .getByTestId('coordinates-mode')
    .getByRole('button', { name: 'Couleur de la case' })
    .click()
  await expect(page.getByTestId('coordinates-orientation')).toHaveCount(0)
  await page
    .getByTestId('run-duration-choice')
    .getByRole('button', { name: '1 min' })
    .click()
  let served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-start').click()
  let item = (await (await served).json()).item
  expect(item.data.mode).toBe('color')
  let squares = item.data.squares
  const player = page.getByTestId('coordinates-player')
  await expect(player).toHaveAttribute('data-mode', 'color')
  await expect(player.locator('.chess-board')).toHaveCount(0)

  await expect(page.getByTestId('coordinates-target')).toHaveText(squares[0])
  await page
    .getByTestId(
      colorOf(squares[0]) === 'light' ? 'coordinates-light' : 'coordinates-dark'
    )
    .click()
  await expect(page.getByTestId('coordinates-feedback')).toHaveText('Juste !')
  await expect(page.getByTestId('coordinates-target')).toHaveText(squares[1])
  // The wrong colour, from the keyboard (C: light, F: dark).
  await page.keyboard.press(colorOf(squares[1]) === 'light' ? 'f' : 'c')
  await expect(page.getByTestId('coordinates-feedback')).toHaveText(
    `Raté : ${squares[1]} est ${colorOf(squares[1]) === 'light' ? 'claire' : 'foncée'}`
  )
  await expect(page.getByTestId('coordinates-counts')).toContainText('1 juste')
  await page.getByTestId('run-stop').click()
  await page.getByRole('button', { name: 'OK' }).click()
  await expect(page.getByTestId('run-end')).toBeVisible()
  await page.getByTestId('run-end-close').click()
  await expect(page.getByTestId('run-recap')).toContainText(
    'pas de validation d’orientation dans ce mode'
  )

  // Name: the square lit on the board, a file then a rank on the keypad.
  await page.getByTestId('run-back').click()
  await page
    .getByTestId('coordinates-mode')
    .getByRole('button', { name: 'Nommer la case' })
    .click()
  await page
    .getByTestId('run-duration-choice')
    .getByRole('button', { name: '1 min' })
    .click()
  served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-start').click()
  item = (await (await served).json()).item
  expect(item.data.mode).toBe('name')
  squares = item.data.squares
  // No keys on screen (they would read as a hint): the keyboard, a letter then a digit.
  await expect(
    page.getByTestId('coordinates-keypad').getByRole('button')
  ).toHaveCount(0)
  await page.keyboard.press(squares[0][0])
  await expect(page.getByTestId('coordinates-typed')).toContainText(
    squares[0][0]
  )
  await page.keyboard.press(squares[0][1])
  await expect(page.getByTestId('coordinates-feedback')).toHaveText('Juste !')
  await page.keyboard.press(squares[1][0])
  await page.keyboard.press(squares[1][1])
  await expect(page.getByTestId('coordinates-counts')).toContainText('2 justes')
  await page.getByTestId('run-stop').click()
  await page.getByRole('button', { name: 'OK' }).click()
  await page.getByTestId('run-end-close').click()
  await page.getByTestId('run-back').click()
  await expect(page.getByTestId('mode-name')).toContainText('1 série')
  await expect(page.getByTestId('mode-color')).toContainText('1 série')
})
