import { expect, test } from '@playwright/test'
import { Chess } from 'chess.js'
import { normalizeFen } from '../../src/utils/chess/normalizeFen.js'
import { closeRunEnd, playMove, signIn } from './helpers.js'

/** White: the trunk 1.e4, then 1...e5 (2.Nf3 3.Bc4) and 1...c5 (2.Nf3 3.d4): 3 segments. */
const LINES = [
  ['e4', 'e5', 'Nf3', 'Nc6', 'Bc4'],
  ['e4', 'c5', 'Nf3', 'd6', 'd4']
]
const PGN = '1. e4 e5 (1... c5 2. Nf3 d6 3. d4) 2. Nf3 Nc6 3. Bc4 *'

/** Normalized FEN of each position where White is to move => the prepared move (UCI). */
const PREPARED = (() => {
  const prepared = new Map()
  for (const line of LINES) {
    const chess = new Chess()
    for (const san of line) {
      const fen = normalizeFen(chess.fen())
      const move = chess.move(san)
      if (move.color === 'w') prepared.set(fen, move.from + move.to)
    }
  }
  return prepared
})()

/**
 * Plays the prepared moves of a segment replayed from the end-of-run review, to its end.
 *
 * @param {import('@playwright/test').Locator} replay
 */
async function replayLine(replay) {
  const page = replay.page()
  const board = replay.getByTestId('chess-board')
  const status = replay.getByTestId('run-end-replay-status')
  let last = ''
  for (;;) {
    let fen = ''
    // The user's turn once the board shows a new position with a prepared move, or the end.
    await expect
      .poll(async () => {
        if ((await status.textContent())?.startsWith('Ligne terminée'))
          return 'done'
        fen = normalizeFen((await board.getAttribute('data-fen')) ?? '')
        return fen !== last && PREPARED.has(fen) ? 'play' : 'wait'
      })
      .not.toBe('wait')
    if ((await status.textContent())?.startsWith('Ligne terminée')) return
    last = fen
    await playMove(page, PREPARED.get(fen), replay)
  }
}

/** @param {import('@playwright/test').Page} page */
async function importItalian(page) {
  await page.goto('/#/repertoire/import')
  await page.getByTestId('import-tab-paste').click()
  await page.getByTestId('import-pgn').fill(PGN)
  await page.getByTestId('import-analyze').click()
  await expect(page.getByTestId('import-preview')).toBeVisible()
  await page.getByTestId('import-name').fill('Italienne e2e')
  await page.getByTestId('import-apply').click()
  await expect(page.getByTestId('editor-name')).toHaveText('Italienne e2e')
}

/**
 * Starts a run from the open test dialog.
 *
 * @param {import('@playwright/test').Page} page
 * @param {number} minutes
 */
async function launch(page, minutes) {
  const dialog = page.getByTestId('test-dialog')
  await dialog
    .getByTestId('run-duration-choice')
    .getByRole('button', { name: 'Autre' })
    .click()
  await dialog.getByTestId('run-duration-custom').fill(String(minutes))
  await dialog.getByTestId('run-start').click()
  await expect(page).toHaveURL(/#\/training\/[^/]+$/)
}

/**
 * Waits for a question, then returns its number, the total and the prepared move.
 *
 * @param {import('@playwright/test').Page} page
 */
async function question(page) {
  const status = page.getByTestId('drill-status')
  await expect(status).toHaveText(/À vous : coup \d+ sur \d+/)
  const [, index, total] = /coup (\d+) sur (\d+)/.exec(await status.innerText())
  // The board's data-fen changes once its animation is over: wait until it shows the question.
  const board = page.getByTestId('chess-board')
  const fen = String(
    await page.getByTestId('drill-player').getAttribute('data-fen')
  )
  await expect(board).toHaveAttribute('data-fen', fen)
  const uci = PREPARED.get(normalizeFen(fen))
  expect(uci).toBeTruthy()
  return { index: Number(index), total: Number(total), uci: String(uci) }
}

/**
 * Plays a whole unit right, or with a mistake on its first question (then the right move).
 *
 * @param {import('@playwright/test').Page} page
 * @param {{mistake?: boolean}} [options]
 * @returns {Promise<string>} the unit's label
 */
async function playUnit(page, { mistake = false } = {}) {
  let q = await question(page)
  expect(q.index).toBe(1)
  const label = await page.getByTestId('drill-label').innerText()
  while (true) {
    if (mistake) {
      await playMove(page, q.uci === 'a2a3' ? 'h2h3' : 'a2a3')
      await expect(page.getByTestId('drill-status')).toContainText(
        'Ce n’est pas le coup préparé'
      )
      mistake = false
    }
    await playMove(page, q.uci)
    if (q.index === q.total) return label
    const next = q.index + 1
    await expect(page.getByTestId('drill-status')).toHaveText(
      new RegExp(`coup ${next} sur`)
    )
    q = await question(page)
  }
}

test('a one-minute test: a failed segment comes back, the end of time cuts a segment', async ({
  page,
  context
}) => {
  test.setTimeout(150_000)
  await signIn(context)
  await importItalian(page)

  await page.goto('/#/repertoire')
  await expect(page.getByTestId('repertoire-due')).toContainText(
    '5 nouvelles sur 5 coups préparés'
  )
  await page.getByTestId('repertoire-test-all').click()
  await launch(page, 1)

  // First unit: the context is shown, not asked; a mistake, the right move imposed.
  const failed = await playUnit(page, { mistake: true })
  await expect(page.getByTestId('drill-failed')).toContainText('reviendra')
  await page.getByTestId('drill-next').click()

  // The two others, then the failed one again.
  const seen = [await playUnit(page), await playUnit(page)]
  expect(seen).not.toContain(failed)
  await expect(page.getByTestId('drill-retry')).toBeVisible()
  expect(await playUnit(page)).toBe(failed)
  await expect(page.getByTestId('drill-score')).toContainText(
    '3 tronçons réussis'
  )

  // A new round: one move right when the unit has several, then the time runs out in it.
  await expect(page.getByTestId('drill-new-round')).toBeVisible()
  const q = await question(page)
  if (q.total > 1) {
    await playMove(page, q.uci)
    await expect(page.getByTestId('drill-status')).toHaveText(/coup 2 sur/)
  }

  // The failed segment played again from the review, from its start position.
  const end = page.getByTestId('run-end')
  await expect(end).toBeVisible({ timeout: 70_000 })
  await end.getByTestId('run-end-missed-item').first().click()
  const replay = end.getByTestId('run-end-replay')
  const status = replay.getByTestId('run-end-replay-status')
  // A wrong move first: the right one is shown, then accepted.
  await expect(status).toHaveText('Joue le coup de ton répertoire.')
  const board = replay.getByTestId('chess-board')
  const at = normalizeFen((await board.getAttribute('data-fen')) ?? '')
  const right = PREPARED.get(at)
  await playMove(page, right === 'a2a3' ? 'h2h3' : 'a2a3', replay)
  await expect(status).toHaveText(
    'Ce n’est pas ton coup : joue celui de la flèche.'
  )
  await replayLine(replay)
  await expect(status).toHaveText('Ligne terminée, 1 erreur.')
  await replay.getByTestId('run-end-replay-back').click()
  await expect(end.getByTestId('run-end-missed-item').first()).toHaveAttribute(
    'data-reviewed',
    'true'
  )

  await closeRunEnd(page)
  await expect(page.getByTestId('run-recap')).toBeVisible()
  await expect(page.getByTestId('run-close-reason')).toHaveText('Temps écoulé.')
  await expect(page.getByTestId('run-items')).toHaveText('4')
  await expect(page.getByTestId('run-successes')).toHaveText('3')
  await expect(page.getByTestId('run-recap')).toContainText(
    '1 tronçon raté puis réussi'
  )
  const units = page.getByTestId('run-unit')
  await expect(units).toHaveCount(5)
  await expect(units.first()).toContainText('Raté')
  await expect(units.nth(3)).toContainText('2ᵉ présentation')
  // Cut by the time, answered or not: interrupted (no mistake in it).
  await expect(units.last()).toContainText('Interrompu')
  await page.getByTestId('run-back').click()
  await expect(page).toHaveURL(/#\/repertoire$/)
})

test('"Tester cette ligne" from the editor, then the statistics and a segment history', async ({
  page,
  context
}) => {
  await signIn(context)
  await importItalian(page)

  // The position after 1.e4 c5: only the Sicilian segment is in the scope.
  await page.locator('[data-testid="tree-move"]', { hasText: 'c5' }).click()
  await page.getByTestId('editor-test').click()
  await page.getByTestId('editor-test-line').click()
  await launch(page, 5)
  await expect(page.getByTestId('drill-label')).toHaveText(
    'Sicilian Defense · 1…c5'
  )
  await expect(page.getByTestId('drill-deviation')).toContainText('1…c5')
  await expect(page.getByTestId('drill-moves')).toContainText('1.e4')
  await playUnit(page)
  await expect(page.getByTestId('drill-new-round')).toBeVisible()
  await page.getByTestId('run-stop').click()
  await page.getByRole('button', { name: 'OK' }).click()
  await closeRunEnd(page)
  await expect(page.getByTestId('run-recap')).toBeVisible()
  await expect(page.getByTestId('run-items')).toHaveText('1')

  // Statistics: one test, the three segments, the Sicilian's history.
  await page.goto('/#/repertoire')
  await page.getByTestId('repertoire-item').first().click()
  await page.getByTestId('editor-stats').click()
  await expect(page.getByTestId('tests-total')).toHaveText('1')
  const table = page.getByTestId('segments-table')
  await expect(table.locator('tbody tr')).toHaveCount(3)
  await table.locator('tbody tr', { hasText: '1…c5' }).click()
  // Newest first: the new round cut by "Terminer", then the test.
  const rows = page.getByTestId('segment-history-row')
  await expect(rows).toHaveCount(2)
  await expect(rows.first()).toContainText('Interrompu')
  await expect(rows.last()).toContainText('Réussi')
})
