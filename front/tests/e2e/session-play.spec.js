import { expect, test } from '@playwright/test'
import { signIn, solve } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'

/**
 * Adds a module from the catalogue with its default settings, at the given length.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} moduleId
 * @param {number} minutes
 */
async function addModule(page, moduleId, minutes) {
  await page.getByTestId(`module-card-${moduleId}`).click()
  const settings = page.getByTestId('module-settings')
  await settings
    .getByTestId('field-duree')
    .locator('input')
    .fill(String(minutes))
  await settings.getByTestId('module-save').click()
  await expect(settings).toBeHidden()
}

/** @param {import('@playwright/test').Page} page */
async function stopRun(page) {
  await page.getByTestId('run-stop').click()
  await page.getByRole('button', { name: 'OK' }).click()
  await expect(page.getByTestId('run-recap')).toBeVisible()
}

test('a session is played module after module, then shows on the dashboard', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/session/new')
  await page.getByTestId('session-title').fill('Mardi soir')
  await addModule(page, 'libre', 5)
  await addModule(page, 'puzzles', 5)

  // Launching starts the first module at once.
  await page.getByTestId('session-launch').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+$/)
  await expect(page.getByTestId('free-run-format')).toHaveText('Livre')
  const firstUrl = page.url()

  await stopRun(page)
  const next = page.getByTestId('session-next')
  await expect(next).toHaveText(/Module suivant : Puzzles/)
  const served = page.waitForResponse(isRunNext)
  await next.click()
  await expect(page).not.toHaveURL(firstUrl)
  const item = (await (await served).json()).item
  expect(item.type).toBe('puzzle')
  await solve(page, item.data.puzzle)
  await expect(page.getByTestId('run-progress')).toContainText('1 puzzles')

  await stopRun(page)
  await expect(page.getByTestId('session-next')).toHaveCount(0)
  await page.getByTestId('session-open').click()
  await expect(page).toHaveURL(/#\/session\/[^/]+$/)
  await expect(page.getByTestId('session-status')).toHaveText('Terminée')
  await expect(page.getByTestId('session-end')).toContainText('bravo')
  const steps = page.getByTestId('session-step-status')
  await expect(steps.nth(0)).toContainText('Fait')
  await expect(steps.nth(1)).toContainText('Fait · 1 terminés, 1 réussis')

  await page.goto('/#/')
  const row = page.getByTestId('recent-session').first()
  await expect(row).toContainText('Mardi soir')
  await expect(row).toContainText('2 / 2 modules')
  await expect(row).toContainText('Terminée')
})

test('a session can be resumed, a module passed and the session abandoned', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/session/new')
  await addModule(page, 'libre', 5)
  await addModule(page, 'libre', 5)
  await addModule(page, 'libre', 5)
  await page.getByTestId('session-launch').click()
  await expect(page.getByTestId('free-run')).toBeVisible()

  // Left between two modules: the session waits, the dashboard offers to resume it.
  await stopRun(page)
  await page.goto('/#/')
  await expect(page.getByTestId('recent-session').first()).toContainText(
    'Reprendre'
  )
  await page.getByTestId('recent-session').first().click()
  await expect(page.getByTestId('session-status')).toHaveText('En cours')
  await expect(page.getByTestId('session-next')).toContainText('Commencer')

  await page.getByTestId('session-skip').click()
  await expect(page.getByTestId('session-step-status').nth(1)).toHaveText(
    'Passé'
  )

  // A second launch while this one is on: resume it or abandon it.
  await page.goto('/#/session/new')
  await page.getByTestId('session-launch').click()
  await expect(page.getByTestId('session-in-progress')).toBeVisible()
  await page
    .getByTestId('session-in-progress')
    .getByRole('link', { name: 'La reprendre' })
    .click()

  await page.getByTestId('session-abandon').click()
  await page.getByRole('button', { name: 'OK' }).click()
  await expect(page.getByTestId('session-status')).toHaveText('Abandonnée')
  await expect(page.getByTestId('session-step-status').nth(2)).toHaveText(
    'Non joué'
  )
})
