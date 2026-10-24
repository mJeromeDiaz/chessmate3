import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

const isSave = r =>
  r.url().endsWith('/api/onboarding') && r.request().method() === 'PUT'

// The guided tour (docs/ONBOARDING.md). The level's effect on the puzzle rating is covered by
// PHPUnit (tests/Functional/Onboarding/OnboardingTest.php); here, the screens, the saved answers
// and the ways in and out. The seeded user has no Lichess account.
test('tour: level and goal saved, the professors and Ray, the bosses and the map, then the session builder', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/visite')
  const tour = page.getByTestId('tour')
  const next = page.getByTestId('tour-next')
  const line = page.getByTestId('tour-line')

  await expect(tour).toHaveAttribute('data-step', 'hello')
  await expect(line).toContainText('Albert Stein')
  await expect(page.getByTestId('tour-back')).toBeHidden()
  await next.click()

  // Level: no Lichess, so a button to link it, and nothing chosen yet.
  await expect(tour).toHaveAttribute('data-step', 'level')
  await expect(page.getByTestId('tour-chip')).toContainText('NIVEAU')
  await expect(page.getByTestId('tour-link-lichess')).toBeVisible()
  await expect(next).toBeDisabled()
  await page.getByTestId('tour-level-expert').click()
  await expect(page.getByTestId('tour-level-expert')).toHaveAttribute(
    'aria-checked',
    'true'
  )
  await expect(line).toContainText('réviser mes notes')
  await expect(page.getByTestId('tour-rating-note')).toContainText(
    'démarreront vers 2 200'
  )
  let saved = page.waitForResponse(isSave)
  await next.click()
  expect(await (await saved).json()).toMatchObject({
    level: 'expert',
    goal: null,
    puzzleRating: 2200
  })

  // Goal: one, as radio buttons.
  await expect(tour).toHaveAttribute('data-step', 'goal')
  await expect(next).toBeDisabled()
  await page.getByTestId('tour-goal-fun').click()
  await page.getByTestId('tour-goal-kasparov').click()
  await expect(page.getByTestId('tour-goal-fun')).toHaveAttribute(
    'aria-checked',
    'false'
  )
  await expect(line).toContainText('Garry')
  saved = page.waitForResponse(isSave)
  await next.click()
  expect((await (await saved).json()).goal).toBe('kasparov')

  // The professors, one at a time; "Pourquoi ça aide" folds and unfolds.
  await expect(tour).toHaveAttribute('data-step', 'albert')
  await expect(page.getByTestId('tour-chip')).toContainText('PROF 1 / 4')
  await expect(page.getByTestId('tour-module-woodpecker')).toContainText(
    'Méthode phare'
  )
  const why = page.getByTestId('tour-why-puzzles')
  await expect(why).toHaveAttribute('aria-expanded', 'false')
  await why.click()
  await expect(why).toHaveAttribute('aria-expanded', 'true')
  await expect(page.getByTestId('tour-module-puzzles')).toContainText(
    'fourchette'
  )
  await next.click()
  await expect(page.getByTestId('tour-module-analyse')).toContainText('Bientôt')
  await next.click()
  await expect(page.getByTestId('tour-module-coach')).toBeVisible()
  await next.click()
  await expect(tour).toHaveAttribute('data-step', 'ray')
  await expect(line).toContainText('yeux fermés')
  await expect(page.getByTestId('tour-module-aveugle')).toBeVisible()
  await expect(page.getByTestId('tour-module-coordonnees')).toBeVisible()
  await expect(next).toHaveText('Voir les boss')
  await next.click()

  // The bosses, going round: no count anywhere, the last ones in the shadow.
  await expect(tour).toHaveAttribute('data-step', 'bosses')
  // The chip counts the tour's steps, never the bosses (« BOSS 1 / 5 »).
  await expect(page.getByTestId('tour-chip')).toContainText('5 / 7')
  await expect(page.getByTestId('tour-chip')).not.toContainText(/BOSS \d/)
  await page.getByTestId('tour-boss-next').click()
  await expect(page.getByTestId('tour-boss-metronome')).toHaveAttribute(
    'aria-current',
    'true'
  )
  await expect(line).toContainText('la mesure est perdue')
  // Back past the first one: the gallery goes round to the shadow.
  await page.getByTestId('tour-boss-previous').click()
  await page.getByTestId('tour-boss-previous').click()
  const others = page.getByTestId('tour-boss-others')
  await expect(others).toHaveAttribute('aria-current', 'true')
  await expect(others).toContainText('Et d’autres…')
  await expect(line).toContainText('garder des surprises')
  await expect(next).toHaveText('Même pas peur')
  await next.click()

  // The map: a real piece of path, opened on the step to play; a step shows what it earns.
  await expect(tour).toHaveAttribute('data-step', 'map')
  const map = page.getByTestId('tour-map')
  const detail = page.getByTestId('tour-map-detail')
  await expect(detail).toContainText('Passages : 2 / 3 à 80 %')
  await map.getByTestId('path-step-2-1-boss').click()
  await expect(detail).toContainText('+80 cristaux')
  await map.getByTestId('path-step-2-boss').click()
  await expect(detail).toContainText('Le Cyclope')
  await expect(detail).toContainText('+240 cristaux')
  await expect(next).toHaveText('Voir mon plan')
  await next.click()

  // Where to start: without Lichess, the mind map first.
  await expect(tour).toHaveAttribute('data-step', 'end')
  await expect(page.getByTestId('tour-skip')).toBeHidden()
  await expect(page.getByTestId('tour-end-map')).toHaveAttribute(
    'aria-checked',
    'true'
  )
  await expect(page.getByTestId('tour-end-map')).toContainText('Conseillé')
  // The session and the repertoire: their pages offer both the generated and the hand-made one,
  // Lichess is asked for there.
  await expect(page.getByTestId('tour-end-choices')).not.toContainText(
    'Lichess requis'
  )
  await page.getByTestId('tour-end-coach').click()
  await expect(next).toHaveText('C’est parti !')
  await next.click()
  await expect(page).toHaveURL(/#\/session$/)

  // The answers in the profile, still able to set the starting rating.
  await page.goto('/#/profile')
  const card = page.getByTestId('profile-tour')
  await expect(card.getByTestId('profile-tour-level')).toHaveValue('Expert')
  await expect(card.getByTestId('profile-tour-goal')).toHaveValue(
    'Arracher une nulle à Kasparov'
  )
  await expect(card).toContainText('démarreront vers 2 200')
  saved = page.waitForResponse(isSave)
  await card.getByTestId('profile-tour-level').click()
  await page.getByRole('option', { name: 'Amateur' }).click()
  expect(await (await saved).json()).toMatchObject({
    level: 'amateur',
    goal: 'kasparov',
    puzzleRating: 1400
  })
})

test('tour: kept in the browser, back, segments and skip; reopened from the profile', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/visite')
  const tour = page.getByTestId('tour')
  await page.getByTestId('tour-next').click()
  await expect(tour).toHaveAttribute('data-step', 'level')

  // Kept across a reload.
  await page.reload()
  await expect(tour).toHaveAttribute('data-step', 'level')
  await page.getByTestId('tour-back').click()
  await expect(tour).toHaveAttribute('data-step', 'hello')

  // A segment goes to its first screen; "Passer" to the plan.
  await page.getByTestId('tour-segment-3').click()
  await expect(tour).toHaveAttribute('data-step', 'albert')
  await page.getByTestId('tour-skip').click()
  await expect(tour).toHaveAttribute('data-step', 'end')

  // The profile starts it over. (In-app links only from here: a goto that only changes the hash
  // may leave the router where it was.)
  await page.getByTestId('nav-burger').click()
  await page.getByTestId('nav-profile').click()
  await expect(page).toHaveURL(/#\/profile$/)
  await page.getByTestId('profile-tour-restart').click()
  await expect(page).toHaveURL(/#\/visite$/)
  await expect(tour).toHaveAttribute('data-step', 'hello')
})

test('tour: a visitor discovers it from the landing, nothing sent, then the real tour starts with those answers', async ({
  page,
  context
}) => {
  /** @type {string[]} */
  const calls = []
  page.on('request', r => {
    if (r.url().includes('/api/onboarding')) calls.push(r.method())
  })
  await page.goto('/#/')
  await expect(page.getByTestId('landing')).toBeVisible()
  await page.getByTestId('landing-tour').click()
  await expect(page).toHaveURL(/#\/visite$/)
  const tour = page.getByTestId('tour')
  const next = page.getByTestId('tour-next')
  await expect(tour).toHaveAttribute('data-step', 'hello')
  await expect(page.getByText('Sans compte')).toBeVisible()
  await next.click()

  // No Lichess to link before an account.
  await expect(tour).toHaveAttribute('data-step', 'level')
  await expect(page.getByTestId('tour-link-lichess')).toHaveCount(0)
  await page.getByTestId('tour-level-expert').click()
  await next.click()
  await page.getByTestId('tour-goal-fun').click()
  await next.click()
  await expect(tour).toHaveAttribute('data-step', 'albert')

  // The end asks for an account.
  await page.getByTestId('tour-skip').click()
  await expect(tour).toHaveAttribute('data-step', 'end')
  await expect(page.getByTestId('tour-visitor-end')).toBeVisible()
  await expect(page.getByTestId('tour-visitor-login')).toHaveAttribute(
    'href',
    '#/login'
  )
  await expect(next).toHaveText('Créer mon compte')
  await next.click()
  await expect(page).toHaveURL(/#\/register$/)
  expect(calls).toEqual([])
  expect(
    await page.evaluate(() => localStorage.getItem('dontstayrooky.tourAnswers'))
  ).toBe('{"level":"expert","goal":"fun"}')

  // Signed up: the real tour starts over, the visitor's answers chosen, then saved and forgotten.
  await signIn(context)
  await page.goto('/#/visite')
  await page.reload()
  await expect(tour).toHaveAttribute('data-step', 'hello')
  await next.click()
  await expect(page.getByTestId('tour-level-expert')).toHaveAttribute(
    'aria-checked',
    'true'
  )
  const saved = page.waitForResponse(isSave)
  await next.click()
  expect(await (await saved).json()).toMatchObject({
    level: 'expert',
    goal: 'fun'
  })
  expect(
    await page.evaluate(() => localStorage.getItem('dontstayrooky.tourAnswers'))
  ).toBeNull()
})
