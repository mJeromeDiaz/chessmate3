import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

/**
 * Adds a module from the catalogue with its default settings, possibly changing the duration.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} moduleId
 * @param {number} [minutes]
 */
async function addModule(page, moduleId, minutes) {
  await page.getByTestId(`module-card-${moduleId}`).click()
  const settings = page.getByTestId('module-settings')
  if (minutes !== undefined) {
    await settings
      .getByTestId('field-duree')
      .locator('input')
      .fill(String(minutes))
  }
  await settings.getByTestId('module-save').click()
  await expect(settings).toBeHidden()
}

test('builder: add, edit, reorder and remove modules; the draft survives a reload', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/session/new')
  await expect(page.getByTestId('session-empty')).toBeVisible()
  await expect(page.getByTestId('session-launch')).toBeDisabled()

  // Modules not built yet cannot be added.
  await expect(page.getByTestId('module-card-finales')).toBeDisabled()

  await page.getByTestId('session-title').fill('Mardi soir')
  await addModule(page, 'puzzles')
  await addModule(page, 'libre', 25)

  const items = page.getByTestId('program-item')
  await expect(items).toHaveCount(2)
  await expect(items.nth(0)).toContainText('Tous les thèmes')
  await expect(page.getByTestId('session-total')).toContainText('45 min')
  await expect(page.getByTestId('session-count')).toContainText('2 modules')

  // Edit: settings open with the item's values.
  await items.nth(1).click()
  const settings = page.getByTestId('module-settings')
  await expect(settings.getByTestId('field-duree')).toContainText('25 min')
  await settings.getByRole('button', { name: 'Vidéo' }).click()
  await settings.getByTestId('module-save').click()
  await expect(items.nth(1)).toContainText('Vidéo')

  // Themes are picked in a second view of the panel.
  await items.nth(0).click()
  await settings.getByTestId('themes-open').click()
  const picker = settings.getByTestId('theme-picker')
  await picker.getByRole('searchbox').fill('fourch')
  await picker.getByTestId('theme-fork').click()
  await settings.getByTestId('themes-done').click()
  await expect(settings.getByTestId('field-themes')).toContainText('Fourchette')
  await settings.getByTestId('module-save').click()
  await expect(items.nth(0)).toContainText('Fourchette')

  // Reorder with the handle.
  await items
    .nth(1)
    .locator('.drag-handle')
    .dragTo(items.nth(0).locator('.drag-handle'))
  await expect(items.nth(0)).toContainText('Libre')
  await expect(items.nth(0)).toContainText('01 · PROF BIBLIO')

  await page.reload()
  await expect(page.getByTestId('session-title')).toHaveValue('Mardi soir')
  await expect(items).toHaveCount(2)
  await expect(items.nth(0)).toContainText('Libre')
  await expect(items.nth(1)).toContainText('Fourchette')

  // Remove.
  await items.nth(0).click()
  await page.getByTestId('module-remove').click()
  await expect(items).toHaveCount(1)
  await expect(page.getByTestId('session-total')).toContainText('20 min')
})

test("builder: repertoire and Woodpecker modules play on the user's own data", async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/session/new')
  const settings = page.getByTestId('module-settings')

  // No repertoire yet: the module cannot be added, a link creates one.
  await page.getByTestId('module-card-repertoire').click()
  await expect(settings).toContainText('Tu n’as pas encore de répertoire')
  await expect(settings.getByTestId('module-save')).toBeDisabled()
  await settings.getByTestId('session-repertoire-create').click()
  await expect(page).toHaveURL(/#\/repertoire\/new$/)
  await page.getByTestId('repertoire-name').fill('Blancs session')
  await page.getByTestId('repertoire-create').click()
  await expect(page.getByTestId('editor-name')).toHaveText('Blancs session')

  // Back in the builder, the only repertoire is chosen by default.
  await page.goto('/#/session/new')
  await page.getByTestId('module-card-repertoire').click()
  await expect(
    settings.getByRole('button', { name: 'Blancs session' })
  ).toHaveAttribute('aria-pressed', 'true')
  await settings.getByTestId('module-save').click()
  const items = page.getByTestId('program-item')
  await expect(items.nth(0)).toContainText('Blancs session')

  // No light set yet: same, through the light set creation page.
  await page.getByTestId('module-card-woodpecker').click()
  await expect(settings.getByTestId('module-save')).toBeDisabled()
  await settings.getByTestId('light-set-create').click()
  await expect(page).toHaveURL(/#\/woodpecker\/new\?mode=light$/)
  await page.getByTestId('set-name').fill('Light session')
  await page.getByTestId('set-create').click()
  await expect(page.getByTestId('light-size')).toContainText('5 puzzles')

  await page.goto('/#/session/new')
  await page.getByTestId('module-card-woodpecker').click()
  await expect(settings.getByTestId('light-set')).toContainText('Light session')
  await expect(settings.getByTestId('light-set')).toContainText('5 puzzles')
  await settings.getByTestId('module-save').click()
  await expect(items).toHaveCount(2)
  await expect(items.nth(1)).toContainText('5 puzzles')
  await expect(page.getByTestId('program-item-issue')).toHaveCount(0)
})

test('builder on a phone: settings in a bottom sheet', async ({
  page,
  context
}) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await signIn(context)
  await page.goto('/#/session/new')

  await page.getByTestId('module-card-libre').click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await page.getByTestId('module-save').click()

  await expect(page.getByTestId('program-item')).toHaveCount(1)
  await expect(page.getByTestId('session-total')).toContainText('30 min')
})

test('professor card: its call to action opens the module settings', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/prof/albert-stein')
  await expect(page.getByTestId('prof-name')).toHaveText('Albert Stein')

  await page.getByTestId('prof-cta').click()
  await expect(page).toHaveURL(/#\/session\/new$/)
  await expect(page.getByTestId('module-settings')).toContainText('Puzzles')
})

test('professor card: unknown professors are not found', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/prof/magnus')
  await expect(page.getByText('Oops. Nothing here...')).toBeVisible()
})
