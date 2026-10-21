import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

const isSave = r =>
  r.url().endsWith('/api/onboarding') && r.request().method() === 'PUT'

// The guided tour (docs/ONBOARDING.md). The level's effect on the puzzle rating is covered by
// PHPUnit (tests/Functional/Onboarding/OnboardingTest.php); here, the screens, the saved answers
// and the ways in and out. The seeded user has no Lichess account.
test('tour: level and goal saved, the professors and Ray, then the session builder', async ({
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
  await expect(next).toHaveText('Voir mon plan')
  await next.click()

  // Where to start: without Lichess, the session builder first.
  await expect(tour).toHaveAttribute('data-step', 'end')
  await expect(page.getByTestId('tour-skip')).toBeHidden()
  await expect(page.getByTestId('tour-end-builder')).toHaveAttribute(
    'aria-checked',
    'true'
  )
  await expect(page.getByTestId('tour-end-coach')).toContainText(
    'Lichess requis'
  )
  await page.getByTestId('tour-end-coach').click()
  await expect(next).toHaveText('Lier Lichess et continuer')
  await page.getByTestId('tour-end-builder').click()
  await expect(next).toHaveText('C’est parti !')
  await next.click()
  await expect(page).toHaveURL(/#\/session\/new$/)

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
