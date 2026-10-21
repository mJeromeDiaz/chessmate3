import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())

// The questions, their time on the server's clock and the fight are covered by PHPUnit
// (tests/Functional/Vision/VisionGargoyleTest.php). Here, the Gargouille as the player meets it:
// strong distractions, its 15 s bar, a question left to its clock.
test('vision: the Gargouille, a question left to its 15 s', async ({
  page,
  context
}) => {
  test.setTimeout(90_000)
  await signIn(context, {
    mindMapChapters: 2,
    mindMapSteps: [
      '3-0-boss',
      ...['3-1', '3-2', '3-3'].flatMap(s =>
        [1, 2, 3, 4, 5, 'boss'].map(n => `${s}-${n}`)
      )
    ]
  })
  await page.goto('/#/cerveau/visualization')
  await page.getByTestId('path-chapter-boss-3').click()
  const intro = page.getByTestId('boss-intro')
  await expect(intro.getByTestId('boss-name')).toHaveText('La Gargouille')
  const served = page.waitForResponse(isRunNext)
  await intro.getByTestId('boss-fight').click()
  const item = (await (await served).json()).item
  expect([item.data.kind, item.data.seconds, item.data.distraction]).toEqual([
    'gargoyle',
    15,
    'strong'
  ])

  const player = page.getByTestId('vision-player')
  await expect(player.getByTestId('board-distractions')).toHaveAttribute(
    'data-distraction',
    'strong'
  )
  // Its face: neutral as the fight starts, delighted by a miss.
  await expect(player).toHaveAttribute('data-mood', 'neutral')
  await player.getByTestId('vision-memorized').click()
  await expect(player).toHaveAttribute('data-phase', 'ask', { timeout: 20_000 })
  await expect(player.getByTestId('vision-clock')).toBeVisible()

  // Nothing answered: at zero, the question goes empty, a life lost.
  const submitted = page.waitForResponse(isRunSubmission, { timeout: 25_000 })
  const result = (await (await submitted).json()).result
  expect(result.data.status).toBe('wrong')
  await expect(player).toHaveAttribute('data-mood', 'joy')
  await expect(page.getByTestId('vision-lives')).toHaveAttribute(
    'data-lives',
    '2'
  )
})
