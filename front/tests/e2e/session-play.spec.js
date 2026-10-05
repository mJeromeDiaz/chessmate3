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

  // Launched again from the saved sessions while it is on: resume it or abandon it.
  await page.goto('/#/session')
  await expect(page.getByTestId('plans-current')).toBeVisible()
  await page.getByTestId('plan-launch').click()
  await expect(page.getByTestId('plans-in-progress')).toBeVisible()
  await page
    .getByTestId('plans-in-progress')
    .getByRole('link', { name: 'La reprendre' })
    .click()

  await page.getByTestId('session-abandon').click()
  await page.getByRole('button', { name: 'OK' }).click()
  await expect(page.getByTestId('session-status')).toHaveText('Abandonnée')
  await expect(page.getByTestId('session-step-status').nth(2)).toHaveText(
    'Non joué'
  )
})

test('saved sessions: settings kept, listed, edited, launched from the dashboard, deleted', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/session/new')
  await page.getByTestId('session-title').fill('Soirs de semaine')
  await addModule(page, 'libre', 10)

  const settings = page.getByTestId('session-settings')
  await settings.getByTestId('repetition-daily').click()
  await settings.getByTestId('session-time').fill('18:30')
  for (const day of [6, 7]) await settings.getByTestId(`weekday-${day}`).click()
  await settings.getByTestId('visibility-public').click()
  await settings.getByTestId('reminder-toggle').click()
  await settings.getByTestId('reminder-push').click()
  // Browser reminders need this device subscribed: offered right there.
  await expect(settings.getByTestId('push-toggle')).toBeVisible()
  await expect(settings.getByTestId('push-state')).not.toHaveText(
    'Vérification…'
  )
  await settings.getByTestId('reminder-delay-10').click()
  await settings.getByTestId('calendar-toggle').click()

  await page.getByTestId('session-save').click()
  await expect(page).toHaveURL(/#\/session$/)
  const card = page.getByTestId('plan-card')
  await expect(card).toHaveCount(1)
  await expect(card).toContainText('Soirs de semaine')
  await expect(card).toContainText('Publique')
  await expect(card.getByTestId('plan-repetition')).toHaveText(
    'En semaine à 18:30'
  )
  await expect(card).toContainText('10 min avant · email, navigateur')
  await expect(card).toContainText('Calendrier')
  await expect(card).toContainText('Prochaine : ')

  // The draft of a new session is empty again; the saved one is edited in the builder.
  await page.goto('/#/session/new')
  await expect(page.getByTestId('session-empty')).toBeVisible()
  await page.goto('/#/session')
  await card.getByTestId('plan-edit').click()
  await expect(page).toHaveURL(/#\/session\/plans\/[^/]+$/)
  await expect(page.getByTestId('session-title')).toHaveValue(
    'Soirs de semaine'
  )
  await expect(page.getByTestId('weekday-6')).toHaveAttribute(
    'aria-pressed',
    'false'
  )
  await page.getByTestId('repetition-on_demand').click()
  await page.getByTestId('session-save').click()
  await expect(card.getByTestId('plan-repetition')).toHaveText('À la demande')
  await expect(card).not.toContainText('Prochaine')

  // Launched from the dashboard block.
  await page.goto('/#/')
  const row = page.getByTestId('my-plan').first()
  await expect(row).toContainText('Soirs de semaine')
  await row.getByTestId('my-plan-launch').click()
  await expect(page.getByTestId('free-run')).toBeVisible()
  await stopRun(page)
  await page.getByTestId('session-open').click()
  await expect(page.getByTestId('session-status')).toHaveText('Terminée')

  // Deleted: the played session stays in the history.
  await page.goto('/#/session')
  await card.getByTestId('plan-delete').click()
  await page.getByRole('button', { name: 'OK' }).click()
  await expect(page.getByTestId('plans-empty')).toBeVisible()
  await expect(page.getByTestId('recent-session').first()).toContainText(
    'Soirs de semaine'
  )
})

test('calendar: a session downloads as .ics, the private address is created, renewed and revoked', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/session/new')
  await page.getByTestId('session-title').fill('Matin échecs')
  await addModule(page, 'libre', 10)
  const settings = page.getByTestId('session-settings')
  await settings.getByTestId('repetition-daily').click()
  await settings.getByTestId('session-time').fill('07:15')
  await settings.getByTestId('calendar-toggle').click()
  await expect(settings.getByTestId('calendar-hint')).toBeVisible()
  await page.getByTestId('session-save').click()
  await expect(page).toHaveURL(/#\/session$/)

  const card = page.getByTestId('plan-card')
  const downloading = page.waitForEvent('download')
  await card.getByTestId('plan-ics').click()
  const download = await downloading
  expect(download.suggestedFilename()).toBe('matin-echecs.ics')
  const { readFile } = await import('node:fs/promises')
  const file = await readFile(await download.path(), 'utf8')
  expect(file).toContain('BEGIN:VCALENDAR')
  expect(file).toContain('RRULE:FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR,SA,SU')

  await page.goto('/#/profile')
  const section = page.getByTestId('calendar-section')
  await section.getByTestId('calendar-create').click()
  const url = section.getByTestId('calendar-url')
  await expect(url).toHaveValue(/\/api\/calendar\/[A-Za-z0-9_-]{43}\.ics$/)
  const first = await url.inputValue()
  const feed = await page.request.get(first)
  expect(feed.status()).toBe(200)
  expect(await feed.text()).toContain('SUMMARY:Matin échecs')
  await expect(section.getByTestId('calendar-subscribe')).toHaveAttribute(
    'href',
    first.replace(/^https?:/, 'webcal:')
  )

  // A new address closes the previous one.
  await section.getByTestId('calendar-regenerate').click()
  await page.getByRole('button', { name: 'OK' }).click()
  await expect(url).not.toHaveValue(first)
  expect((await page.request.get(first)).status()).toBe(404)
  const second = await url.inputValue()

  // Shown again on the next visit, until revoked.
  await page.reload()
  await expect(url).toHaveValue(second)
  await section.getByTestId('calendar-revoke').click()
  await page.getByRole('button', { name: 'OK' }).click()
  await expect(section.getByTestId('calendar-create')).toBeVisible()
  expect((await page.request.get(second)).status()).toBe(404)
})
