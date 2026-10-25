import { expect, test } from '@playwright/test'
import { playMove, signIn } from './helpers.js'

const isChange = r =>
  /\/api\/repertoires\/[^/]+\/(moves|lines|undo)(\/|$)/.test(r.url()) &&
  r.request().method() === 'POST'

/**
 * Plays a move (into the draft, or along the repertoire): nothing is sent.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} uci
 */
async function play(page, uci) {
  const board = page.getByTestId('chess-board')
  const before = await board.getAttribute('data-fen')
  await playMove(page, uci)
  // data-fen changes once the board is done animating: ready for the next move.
  await expect(board).not.toHaveAttribute('data-fen', before ?? '')
}

/**
 * Saves the draft and waits until the server saved the line.
 *
 * @param {import('@playwright/test').Page} page
 */
async function saveLine(page) {
  const saved = page.waitForResponse(isChange)
  await page.getByTestId('editor-draft-save').click()
  expect((await saved).status()).toBe(200)
  await expect(page.getByTestId('editor-draft')).toHaveCount(0)
}

/**
 * The draft's moves as shown ("1.e4", "e5").
 *
 * @param {import('@playwright/test').Page} page
 */
const draftMoves = page =>
  page.getByTestId('editor-draft-moves').locator('.editor__draft-move')

/**
 * The tree as written, without spaces ("1.e4e5(1…c5)2.Nf3").
 *
 * @param {import('@playwright/test').Page} page
 */
const treeText = page =>
  page
    .getByTestId('move-tree')
    .innerText()
    .then(t => t.replace(/\s+/g, ''))

test('builds a repertoire line by line, with a variation, a comment, a deletion and undo', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/repertoire/new')

  await page.getByTestId('repertoire-name').fill('Blancs e2e')
  await page.getByTestId('repertoire-create').click()
  await expect(page.getByTestId('editor-name')).toHaveText('Blancs e2e')
  await expect(page.getByTestId('move-tree')).toContainText('Aucun coup')

  // The moves make a draft, sent only when the line is saved; undo takes the whole line back.
  await play(page, 'e2e4')
  await play(page, 'e7e5')
  await expect(draftMoves(page)).toHaveText(['1.e4', 'e5'])
  await expect(page.getByTestId('move-tree')).toContainText('Aucun coup')
  await saveLine(page)
  expect(await treeText(page)).toBe('1.e4e5')
  const undoneLine = page.waitForResponse(isChange)
  await page.getByTestId('editor-undo').click()
  await undoneLine
  await expect(page.getByTestId('move-tree')).toContainText('Aucun coup')

  await play(page, 'e2e4')
  await play(page, 'e7e5')
  await play(page, 'g1f3')
  await saveLine(page)
  await expect(page.getByTestId('editor-save-state')).toHaveText('Enregistré')
  expect(await treeText(page)).toBe('1.e4e52.Nf3')
  // Opening names come from lichess-org/chess-openings (loaded with the fixtures).
  await expect(page.getByTestId('editor-opening')).toHaveText(
    /C40\s+King's Knight Opening/
  )

  // Back to 1.e4, another reply: a variation.
  await page.keyboard.press('ArrowLeft')
  await page.keyboard.press('ArrowLeft')
  await expect(page.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    /^rnbqkbnr\/pppppppp\/8\/8\/4P3\/8\/PPPP1PPP\/RNBQKBNR b KQkq -/
  )
  await play(page, 'c7c5')
  await saveLine(page)
  expect(await treeText(page)).toBe('1.e4e51…c52.Nf3')

  // The keyboard walks the variation back to the main line.
  await page.keyboard.press('ArrowUp')
  await expect(page.locator('.move-tree__move--current')).toHaveText('e5')

  // Comment on 2.Nf3, shown as plain text.
  await page.keyboard.press('ArrowRight')
  await expect(page.locator('.move-tree__move--current')).toHaveText('Nf3')
  await page.getByTestId('move-annotate').click()
  const annotated = page.waitForResponse(isChange)
  await page.getByTestId('nag-1').click()
  await annotated
  await page.keyboard.press('Escape')
  await expect(page.getByTestId('glyph-f3')).toHaveText('!')

  // Delete 1…c5 (confirmed), then undo.
  await page.locator('[data-testid="tree-move"]', { hasText: 'c5' }).click()
  await page.getByTestId('move-delete').click()
  const deleted = page.waitForResponse(isChange)
  await page.getByRole('button', { name: 'Supprimer' }).last().click()
  await deleted
  expect(await treeText(page)).not.toContain('c5')

  const undone = page.waitForResponse(isChange)
  await page.getByTestId('editor-undo').click()
  await undone
  await expect(page.getByTestId('move-tree')).toContainText('c5')

  // Everything was saved.
  await page.reload()
  await expect(page.getByTestId('move-tree')).toContainText('c5')
  expect(await treeText(page)).toBe('1.e4e51…c52.Nf3!')
})

test('one prepared move per position: a draft kept on reload, replacing at save, restore from the trash', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/repertoire/new')
  await page.getByTestId('repertoire-name').fill('Blancs remplacement')
  await page.getByTestId('repertoire-create').click()
  await expect(page.getByTestId('editor-name')).toHaveText(
    'Blancs remplacement'
  )
  for (const uci of ['e2e4', 'e7e5', 'g1f3', 'b8c6', 'f1b5']) {
    await play(page, uci)
  }
  await saveLine(page)
  expect(await treeText(page)).toBe('1.e4e52.Nf3Nc63.Bb5')

  // Another move of mine after 2…Nc6: a draft, stepped through with the arrows.
  await page.keyboard.press('ArrowLeft')
  await play(page, 'f1c4')
  await play(page, 'g8f6')
  await expect(draftMoves(page)).toHaveText(['3.Bc4', 'Nf6'])
  const after = page.locator('.editor__draft-move--after')
  await page.keyboard.press('ArrowLeft')
  await expect(after).toHaveText('Nf6')
  await page.keyboard.press('ArrowRight')
  await expect(after).toHaveCount(0)

  // Kept in the browser: back after a reload, nothing saved.
  await page.reload()
  await expect(draftMoves(page)).toHaveText(['3.Bc4', 'Nf6'])
  expect(await treeText(page)).toBe('1.e4e52.Nf3Nc63.Bb5')

  // Another move picked in the tree asks before dropping the draft.
  await page.locator('[data-testid="tree-move"]', { hasText: 'e4' }).click()
  await page
    .locator('.q-dialog')
    .getByRole('button', { name: 'Annuler' })
    .click()
  await expect(draftMoves(page)).toHaveText(['3.Bc4', 'Nf6'])

  // Saving it replaces 3.Bb5, which goes to the trash.
  await page.getByTestId('editor-draft-save').click()
  const dialog = page.getByTestId('replace-dialog')
  await expect(dialog).toContainText('Remplacer 3.Bb5 par 3.Bc4 ?')
  const replaced = page.waitForResponse(isChange)
  await page.getByTestId('replace-confirm').click()
  expect((await replaced).status()).toBe(200)
  await expect(page.getByTestId('editor-draft')).toHaveCount(0)
  expect(await treeText(page)).toBe('1.e4e52.Nf3Nc63.Bc4Nf6')
  await expect(page.locator('.move-tree__move--current')).toHaveText('Nf6')

  // A draft dropped on purpose.
  await play(page, 'd2d3')
  await page.getByTestId('editor-draft-discard').click()
  await page
    .locator('.q-dialog')
    .getByRole('button', { name: 'Abandonner' })
    .click()
  await expect(page.getByTestId('editor-draft')).toHaveCount(0)
  expect(await treeText(page)).toBe('1.e4e52.Nf3Nc63.Bc4Nf6')

  // The trash: restore 3.Bb5, 3.Bc4 goes there in turn.
  await page.getByTestId('editor-trash').click()
  await expect(page.getByTestId('trash-suite')).toHaveCount(1)
  await expect(page.getByTestId('trash-suite')).toContainText(
    '1.e4 e5 2.Nf3 Nc6 3.Bb5'
  )
  await page.getByTestId('trash-restore').click()
  await expect(page.getByTestId('restore-conflict')).toContainText(
    'Bb5 (restauré)'
  )
  await expect(page.getByTestId('restore-summary')).toContainText(
    '1 coup actuel part à la corbeille'
  )
  const restored = page.waitForResponse(
    r =>
      /\/trash\/[^/]+\/restore$/.test(r.url()) &&
      r.request().method() === 'POST'
  )
  await page.getByTestId('restore-confirm').click()
  expect((await restored).status()).toBe(200)
  await expect(page.getByTestId('trash-suite')).toContainText('3.Bc4')

  // Gone for good.
  await page.getByTestId('trash-discard').click()
  await page
    .locator('.q-dialog')
    .getByRole('button', { name: 'Supprimer définitivement' })
    .click()
  await expect(page.getByTestId('trash-empty')).toBeVisible()

  await page.goBack()
  await page.reload()
  await expect(page.getByTestId('move-tree')).toContainText('Bb5')
  expect(await treeText(page)).toBe('1.e4e52.Nf3Nc63.Bb5')
})

test('the Lichess panels: explorer and engine lines play their moves (Lichess simulated)', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/repertoire/new')
  await page.getByTestId('repertoire-name').fill('Explorateur e2e')
  await page.getByTestId('repertoire-create').click()
  await expect(page.getByTestId('editor-name')).toHaveText('Explorateur e2e')

  // The real API, without any Lichess token in e2e: it refuses without calling Lichess, and the
  // reason crosses CORS in its header.
  await page.getByTestId('tab-explorer').click()
  await expect(page.getByTestId('explorer-failure')).toContainText(
    'liez le vôtre dans votre profil'
  )

  // From now on the browser never reaches the proxy: answers are simulated.
  await page.route('**/api/repertoires/explorer/**', route =>
    route.fulfill({
      contentType: 'application/ld+json',
      json: {
        source: 'masters',
        fen: 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -',
        white: 60,
        draws: 25,
        black: 15,
        total: 100,
        moves: [
          {
            uci: 'e2e4',
            san: 'e4',
            white: 30,
            draws: 10,
            black: 10,
            total: 50,
            averageRating: 2450
          },
          {
            uci: 'd2d4',
            san: 'd4',
            white: 30,
            draws: 15,
            black: 5,
            total: 50,
            averageRating: 2460
          }
        ],
        opening: null
      }
    })
  )
  await page.route('**/api/repertoires/cloud-eval**', route =>
    route.fulfill({
      contentType: 'application/ld+json',
      json: {
        fen: 'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq -',
        found: true,
        depth: 40,
        knodes: 250000,
        lines: [
          { cp: 30, mate: null, moves: ['e7e5', 'g1f3'], san: ['e5', 'Nf3'] },
          { cp: 35, mate: null, moves: ['c7c5', 'g1f3'], san: ['c5', 'Nf3'] }
        ]
      }
    })
  )
  // No retry offered without a token (the profile link instead): another database asks again.
  await expect(
    page
      .getByTestId('explorer-failure')
      .getByRole('link', { name: 'Mon profil' })
  ).toBeVisible()
  await page.getByTestId('explorer-source').getByText('Lichess').click()
  await expect(page.getByTestId('explorer-move')).toHaveCount(2)

  await page.locator('[data-testid="explorer-move"][data-uci="e2e4"]').click()
  await expect(page.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    /^rnbqkbnr\/pppppppp\/8\/8\/4P3\//
  )

  await page.getByTestId('tab-engine').click()
  await expect(page.getByTestId('cloud-eval-line').first()).toContainText(
    '+0.30'
  )
  await expect(page.getByTestId('cloud-eval-line').first()).toContainText(
    '1…e5 2.Nf3'
  )
  await page.getByTestId('cloud-eval-line').nth(1).click()
  await expect(draftMoves(page)).toHaveText(['1.e4', 'c5'])
  await saveLine(page)
  await page.getByTestId('tab-moves').click()
  expect(await treeText(page)).toBe('1.e4c5')
})

test('the Lines tab: every line, filtered, shown on the board, deleted then undone', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/repertoire/new')
  await page.getByTestId('repertoire-name').fill('Lignes e2e')
  await page.getByTestId('repertoire-create').click()
  await expect(page.getByTestId('editor-name')).toHaveText('Lignes e2e')
  for (const uci of ['e2e4', 'e7e5', 'g1f3', 'b8c6', 'f1b5']) {
    await play(page, uci)
  }
  await saveLine(page)
  // Back to 1.e4: 1…c5 2.Nf3, a second line.
  await page.locator('[data-testid="tree-move"]', { hasText: 'e4' }).click()
  await play(page, 'c7c5')
  await play(page, 'g1f3')
  await saveLine(page)

  await page.getByTestId('tab-lines').click()
  const items = page.getByTestId('line-item')
  await expect(page.getByTestId('lines-count')).toHaveText('2 lignes')
  await expect(items.getByTestId('line-moves')).toHaveText([
    '1.e4 e5 2.Nf3 Nc6 3.Bb5',
    '1.e4 c5 2.Nf3'
  ])

  await page.getByTestId('lines-filter').fill('c5')
  await expect(items).toHaveCount(1)
  await expect(page.getByTestId('lines-count')).toHaveText('1 ligne sur 2')
  await page.getByTestId('lines-filter').fill('')
  await expect(items).toHaveCount(2)

  // A click shows the line's end.
  await items.first().click()
  await expect(page.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    /^r1bqkbnr\/pppp1ppp\/2n5\/1B2p3\/4P3\/5N2\/PPPP1PPP\/RNBQK2R b KQkq -/
  )

  // The bin removes what only this line has: 1…e5 and what follows.
  await items.first().getByTestId('line-delete').click()
  await expect(page.locator('.q-dialog')).toContainText(
    '1…e5 et les 3 coups qui le suivent'
  )
  const deleted = page.waitForResponse(isChange)
  await page
    .locator('.q-dialog')
    .getByRole('button', { name: 'Supprimer la ligne' })
    .click()
  expect((await deleted).status()).toBe(200)
  await expect(items.getByTestId('line-moves')).toHaveText(['1.e4 c5 2.Nf3'])

  const undone = page.waitForResponse(isChange)
  await page.getByTestId('editor-undo').click()
  await undone
  await expect(items).toHaveCount(2)
})
