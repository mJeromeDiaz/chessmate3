import { expect, test } from '@playwright/test'
import { Chess } from 'chess.js'
import { fenAfter, playMove, signIn, solve } from './helpers.js'

const API = 'http://localhost:8100'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())

/**
 * A legal move of the side to move that is neither `expected` nor a mate (a mistake).
 *
 * @param {string} fen
 * @param {string} expected UCI
 */
function wrongMove(fen, expected) {
  const move = new Chess(fen).moves({ verbose: true }).find(m => {
    if (m.from + m.to === expected.slice(0, 4)) return false
    const probe = new Chess(fen)
    probe.move(m)
    return !probe.isCheckmate()
  })
  return /** @type {import('chess.js').Move} */ (move).lan
}

/**
 * Calls the API as the signed-in user of this context (an access token from the refresh cookie).
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<{token: string, userId: string}>}
 */
async function apiSession(page) {
  const refreshed = await page.request.post(`${API}/api/auth/refresh`, {
    headers: { 'X-Refresh-Request': '1' }
  })
  expect(refreshed.ok()).toBe(true)
  const { accessToken } = await refreshed.json()
  const profile = await page.request.get(`${API}/api/profile`, {
    headers: { Authorization: `Bearer ${accessToken}` }
  })
  return { token: accessToken, userId: (await profile.json()).id }
}

test('streak: one streak from the page, no help, ends at the first miss, its result and record', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/streak')
  const card = page.getByTestId('streak-new')
  await expect(card).toBeVisible()
  await expect(card.getByTestId('streak-record')).toHaveText(
    'Pas encore de record ici.'
  )
  await expect(page.getByTestId('streak-records-empty')).toBeVisible()
  // One streak, no clock: no length to choose.
  await expect(card.getByTestId('run-duration-choice')).toHaveCount(0)

  let served = page.waitForResponse(isRunNext)
  await card.getByTestId('run-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+$/)
  let item = (await (await served).json()).item
  expect(item.type).toBe('streak_puzzle')
  expect(item.data).toMatchObject({
    ramp: 'ladder',
    topic: 'general',
    mode: 'single',
    streak: 0
  })
  await expect(page.getByTestId('run-progress')).toHaveText('Streak : 0')
  await expect(page.getByTestId('run-timer')).toHaveCount(0)
  // No help at all.
  await expect(page.getByTestId('puzzle-hint')).toHaveCount(0)
  await expect(page.getByTestId('puzzle-solution')).toHaveCount(0)

  // Solved: the next one comes by itself, the streak goes up.
  served = page.waitForResponse(isRunNext)
  await solve(page, item.data.puzzle)
  await expect(page.getByTestId('result-sheet')).toHaveAttribute(
    'data-kind',
    'win'
  )
  item = (await (await served).json()).item
  expect(item.data.streak).toBe(1)
  await expect(page.getByTestId('run-progress')).toHaveText('Streak : 1')

  // A miss: the streak breaks, the solution plays, the run is over.
  const puzzle = item.data.puzzle
  const board = page.getByTestId('chess-board')
  await expect(board).toHaveAttribute('data-fen', fenAfter(puzzle, 1))
  const submitted = page.waitForResponse(isRunSubmission)
  await playMove(page, wrongMove(fenAfter(puzzle, 1), puzzle.moves[1]))
  const result = (await (await submitted).json()).result
  expect(result.data).toMatchObject({ broken: true, streak: 1 })
  await expect(page.getByTestId('result-sheet')).toHaveAttribute(
    'data-kind',
    'miss'
  )
  await expect(page.getByTestId('run-next')).toHaveText('Voir le résultat')
  await page.getByTestId('run-next').click()

  const end = page.getByTestId('run-end')
  const screen = end.getByTestId('run-result')
  await expect(screen).toHaveAttribute('data-kind', 'streak')
  await expect(screen.getByTestId('run-result-score')).toHaveText('1')
  await end.getByTestId('run-end-close').click()
  await expect(end).toBeHidden()

  await expect(page.getByTestId('run-recap')).toContainText(
    'Meilleur streak : 1 puzzle d’affilée.'
  )
  await expect(page.getByTestId('run-back')).toHaveText('Retour au streak')
  await page.getByTestId('run-back').click()
  await expect(page).toHaveURL(/#\/streak$/)
  await expect(page.getByTestId('streak-record')).toContainText(
    '1 puzzle d’affilée'
  )
  await expect(page.getByTestId('streak-records-row')).toContainText(
    '1 puzzle d’affilée · Général · Échelle'
  )
})

test('streak: in a timed run, time up with a streak alive offers overtime, then goes on with the same puzzle', async ({
  page,
  context
}) => {
  test.setTimeout(150_000)
  await signIn(context)
  // A session step's run (timed streaks), started through the API before the app loads: the
  // refresh rotates the cookie the app then uses.
  const { token, userId } = await apiSession(page)
  const started = await page.request.post(`${API}/api/training/runs`, {
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/ld+json',
      Accept: 'application/ld+json'
    },
    data: {
      module: 'puzzle_streak',
      subjectId: userId,
      budgetSeconds: 60,
      config: { ramp: 'ladder', topic: 'general', mode: 'timed' }
    }
  })
  expect(started.status()).toBe(201)
  const run = await started.json()

  let served = page.waitForResponse(isRunNext)
  await page.goto(`/#/training/${run.id}`)
  const first = (await (await served).json()).item
  await expect(page.getByTestId('run-timer')).toBeVisible()

  served = page.waitForResponse(isRunNext)
  await solve(page, first.data.puzzle)
  const onScreen = (await (await served).json()).item
  await expect(page.getByTestId('run-progress')).toContainText(
    'Streak 1 · meilleur 1'
  )
  const board = page.getByTestId('chess-board')
  await expect(board).toHaveAttribute(
    'data-fen',
    fenAfter(onScreen.data.puzzle, 1)
  )

  // Time up, a streak of 1 alive: the decision, the puzzle still on screen.
  const overtime = page.getByTestId('streak-overtime')
  await expect(overtime).toBeVisible({ timeout: 75_000 })
  await expect(overtime).toContainText('Ton streak est à 1 puzzle d’affilée')
  await expect(overtime.getByTestId('streak-overtime-left')).toContainText(
    's, le streak s’arrête là.'
  )
  await expect(board).toHaveAttribute(
    'data-fen',
    fenAfter(onScreen.data.puzzle, 1)
  )

  // "Continuer": 5 more minutes on the same puzzle, nothing asked again.
  let asked = 0
  page.on('response', r => {
    if (isRunNext(r)) asked++
  })
  await overtime.getByTestId('streak-overtime-extend').click()
  await expect(overtime).toBeHidden()
  await expect(page.getByTestId('run-timer')).toHaveText(/^(5:00|4:5\d)$/)
  await expect(board).toHaveAttribute(
    'data-fen',
    fenAfter(onScreen.data.puzzle, 1)
  )
  expect(asked).toBe(0)

  // "Terminer": the puzzle on screen is not counted, the streak of 1 is.
  await page.getByTestId('run-stop').click()
  await expect(
    page.getByRole('dialog').filter({ hasText: 'Terminer la séance ?' })
  ).toContainText('ton streak s’arrête là.')
  await page.getByRole('button', { name: 'OK' }).click()
  const end = page.getByTestId('run-end')
  await expect(end.getByTestId('run-result-score')).toHaveText('1')
  await end.getByTestId('run-end-close').click()
  await expect(page.getByTestId('run-recap')).toContainText(
    'Prolongé 1 fois de 5 minutes.'
  )
})

test('streak: the session builder card, its topic picked among the themes', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/session/new')
  await page.getByTestId('module-card-streak').click()
  const settings = page.getByTestId('module-settings')
  await expect(settings.getByTestId('field-difficulte')).toBeVisible()
  const topic = settings.getByTestId('streak-topic')
  await expect(topic).toBeVisible()
  await topic.click()
  await page.keyboard.type('tours')
  await page.getByRole('option', { name: 'Finales de tours' }).click()
  await expect(page.getByTestId('module-issue')).toHaveCount(0)
  await settings.getByTestId('module-save').click()
  await expect(settings).toBeHidden()
  await expect(page.getByTestId('program-item-issue')).toHaveCount(0)
  await expect(page.getByText('Finales de tours')).toBeVisible()
})
