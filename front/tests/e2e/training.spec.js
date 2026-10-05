import { expect, test } from '@playwright/test'
import { closeRunEnd, signIn, solve } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())

/**
 * Starts a 1-minute run (the shortest) from the set page.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<import('@playwright/test').Response>} the first `next` response
 */
async function startOneMinuteRun(page) {
  await page
    .getByTestId('run-duration-choice')
    .getByRole('button', { name: 'Autre' })
    .click()
  await page.getByTestId('run-duration-custom').fill('1')
  const first = page.waitForResponse(isRunNext)
  await page.getByTestId('run-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+$/)
  return first
}

test('light: a run stopped early shows its recap and joins the set history', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/woodpecker/new?mode=light')
  await page.getByTestId('set-name').fill('Light e2e')
  await page.getByTestId('set-create').click()
  await expect(page.getByTestId('light-size')).toContainText('5 puzzles')

  const first = await (await startOneMinuteRun(page)).json()
  await expect(page.getByTestId('run-timer')).toBeVisible()

  // Solve the first puzzle: the next one comes by itself.
  let submitted = page.waitForResponse(isRunSubmission)
  const second = page.waitForResponse(isRunNext)
  await solve(page, first.item.data.puzzle)
  expect((await (await submitted).json()).result.success).toBe(true)
  const missedPuzzle = (await (await second).json()).item.data.puzzle
  await expect(page.getByTestId('run-progress')).toContainText(
    '1 puzzles · 1 réussis'
  )

  // Give up on the second one: failed, the solution plays.
  submitted = page.waitForResponse(isRunSubmission)
  await page.getByTestId('puzzle-solution').click()
  expect((await (await submitted).json()).result.success).toBe(false)
  await expect(page.getByTestId('run-next')).toBeEnabled()

  await page.getByTestId('run-stop').click()
  await page.getByRole('button', { name: 'OK' }).click()

  // The end-of-run review: both puzzles in the grid, the failed one to review again.
  const end = page.getByTestId('run-end')
  await expect(
    end.getByTestId('run-end-grid').locator('[data-status]')
  ).toHaveCount(2)
  await expect(end.getByTestId('run-end-missed-item')).toHaveCount(1)
  await expect(end.getByTestId('run-end-back')).toHaveText('Retour au set')

  // Played again from the review, client side only: nothing reaches the API.
  /** @type {string[]} */
  const sent = []
  page.on('request', request => {
    if (request.method() !== 'GET') sent.push(request.url())
  })
  await end.getByTestId('run-end-missed-item').click()
  const replay = end.getByTestId('run-end-replay')
  await expect(replay).toBeVisible()
  // Not a session step: no pause message.
  await expect(replay.getByTestId('run-end-replay-pause')).toHaveCount(0)
  await solve(page, missedPuzzle, replay)
  await expect(replay.getByTestId('run-end-replay-result')).toHaveText(
    'Bien joué, c’est revu !'
  )
  // The only missed puzzle: no next one.
  await expect(replay.getByTestId('run-end-replay-next')).toHaveCount(0)
  await replay.getByTestId('run-end-replay-done').click()
  await expect(end.getByTestId('run-end-missed-item')).toHaveAttribute(
    'data-reviewed',
    'true'
  )
  expect(sent).toEqual([])
  await closeRunEnd(page)
  await page.getByTestId('run-end-open').click()
  await closeRunEnd(page)

  const recap = page.getByTestId('run-recap')
  await expect(recap).toBeVisible()
  await expect(page.getByTestId('run-close-reason')).toHaveText(
    'Séance terminée avant la fin du temps.'
  )
  await expect(page.getByTestId('run-items')).toHaveText('2')
  await expect(page.getByTestId('run-successes')).toHaveText('1')
  await expect(page.getByTestId('run-failures')).toHaveText('1')

  await page.getByTestId('run-back').click()
  await expect(page.getByTestId('run-table').locator('tbody tr')).toHaveCount(1)
})

test('classic: a run ends at its expiry and does not count the puzzle on screen', async ({
  page,
  context
}) => {
  // The run lasts its real minute.
  test.setTimeout(150_000)
  await signIn(context)
  await page.goto('/#/woodpecker/new')
  await page.getByTestId('set-name').fill('Classic e2e')
  await page.getByTestId('set-count').fill('5')
  await page.getByTestId('set-create').click()
  await expect(page).toHaveURL(/#\/woodpecker\/[^/]+\/play$/)
  await page.goto(page.url().replace(/\/play$/, ''))

  const first = await (await startOneMinuteRun(page)).json()
  const submitted = page.waitForResponse(isRunSubmission)
  const second = page.waitForResponse(isRunNext)
  await solve(page, first.item.data.puzzle)
  expect((await (await submitted).json()).result.success).toBe(true)
  expect((await (await second).json()).item).not.toBeNull()

  // Leave the second puzzle on screen until the time is up.
  await closeRunEnd(page, 90_000)
  await expect(page.getByTestId('run-recap')).toBeVisible()
  await expect(page.getByTestId('run-close-reason')).toHaveText('Temps écoulé.')
  await expect(page.getByTestId('run-items')).toHaveText('1')
  await expect(page.getByTestId('run-successes')).toHaveText('1')
  await expect(page.getByTestId('run-recap')).toContainText(
    'Cycle 1 : 1 / 5 puzzles.'
  )

  // The set page: the run is in the history, the cycle moved on by one puzzle only.
  await page.getByTestId('run-back').click()
  await expect(page.getByTestId('run-table').locator('tbody tr')).toHaveCount(1)
  await expect(page.getByText(/Cycle 1 \/ \d+ : 1 \/ 5\./)).toBeVisible()
})
