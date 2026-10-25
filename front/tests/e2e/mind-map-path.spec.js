import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'

/**
 * Answers `count` squares of a coordinates series, `right` of them right, then « Terminer ».
 *
 * @param {import('@playwright/test').Page} page
 * @param {string[]} squares
 * @param {number} count
 * @param {number} right
 */
async function answerAndStop(page, squares, count, right) {
  const player = page.getByTestId('coordinates-player')
  for (let i = 0; i < count; i++) {
    await expect(page.getByTestId('coordinates-target')).toHaveText(squares[i])
    const square = i < right ? squares[i] : squares[i] === 'a1' ? 'h8' : 'a1'
    await player.getByTestId(`square-${square}`).click()
  }
  await page.getByTestId('run-stop').click()
  await page
    .getByRole('dialog')
    .filter({ hasText: 'Terminer la séance ?' })
    .getByRole('button', { name: 'OK' })
    .click()
}

// The judging of passes, chests and bosses is covered by PHPUnit
// (tests/Functional/MindMap/MindMapPathTest.php). Here, the path as the player walks it.
test('mind map path: chapters and steps, a pass, a failed lesson, a boss announced, a skip', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/cerveau')
  await page.getByTestId('capacity-card-visualization').click()
  await expect(page).toHaveURL(/#\/cerveau\/visualization$/)

  await expect(page.getByTestId('path-chapter-1')).toHaveAttribute(
    'data-status',
    'open'
  )
  await expect(page.getByTestId('path-chapter-2')).toHaveAttribute(
    'data-status',
    'locked'
  )
  await expect(page.getByTestId('path-step-1-1')).toHaveAttribute(
    'data-status',
    'open'
  )
  await expect(page.getByTestId('path-step-1-2')).toHaveAttribute(
    'data-status',
    'locked'
  )
  // Arriving: the step to play is on screen, no scrolling to find it.
  await expect(page.getByTestId('path-step-1-1')).toBeInViewport()
  // A locked chapter shows all its steps, greyed.
  await expect(page.getByTestId('path-step-2-1-1')).toHaveAttribute(
    'data-status',
    'locked'
  )
  // Deep in chapter 2, its card sticks under the header, on one line.
  await page.getByTestId('path-step-2-2-1').scrollIntoViewIfNeeded()
  await expect(
    page.getByTestId('path-chapter-2').locator('.path-chapter__head')
  ).toHaveAttribute('data-stuck', 'true')
  await page.getByTestId('path-step-1-1').scrollIntoViewIfNeeded()
  // Beside the path, the step to play shown in full until another one is chosen.
  await expect(page.getByTestId('step-detail')).toHaveAttribute(
    'data-step',
    '1-1'
  )
  await expect(page.getByTestId('step-detail')).toContainText('Cliquer la case')

  // The chapter's boss, at its end: open with its chapter (a strong player may go straight to
  // it).
  await page.getByTestId('path-step-1-boss').click()
  const intro = page.getByTestId('step-detail')
  await expect(intro.getByTestId('boss-name')).toHaveText('Le Jongleur')
  await expect(intro).toContainText('3 vies')
  await expect(intro.getByTestId('boss-fight')).toBeVisible()

  // Exercise 1.1: a one-minute coordinates series, 10 answers at 80 %.
  await page.getByTestId('path-step-1-1').click()
  await expect(page.getByTestId('step-passes')).toContainText('Passage 1 sur 2')
  // Clicked again while shown: played, as « Commencer » does.
  let served = page.waitForResponse(isRunNext)
  await page.getByTestId('path-step-1-1').click()
  await expect(page).toHaveURL(/#\/training\/[^/?]+\?mindMap=1(&boss=\w+)?$/)
  let item = (await (await served).json()).item
  expect(item.type).toBe('coordinates_series')
  await answerAndStop(page, item.data.squares, 10, 10)

  const outcome = page.getByTestId('mind-outcome')
  await expect(outcome.getByTestId('mind-outcome-title')).toHaveText(
    'Passage réussi'
  )
  await expect(outcome.getByTestId('mind-outcome-passes')).toHaveText(
    'Passage 1 sur 2'
  )

  // The next pass, failed: nothing counts.
  served = page.waitForResponse(isRunNext)
  await outcome.getByTestId('mind-outcome-replay').click()
  item = (await (await served).json()).item
  await answerAndStop(page, item.data.squares, 3, 3)
  await expect(outcome.getByTestId('mind-outcome-title')).toHaveText(
    'Pas encore'
  )
  await expect(outcome.getByTestId('mind-outcome-passes')).toHaveText(
    'Passage 1 sur 2'
  )

  // Back on the path: skipped, 1.1 opens what follows.
  await outcome.getByTestId('mind-outcome-back').click()
  await expect(page).toHaveURL(/#\/cerveau\/visualization$/)
  await page.getByTestId('path-step-1-1').click()
  await page.getByTestId('step-skip').click()
  await page
    .getByRole('dialog')
    .filter({ hasText: 'Passer cet exercice ?' })
    .getByRole('button', { name: 'OK' })
    .click()
  await expect(page.getByTestId('path-step-1-1')).toHaveAttribute(
    'data-status',
    'skipped'
  )
  await expect(page.getByTestId('path-step-1-2')).toHaveAttribute(
    'data-status',
    'open'
  )
  // What opened shows beside the path now.
  await expect(page.getByTestId('step-detail')).toHaveAttribute(
    'data-step',
    '1-2'
  )
})

test('mind map path: a mini-boss is open with its section, its fight in sight', async ({
  page,
  context
}) => {
  await signIn(context, { mindMapChapters: 1 })
  await page.goto('/#/cerveau/visualization')
  // Section 2.1 open: its mini-boss at once (a strong player skips the exercises), not 2.2's.
  await expect(page.getByTestId('path-step-2-1-1')).toHaveAttribute(
    'data-status',
    'open'
  )
  await expect(page.getByTestId('path-step-2-1-boss')).toHaveAttribute(
    'data-status',
    'open'
  )
  await expect(page.getByTestId('path-step-2-2-boss')).toHaveAttribute(
    'data-status',
    'locked'
  )
  await page.getByTestId('path-step-2-1-boss').click()
  const detail = page.getByTestId('step-detail')
  await expect(detail).toHaveAttribute('data-step', '2-1-boss')
  // However tall the detail, its button stays on screen.
  await expect(detail.getByTestId('boss-fight')).toBeInViewport()
})

test('mind map path: on a phone, a step opens in a sheet from the bottom', async ({
  page,
  context
}) => {
  await page.setViewportSize({ width: 390, height: 800 })
  await signIn(context)
  await page.goto('/#/cerveau/visualization')
  await expect(page.getByTestId('path-step-1-1')).toBeVisible()
  // No column on a phone: nothing shown until a step is chosen.
  await expect(page.getByTestId('step-detail')).toHaveCount(0)

  await page.getByTestId('path-step-1-boss').click()
  const sheet = page.getByTestId('step-detail')
  await expect(sheet).toHaveAttribute('data-step', '1-boss')
  await expect(sheet.getByTestId('boss-name')).toHaveText('Le Jongleur')
  await sheet.getByRole('button', { name: 'Fermer' }).click()
  await expect(sheet).toHaveCount(0)
})

test('mind map path: a chest not opened yet opens on arrival', async ({
  page,
  context
}) => {
  await signIn(context)
  const chestId = '01920000-0000-7000-8000-000000000001'
  await page.route('**/api/mind-map/visualisation', async route => {
    const response = await route.fetch()
    const path = await response.json()
    path.unopenedChests = [
      { id: chestId, step: '1-chest', crystals: 60, opened: false }
    ]
    await route.fulfill({ response, json: path })
  })
  let opened = false
  await page.route(`**/api/mind-map/chests/${chestId}/open`, route => {
    opened = true
    return route.fulfill({
      json: {
        id: chestId,
        capacity: 'visualisation',
        step: '1-chest',
        crystals: 60,
        opened: true,
        balance: 60
      }
    })
  })

  await page.goto('/#/cerveau/visualization')
  const chest = page.getByTestId('treasure-chest')
  await expect(chest).toBeVisible()
  for (let i = 0; i < 3; i++) await chest.getByTestId('chest-hit').click()
  await expect(chest.getByTestId('chest-amount')).toHaveText('+60')
  await expect(chest).toContainText('Carte mentale · Coffre')
  await expect.poll(() => opened).toBe(true)
  await chest.getByTestId('chest-results').click()
  await expect(chest).toBeHidden()
  await expect(page.getByTestId('path-chapter-1')).toBeVisible()
})
