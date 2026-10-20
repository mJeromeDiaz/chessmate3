import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())

// The relations and their judging are covered by PHPUnit (tests/Unit/Vision/RelationsTest.php,
// tests/Functional/Vision/VisionRelationTest.php). Here, the section 3.3 as the player meets it:
// the squares clicked then « Valider », the correction in green and red.
test('vision: every piece attacked, clicked then validated', async ({
  page,
  context
}) => {
  await signIn(context, {
    mindMapChapters: 2,
    mindMapSteps: [
      '3-0-boss',
      ...['3-1', '3-2'].flatMap(s => [1, 2, 3, 4, 5].map(n => `${s}-${n}`)),
      '3-1-boss',
      '3-2-boss'
    ]
  })
  await page.goto('/#/cerveau/visualization')
  await page.getByTestId('path-step-3-3-1').click()
  const served = page.waitForResponse(isRunNext)
  await page.getByTestId('step-start').click()
  const item = (await (await served).json()).item
  expect([item.data.kind, item.data.ask]).toEqual(['rel_attacked', 'attacked'])

  const player = page.getByTestId('vision-player')
  await player.getByTestId('vision-memorized').click()
  await expect(player).toHaveAttribute('data-phase', 'ask', { timeout: 15_000 })
  await expect(player.getByTestId('vision-question')).toContainText(
    'sont attaquées ?'
  )
  // A wrong answer on purpose: a1, then a1 again (chosen, unchosen), then h8.
  await player.getByTestId('square-a1').click()
  await expect(player.getByTestId('vision-chosen')).toContainText('1 case choisie')
  await player.getByTestId('square-a1').click()
  await expect(player.getByTestId('vision-chosen')).toContainText('0 case choisie')
  await player.getByTestId('square-h8').click()
  const submitted = page.waitForResponse(isRunSubmission)
  await player.getByTestId('vision-validate').click()
  const result = (await (await submitted).json()).result
  expect(result.data.answer).toEqual(['h8'])
  if (result.data.status === 'wrong') {
    await expect(player.getByTestId('vision-result')).toContainText(
      'Les pièces attaquées'
    )
  }
})
