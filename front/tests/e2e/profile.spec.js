import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

test('the profile shows the design cards with the account data', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/profile')

  // The seeded user is "e2e-<hex>@example.com": the hero shows the part before "@".
  await expect(page.getByTestId('profile-name')).toHaveText(/^e2e-[0-9a-f]+$/)
  await expect(page.getByTestId('profile-hero')).toContainText('Membre depuis')
  await expect(page.getByTestId('profile-hero')).toContainText('Aperçu')

  // No linked account: both providers can be linked, nothing is locked.
  for (const provider of ['google', 'lichess']) {
    const row = page.getByTestId(`connection-${provider}`)
    await expect(row).toContainText('Non lié')
    await expect(row.getByTestId(`connection-${provider}-link`)).toBeEnabled()
  }
  await expect(page.getByTestId('connection-lock-note')).toHaveCount(0)

  // The sections that existed before the design are all still there.
  await expect(page.getByTestId('profile-theme')).toBeVisible()
  await expect(page.getByTestId('timezone-select')).toBeVisible()
  await expect(page.getByTestId('push-toggle')).toBeVisible()
  await expect(page.getByTestId('calendar-section')).toBeVisible()
  await expect(page.getByTestId('profile-email')).toContainText('@example.com')

  // Not built yet: language, export and deletion are shown disabled.
  await expect(
    page
      .getByTestId('profile-language')
      .getByRole('button', { name: 'English' })
  ).toBeDisabled()
  await expect(
    page.getByTestId('profile-data').getByRole('button', { name: 'Exporter' })
  ).toBeDisabled()

  await expect(page.getByTestId('profile-footer')).toContainText(
    /ChessMate v\d+\.\d+\.\d+/
  )
})

test('the profile edit sets the avatar, name and handle', async ({
  page,
  context
}) => {
  await page.setViewportSize({ width: 1180, height: 820 })
  await signIn(context)
  await page.goto('/#/profile')
  await expect(page.getByTestId('profile-handle')).toHaveCount(0)

  await page.getByTestId('profile-edit-open').click()
  const dialog = page.getByTestId('profile-edit')
  await dialog.getByTestId('avatar-rook').click()
  await dialog.getByTestId('profile-edit-name').fill('Léa Moreau')

  // Cleaned while typed, then checked with the API.
  const handle = `lea_${Date.now().toString(36)}`
  const checked = page.waitForResponse(r =>
    r.url().includes('/api/profile/handle-availability')
  )
  await dialog.getByTestId('profile-edit-handle').fill(handle.toUpperCase())
  await expect(dialog.getByTestId('profile-edit-handle')).toHaveValue(handle)
  await checked
  await expect(dialog.getByTestId('profile-edit-handle-hint')).toHaveText(
    `✓ @${handle} est disponible`
  )

  // A reserved one cannot be saved.
  await dialog.getByTestId('profile-edit-handle').fill('admin')
  await expect(dialog.getByTestId('profile-edit-handle-hint')).toHaveText(
    'Ce pseudo est réservé.'
  )
  await expect(dialog.getByTestId('profile-edit-save')).toBeDisabled()

  await dialog.getByTestId('profile-edit-handle').fill(handle)
  await expect(dialog.getByTestId('profile-edit-save')).toBeEnabled()
  const saved = page.waitForResponse(
    r => r.url().endsWith('/api/profile/info') && r.ok()
  )
  await dialog.getByTestId('profile-edit-save').click()
  await saved
  await expect(dialog).toBeHidden()

  await expect(page.getByTestId('profile-name')).toHaveText('Léa Moreau')
  await expect(page.getByTestId('profile-handle')).toContainText(`@${handle}`)
  await expect(page.getByTestId('profile-avatar')).toHaveText('♜\uFE0E')

  // Kept on the account.
  await page.reload()
  await expect(page.getByTestId('profile-name')).toHaveText('Léa Moreau')
})

test('the board preferences are saved at once and kept', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/profile')

  const preferences = page.getByTestId('profile-preferences')
  await expect(preferences.getByTestId('board-theme-wood')).toHaveAttribute(
    'aria-pressed',
    'true'
  )

  const saved = () =>
    page.waitForResponse(
      r => r.url().endsWith('/api/profile/preferences') && r.ok()
    )
  let response = saved()
  await preferences.getByTestId('board-theme-slate').click()
  await response
  response = saved()
  await preferences.getByTestId('move-sound-toggle').click()
  await response
  response = saved()
  await preferences.getByTestId('public-profile-toggle').click()
  await response
  await expect(preferences).toContainText('Profil public')

  await page.reload()
  await expect(preferences.getByTestId('board-theme-slate')).toHaveAttribute(
    'aria-pressed',
    'true'
  )
  await expect(preferences.getByTestId('move-sound-toggle')).toHaveAttribute(
    'aria-checked',
    'false'
  )
  await expect(
    preferences.getByTestId('public-profile-toggle')
  ).toHaveAttribute('aria-checked', 'true')
})

test('the sessions list this device first, without a close button', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/profile')

  const sessions = page.getByTestId('profile-sessions')
  const rows = sessions.getByTestId('session-row')
  await expect(rows).toHaveCount(1)
  // The SPA refreshed its token on load: the session now knows the browser.
  await expect(rows.first()).toContainText('Chrome')
  await expect(rows.first().getByTestId('session-current')).toHaveText(
    'Cet appareil'
  )
  await expect(rows.first().getByTestId('session-close')).toHaveCount(0)
})
