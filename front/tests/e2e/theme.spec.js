import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

/** @param {import('@playwright/test').Page} page */
const body = page => page.locator('body')

test('visitor: the theme chosen in the landing header survives a reload', async ({
  page
}) => {
  await page.emulateMedia({ colorScheme: 'light' })
  await page.goto('/')
  await expect(body(page)).toHaveClass(/body--light/)

  await page.getByTestId('theme-menu').click()
  await page.getByTestId('theme-dark').click()
  await expect(body(page)).toHaveClass(/body--dark/)

  await page.reload()
  await expect(body(page)).toHaveClass(/body--dark/)

  // Back to automatic: the OS setting again.
  await page.getByTestId('theme-menu').click()
  await page.getByTestId('theme-auto').click()
  await expect(body(page)).toHaveClass(/body--light/)
})

test('signed in: the choice is kept on the account and found again on another browser', async ({
  page,
  context
}) => {
  await page.emulateMedia({ colorScheme: 'light' })
  await signIn(context)
  await page.goto('/#/profile')
  await expect(page.getByTestId('profile-theme')).toBeVisible()
  // The browser notifications of this device are listed too (state checked with the server).
  await expect(page.getByTestId('push-state')).not.toHaveText('Vérification…')

  const saved = page.waitForResponse(
    r => r.url().endsWith('/api/profile/theme') && r.ok()
  )
  // Signed in, the theme is in the account menu.
  await page.getByTestId('account-menu').click()
  await page.getByTestId('account-theme').click()
  await page.getByTestId('theme-dark').click()
  await saved
  await expect(body(page)).toHaveClass(/body--dark/)

  // Another browser: nothing stored locally, the profile brings the theme back.
  await page.evaluate(() => localStorage.clear())
  await page.reload()
  await expect(body(page)).toHaveClass(/body--dark/)
  await expect(
    page.getByTestId('profile-theme').getByRole('button', { name: 'Sombre' })
  ).toHaveAttribute('aria-pressed', 'true')

  // The profile's own toggle.
  await page
    .getByTestId('profile-theme')
    .getByRole('button', { name: 'Clair' })
    .click()
  await expect(body(page)).toHaveClass(/body--light/)
})
