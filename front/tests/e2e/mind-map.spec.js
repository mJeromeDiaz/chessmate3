import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

test('mind map: a capacity chosen on the brain or on its card opens beside it', async ({
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

  // A card: its capacity opens on the side.
  await page.getByTestId('capacity-card-visualization').click()
  const panel = page.getByTestId('capacity-panel')
  await expect(panel).toHaveAttribute('data-capacity', 'visualization')
  await expect(panel).toContainText('La visualisation spatiale')
  await expect(panel.getByTestId('capacity-module-recall')).toContainText(
    'principal'
  )
  await expect(panel.getByTestId('capacity-why')).toHaveCount(0)
  await panel.getByTestId('capacity-why-toggle').click()
  await expect(panel.getByTestId('capacity-why')).toContainText(
    'Associer nom et case rapidement'
  )
  await panel.getByTestId('capacity-panel-close').click()
  await expect(panel).toBeHidden()
  await expect(page.getByTestId('capacity-card-visualization')).toHaveAttribute(
    'aria-pressed',
    'false'
  )

  // A zone of the brain does the same; a module leads to its page.
  await page.getByTestId('brain-zone-composure').click({ force: true })
  await expect(panel).toHaveAttribute('data-capacity', 'composure')
  await panel.getByTestId('capacity-module-streak').click()
  await expect(page).toHaveURL(/#\/streak$/)
})

test('mind map: the calculation path, its Métronome standing on its card', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/cerveau/calculation')
  await expect(page.getByText('La ligne droite').first()).toBeVisible()
  await expect(page.getByTestId('path-step-1-1')).toHaveAttribute(
    'data-status',
    'open'
  )

  await page.getByTestId('path-chapter-boss-1').click()
  const intro = page.getByTestId('boss-intro')
  await expect(intro.getByTestId('boss-name')).toHaveText('Le Métronome')
  await expect(intro.locator('[data-mood="standing"]')).toBeVisible()
  // Open with its chapter: fought at once by a strong player.
  await expect(intro.getByTestId('boss-fight')).toBeVisible()
})
