import { expect, test } from '@playwright/test'
import { signIn, solve } from './helpers.js'

const isNext = r =>
  /\/api\/woodpecker\/sets\/[^/]+\/attempts$/.test(r.url()) &&
  r.request().method() === 'POST'
const isSubmission = r =>
  /\/api\/woodpecker\/attempts\/[^/]+\/submission$/.test(r.url())

test('creates a small set, completes a cycle and sees the recap', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/woodpecker/new')

  await page.getByTestId('set-name').fill('Set e2e')
  await page.getByTestId('set-count').fill('5')
  let started = page.waitForResponse(isNext)
  await page.getByTestId('set-create').click()

  for (let i = 0; i < 5; i++) {
    const attempt = await (await started).json()
    await expect(page.getByTestId('woodpecker-progress')).toContainText(
      `${i} / 5`
    )
    await expect(page.getByTestId('puzzle-status')).toContainText(
      'Trouve le meilleur coup'
    )

    const submitted = page.waitForResponse(isSubmission)
    if (i === 0) {
      // Solve the first one move by move.
      await solve(page, attempt.puzzle)
    } else {
      // Give up on the others: the solution plays, and the attempt counts as failed.
      await page.getByTestId('puzzle-solution').click()
    }
    const result = await (await submitted).json()
    expect(result.status).toBe(i === 0 ? 'solved' : 'failed')
    await expect(page.getByTestId('result-sheet')).toHaveAttribute(
      'data-kind',
      i === 0 ? 'win' : 'miss',
      { timeout: 20_000 }
    )
    await expect(page.getByTestId('result-xp')).toHaveText(
      i === 0 ? '+8 XP' : '+2 XP'
    )

    if (i < 4) {
      started = page.waitForResponse(isNext)
      await page.getByTestId('woodpecker-next').click()
    }
  }

  const recap = page.getByTestId('cycle-recap')
  await expect(recap).toContainText('Cycle 1 terminé')
  await expect(recap).toContainText('Précision : 20 %')

  // The set page shows the completed cycle and the next one, shorter.
  await page.goto(page.url().replace(/\/play$/, ''))
  const table = page.getByTestId('cycle-table')
  await expect(table).toContainText('Terminé')
  await expect(table).toContainText('En cours')
})
