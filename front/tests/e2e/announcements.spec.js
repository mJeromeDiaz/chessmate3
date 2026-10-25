import { expect, test } from '@playwright/test'
import { consumeQueue, signIn } from './helpers.js'
import { emailTo, linkIn } from './mailpit.js'

/**
 * The team's announcements (docs/ANNOUNCEMENTS.md) against the real API: written and published on
 * the admin page, shown once to a player in the modal, then emailed (read in Mailpit, the async
 * queue run by the test) with its « stop these emails » link. The rules (sanitizing, dates, who
 * gets the email, the one-click POST, idempotence) are covered by PHPUnit
 * (tests/Functional/Announcement/AnnouncementTest.php).
 */

const UNSUBSCRIBE_LINK = 'http://localhost:9100/#/annonces/desinscription?'

const isAdminList = r =>
  r.url().endsWith('/api/admin/announcements') && r.request().method() === 'GET'

/**
 * The admin's announcements page, in a context of its own, the list loaded.
 *
 * @param {import('@playwright/test').Browser} browser
 */
async function adminAnnouncements(browser) {
  const context = await browser.newContext()
  const page = await context.newPage()
  await signIn(context, { admin: true })
  const listed = page.waitForResponse(isAdminList)
  await page.goto('/#/admin/annonces')
  await listed
  return { context, page }
}

/**
 * Archives every announcement still shown (an earlier run's leftover would come first in the
 * player's modal).
 *
 * @param {import('@playwright/test').Page} page the admin's page
 */
async function archiveAll(page) {
  const open = page
    .getByTestId('announcement-row')
    .filter({ has: page.getByTestId('announcement-archive') })
  for (let left = await open.count(); left > 0; left--) {
    await open.first().getByTestId('announcement-archive').click()
    const dialog = page
      .getByRole('dialog')
      .filter({ hasText: 'Archiver l’annonce ?' })
    await dialog.getByRole('button', { name: 'Archiver', exact: true }).click()
    await expect(open).toHaveCount(left - 1)
  }
}

test('an announcement: published, seen once in the modal, emailed, unsubscribed', async ({
  page,
  context,
  browser
}) => {
  const player = await signIn(context, { announcements: true })
  const title = `Nouveauté ${Date.now()}`
  const text = 'Les défis ont maintenant leurs boss !'

  const admin = await adminAnnouncements(browser)
  await archiveAll(admin.page)

  // Written, previewed, published.
  await admin.page.getByTestId('announcement-new').click()
  const form = admin.page.getByTestId('announcement-form')
  // Quasar puts the test id on the native input itself, and QEditor on its editable area.
  await form.getByTestId('announcement-title-fr').fill(title)
  await form.getByTestId('announcement-body-fr').fill(text)
  await expect(form.getByTestId('announcement-preview')).toContainText(text)
  await form.getByTestId('announcement-publish').click()
  const created = admin.page.waitForResponse(
    r =>
      r.url().endsWith('/api/admin/announcements') &&
      r.request().method() === 'POST'
  )
  await admin.page
    .getByRole('dialog')
    .filter({ hasText: 'Publier l’annonce ?' })
    .getByRole('button', { name: 'Publier', exact: true })
    .click()
  expect((await created).status()).toBe(201)
  await expect(form).toBeHidden()
  const row = admin.page
    .getByTestId('announcement-row')
    .filter({ hasText: title })
  await expect(row.getByTestId('announcement-state')).toHaveText('En ligne')
  await expect(row.getByTestId('announcement-reads')).toHaveText('0')

  // The player sees it once, at the top, then never again.
  await page.goto('/#/')
  const notice = page.getByTestId('announcement-notice')
  await expect(notice.getByTestId('announcement-title')).toHaveText(title)
  await expect(notice.getByTestId('announcement-body')).toHaveText(text)
  const read = page.waitForResponse(
    r =>
      r.url().endsWith('/read') &&
      r.url().includes('/api/announcements/') &&
      r.request().method() === 'POST'
  )
  await notice.getByTestId('announcement-ok').click()
  expect((await read).status()).toBe(204)
  await expect(notice).toBeHidden()
  const asked = page.waitForResponse(
    r =>
      r.url().endsWith('/api/announcements') && r.request().method() === 'GET'
  )
  await page.reload()
  expect((await asked).ok()).toBe(true)
  await expect(page.getByTestId('announcement-notice')).toHaveCount(0)

  await admin.page.reload()
  await expect(row.getByTestId('announcement-reads')).toHaveText('1')

  // The email, asked once after a confirmation with the count.
  await row.getByTestId('announcement-send-email').click()
  const confirm = admin.page
    .getByRole('dialog')
    .filter({ hasText: 'Envoyer par email ?' })
  await expect(confirm).toContainText(title)
  await expect(confirm).toContainText(/\d+ comptes? actifs?/)
  const emailed = admin.page.waitForResponse(
    r => r.url().endsWith('/email') && r.request().method() === 'POST'
  )
  await confirm.getByRole('button', { name: 'Envoyer', exact: true }).click()
  expect((await emailed).status()).toBe(200)
  await expect(row.getByTestId('announcement-send-email')).toHaveCount(0)

  consumeQueue()
  const email = await emailTo(player.email, { subject: new RegExp(title) })
  expect(email).toContain(text)
  await admin.page.reload()
  await expect(row.getByTestId('announcement-email')).toContainText(
    /Mis en file pour \d+ comptes, [1-9]\d* envoyés/
  )

  // The email's link turns them off, then the profile shows it.
  await page.goto(linkIn(email, UNSUBSCRIBE_LINK))
  await expect(page.getByTestId('announcement-unsubscribe-done')).toBeVisible()
  await expect(page).not.toHaveURL(/[?&]t=/)
  await page.getByRole('link', { name: 'Mon profil' }).click()
  await expect(page.getByTestId('announcement-emails-toggle')).toHaveAttribute(
    'aria-checked',
    'false'
  )

  await archiveAll(admin.page)
  await expect(row.getByTestId('announcement-state')).toHaveText('Archivée')
  await admin.context.close()
})

test('a wrong unsubscribe link is refused', async ({ page }) => {
  await page.goto(
    '/#/annonces/desinscription?u=00000000-0000-0000-0000-000000000000&t=nope'
  )
  await expect(
    page.getByTestId('announcement-unsubscribe-failed')
  ).toContainText('Ce lien n’est pas valide.')
})
