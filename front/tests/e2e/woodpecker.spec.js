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

test('skips a puzzle (failed for the cycle) and replaces another one', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/woodpecker/new')
  await page.getByTestId('set-name').fill('Sur mesure')
  await page.getByTestId('set-count').fill('5')
  let started = page.waitForResponse(isNext)
  await page.getByTestId('set-create').click()
  const first = await (await started).json()
  await expect(page.getByTestId('puzzle-status')).toContainText(
    'Trouve le meilleur coup'
  )

  // Passer: confirmed, the puzzle is failed for this cycle, the next one comes at once.
  await page.getByTestId('puzzle-skip').click()
  const submitted = page.waitForResponse(isSubmission)
  started = page.waitForResponse(isNext)
  await page.getByRole('dialog').getByRole('button', { name: 'Passer' }).click()
  expect((await (await submitted).json()).status).toBe('failed')
  const second = await (await started).json()
  expect(second.puzzle.id).not.toBe(first.puzzle.id)
  await expect(page.getByTestId('result-sheet')).toHaveCount(0)
  await expect(page.getByTestId('woodpecker-progress')).toContainText('1 / 5')

  // Remplacer: another puzzle takes its place, nothing is counted.
  await expect(page.getByTestId('puzzle-status')).toContainText(
    'Trouve le meilleur coup'
  )
  await page.getByTestId('puzzle-replace').click()
  const replaced = page.waitForResponse(r => r.url().endsWith('/replace'))
  started = page.waitForResponse(isNext)
  await page
    .getByRole('dialog')
    .getByRole('button', { name: 'Remplacer' })
    .click()
  const swapped = await (await replaced).json()
  const third = await (await started).json()
  expect(swapped.puzzleId).not.toBe(second.puzzle.id)
  expect(third.puzzle.id).toBe(swapped.puzzleId)
  await expect(page.getByTestId('woodpecker-progress')).toContainText('1 / 5')
})

test('the set page: continue the cycle, how it works, pause and a tailor-made list', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/woodpecker/new')
  await page.getByTestId('set-name').fill('Page du set')
  await page.getByTestId('set-count').fill('5')
  await page.getByTestId('set-create').click()
  await expect(page).toHaveURL(/#\/woodpecker\/[^/]+\/play$/)
  await page.goto(page.url().replace(/\/play$/, ''))

  const card = page.getByTestId('current-cycle')
  await expect(card).toContainText('CYCLE 1 /')
  await expect(page.getByTestId('cycle-progress')).toHaveAttribute(
    'aria-valuenow',
    '0'
  )
  await expect(page.getByTestId('cycle-pace')).toContainText('inclus')

  await page.getByTestId('cycle-explainer').click()
  await expect(page.getByTestId('cycle-explainer')).toContainText(
    'les 5 puzzles du set'
  )

  // Pause from the menu, resume from the card.
  await page.getByTestId('set-menu').click()
  await page.getByTestId('set-pause').click()
  await expect(card).toContainText('En pause')
  await page.getByTestId('set-resume').click()
  await expect(card).toContainText('Cycle en cours')

  // The list of the set: a puzzle swapped for another one at the same place.
  await page.getByTestId('set-puzzles').getByText('Puzzles du set (5)').click()
  const rows = page.getByTestId('set-puzzle')
  await expect(rows).toHaveCount(5)
  const before = await rows.first().textContent()
  await rows.first().getByTestId('set-puzzle-replace').click()
  const replaced = page.waitForResponse(r => r.url().endsWith('/replace'))
  await page
    .getByRole('dialog')
    .getByRole('button', { name: 'Remplacer' })
    .click()
  const fresh = await (await replaced).json()
  expect(fresh.position).toBe(0)
  await expect(rows.first()).toContainText(fresh.puzzleId)
  expect(await rows.first().textContent()).not.toBe(before)

  // Continue the cycle.
  await page.getByTestId('cycle-continue').click()
  await expect(page).toHaveURL(/#\/woodpecker\/[^/]+\/play$/)
})
