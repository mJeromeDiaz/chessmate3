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
  // Real gamification (docs/GAMIFICATION.md): a new player is level 1, no "Aperçu" tag left.
  await expect(page.getByTestId('profile-level')).toHaveText('Niveau 1')
  await expect(page.getByTestId('profile-hero')).toContainText('Débutant')
  await expect(page.getByTestId('profile-hero')).not.toContainText('Aperçu')

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

  // Not built yet: the language is shown disabled.
  await expect(
    page
      .getByTestId('profile-language')
      .getByRole('button', { name: 'English' })
  ).toBeDisabled()
  await expect(page.getByTestId('profile-export')).toBeEnabled()
  await expect(page.getByTestId('profile-delete')).toBeEnabled()

  await expect(page.getByTestId('profile-footer')).toContainText(
    /Don't Stay Rooky v\d+\.\d+\.\d+/
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

test('the export downloads a ZIP of the account', async ({ page, context }) => {
  await signIn(context)
  await page.goto('/#/profile')
  const download = page.waitForEvent('download')
  await page.getByTestId('profile-export').click()
  expect((await download).suggestedFilename()).toMatch(
    /^dontstayrooky-export-\d{4}-\d{2}-\d{2}\.zip$/
  )
})

test('deleting the account: the emailed code, then signed out with the date', async ({
  page,
  context
}) => {
  await signIn(context)
  // The code goes by email: the API's answers are simulated (tests/Functional/Auth cover the rest).
  await page.route('**/api/auth/account-deletion', route =>
    route.fulfill({
      status: 202,
      json: { method: 'email', expiresAt: '2026-10-05T10:10:00+00:00' }
    })
  )
  /** @type {any} */
  let sent = null
  await page.route('**/api/auth/account-deletion/confirm', route => {
    sent = route.request().postDataJSON()
    return route.fulfill({
      json: { deletionScheduledAt: '2026-11-04T12:00:00+00:00' }
    })
  })
  await page.goto('/#/profile')

  await page.getByTestId('profile-delete').click()
  const dialog = page.getByTestId('deletion-dialog')
  await expect(dialog.getByTestId('deletion-start')).toBeDisabled()
  await dialog.getByTestId('deletion-understood').click()
  await dialog.getByTestId('deletion-start').click()
  await expect(dialog.getByTestId('deletion-confirm')).toBeDisabled()
  await dialog.getByTestId('deletion-code').fill('123456')
  await dialog.getByTestId('deletion-confirm').click()

  await expect(page).toHaveURL(/#\/account-deletion\?at=/)
  expect(sent).toEqual({ code: '123456' })
  await expect(page.getByTestId('scheduled-date')).toContainText(
    '4 novembre 2026'
  )
  await expect(page.getByTestId('scheduled-login')).toBeVisible()
})

test('a frozen account stays on the deletion page until it cancels', async ({
  page,
  context
}) => {
  await signIn(context)
  let frozen = true
  // The profile of a frozen account, simulated on top of the real one: read, and returned by the
  // timezone and theme the SPA reports on sign-in.
  await page.route(/\/api\/profile(\/(timezone|theme))?$/, async route => {
    // A CORS preflight has no JSON to patch.
    if (route.request().method() === 'OPTIONS') return route.fallback()
    const response = await route.fetch()
    const profile = await response.json()
    await route.fulfill({
      response,
      json: frozen
        ? { ...profile, deletionScheduledAt: '2026-11-04T12:00:00+00:00' }
        : profile
    })
  })
  await page.route('**/api/auth/account-deletion/cancel', route => {
    frozen = false
    return route.fulfill({ status: 204 })
  })

  await page.goto('/#/puzzle')
  await expect(page).toHaveURL(/#\/account-deletion$/)
  await expect(page.getByTestId('frozen-date')).toContainText('4 novembre 2026')
  await expect(page.getByTestId('frozen-export')).toBeVisible()

  await page.getByTestId('frozen-cancel').click()
  await expect(page).toHaveURL(/#\/$/)
  await expect(page.getByTestId('dashboard')).toBeVisible()
})
