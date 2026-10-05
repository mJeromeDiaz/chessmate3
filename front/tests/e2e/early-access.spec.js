import { expect, test } from '@playwright/test'
import { consumeQueue, signIn } from './helpers.js'
import { codeIn, emailTo, linkIn } from './mailpit.js'

/**
 * Early access and administration (docs/EARLY_ACCESS.md), against the real API: invitation emails
 * and sign-in codes are read in Mailpit; the async queue is run by the test (consumeQueue), as the
 * worker would. Google and Lichess sign-ups are covered by the API's tests (no real provider here).
 */

const PASSWORD = 'Une phrase de passe assez longue 42'
const SIGNUP_LINK = 'http://localhost:9100/#/register?key='

/**
 * Creates an invitation from the admin page and returns the key shown once.
 *
 * @param {import('@playwright/test').Page} page signed in as an admin
 * @param {string} email
 * @returns {Promise<string>}
 */
async function invite(page, email) {
  await page.goto('/#/admin/invitations')
  await page.getByTestId('invitation-email').fill(email)
  await page.getByTestId('invitation-submit').click()
  const dialog = page.getByTestId('key-dialog')
  await expect(dialog).toContainText('Invitation envoyée')
  const key = (await dialog.getByTestId('key-dialog-key').textContent()).trim()
  expect(key).toMatch(/^[A-Za-z0-9]{32}$/)
  await expect(dialog.getByTestId('key-dialog-link')).toHaveText(
    `${SIGNUP_LINK}${key}`
  )
  await dialog.getByTestId('key-dialog-close').click()
  await expect(dialog).toBeHidden()
  return key
}

/**
 * Signs in through the login page: password, then the code emailed (read in Mailpit).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} email
 */
async function logIn(page, email) {
  await page.getByTestId('login-email').fill(email)
  await page.getByTestId('login-password').fill(PASSWORD)
  await page.getByTestId('login-submit').click()
  await expect(page).toHaveURL(/#\/mfa/)
  const code = codeIn(await emailTo(email, { subject: /code de connexion/ }))
  await page.getByTestId('mfa-code').fill(code)
  await page.getByRole('button', { name: 'Valider' }).click()
}

test('an invitation opens an account: email, sign-up, verification, first sign-in', async ({
  page,
  context,
  browser
}) => {
  await signIn(context, { admin: true })
  const guest = `guest-${Date.now()}@example.com`
  const key = await invite(page, guest)

  // The worker sends the invitation.
  consumeQueue()
  const link = linkIn(
    await emailTo(guest, { subject: /invitation/ }),
    SIGNUP_LINK
  )
  expect(link).toBe(`${SIGNUP_LINK}${key}`)

  // The guest, in their own browser: the key comes from the link, is checked, leaves the URL.
  const guestContext = await browser.newContext()
  const guestPage = await guestContext.newPage()
  await guestPage.goto(link)
  await expect(guestPage.getByText(/Clé valable jusqu/)).toBeVisible()
  await expect(guestPage).not.toHaveURL(/key=/)
  await guestPage.getByTestId('register-email').fill(guest)
  await guestPage.getByTestId('register-password').fill(PASSWORD)
  await guestPage.getByTestId('register-submit').click()
  await expect(guestPage.getByText(/email de vérification/)).toBeVisible()

  // The verification email, then the first sign-in with the emailed code.
  consumeQueue()
  const verify = linkIn(
    await emailTo(guest, { subject: /Confirmez votre adresse/ }),
    'http://localhost:8100/api/auth/verify-email/'
  )
  await guestPage.goto(verify)
  await expect(guestPage).toHaveURL(/#\/login\?verified=1/)
  await logIn(guestPage, guest)
  await expect(guestPage.getByTestId('dashboard')).toBeVisible()
  await guestContext.close()

  // The admin sees the key used, and the account in the players.
  await page.goto('/#/admin/invitations')
  await page.getByTestId('invitation-filter-email').fill(guest)
  const row = page.getByTestId('invitation-row').filter({ hasText: guest })
  await expect(row).toContainText('Utilisée')
  await expect(row.getByTestId('invitation-resend')).toHaveCount(0)

  await page.goto('/#/admin/joueurs')
  await page.getByTestId('player-search').fill(guest)
  await expect(
    page.getByTestId('player-row').filter({ hasText: guest })
  ).toContainText('Mot de passe')
})

test('a resent key replaces the previous one, a revoked one opens nothing', async ({
  page,
  context,
  browser
}) => {
  await signIn(context, { admin: true })
  const guest = `resend-${Date.now()}@example.com`
  const first = await invite(page, guest)

  const row = page.getByTestId('invitation-row').filter({ hasText: guest })
  await row.getByTestId('invitation-resend').click()
  await page
    .locator('.q-dialog')
    .getByRole('button', { name: 'Renvoyer' })
    .click()
  const dialog = page.getByTestId('key-dialog')
  await expect(dialog).toContainText('Nouvelle clé envoyée')
  const second = (
    await dialog.getByTestId('key-dialog-key').textContent()
  ).trim()
  expect(second).not.toBe(first)
  await dialog.getByTestId('key-dialog-close').click()
  consumeQueue()

  // A visitor (the sign-up page is for signed-out users): the first link no longer works, the
  // second one does.
  const guestContext = await browser.newContext()
  const guestPage = await guestContext.newPage()
  await guestPage.goto(`/#/register?key=${first}`)
  await expect(guestPage.getByText(/Cette clé n’est pas valable/)).toBeVisible()
  await expect(guestPage.getByTestId('register-submit')).toBeDisabled()
  await guestPage.goto(`/#/register?key=${second}`)
  await expect(guestPage.getByText(/Clé valable jusqu/)).toBeVisible()

  // Revoked: the second link stops working too.
  await row.getByTestId('invitation-revoke').click()
  await page
    .locator('.q-dialog')
    .getByRole('button', { name: 'Révoquer' })
    .click()
  await expect(row).toContainText('Révoquée')
  await expect(row.getByTestId('invitation-revoke')).toHaveCount(0)
  await guestPage.goto(`/#/register?key=${second}`)
  await expect(guestPage.getByText(/Cette clé n’est pas valable/)).toBeVisible()
  await guestContext.close()
})

test('the sign-up page needs a usable key', async ({ page }) => {
  await page.goto('/#/register')
  await expect(page.getByTestId('register-submit')).toBeDisabled()
  await expect(
    page.getByRole('button', { name: "S'inscrire avec Google" })
  ).toBeDisabled()

  await page.getByTestId('invitation-key').fill('trop-court')
  await expect(page.getByText(/32 lettres et chiffres/)).toBeVisible()

  await page.getByTestId('invitation-key').fill('A'.repeat(32))
  await expect(page.getByText(/Cette clé n’est pas valable/)).toBeVisible()
  await expect(page.getByTestId('register-submit')).toBeDisabled()
})

test('the dashboard shows the figures and charts, each chart as a table too', async ({
  page,
  context
}) => {
  await signIn(context, { admin: true })
  await page.goto('/#/admin')

  await expect(page.getByTestId('admin-overview')).toBeVisible()
  for (const tile of [
    'tile-accounts',
    'tile-signups',
    'tile-active',
    'tile-time',
    'tile-elo'
  ]) {
    await expect(page.getByTestId(tile)).toBeVisible()
  }
  // Seeded users signed up today: the sign-ups chart has bars and a legend per method.
  const signups = page.getByTestId('chart-signups')
  await expect(signups.locator('svg path').first()).toBeVisible()
  await expect(signups.getByTestId('chart-signups-legend-other')).toBeVisible()
  await signups.getByTestId('chart-signups-toggle').click()
  await expect(signups.getByTestId('chart-signups-table')).toContainText(
    'Total'
  )
  await expect(page.getByTestId('admin-funnel')).toBeVisible()

  // Another period reloads the figures.
  const reload = page.waitForResponse(r =>
    r.url().includes('/api/admin/stats?days=7')
  )
  await page.getByTestId('period-7').click()
  expect((await reload).status()).toBe(200)
  await expect(
    signups.getByTestId('chart-signups-table').locator('tbody tr')
  ).toHaveCount(7)
})

test('a suspended player cannot sign in until the suspension is lifted', async ({
  page,
  context,
  browser
}) => {
  await signIn(context, { admin: true })
  const playerContext = await browser.newContext()
  const player = await signIn(playerContext, { password: PASSWORD })

  await page.goto('/#/admin/joueurs')
  await page.getByTestId('player-search').fill(player.email)
  const row = page.getByTestId('player-row').filter({ hasText: player.email })
  await row.getByTestId('player-suspend').click()
  const confirm = page.locator('.q-dialog')
  await confirm.locator('textarea').fill('Comportement abusif (test e2e)')
  await confirm.getByRole('button', { name: 'Suspendre' }).click()
  await expect(row.getByTestId('player-suspended')).toBeVisible()
  await expect(row).toContainText('Comportement abusif (test e2e)')

  // The player's session is gone: back to the login page, told the account is suspended.
  const playerPage = await playerContext.newPage()
  await playerPage.goto('/#/profile')
  await expect(playerPage).toHaveURL(/#\/login/)
  await playerPage.getByTestId('login-email').fill(player.email)
  await playerPage.getByTestId('login-password').fill(PASSWORD)
  await playerPage.getByTestId('login-submit').click()
  await expect(playerPage.getByText(/Ce compte est suspendu/)).toBeVisible()

  // Lifted: the player signs in again (password, then the emailed code).
  await row.getByTestId('player-unsuspend').click()
  await page
    .locator('.q-dialog')
    .getByRole('button', { name: 'Réactiver' })
    .click()
  await expect(row.getByTestId('player-suspended')).toHaveCount(0)
  await playerPage.goto('/#/login')
  await logIn(playerPage, player.email)
  await expect(playerPage.getByTestId('dashboard')).toBeVisible()
  await playerContext.close()
})

test('a player sees no administration', async ({ page, context }) => {
  await signIn(context)
  await page.goto('/#/')
  await expect(page.getByTestId('dashboard')).toBeVisible()
  await expect(page.getByTestId('nav-admin')).toHaveCount(0)

  await page.goto('/#/admin/joueurs')
  await expect(page).not.toHaveURL(/admin/)
  await expect(page.getByTestId('dashboard')).toBeVisible()
})
