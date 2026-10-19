import { expect, test } from '@playwright/test'
import { closeRunEnd, signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())
const isRecall = r =>
  r.url().endsWith('/api/blindfold/recall') && r.request().method() === 'GET'

/**
 * The squares of a FEN: square => FEN piece letter.
 *
 * @param {string} fen
 * @returns {Record<string, string>}
 */
function squaresOf(fen) {
  /** @type {Record<string, string>} */
  const squares = {}
  fen
    .split(' ')[0]
    .split('/')
    .forEach((rank, index) => {
      let file = 0
      for (const char of rank) {
        if (/\d/.test(char)) file += Number(char)
        else squares[String.fromCharCode(97 + file++) + (8 - index)] = char
      }
    })
  return squares
}

/**
 * Skips the memorizing, waits for the empty board, then places `squares` with the palette.
 *
 * @param {import('@playwright/test').Locator} player
 * @param {Record<string, string>} squares
 */
async function rebuild(player, squares) {
  await expect(player).toHaveAttribute('data-phase', 'show')
  await player.getByTestId('recall-memorized').click()
  await expect(player.getByTestId('recall-veil')).toBeVisible()
  await expect(player).toHaveAttribute('data-phase', 'build')
  for (const [square, piece] of Object.entries(squares)) {
    await player.getByTestId(`recall-piece-${piece}`).click()
    await player.getByTestId(`square-${square}`).click()
  }
  await expect(player).toHaveAttribute(
    'data-placed',
    String(Object.keys(squares).length)
  )
}

/**
 * Sends the rebuild and returns the server's correction.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} player
 */
async function send(page, player) {
  const submitted = page.waitForResponse(isRunSubmission)
  await player.getByTestId('recall-done').click()
  const result = (await (await submitted).json()).result
  await expect(player).toHaveAttribute('data-phase', 'complete')
  await expect(player.getByTestId('recall-result')).toBeVisible()
  return result
}

test('position recall: level 1 without a clock, three exact rebuilds open level 2', async ({
  page,
  context
}) => {
  await signIn(context)
  const loaded = page.waitForResponse(isRecall)
  await page.goto('/#/reconstitution')
  await loaded

  // Level 1 open and chosen, level 2 locked.
  const map = page.getByTestId('recall-map')
  await expect(map.getByTestId('recall-level-1')).toHaveAttribute(
    'data-state',
    'open'
  )
  await expect(map.getByTestId('recall-level-1')).toContainText('0/3')
  await expect(map.getByTestId('recall-level-2')).toBeDisabled()
  await expect(map.getByTestId('recall-level-2')).toHaveAttribute(
    'data-state',
    'locked'
  )
  const selected = page.getByTestId('recall-selected')
  await expect(selected.getByTestId('recall-selected-title')).toHaveText(
    'Les trois repères'
  )

  // Without a clock: the free run of the level.
  let next = page.waitForResponse(isRunNext)
  await selected.getByTestId('recall-play').getByTestId('run-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+$/)
  let item = (await (await next).json()).item
  expect(item.type).toBe('blindfold_recall')
  expect([item.data.level, item.data.mode]).toEqual([1, 'free'])
  const player = page.getByTestId('recall-player')
  await expect(player.getByTestId('recall-info').first()).toHaveText('Niveau 1')
  await expect(page.getByTestId('run-progress')).toHaveText(
    '0 positions · 0 exactes'
  )
  // The position shown, then hidden.
  await expect(player.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    item.data.fen
  )

  // Exact.
  await rebuild(player, squaresOf(item.data.fen))
  let result = await send(page, player)
  expect([result.success, result.data.status]).toEqual([true, 'exact'])
  await expect(player.getByTestId('recall-result')).toContainText(
    'Position exacte !'
  )

  // Only the kings: the pawn forgotten.
  next = page.waitForResponse(isRunNext)
  await player.getByTestId('run-next').click()
  item = (await (await next).json()).item
  const kings = Object.fromEntries(
    Object.entries(squaresOf(item.data.fen)).filter(([, p]) =>
      ['K', 'k'].includes(p)
    )
  )
  await rebuild(player, kings)
  result = await send(page, player)
  expect([
    result.data.status,
    result.data.correct,
    result.data.missing
  ]).toEqual(['missed', 2, 1])
  await expect(player.getByTestId('recall-result')).toContainText(
    '2/3 pièces bien placées'
  )
  // The correction on the position (the pawn circled), then on the rebuild.
  const board = player.getByTestId('chess-board')
  await expect(board).toHaveAttribute(
    'data-fen',
    `${item.data.fen.split(' ')[0]} w - - 0 1`
  )
  await player
    .getByTestId('recall-view')
    .getByRole('button', { name: 'Ma reconstitution' })
    .click()
  // The rebuild: the kings alone.
  await expect(board).not.toHaveAttribute(
    'data-fen',
    `${item.data.fen.split(' ')[0]} w - - 0 1`
  )
  await expect(board).not.toHaveAttribute('data-fen', /P/)

  // Two more exact ones: the third opens level 2.
  for (let i = 0; i < 2; i++) {
    next = page.waitForResponse(isRunNext)
    await player.getByTestId('run-next').click()
    item = (await (await next).json()).item
    await rebuild(player, squaresOf(item.data.fen))
    result = await send(page, player)
    expect(result.data.status).toBe('exact')
  }
  expect(result.data.unlockedLevel).toBe(2)
  await expect(player.getByTestId('recall-result')).toContainText(
    'niveau 2 débloqué !'
  )
  await expect(page.getByTestId('run-progress')).toHaveText(
    '4 positions · 3 exactes'
  )

  // "Niveau 2 →": this run ends, a free run of level 2 starts.
  const firstRun = page.url()
  next = page.waitForResponse(isRunNext)
  await player.getByTestId('recall-next-level').click()
  await expect(page).not.toHaveURL(firstRun)
  item = (await (await next).json()).item
  expect([item.data.level, item.data.mode]).toEqual([2, 'free'])
  expect(Object.keys(squaresOf(item.data.fen))).toHaveLength(4)

  // "Terminer": the position on screen is not counted.
  await page.getByTestId('run-stop').click()
  await expect(
    page.getByRole('dialog').filter({ hasText: 'Terminer la séance ?' })
  ).toContainText('La position à l’écran ne sera pas comptée.')
  await page.getByRole('button', { name: 'OK' }).click()

  // The map: level 1 passed, level 2 open and chosen.
  const reloaded = page.waitForResponse(isRecall)
  await page.goto('/#/reconstitution')
  await reloaded
  await expect(map.getByTestId('recall-level-1')).toHaveAttribute(
    'data-state',
    'passed'
  )
  await expect(map.getByTestId('recall-level-2')).toHaveAttribute(
    'data-state',
    'open'
  )
  await expect(map.getByTestId('recall-level-2')).toHaveAttribute(
    'aria-pressed',
    'true'
  )
  await expect(selected.getByTestId('recall-selected-progress')).toContainText(
    'encore 3 exacte(s) pour ouvrir le niveau 3'
  )
})

test('position recall: a timed run, its recap, and the Session Builder card', async ({
  page,
  context
}) => {
  await signIn(context)
  const loaded = page.waitForResponse(isRecall)
  await page.goto('/#/reconstitution')
  await loaded

  const timed = page.getByTestId('recall-timed')
  await timed
    .getByTestId('run-duration-choice')
    .getByRole('button', { name: '5 min', exact: true })
    .click()
  const next = page.waitForResponse(isRunNext)
  await timed.getByTestId('run-start').click()
  const item = (await (await next).json()).item
  expect(item.data.mode).toBe('timed')
  const player = page.getByTestId('recall-player')
  await rebuild(player, squaresOf(item.data.fen))
  expect((await send(page, player)).data.status).toBe('exact')

  // Stopped on the correction: it stays on screen until "Voir le résultat".
  await page.getByTestId('run-stop').click()
  await page.getByRole('button', { name: 'OK' }).click()
  await expect(player.getByTestId('run-next')).toContainText('Voir le résultat')
  await player.getByTestId('run-next').click()
  await closeRunEnd(page)
  const recap = page.getByTestId('run-recap')
  await expect(recap).toContainText('Niveau 1 · Les trois repères.')
  await expect(recap).toContainText(
    'Exactes : 1 · proches : 0 · ratées : 0 · précision : 100 %.'
  )

  // The Session Builder: the card plays "my level" by default.
  await page.goto('/#/session/new')
  const card = page.getByTestId('module-card-reconstitution')
  await expect(card).toContainText('Reconstitution')
  await card.click()
  const settings = page.getByTestId('module-settings')
  await expect(settings).toContainText('Mon niveau')
  await settings.getByTestId('module-save').click()
  await expect(settings).toBeHidden()
  await expect(page.getByTestId('program-item-issue')).toHaveCount(0)
})
