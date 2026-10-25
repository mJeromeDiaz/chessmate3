import { expect, test } from '@playwright/test'
import { Chess } from 'chess.js'
import { fenAfter, playMove, signIn, solve } from './helpers.js'

/**
 * The défis, `/defis` (docs/DEFIS.md): the page (its chapters, the advised step, no opening
 * chapter without a repertoire), a pass of a tier (no clock, its zones, a miss goes on, "Terminer"
 * ends it on its verdict), a boss's lives. A whole pass, the bosses' rewards, the opening chapters
 * and their crystals paid once are PHPUnit's (tests/Functional/Challenge/ChallengePathTest.php):
 * the sample puzzles hold too few of each theme.
 */

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())

/**
 * A legal move of the side to move that is neither `expected` nor a mate (a mistake).
 *
 * @param {string} fen
 * @param {string} expected UCI
 */
function wrongMove(fen, expected) {
  const move = new Chess(fen).moves({ verbose: true }).find(m => {
    if (m.from + m.to === expected.slice(0, 4)) return false
    const probe = new Chess(fen)
    probe.move(m)
    return !probe.isCheckmate()
  })
  return /** @type {import('chess.js').Move} */ (move).lan
}

/**
 * Misses the puzzle on screen: its first move to find played wrong.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{fen: string, moves: string[]}} puzzle
 */
async function miss(page, puzzle) {
  await expect(page.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    fenAfter(puzzle, 1)
  )
  const submitted = page.waitForResponse(isRunSubmission)
  await playMove(page, wrongMove(fenAfter(puzzle, 1), puzzle.moves[1]))
  return (await (await submitted).json()).result
}

test('the page: three chapters, the advised step, no opening chapter without a repertoire, /parcours sends there', async ({
  page,
  context
}) => {
  await signIn(context, {
    mindMapCapacity: 'challenges',
    mindMapSteps: ['mates-1']
  })
  await page.goto('/#/session')
  await page.getByTestId('plans-challenges').click()
  await expect(page).toHaveURL(/#\/defis$/)

  for (const key of ['mates', 'endgames', 'tactics'])
    await expect(page.getByTestId(`challenge-path-${key}`)).toBeVisible()
  await expect(page.getByTestId('challenge-path-mates')).toContainText(
    'Du mat en 1 au mat en 5.'
  )
  await expect(page.getByTestId('challenge-path-totals')).toContainText(
    '1 / 15 étapes maîtrisées · 0 / 3 boss battus'
  )
  await expect(page.getByTestId('challenge-path-no-opening')).toBeVisible()

  // Mate in 1 mastered (seeded): the advised step is mate in 2.
  await expect(page.getByTestId('path-step-mates-1')).toHaveAttribute(
    'data-state',
    'mastered'
  )
  const advised = page.getByTestId('path-step-mates-2')
  await expect(advised).toHaveClass(/path-card__step--next/)
  await advised.click()
  const detail = page.getByTestId('step-detail')
  await expect(detail).toHaveAttribute('data-step', 'mates-2')
  await expect(detail.getByTestId('step-detail-status')).toHaveText(
    'À commencer'
  )
  await expect(detail.getByTestId('step-passes')).toContainText('0 / 10')
  await expect(detail.getByTestId('step-demo')).toHaveCount(0)

  // A boss: its rule and its crystals.
  await page.getByTestId('path-step-tactics-boss').click()
  await expect(detail).toHaveAttribute('data-kind', 'boss')
  await expect(detail.getByTestId('boss-name')).toHaveText('Le Filou')
  await expect(detail).toContainText('Fourchette, Clouage, Enfilade')
  await expect(detail).toContainText('150')

  // The former parcours' page.
  await page.goto('/#/parcours')
  await expect(page).toHaveURL(/#\/defis$/)
  await expect(page.getByTestId('challenge-path')).toBeVisible()
})

test('a pass of a tier: no clock, its zones, a miss goes on, "Terminer" ends it on its verdict', async ({
  page,
  context
}) => {
  test.setTimeout(90_000)
  await signIn(context)
  await page.goto('/#/defis')
  await page.getByTestId('path-step-mates-1').click()

  let served = page.waitForResponse(isRunNext)
  await page.getByTestId('step-detail').getByTestId('step-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+\?mindMap=1/)
  let item = (await (await served).json()).item
  expect(item.type).toBe('parcours_puzzle')
  expect(item.data).toMatchObject({
    tier: 0,
    position: 0,
    count: 10,
    lives: null
  })
  await expect(page.getByTestId('run-progress')).toHaveText('Puzzle 1 sur 10')
  await expect(page.getByTestId('parcours-zone')).toHaveText('Sous ton niveau')
  await expect(page.getByTestId('run-timer')).toHaveCount(0)
  await expect(page.getByTestId('challenge-lives')).toHaveCount(0)

  // Solved: the next one comes by itself.
  served = page.waitForResponse(isRunNext)
  await solve(page, item.data.puzzle)
  item = (await (await served).json()).item
  await expect(page.getByTestId('run-progress')).toHaveText('Puzzle 2 sur 10')

  // Missed: the solution is shown, the pass goes on with "Suivant".
  expect((await miss(page, item.data.puzzle)).success).toBe(false)
  await expect(page.getByTestId('result-sheet')).toHaveAttribute(
    'data-kind',
    'miss'
  )
  served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-next').click()
  await served
  await expect(page.getByTestId('run-progress')).toHaveText('Puzzle 3 sur 10')

  // "Terminer": too few puzzles, the pass does not count.
  await page.getByTestId('run-stop').click()
  await page.getByRole('button', { name: 'OK' }).click()
  const outcome = page.getByTestId('mind-outcome')
  await expect(outcome.locator('[data-outcome]')).toHaveAttribute(
    'data-outcome',
    'failed'
  )
  await expect(outcome.getByTestId('mind-outcome-passes')).toContainText('0')
  await outcome.getByTestId('mind-outcome-back').click()
  await expect(page).toHaveURL(/#\/defis$/)
})

test('a boss: its lives, above the rating, without help; a miss costs a life', async ({
  page,
  context
}) => {
  test.setTimeout(90_000)
  await signIn(context)
  await page.goto('/#/defis')
  await page.getByTestId('path-step-mates-boss').click()

  const served = page.waitForResponse(isRunNext)
  await page.getByTestId('step-detail').getByTestId('boss-fight').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+\?.*boss=defi-mates/)
  const item = (await (await served).json()).item
  expect(item.data).toMatchObject({
    tier: null,
    themes: ['mateIn1'],
    count: 10,
    lives: 3,
    failures: 0
  })
  await expect(page.getByTestId('run-progress')).toHaveText('Puzzle 1 sur 10')
  await expect(page.getByTestId('challenge-lives')).toHaveAttribute(
    'data-lives',
    '3'
  )
  await expect(page.getByTestId('parcours-zone')).toHaveText(
    'Au-dessus de ton niveau'
  )

  const result = await miss(page, item.data.puzzle)
  expect(result.data).toMatchObject({ failures: 1, lives: 3 })
  await expect(page.getByTestId('challenge-lives')).toHaveAttribute(
    'data-lives',
    '2'
  )
})
