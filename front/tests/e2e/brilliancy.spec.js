import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'

/**
 * The sample puzzles indexed by the fixtures (api/src/DataFixtures/Brilliancy): the move shown,
 * by its UCI, and what it is. GlnnF and Glo3V show their winning sacrifice, Glo2A and RxOnz the
 * opponent's move they punish.
 */
const KINDS = {
  h8h4: 'brilliant',
  e5e1: 'brilliant',
  f3e5: 'blunder',
  h7g5: 'blunder'
}

/** @param {{data: {position: {move: {uci: string}}}}} item */
function kindOf(item) {
  const kind = KINDS[item.data.position.move.uci]
  expect(kind, `unknown move ${item.data.position.move.uci}`).toBeTruthy()
  return kind
}

/** @param {string} kind */
const other = kind => (kind === 'brilliant' ? 'blunder' : 'brilliant')

test('brillant ou blunder: buttons and keys, the badge and the line, no position left, recap and results', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/brillant-ou-blunder')
  const card = page.getByTestId('brilliancy-settings')
  await expect(card).toBeVisible()
  await expect(card.getByTestId('brilliancy-results')).toHaveText(
    'Aucun coup jugé pour l’instant.'
  )

  // 1, 2, 5 or 10 minutes, 2 first; no other setting.
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

  let served = page.waitForResponse(isRunNext)
  await card.getByTestId('run-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+$/)
  let item = (await (await served).json()).item
  expect(item.type).toBe('brilliancy_position')
  expect(item.data).toMatchObject({ index: 1, right: 0, wrong: 0 })
  // Nothing of the answer is sent with the position.
  expect(item.data).not.toHaveProperty('kind')
  expect(item.data).not.toHaveProperty('line')
  expect(item.data).not.toHaveProperty('puzzle')

  const player = page.getByTestId('brilliancy-player')
  const board = player.getByTestId('chess-board')
  /** What the player answered right, by kind: the recap's and the results' counts. */
  const tally = {
    brilliant: { played: 0, right: 0 },
    blunder: { played: 0, right: 0 }
  }

  /**
   * Waits for the position's move to be played on the board, then answers.
   *
   * @param {any} current the item
   * @param {boolean} right
   * @param {'click'|'letter'|'number'} how
   */
  async function answer(current, right, how) {
    const kind = kindOf(current)
    const choice = right ? kind : other(kind)
    await expect(page.getByTestId('run-progress')).toContainText(
      `Coup ${current.data.index} ·`
    )
    await expect(player.getByTestId('brilliancy-move')).toContainText(
      current.data.position.move.san
    )
    // The move is played on the position shown first.
    await expect(board).not.toHaveAttribute(
      'data-fen',
      current.data.position.fen
    )
    if (how === 'click')
      await player.getByTestId(`brilliancy-choice-${choice}`).click()
    else if (how === 'letter')
      await page.keyboard.press(choice === 'brilliant' ? 'b' : 'n')
    else await page.keyboard.press(choice === 'brilliant' ? '1' : '2')
    tally[kind].played++
    if (right) tally[kind].right++
    await expect(page.getByTestId('result-sheet')).toHaveAttribute(
      'data-kind',
      right ? 'win' : 'miss'
    )
    await expect(page.getByTestId('result-xp')).toHaveText(
      right ? '+4 XP' : '+1 XP'
    )
    await expect(player.getByTestId('brilliancy-verdict')).toHaveText(
      kind === 'brilliant' ? 'C’était un coup brillant' : 'C’était un blunder'
    )
    // The line from the move shown: back on its first move, the badge on its arrival square.
    const line = player.getByTestId('brilliancy-line').getByRole('button')
    expect(await line.count()).toBeGreaterThan(1)
    await line.first().click()
    const to = current.data.position.move.uci.slice(2, 4)
    await expect(board.getByTestId(`glyph-${to}`)).toHaveText(
      kind === 'brilliant' ? '!!' : '??'
    )
    await player.getByTestId('brilliancy-next').click()
    await expect(board.getByTestId(`glyph-${to}`)).toHaveCount(0)
    await expect(player.getByTestId('brilliancy-source')).toBeVisible()
  }

  /** « Position suivante », the next item. */
  async function next() {
    const response = page.waitForResponse(isRunNext)
    await page.getByTestId('run-next').click()
    return (await (await response).json()).item
  }

  // Right with a button, wrong with a letter, right with a number, wrong with a button.
  await answer(item, true, 'click')
  await expect(page.getByTestId('run-progress')).toContainText(
    'justes : 1 · faux : 0'
  )
  item = await next()
  expect(item.data.index).toBe(2)
  await answer(item, false, 'letter')
  item = await next()
  await answer(item, true, 'number')
  item = await next()
  await answer(item, false, 'click')
  await expect(page.getByTestId('run-progress')).toContainText(
    'justes : 2 · faux : 2'
  )
  await expect(page.getByTestId('run-end')).toBeHidden()

  // The four samples played: none left, the run closes before its time.
  await page.getByTestId('run-next').click()
  const end = page.getByTestId('run-end')
  await expect(end).toBeVisible()
  // A move to judge has no line to replay as an exercise: no mistakes to correct.
  await expect(
    end.getByTestId('run-result').getByTestId('run-result-score')
  ).toHaveText('50')
  await expect(end.getByTestId('run-end-fix')).toHaveCount(0)
  await end.getByTestId('run-end-close').click()

  const recap = page.getByTestId('run-recap')
  await expect(recap).toContainText('Plus aucun coup à juger : séance close.')
  await expect(recap).toContainText('Justes : 2 · faux : 2.')
  await expect(recap).toContainText(
    `Brillants : ${tally.brilliant.right} sur 2 · blunders : ${tally.blunder.right} sur 2.`
  )
  await expect(page.getByTestId('run-back')).toHaveText(
    'Retour à Brillant ou Blunder'
  )
  await page.getByTestId('run-back').click()
  await expect(page).toHaveURL(/#\/brillant-ou-blunder$/)
  /** @param {{right: number}} k */
  const rate = k => `${Math.floor((k.right / 2) * 100)} %`
  await expect(
    page.getByTestId('brilliancy-settings').getByTestId('brilliancy-results')
  ).toHaveText(
    `4 coups jugés · 2 justes (50 %) · brillants : ${rate(tally.brilliant)} · blunders : ${rate(tally.blunder)}`
  )
})

test('brillant ou blunder: its session builder card', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/session/new')
  await page.getByTestId('module-card-brillant').click()
  const settings = page.getByTestId('module-settings')
  // Its length: one of the API's, 2 minutes first; no other setting.
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
