import { expect, test } from '@playwright/test'
import { playMove, signIn } from './helpers.js'

const isChange = r =>
  /\/api\/repertoires\/[^/]+\/(moves|undo)(\/|$)/.test(r.url()) &&
  r.request().method() === 'POST'

/**
 * Plays a move and waits until the server saved it.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} uci
 */
async function play(page, uci) {
  const board = page.getByTestId('chess-board')
  const before = await board.getAttribute('data-fen')
  const saved = page.waitForResponse(isChange)
  await playMove(page, uci)
  expect((await saved).status()).toBe(200)
  // data-fen changes once the board is done animating: ready for the next move.
  await expect(board).not.toHaveAttribute('data-fen', before ?? '')
}

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

test('builds a repertoire move by move, with a variation, a comment, a deletion and undo', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/repertoire/new')

  await page.getByTestId('repertoire-name').fill('Blancs e2e')
  await page.getByTestId('repertoire-create').click()
  await expect(page.getByTestId('editor-name')).toHaveText('Blancs e2e')
  await expect(page.getByTestId('move-tree')).toContainText('Aucun coup')

  await play(page, 'e2e4')
  await play(page, 'e7e5')
  await play(page, 'g1f3')
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

test('one prepared move per position: explore without saving, replace, restore from the trash', async ({
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
  expect(await treeText(page)).toBe('1.e4e52.Nf3Nc63.Bb5')

  // Another move of mine after 2…Nc6: replace it, or explore.
  await page.keyboard.press('ArrowLeft')
  await playMove(page, 'f1c4')
  const dialog = page.getByTestId('replace-dialog')
  await expect(dialog).toContainText('Remplacer 3.Bb5 par 3.Bc4 ?')
  await page.getByTestId('replace-explore').click()
  await expect(page.getByTestId('editor-off-book')).toContainText('3.Bc4')
  await expect(page.getByTestId('editor-explore')).toHaveAttribute(
    'aria-checked',
    'true'
  )
  await playMove(page, 'g8f6')
  await expect(page.getByTestId('editor-off-book')).toContainText('3.Bc4 Nf6')
  // Off book, ← goes back through the explored moves; nothing was saved.
  await page.keyboard.press('ArrowLeft')
  await expect(page.getByTestId('editor-off-book')).not.toContainText('Nf6')
  await page.getByTestId('editor-off-book-leave').click()
  await expect(page.getByTestId('editor-off-book')).toHaveCount(0)
  await page.getByTestId('editor-explore').click()
  expect(await treeText(page)).toBe('1.e4e52.Nf3Nc63.Bb5')

  // Replaced: 3.Bb5 goes to the trash.
  await playMove(page, 'f1c4')
  const replaced = page.waitForResponse(isChange)
  await page.getByTestId('replace-confirm').click()
  expect((await replaced).status()).toBe(200)
  await expect(page.getByTestId('move-tree')).toContainText('Bc4')
  expect(await treeText(page)).toBe('1.e4e52.Nf3Nc63.Bc4')

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

  const added = page.waitForResponse(isChange)
  await page.locator('[data-testid="explorer-move"][data-uci="e2e4"]').click()
  expect((await added).status()).toBe(200)
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
  const reply = page.waitForResponse(isChange)
  await page.getByTestId('cloud-eval-line').nth(1).click()
  expect((await reply).status()).toBe(200)
  await page.getByTestId('tab-moves').click()
  expect(await treeText(page)).toBe('1.e4c5')
})
