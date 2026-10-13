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

  // The hero's call to action: the tailored session, which offers the Lichess sign-in itself.
  await expect(page.getByTestId('landing-coach')).toHaveAttribute(
    'href',
    '#/session/auto'
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

  // The "from my games" section: a Lichess sign-in too.
  for (const id of ['landing-auto-card', 'landing-auto-session-btn']) {
    await expect(page.getByTestId(id)).toHaveAttribute(
      'href',
      /\/api\/auth\/oauth\/lichess\/redirect$/
    )
  }
  await page.getByRole('button', { name: 'Lichess', exact: true }).click()
  await expect(page.getByTestId('landing-auto')).toBeInViewport()
})

/** The page a landing call to action noted for after the Lichess sign-in. */
const afterSignIn = page =>
  page.evaluate(() => sessionStorage.getItem('dontstayrooky.afterSignIn'))

test('landing: "Générer mon répertoire" signs up through the early access screen, then opens the repertoire from my games', async ({
  page,
  context,
  baseURL
}) => {
  // Lichess is never reached: the API's OAuth start answers as its callback does for a Lichess
  // account linked to no account.
  await page.route(/\/api\/auth\/oauth\/lichess\/redirect$/, route =>
    route.fulfill({
      status: 302,
      headers: {
        location: `${baseURL}/#/oauth/callback?status=error&mode=login&provider=lichess&reason=invitation_required`
      }
    })
  )
  await page.goto('/#/')
  await page.getByTestId('landing-auto-card').click()

  // The key is asked for; the destination survives that detour.
  await expect(page).toHaveURL(/#\/register$/)
  await expect(page.getByTestId('access-provider-note')).toBeVisible()
  expect(await afterSignIn(page)).toBe('/repertoire/auto')

  // Back from Lichess with the key: signed up, straight to the repertoire from my games.
  await signIn(context)
  await page.goto(
    '/#/oauth/callback?status=success&mode=login&provider=lichess'
  )
  await expect(page).toHaveURL(/#\/repertoire\/auto$/)
  await expect(page.getByTestId('auto-link')).toBeVisible()
  expect(await afterSignIn(page)).toBeNull()
})

test('landing: a failed Lichess sign-in forgets where the call to action led', async ({
  page
}) => {
  await page.goto('/#/')
  // The app started first: a hash change during its first navigation is lost.
  await expect(page.getByTestId('landing')).toBeVisible()
  await page.evaluate(() =>
    sessionStorage.setItem('dontstayrooky.afterSignIn', '/repertoire/auto')
  )
  await page.goto(
    '/#/oauth/callback?status=error&mode=login&provider=lichess&reason=cancelled'
  )
  await expect(page.getByText('Connexion annulée.')).toBeVisible()
  expect(await afterSignIn(page)).toBeNull()
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
  await expect(
    notFound.getByRole('heading', { name: 'Blunder !' })
  ).toBeVisible()
  await expect(notFound.getByTestId('not-found-home')).toHaveText(
    'Retour à l’accueil'
  )
  // The dashboard and the puzzles are for signed-in users only.
  await expect(notFound.getByTestId('not-found-dashboard')).toHaveCount(0)
  await expect(notFound.getByTestId('not-found-puzzle')).toHaveCount(0)

  // Signed in: the same URL reloaded (a hash-only goto would keep the visitor's session state).
  await signIn(context)
  await page.reload()
  await expect(notFound.getByTestId('not-found-dashboard')).toHaveText(
    'Retour au tableau de bord'
  )
  await expect(notFound.getByTestId('not-found-home')).toHaveCount(0)
  await expect(notFound.getByTestId('not-found-puzzle')).toHaveAttribute(
    'href',
    '#/puzzle'
  )
  await notFound.getByTestId('not-found-dashboard').click()
  await expect(page.getByTestId('dashboard')).toBeVisible()
})
