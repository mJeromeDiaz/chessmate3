import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

/**
 * Gift wheel tickets given by an admin (docs/SHOP.md, § 4 quater): to one player from the
 * player's detail, or to every active account with a confirmation and a journal; the player sees
 * the message at the next visit, then spins or keeps the tickets. The rules (who is served,
 * refusals, idempotence) are covered by PHPUnit (tests/Functional/Shop/TicketGrantTest.php).
 */

const isGiftsSeen = r =>
  r.url().endsWith('/api/shop/gifts/seen') && r.request().method() === 'POST'

/**
 * The admin's « Joueurs » tab of the store, in a context of its own.
 *
 * @param {import('@playwright/test').Browser} browser
 */
async function adminPlayers(browser) {
  const context = await browser.newContext()
  const page = await context.newPage()
  await signIn(context, { admin: true })
  await page.goto('/#/admin/boutique')
  await page.getByTestId('shop-admin-players').click()
  return { context, page }
}

/**
 * Opens a player's detail in the admin's tab.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} email
 */
async function openPlayer(page, email) {
  await page.getByTestId('shop-player-search').fill(email)
  await page.getByTestId('shop-player-row').filter({ hasText: email }).click()
  return page.getByTestId('shop-player')
}

test('tickets given to a player: the message at the next visit, then the wheel', async ({
  page,
  context,
  browser
}) => {
  const player = await signIn(context)

  const admin = await adminPlayers(browser)
  let detail = await openPlayer(admin.page, player.email)
  // Quasar puts the test id on the native input itself.
  await detail.getByTestId('shop-tickets-quantity').fill('2')
  await detail
    .getByTestId('shop-tickets-message')
    .fill('Merci pour ton retour !')
  await detail.getByTestId('shop-tickets-give').click()
  const grant = detail.getByTestId('shop-grant-row').first()
  await expect(grant).toContainText('Merci pour ton retour !')
  await expect(grant).toContainText('Pas encore vu')

  await page.goto('/#/boutique')
  const notice = page.getByTestId('gift-notice')
  await expect(notice).toBeVisible()
  await expect(notice.getByTestId('gift-notice-title')).toHaveText(
    'L’équipe t’offre 2 tours de roue !'
  )
  await expect(notice.getByTestId('gift-notice-message')).toHaveText(
    '« Merci pour ton retour ! »'
  )
  await expect(page.getByTestId('header-wheel-count')).toHaveText('2')

  const seen = page.waitForResponse(isGiftsSeen)
  await notice.getByTestId('gift-notice-spin').click()
  expect((await seen).status()).toBe(204)
  await expect(notice).toBeHidden()
  await expect(page.getByTestId('gift-wheel')).toBeVisible()

  // Seen: not shown again.
  await page.reload()
  await expect(page.getByTestId('header-wheel-count')).toHaveText('2')
  await expect(page.getByTestId('gift-notice')).toHaveCount(0)

  await admin.page.reload()
  await admin.page.getByTestId('shop-admin-players').click()
  detail = await openPlayer(admin.page, player.email)
  await expect(detail.getByTestId('shop-grant-row').first()).toContainText(
    'Vu le'
  )
  await admin.context.close()
})

test('tickets given to every active account, with a confirmation and a journal line', async ({
  page,
  context,
  browser
}) => {
  await signIn(context)
  const message = `Joyeux Noël à tous ! ${Date.now()}`

  const admin = await adminPlayers(browser)
  const card = admin.page.getByTestId('ticket-gift-all')
  await card.getByTestId('ticket-gift-all-quantity').fill('1')
  await card.getByTestId('ticket-gift-all-message').fill(message)
  await card.getByTestId('ticket-gift-all-give').click()
  const dialog = admin.page.getByRole('dialog')
  await expect(dialog).toContainText('Offrir à tous ?')
  await expect(dialog).toContainText(/1 ticket de roue à \d+ comptes actifs/)
  await expect(dialog).toContainText(message)
  const given = admin.page.waitForResponse(
    r =>
      r.url().endsWith('/api/admin/shop/wheel-tickets') &&
      r.request().method() === 'POST'
  )
  await dialog.getByRole('button', { name: 'Offrir', exact: true }).click()
  const batch = await (await given).json()
  expect(batch).toMatchObject({ item: 'wheel_ticket', quantity: 1, message })
  expect(batch.recipients).toBeGreaterThan(1)
  await expect(card.getByTestId('ticket-gift-all-done')).toContainText(
    `1 ticket offert à ${batch.recipients} comptes.`
  )
  await expect(card.getByTestId('ticket-gift-batch').first()).toContainText(
    message
  )
  await admin.context.close()

  await page.goto('/#/boutique')
  const notice = page.getByTestId('gift-notice')
  await expect(notice.getByTestId('gift-notice-title')).toHaveText(
    'L’équipe t’offre 1 tour de roue !'
  )
  await expect(notice.getByTestId('gift-notice-message')).toHaveText(
    `« ${message} »`
  )
  // « Plus tard »: the ticket stays in the header.
  const seen = page.waitForResponse(isGiftsSeen)
  await notice.getByTestId('gift-notice-later').click()
  expect((await seen).status()).toBe(204)
  await expect(notice).toBeHidden()
  await expect(page.getByTestId('gift-wheel')).toHaveCount(0)
  await expect(page.getByTestId('header-wheel-count')).toHaveText('1')
})
