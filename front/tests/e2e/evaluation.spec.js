import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'

/**
 * The fixtures' positions with White to move (api/src/DataFixtures/Evaluation): their category
 * and plan, by FEN.
 */
const WHITE_POSITIONS = {
  'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1': {
    category: 0,
    plan: 'center_space'
  },
  '1K1k4/1P6/8/8/8/8/r7/2R5 w - - 0 1': { category: 2, plan: 'simplify' },
  '8/8/8/4k3/8/4K3/4P3/8 w - - 0 1': { category: 0, plan: 'active_defense' }
}

/** @param {{data: {position: {fen: string}}}} item */
function known(item) {
  const position = WHITE_POSITIONS[item.data.position.fen]
  expect(position, `unknown position ${item.data.position.fen}`).toBeTruthy()
  return position
}

test('position evaluation: exact with the plan, one notch off, a miss, no position left, recap and results', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/evaluation')
  const card = page.getByTestId('evaluation-settings')
  await expect(card).toBeVisible()
  await expect(card.getByTestId('evaluation-results')).toHaveText(
    'Aucune position jouée.'
  )

  // 1, 2, 5 or 10 minutes, 2 first; White to move (three positions in the fixtures).
  const durations = card.getByTestId('run-duration-choice')
  await expect(durations.getByRole('button')).toHaveText([
    '1 min',
    '2 min',
    '5 min',
    '10 min'
  ])
  await expect(
    durations.getByRole('button', { name: '2 min', exact: true })
  ).toHaveAttribute('aria-pressed', 'true')
  await card
    .getByTestId('evaluation-side')
    .getByRole('button', { name: /^Blancs/ })
    .click()

  let served = page.waitForResponse(isRunNext)
  await card.getByTestId('run-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+$/)
  let item = (await (await served).json()).item
  expect(item.type).toBe('evaluation_position')
  expect(item.data).toMatchObject({ index: 1, askPlan: true })
  // No time per position, and nothing of the answer is sent with the position.
  expect(item.data).not.toHaveProperty('seconds')
  expect(item.data).not.toHaveProperty('evalCp')
  expect(item.data).not.toHaveProperty('ideas')

  // First position: exact, right plan: 15 + 5 XP (no bonus for speed).
  const player = page.getByTestId('evaluation-player')
  await expect(player.getByTestId('evaluation-turn')).toHaveText(
    'Trait aux Blancs'
  )
  let position = known(item)
  await expect(player.getByTestId('evaluation-validate')).toBeDisabled()
  await player.getByTestId(`evaluation-choice-${position.category}`).click()
  await player.getByTestId(`evaluation-plan-${position.plan}`).click()
  await player.getByTestId('evaluation-validate').click()
  await expect(page.getByTestId('result-sheet')).toHaveAttribute(
    'data-kind',
    'win'
  )
  await expect(page.getByTestId('result-xp')).toHaveText('+20 XP')
  await expect(player.getByTestId('evaluation-verdict')).toHaveText('Bien vu !')
  await expect(player.getByTestId('evaluation-plan-result')).toContainText(
    '✓ bien trouvé (+5 XP)'
  )
  await expect(
    player.getByTestId('evaluation-ideas').locator('li')
  ).not.toHaveCount(0)
  await expect(page.getByTestId('run-progress')).toContainText('Position 1 ·')

  // Second position: one notch off, no plan: "Presque !".
  served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-next').click()
  item = (await (await served).json()).item
  expect(item.data.index).toBe(2)
  position = known(item)
  await player.getByTestId(`evaluation-choice-${position.category - 1}`).click()
  await player.getByTestId('evaluation-validate').click()
  await expect(page.getByTestId('result-sheet')).toHaveAttribute(
    'data-kind',
    'miss'
  )
  await expect(page.getByTestId('result-title')).toHaveText('Presque !')
  await expect(page.getByTestId('result-xp')).toHaveText('+6 XP')
  await expect(player.getByTestId('evaluation-plan-result')).toContainText(
    'aucun plan choisi'
  )

  // Third position: two notches off.
  served = page.waitForResponse(isRunNext)
  await page.getByTestId('run-next').click()
  item = (await (await served).json()).item
  position = known(item)
  await player
    .getByTestId(`evaluation-choice-${position.category === 2 ? 0 : 2}`)
    .click()
  await player.getByTestId('evaluation-validate').click()
  await expect(page.getByTestId('result-title')).toHaveText('Pas cette fois')
  await expect(page.getByTestId('result-xp')).toHaveText('+1 XP')
  await expect(page.getByTestId('run-end')).toBeHidden()
  // No fourth position with White to move: the run closes before its time.
  await page.getByTestId('run-next').click()

  const end = page.getByTestId('run-end')
  await expect(end).toBeVisible()
  // 1 exact out of 3: a failed lesson; a position has nothing to replay, no mistakes to correct.
  const result = end.getByTestId('run-result')
  await expect(result).toHaveAttribute('data-kind', 'fail')
  await expect(result.getByTestId('run-result-score')).toHaveText('33')
  await expect(end.getByTestId('run-end-fix')).toHaveCount(0)
  await expect(end.getByTestId('run-end-close')).toHaveText('Continuer')
  await end.getByTestId('run-end-close').click()

  const recap = page.getByTestId('run-recap')
  await expect(recap).toContainText(
    'Plus aucune position pour ces réglages : séance close.'
  )
  await expect(recap).toContainText(
    'Justes : 1 · à un cran : 1 · ratées : 1 · temps écoulé : 0.'
  )
  await expect(recap).toContainText('Plans trouvés : 1.')
  await expect(page.getByTestId('run-back')).toHaveText('Retour à l’évaluation')
  await page.getByTestId('run-back').click()
  await expect(page).toHaveURL(/#\/evaluation$/)
  await expect(
    page.getByTestId('evaluation-settings').getByTestId('evaluation-results')
  ).toHaveText('3 positions · 1 exacte (33 %) · 1 à un cran · 1 plan trouvé')
})

test('position evaluation: the session builder card is available', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/session/new')
  await page.getByTestId('module-card-evaluation').click()
  const settings = page.getByTestId('module-settings')
  await expect(settings.getByTestId('field-couleur')).toBeVisible()
  // Its length: one of the API's, 2 minutes first.
  await expect(settings.getByTestId('duration-2')).toHaveAttribute(
    'aria-pressed',
    'true'
  )
  await expect(settings.getByTestId('duration-1')).toBeVisible()
  await expect(settings.getByTestId('duration-10')).toBeVisible()
  await expect(page.getByTestId('module-issue')).toHaveCount(0)
  await settings.getByTestId('module-save').click()
  await expect(settings).toBeHidden()
  await expect(page.getByTestId('program-item-issue')).toHaveCount(0)
})
