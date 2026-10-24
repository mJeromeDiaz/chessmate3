import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'
import {
  ENGINE_TIMEOUT,
  isRunNext,
  isRunSubmission,
  noMaia,
  tablebaseDown,
  tablebaseOfPawnRace
} from './endgames.js'

/**
 * The endgames path, `/finales` (docs/ENDGAMES.md, § 10): the page, its advised step, a lesson's
 * detail and demonstration, the Roi des cendres' fight (its clock, its lives, its distractions).
 * Its passes, bosses, rewards and the Roi's clock out are covered by PHPUnit
 * (tests/Functional/MindMap/EndgamePathTest.php), the page's rules by Vitest
 * (tests/unit/endgame-path.test.js, tests/unit/ashes.test.js).
 */

/** The path's mini-parcours and their lessons, in the page's order (App\MindMap\Path\Endgames). */
const PATHS = {
  mates: ['kqk', 'krk', 'kbbk', 'kbnk'],
  'pawns-basics': [
    'square-promote',
    'square-stop',
    'opposition',
    'key-squares'
  ],
  'pawns-technique': [
    'rook-pawn',
    'pawn-defence',
    'triangulation',
    'pawn-race'
  ],
  queens: ['queen-vs-pawn', 'queen-vs-bishop-pawn', 'queen-vs-rook'],
  rooks: ['king-cut-off', 'lucena', 'philidor', 'rook-vs-pawn', 'vancura'],
  bishops: [
    'wrong-rook-pawn',
    'bishop-vs-pawn',
    'opposite-bishops',
    'bishop-pawn'
  ],
  knights: ['knight-vs-pawn', 'knight-vs-rook-pawn', 'knight-pawn']
}

test('the path: 7 mini-parcours and the Roi des cendres, the advised step, a lesson and its demonstration', async ({
  page,
  context
}) => {
  await signIn(context, {
    mindMapCapacity: 'endgames',
    mindMapSteps: [...PATHS.mates, 'mates-boss']
  })
  await noMaia(page)
  await tablebaseOfPawnRace(page)
  await page.goto('/#/finales')

  await expect(page.getByTestId('endgame-path')).toBeVisible()
  for (const key of Object.keys(PATHS))
    await expect(page.getByTestId(`endgame-path-${key}`)).toBeVisible()
  await expect(page.getByTestId('path-step-ashes-boss')).toBeVisible()
  await expect(page.getByTestId('endgame-path-totals')).toContainText(
    '4 / 27 leçons maîtrisées'
  )

  // The mates mastered and their boss beaten (seeded): the advised step is the next mini-parcours'
  // first lesson.
  for (const key of PATHS.mates)
    await expect(page.getByTestId(`path-step-${key}`)).toHaveAttribute(
      'data-state',
      'mastered'
    )
  await expect(page.getByTestId('path-step-mates-boss')).toHaveAttribute(
    'data-state',
    'beaten'
  )
  const advised = page.getByTestId('path-step-square-promote')
  await expect(advised).toHaveAttribute('data-state', 'new')
  await expect(advised).toHaveClass(/path-card__step--next/)

  // Its detail, beside the path, then its demonstration.
  await advised.click()
  const detail = page.getByTestId('step-detail')
  await expect(detail.getByTestId('step-detail-status')).toHaveText(
    'À commencer'
  )
  await expect(detail.getByTestId('step-passes')).toContainText('0 / 10')
  await expect(detail.getByTestId('step-start')).toBeVisible()
  await detail.getByTestId('step-demo').click()
  const demo = page.getByTestId('endgame-demo')
  await expect(demo.getByTestId('endgame-demo-status')).toHaveAttribute(
    'data-status',
    'done',
    { timeout: 15_000 }
  )
  await expect(demo.locator('.endgame-demo__san').last()).toHaveText('a8=D')
  await demo.getByTestId('endgame-demo-close').click()

  // The free page is a click away.
  await page.getByTestId('endgame-path-free').click()
  await expect(page).toHaveURL(/#\/finales\/libre$/)
  await expect(page.getByTestId('endgame-lessons')).toBeVisible()
})

test('a mini-parcours whose lessons are all known: its boss is the advised step', async ({
  page,
  context
}) => {
  await signIn(context, {
    mindMapCapacity: 'endgames',
    mindMapSteps: PATHS.mates
  })
  await page.goto('/#/finales')
  await expect(page.getByTestId('endgame-path-totals')).toContainText(
    '4 / 27 leçons maîtrisées'
  )
  await expect(page.getByTestId('path-step-square-promote')).not.toHaveClass(
    /path-card__step--next/
  )
  const boss = page.getByTestId('path-step-mates-boss')
  await expect(boss).toHaveClass(/path-card__step--next/)
  await boss.click()
  const detail = page.getByTestId('step-detail')
  await expect(detail.getByTestId('step-detail-status')).toHaveText(
    'Prêt au combat'
  )
  await expect(detail.getByTestId('boss-fight')).toBeVisible()
})

test('the Roi des cendres: 10 positions, 3 lives, a clock; a position given up costs a life', async ({
  page,
  context
}) => {
  test.setTimeout(120_000)
  // Less motion asked: the board stays still (rain without falling, no shaking nor lightning).
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await signIn(context)
  await tablebaseDown(page)
  await page.goto('/#/finales')

  await page.getByTestId('path-step-ashes-boss').click()
  const detail = page.getByTestId('step-detail')
  await expect(detail.getByTestId('boss-name')).toBeVisible()
  const served = page.waitForResponse(isRunNext)
  await detail.getByTestId('boss-fight').click()
  await expect(page).toHaveURL(/#\/training\/[^/?]+\?mindMap=1&boss=[\w-]+$/)
  const item = (await (await served).json()).item
  expect(item.data).toMatchObject({
    index: 1,
    count: 10,
    lives: 3,
    clockSeconds: 90,
    tier: 1,
    opponent: { perfect: true },
    mode: 'review'
  })
  expect(item.data.deadline).toBeTruthy()

  await expect(page.getByTestId('run-progress')).toHaveText('Position 1 sur 10')
  await expect(page.getByTestId('endgame-lives')).toHaveAttribute(
    'data-lives',
    '3'
  )
  const distractions = page.getByTestId('ashes-distractions')
  await expect(distractions).toHaveAttribute('data-tier', '1')
  await expect(distractions).toHaveClass(/ashes--still/)
  await expect(page.getByTestId('ashes-flash')).toHaveCount(0)

  // The clock runs down, from 90 s at most.
  const clock = page.getByTestId('ashes-clock')
  await expect(clock).toBeVisible()
  const left = Number(await clock.getAttribute('data-left'))
  expect(left).toBeLessThanOrEqual(90)
  await expect
    .poll(async () => Number(await clock.getAttribute('data-left')), {
      timeout: 5_000
    })
    .toBeLessThan(left)
  // No help against a boss.
  await expect(page.getByTestId('endgame-hint')).toHaveCount(0)
  await expect(page.getByTestId('endgame-demo-open')).toHaveCount(0)

  // Given up: a life lost, the next position (its own clock).
  const submitted = page.waitForResponse(isRunSubmission, {
    timeout: ENGINE_TIMEOUT
  })
  await page.getByTestId('endgame-pass').click()
  await page
    .getByRole('dialog')
    .getByRole('button', { name: 'Passer' })
    .click()
  const response = await submitted
  expect(response.request().postDataJSON()).toMatchObject({ pass: true })
  const { result } = await response.json()
  expect(result.success).toBe(false)
  expect(result.data).toMatchObject({ status: 'resigned', lives: 2 })
  await expect(page.getByTestId('endgame-lives')).toHaveAttribute(
    'data-lives',
    '2'
  )

  const next = page.waitForResponse(isRunNext)
  await page.getByTestId('run-next').click()
  const second = (await (await next).json()).item
  expect(second.data).toMatchObject({ index: 2, lives: 2, clockSeconds: 82 })
  await expect(page.getByTestId('run-progress')).toHaveText('Position 2 sur 10')
})
