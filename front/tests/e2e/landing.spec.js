import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

test('landing: a visitor sees the landing page with its own header', async ({
  page
}) => {
  await page.goto('/#/')
  await expect(page.getByTestId('landing')).toBeVisible()
  await expect(page.getByTestId('landing-header')).toBeVisible()
  // The layout's header is replaced, not stacked.
  await expect(page.locator('.app-header')).toHaveCount(0)
  await expect(page.getByTestId('landing-header')).toContainText(
    "Don't Stay Rooky"
  )

  // All nine modules, those not built yet tagged "Bientôt".
  await expect(page.locator('[data-testid^="landing-module-"]')).toHaveCount(9)
  for (const id of ['puzzles', 'coordonnees', 'aveugle']) {
    await expect(page.getByTestId(`landing-module-${id}`)).not.toContainText(
      'Bientôt'
    )
  }
  await expect(page.getByTestId('landing-module-finales')).toContainText(
    'Bientôt'
  )

  // Lichess sign-in goes to the API's OAuth start.
  await expect(page.getByTestId('landing-lichess').first()).toHaveAttribute(
    'href',
    /\/api\/auth\/oauth\/lichess/
  )

  // Header anchors scroll in the page without changing the route.
  await page.getByRole('button', { name: 'Les profs' }).click()
  await expect(page).toHaveURL(/#\/$/)
  await expect(page.getByTestId('landing-prof-lizy')).toBeInViewport()

  await page.getByTestId('landing-start').click()
  await expect(page).toHaveURL(/#\/register$/)
})

test('landing: professor cards are public', async ({ page }) => {
  await page.goto('/#/')
  await page.getByTestId('landing-prof-aaron').click()
  await expect(page).toHaveURL(/#\/prof\/aaron$/)
  await expect(page.getByTestId('prof-name')).toHaveText('Aaron')
  await page.getByRole('link', { name: 'Retour à l’accueil' }).click()
  await expect(page.getByTestId('landing')).toBeVisible()
})

test('landing: footer pages exist', async ({ page }) => {
  await page.goto('/#/')
  await page.getByRole('link', { name: 'Confidentialité' }).click()
  await expect(page).toHaveURL(/#\/confidentialite$/)
  await expect(page.getByText('Contenu à venir.')).toBeVisible()
})

test('landing: a signed-in user gets the dashboard and the app header', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/')
  await expect(page.getByTestId('dashboard')).toBeVisible()
  await expect(page.getByTestId('landing')).toHaveCount(0)
  await expect(page.locator('.app-header')).toBeVisible()
})

test('landing: the hero shows the slogans in turn', async ({ page }) => {
  await page.clock.install()
  await page.goto('/#/')
  const slogan = page.getByTestId('landing-slogan')
  const shown = slogan.locator('[aria-hidden="false"]')

  await expect(shown).toHaveText(
    "Don't Stay Rooky ! Stop taking checkmate and find a mate !"
  )
  await page.clock.runFor(6000)
  await expect(shown).toHaveText("Don't Stay Rooky ! Become the King !")
  await page.clock.runFor(6000)
  await expect(shown).toHaveText(
    "Don't Stay Rooky ! Stop taking checkmate and find a mate !"
  )
})

test('404: an unknown page, back to the landing page for a visitor, to the dashboard once signed in', async ({
  page,
  context
}) => {
  await page.goto('/#/nowhere/at-all')
  const notFound = page.getByTestId('not-found')
  await expect(notFound).toBeVisible()
  await expect(notFound.getByRole('heading', { name: 'Blunder !' })).toBeVisible()
  await expect(notFound.getByTestId('not-found-home')).toHaveText(
    'Retour à l’accueil'
  )
  await expect(notFound.getByRole('link', { name: 'Se venger sur un puzzle' })).toHaveCount(0)

  await signIn(context)
  await page.goto('/#/nowhere/at-all')
  await expect(page.getByTestId('not-found-home')).toHaveText(
    'Retour au tableau de bord'
  )
  await page.getByTestId('not-found-home').click()
  await expect(page.getByTestId('dashboard')).toBeVisible()
})
