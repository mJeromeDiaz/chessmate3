import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

test('mind map: a capacity chosen on its card or on the brain opens its path', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/cerveau')
  await expect(page.getByTestId('brain-map')).toBeVisible()
  for (const key of [
    'calculation',
    'visualization',
    'patterns',
    'speed',
    'strategy',
    'composure'
  ])
    await expect(page.getByTestId(`capacity-card-${key}`)).toBeVisible()

  // Without path yet: disabled, « Bientôt », on its card as on its zone.
  await expect(page.getByTestId('capacity-card-strategy')).toBeDisabled()
  await expect(page.getByTestId('capacity-soon-strategy')).toHaveText('Bientôt')
  await expect(page.getByTestId('capacity-soon-visualization')).toHaveCount(0)
  await page.getByTestId('brain-zone-composure').click({ force: true })
  await expect(page).toHaveURL(/#\/cerveau$/)

  // A card opens its path, no popup in between.
  await page.getByTestId('capacity-card-visualization').click()
  await expect(page).toHaveURL(/#\/cerveau\/visualization$/)
  await expect(page.getByTestId('mind-path')).toBeVisible()

  // A zone of the brain does the same.
  await page.goBack()
  await page.getByTestId('brain-zone-calculation').click({ force: true })
  await expect(page).toHaveURL(/#\/cerveau\/calculation$/)
})

test('mind map: the calculation path, its Métronome standing on its card', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/cerveau/calculation')
  await expect(page.getByText('La ligne droite').first()).toBeVisible()
  // Its head: the capacity, nothing passed yet.
  const hero = page.getByTestId('path-hero')
  await expect(hero).toContainText('La profondeur de calcul')
  await expect(page.getByTestId('path-hero-progress')).toContainText(
    'Capacité 1 · 0 /'
  )
  await expect(page.getByTestId('path-step-1-1')).toHaveAttribute(
    'data-status',
    'open'
  )

  await page.getByTestId('path-step-1-boss').click()
  const intro = page.getByTestId('step-detail')
  await expect(intro.getByTestId('boss-name')).toHaveText('Le Métronome')
  await expect(intro.locator('[data-mood="standing"]')).toBeVisible()
  // Open with its chapter: fought at once by a strong player.
  await expect(intro.getByTestId('boss-fight')).toBeVisible()
})
