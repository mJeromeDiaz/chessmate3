import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

/**
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<boolean>} whether the page scrolls sideways
 */
const overflows = page =>
  page.evaluate(
    () =>
      document.documentElement.scrollWidth >
      document.documentElement.clientWidth
  )

test('wide screen: the pages in the header, the account in the avatar menu', async ({
  page,
  context
}) => {
  await signIn(context, { admin: true })
  await page.goto('/#/')
  await expect(page.getByTestId('dashboard')).toBeVisible()

  const header = page.locator('.app-header')
  await expect(page.getByTestId('nav-burger')).toBeHidden()
  await header.getByRole('link', { name: 'Répertoires' }).click()
  await expect(page).toHaveURL(/#\/repertoire$/)
  await expect(
    header.getByRole('link', { name: 'Répertoires' })
  ).toHaveAttribute('aria-current', 'page')
  await expect(
    header.getByRole('link', { name: 'Puzzles' })
  ).not.toHaveAttribute('aria-current', 'page')

  await page.getByTestId('account-menu').click()
  await expect(page.getByTestId('nav-admin')).toBeVisible()
  await page.getByTestId('nav-profile').click()
  await expect(page).toHaveURL(/#\/profile$/)

  await page.getByTestId('account-menu').click()
  await page.getByTestId('nav-logout').click()
  await expect(page).toHaveURL(/#\/login/)
})

test('phone: the burger opens a drawer that closes once a page is chosen', async ({
  page,
  context
}) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await signIn(context)
  await page.goto('/#/')
  await expect(page.getByTestId('dashboard')).toBeVisible()
  expect(await overflows(page)).toBe(false)

  const drawer = page.getByTestId('nav-drawer')
  await expect(
    page.locator('.app-header').getByRole('link', { name: 'Puzzles' })
  ).toBeHidden()
  await expect(page.getByTestId('account-menu')).toBeHidden()
  await expect(drawer).not.toBeInViewport()

  await page.getByTestId('nav-burger').click()
  await expect(drawer).toBeInViewport()
  await expect(drawer.getByTestId('drawer-admin')).toHaveCount(0)
  await drawer.getByRole('link', { name: 'Répertoires' }).click()
  await expect(page).toHaveURL(/#\/repertoire$/)
  await expect(drawer).not.toBeInViewport()

  await page.getByTestId('nav-burger').click()
  await page.getByTestId('drawer-logout').click()
  await expect(page).toHaveURL(/#\/login/)
  // Signed out, the login screen has its own header (no app header) and still fits.
  await expect(page.locator('.app-header')).toHaveCount(0)
  expect(await overflows(page)).toBe(false)
})
