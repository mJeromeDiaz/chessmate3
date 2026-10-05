import { expect, test } from '@playwright/test'
import { closeRunEnd, signIn, solve } from './helpers.js'

const API = 'http://localhost:8100'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())
const isPuzzleStart = r =>
  r.url().endsWith('/api/puzzles/attempts') && r.request().method() === 'POST'

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

test('puzzles: a timed run of rated puzzles; the puzzle on screen at the end comes back', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/puzzle')
  await page.waitForResponse(isPuzzleStart)

  await page.getByTestId('puzzle-run-open').click()
  const dialog = page.getByTestId('puzzle-run-dialog')
  await expect(dialog).toContainText('Tous les thèmes')
  await dialog
    .getByTestId('run-duration-choice')
    .getByRole('button', { name: 'Autre' })
    .click()
  await dialog.getByTestId('run-duration-custom').fill('1')
  const first = page.waitForResponse(isRunNext)
  await dialog.getByTestId('run-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+$/)
  // The pending free-play puzzle opens the run.
  const firstItem = (await (await first).json()).item

  const submitted = page.waitForResponse(isRunSubmission)
  const second = page.waitForResponse(isRunNext)
  await solve(page, firstItem.data.puzzle)
  expect(
    (await (await submitted).json()).result.data.ratingDelta
  ).toBeGreaterThan(0)
  const onScreen = (await (await second).json()).item
  await expect(page.getByTestId('run-progress')).toContainText(
    '1 puzzles · 1 réussis'
  )

  await page.getByTestId('run-stop').click()
  await page.getByRole('button', { name: 'OK' }).click()
  await closeRunEnd(page)
  await expect(page.getByTestId('run-recap')).toContainText('Classement : ')
  await expect(page.getByTestId('run-items')).toHaveText('1')

  // Not skipped: free play serves it again.
  const served = page.waitForResponse(isPuzzleStart)
  await page.getByTestId('run-back').click()
  await expect(page).toHaveURL(/#\/puzzle$/)
  expect((await (await served).json()).id).toBe(onScreen.id)
})

test('free study: a timer with the format and notes, its time in the recap', async ({
  page,
  context
}) => {
  await signIn(context)
  // Started through the API (the session launch comes with the next step), before the app loads:
  // the refresh rotates the cookie the app then uses.
  const { token, userId } = await apiSession(page)
  const started = await page.request.post(`${API}/api/training/runs`, {
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/ld+json',
      Accept: 'application/ld+json'
    },
    data: {
      module: 'free',
      subjectId: userId,
      budgetSeconds: 600,
      config: { format: 'book', notes: 'Chapitre 3 : finales de tours' }
    }
  })
  expect(started.status()).toBe(201)
  const run = await started.json()

  await page.goto(`/#/training/${run.id}`)
  await expect(page.getByTestId('free-run-format')).toHaveText('Livre')
  await expect(page.getByTestId('free-run-notes')).toHaveText(
    'Chapitre 3 : finales de tours'
  )
  await expect(page.getByTestId('run-timer')).toContainText(/^(10:00|9:5\d)$/)

  await page.getByTestId('run-stop').click()
  await expect(page.getByRole('dialog')).toContainText(
    'Le temps passé jusqu’ici sera compté.'
  )
  await page.getByRole('button', { name: 'OK' }).click()
  await closeRunEnd(page)

  const recap = page.getByTestId('run-recap')
  await expect(recap).toContainText('Format : Livre.')
  await expect(recap).toContainText('Notes : Chapitre 3 : finales de tours')
  await expect(page.getByTestId('run-duration')).toBeVisible()
  await expect(page.getByTestId('run-items')).toHaveCount(0)
  await expect(page.getByTestId('run-back')).toHaveText('Retour à l’accueil')
})
