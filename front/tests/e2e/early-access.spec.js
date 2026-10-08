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
 * Types the address, then the password, on the login page (two screens, one request).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} email
 */
async function enterPassword(page, email) {
  await page.getByTestId('login-email').fill(email)
  await page.getByTestId('login-continue').click()
  await page.getByTestId('login-password').fill(PASSWORD)
  await page.getByTestId('login-submit').click()
}

/**
 * Signs in through the login page: password, then the code emailed (read in Mailpit), sent as
 * soon as its sixth digit is in.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} email
 */
async function logIn(page, email) {
  await enterPassword(page, email)
  await expect(page).toHaveURL(/#\/mfa/)
  const code = codeIn(await emailTo(email, { subject: /code de connexion/ }))
  await page.getByTestId('mfa-code').fill(code)
}

/**
 * Fills in and sends the sign-up form of the early access screen (key already accepted).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} email
 */
async function signUp(page, email) {
  await page.getByTestId('register-email').fill(email)
  await page.getByTestId('new-password').fill(PASSWORD)
  await page.getByTestId('new-password-confirmation').fill(PASSWORD)
  await page.getByTestId('register-submit').click()
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
  await expect(guestPage.getByText(/Valable jusqu/)).toBeVisible()
  await expect(guestPage).not.toHaveURL(/key=/)
  await signUp(guestPage, guest)
  await expect(guestPage.getByTestId('register-done')).toContainText(
    'e-mail de vérification'
  )

  // The verification email, then the first sign-in with the emailed code.
  consumeQueue()
  const verify = linkIn(
    await emailTo(guest, { subject: /Confirmez votre adresse/ }),
    'http://localhost:8100/api/auth/verify-email/'
  )
  await guestPage.goto(verify)
  await expect(guestPage).toHaveURL(/#\/login\?verified=1/)
  await expect(guestPage.getByTestId('login-notice')).toContainText(
    'Adresse confirmée'
  )
  await logIn(guestPage, guest)

  // The first sign-in opens the welcome screen: Lichess can wait.
  await expect(guestPage).toHaveURL(/#\/bienvenue/)
  await guestPage.getByTestId('welcome-skip').click()
  await expect(guestPage.getByTestId('welcome-done')).toContainText(
    'C’est parti'
  )
  await guestPage
    .getByRole('link', { name: 'Aller au tableau de bord' })
    .click()
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
  await expect(guestPage.getByTestId('register-submit')).toHaveCount(0)
  await guestPage.goto(`/#/register?key=${second}`)
  await expect(guestPage.getByText(/Valable jusqu/)).toBeVisible()

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

test('the early access screen needs a usable key', async ({ page }) => {
  await page.goto('/#/register')
  const activate = page.getByTestId('invitation-activate')
  await expect(activate).toBeDisabled()
  await expect(page.getByTestId('provider-google')).toHaveCount(0)
  await expect(page.getByTestId('register-submit')).toHaveCount(0)

  await page.getByTestId('invitation-key').fill('trop-court')
  await expect(page.getByText('10 / 32')).toBeVisible()
  await expect(activate).toBeDisabled()

  await page.getByTestId('invitation-key').fill('A'.repeat(32))
  await expect(page.getByText('Clé complète ✓')).toBeVisible()
  await activate.click()
  await expect(page.getByText(/Cette clé n’est pas valable/)).toBeVisible()
  await expect(page.getByTestId('register-submit')).toHaveCount(0)
})

test('a Lichess sign-in without an account leads to the early access screen', async ({
  page
}) => {
  // What the API's OAuth callback answers for a Lichess account linked to no account.
  await page.goto(
    '/#/oauth/callback?status=error&mode=login&provider=lichess&reason=invitation_required'
  )
  await expect(page).toHaveURL(/#\/register$/)
  await expect(page.getByTestId('access-provider-note')).toContainText(
    'Ton compte Lichess n’est lié à aucun compte'
  )
})

test('a visitor joins the waiting list, an admin invites then deletes the request', async ({
  page,
  context,
  browser
}) => {
  const visitor = `waiting-${Date.now()}@example.com`
  const visitorContext = await browser.newContext()
  const visitorPage = await visitorContext.newPage()
  await visitorPage.goto('/#/register')
  await visitorPage.getByTestId('access-tab-ask').click()
  await visitorPage.getByTestId('access-email').fill(visitor)
  await visitorPage.getByTestId('access-submit').click()
  await expect(visitorPage.getByTestId('access-queued')).toBeVisible()
  await expect(visitorPage.getByText(visitor)).toBeVisible()
  await visitorContext.close()

  await signIn(context, { admin: true })
  await page.goto('/#/admin/demandes')
  const row = page.getByTestId('request-row').filter({ hasText: visitor })
  await expect(row).toContainText('En attente')
  await row.getByTestId('request-invite').click()
  await page
    .locator('.q-dialog')
    .getByRole('button', { name: 'Inviter' })
    .click()
  const dialog = page.getByTestId('key-dialog')
  await expect(dialog).toContainText('Invitation envoyée')
  const key = (await dialog.getByTestId('key-dialog-key').textContent()).trim()
  await dialog.getByTestId('key-dialog-close').click()
  await expect(row.getByTestId('request-invite')).toHaveCount(0)
  await expect(row).toContainText(key.slice(0, 4))

  // The worker sends the invitation to the waiting address.
  consumeQueue()
  expect(
    linkIn(await emailTo(visitor, { subject: /invitation/ }), SIGNUP_LINK)
  ).toBe(`${SIGNUP_LINK}${key}`)

  await row.getByTestId('request-delete').click()
  await page
    .locator('.q-dialog')
    .getByRole('button', { name: 'Supprimer' })
    .click()
  await expect(row).toHaveCount(0)
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
  await enterPassword(playerPage, player.email)
  await expect(playerPage.getByText(/Ce compte est suspendu/)).toBeVisible()

  // Lifted: the player signs in again (password, then the emailed code).
  await row.getByTestId('player-unsuspend').click()
  await page
    .locator('.q-dialog')
    .getByRole('button', { name: 'Réactiver' })
    .click()
  await expect(row.getByTestId('player-suspended')).toHaveCount(0)
  // A hash change keeps the login page instance (still on its password screen): reload it.
  await playerPage.goto('/#/login')
  await playerPage.reload()
  await logIn(playerPage, player.email)
  await expect(playerPage.getByTestId('dashboard')).toBeVisible()
  await playerContext.close()
})

test('a player sees no administration', async ({ page, context }) => {
  await signIn(context)
  await page.goto('/#/')
  await expect(page.getByTestId('dashboard')).toBeVisible()
  await page.getByTestId('account-menu').click()
  await expect(page.getByTestId('nav-profile')).toBeVisible()
  await expect(page.getByTestId('nav-admin')).toHaveCount(0)
  await page.keyboard.press('Escape')

  await page.goto('/#/admin/joueurs')
  await expect(page).not.toHaveURL(/admin/)
  await expect(page.getByTestId('dashboard')).toBeVisible()
})
