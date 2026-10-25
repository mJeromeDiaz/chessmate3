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

test('header: back to the parent page, the page title and subtitle, the brand on the dashboard', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/repertoire/stats')
  await expect(page.getByTestId('header-title')).toHaveText(
    'Statistiques des répertoires'
  )
  await expect(page.getByTestId('header-subtitle')).toHaveText('Répertoires')

  await page.getByTestId('header-back').click()
  await expect(page).toHaveURL(/#\/repertoire$/)
  await expect(page.getByTestId('header-title')).toHaveText('Répertoires')
  await expect(page.getByTestId('header-subtitle')).toHaveText(
    'Entraînement libre'
  )
  // A top-level page: no arrow (its parent would be the dashboard).
  await expect(page.getByTestId('header-back')).toHaveCount(0)

  await page.getByTestId('nav-burger').click()
  await page.getByTestId('nav-dashboard').click()
  await expect(page.getByTestId('dashboard')).toBeVisible()
  await expect(page.getByTestId('header-title')).toHaveCount(0)
  await expect(
    page.locator('.app-header').getByRole('link', { name: "Don't Stay Rooky" })
  ).toBeVisible()

  // The crystals lead to the shop.
  await page.getByTestId('crystals').click()
  await expect(page).toHaveURL(/#\/boutique$/)
})

test('wide screen: the menu, its free training panel and the account', async ({
  page,
  context
}) => {
  await signIn(context, { admin: true })
  await page.goto('/#/')
  await expect(page.getByTestId('dashboard')).toBeVisible()

  const menu = page.getByTestId('nav-menu')
  await expect(menu).not.toBeInViewport()
  await page.getByTestId('nav-burger').click()
  await expect(menu).toBeInViewport()
  await expect(page.getByTestId('nav-dashboard')).toHaveClass(
    /app-menu__item--on/
  )
  await expect(page.getByTestId('nav-dashboard')).toContainText(
    'Suis ta progression.'
  )
  await expect(page.getByTestId('nav-create')).toContainText(
    'Crée ta séance d’entraînement'
  )
  await page.getByTestId('nav-mind-map').click()
  await expect(page).toHaveURL(/#\/cerveau$/)
  await expect(menu).not.toBeInViewport()
  await expect(page.getByTestId('header-title')).toHaveText(
    'Entraînement ludique'
  )
  await expect(page.getByTestId('header-subtitle')).toHaveText('Tes capacités')
  await expect(page.getByTestId('mind-lead')).toContainText('Six capacités')

  // "Entraînement libre" slides in the list of modules, and back.
  await page.getByTestId('nav-burger').click()
  await page.getByTestId('nav-free').click()
  await expect(page.getByTestId('nav-free-woodpecker')).toBeVisible()
  await expect(page.getByTestId('nav-free-section-middlegame')).toHaveText(
    'Milieu de jeu'
  )
  await expect(page.getByTestId('nav-free-endgames')).toBeVisible()
  await expect(page.getByTestId('nav-free-challenges')).toHaveCount(0)
  await page.getByTestId('nav-free-back').click()
  await expect(page.getByTestId('nav-mind-map')).toBeVisible()
  await page.getByTestId('nav-free').click()
  await page.getByTestId('nav-free-repertoire').click()
  await expect(page).toHaveURL(/#\/repertoire$/)

  await page.getByTestId('nav-burger').click()
  await expect(page.getByTestId('nav-free')).toHaveClass(/app-menu__item--on/)
  await page.getByTestId('nav-free').click()
  await expect(page.getByTestId('nav-free-repertoire')).toHaveAttribute(
    'aria-current',
    'page'
  )
  // Puzzle Rush opens its launcher on the puzzle page.
  await page.getByTestId('nav-free-rush').click()
  await expect(page.getByTestId('rush-dialog')).toBeVisible()
  await expect(page).toHaveURL(/#\/puzzle$/)
  await page.keyboard.press('Escape')

  // The défis card: the endgames' path and Lizy's défis.
  await page.getByTestId('nav-burger').click()
  await page.getByTestId('nav-challenges-defis').click()
  await expect(page).toHaveURL(/#\/defis$/)
  await page.getByTestId('nav-burger').click()
  await expect(page.getByTestId('nav-challenges-defis')).toHaveAttribute(
    'aria-current',
    'page'
  )
  await page.getByTestId('nav-challenges-endgames').click()
  await expect(page).toHaveURL(/#\/finales$/)

  await page.getByTestId('nav-burger').click()
  await expect(page.getByTestId('nav-shop')).toBeVisible()
  await expect(page.getByTestId('nav-admin')).toBeVisible()
  await page.getByTestId('nav-profile').click()
  await expect(page).toHaveURL(/#\/profile$/)

  await page.getByTestId('nav-burger').click()
  await page.getByTestId('nav-logout').click()
  await expect(page).toHaveURL(/#\/login/)
})

test('phone: the header fits, the menu closes once a page is chosen', async ({
  page,
  context
}) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await signIn(context)
  await page.goto('/#/')
  await expect(page.getByTestId('dashboard')).toBeVisible()
  await expect(page.getByTestId('streak')).toBeVisible()
  await expect(page.getByTestId('crystals')).toBeVisible()
  expect(await overflows(page)).toBe(false)

  const menu = page.getByTestId('nav-menu')
  await page.getByTestId('nav-burger').click()
  await expect(menu).toBeInViewport()
  await expect(page.getByTestId('nav-admin')).toHaveCount(0)
  await page.getByTestId('nav-shop').click()
  await expect(page).toHaveURL(/#\/boutique$/)
  await expect(menu).not.toBeInViewport()
  expect(await overflows(page)).toBe(false)

  await page.getByTestId('nav-burger').click()
  await page.getByTestId('nav-logout').click()
  await expect(page).toHaveURL(/#\/login/)
  // Signed out, the login screen has its own header (no app header) and still fits.
  await expect(page.locator('.app-header')).toHaveCount(0)
  expect(await overflows(page)).toBe(false)
})
