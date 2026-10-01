import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

const OPENBOOK = readFileSync(
  new URL(
    '../../../api/tests/Fixtures/Chess/openbook-white.pgn',
    import.meta.url
  ),
  'utf8'
)
const OPENBOOK_BACKUP = new URL(
  '../../../api/tests/Fixtures/Chess/openbook-backup.json',
  import.meta.url
).pathname

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} pgn
 */
async function analyzePasted(page, pgn) {
  await page.goto('/#/repertoire/import')
  await page.getByTestId('import-tab-paste').click()
  await page.getByTestId('import-pgn').fill(pgn)
  await page.getByTestId('import-analyze').click()
  await expect(page.getByTestId('import-preview')).toBeVisible()
}

test('imports a PGN, exports it, then merges another one with a chosen conflict and undoes it', async ({
  page,
  context
}) => {
  await signIn(context)

  // The OpenBook acceptance repertoire: 3 lines, into a new repertoire.
  await analyzePasted(page, OPENBOOK)
  await expect(page.getByTestId('import-figure-lines')).toHaveText('3')
  await expect(page.getByTestId('import-figure-new-positions')).toHaveText('39')
  await expect(page.getByTestId('import-name')).toHaveValue(
    'OpenBook white repertoire'
  )
  await page.getByTestId('import-apply').click()
  await expect(page.getByTestId('editor-name')).toHaveText(
    'OpenBook white repertoire'
  )
  await expect(page.getByTestId('move-tree')).toContainText('Bxd6')

  // Export: the browser receives a PGN file.
  const download = page.waitForEvent('download')
  await page.getByTestId('editor-export').click()
  await page.getByTestId('editor-export-pgn').click()
  const file = await download
  expect(file.suggestedFilename()).toBe('openbook-white-repertoire.pgn')

  // Merge: 3.Bg5 instead of 3.Bf4 (a conflict), taken from the file.
  await analyzePasted(page, '1. d4 d5 2. Nc3 Nf6 3. Bg5 *')
  await page
    .getByTestId('import-destination')
    .getByText('Un de mes répertoires')
    .click()
  await expect(page.getByTestId('import-conflict')).toHaveCount(1)
  await expect(page.getByTestId('import-conflict')).toContainText(
    '1.d4 d5 2.Nc3 Nf6 3.'
  )
  await page.getByTestId('import-choose-file').click()
  // 3.Bf4 and what follows it go to the trash.
  await expect(page.getByTestId('import-replaced')).toContainText(
    '1 coup préparé du répertoire sera remplacé'
  )
  await page.getByTestId('import-apply').click()
  await expect(page.getByTestId('editor-name')).toHaveText(
    'OpenBook white repertoire'
  )
  const bg5 = page.locator('[data-testid="tree-move"]', { hasText: 'Bg5' })
  const bf4 = page.locator('[data-testid="tree-move"]', { hasText: 'Bf4' })
  await expect(bg5).toBeVisible()
  await expect(bf4).toHaveCount(0)

  // One undo removes the whole import, and takes 3.Bf4 back from the trash.
  await page.getByTestId('editor-undo').click()
  await expect(bg5).toHaveCount(0)
  await expect(bf4.first()).toBeVisible()
})

test('imports an OpenBook backup file and exports it back for OpenBook', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/repertoire/import')
  await page.locator('input[type="file"]').setInputFiles(OPENBOOK_BACKUP)
  await page.getByTestId('import-analyze').click()
  await expect(page.getByTestId('import-preview')).toBeVisible()
  await expect(page.getByText('Sauvegarde OpenBook')).toBeVisible()
  await expect(page.getByTestId('import-figure-lines')).toHaveText('3')
  await expect(page.getByTestId('import-figure-new-positions')).toHaveText('39')
  await page.getByTestId('import-name').fill('OpenBook JSON')
  await page.getByTestId('import-apply').click()
  await expect(page.getByTestId('editor-name')).toHaveText('OpenBook JSON')
  await expect(page.getByTestId('move-tree')).toContainText('Bxd6')

  const download = page.waitForEvent('download')
  await page.getByTestId('editor-export').click()
  await page.getByTestId('editor-export-openbook').click()
  const file = await download
  expect(file.suggestedFilename()).toBe('openbook-json.json')
  const exported = JSON.parse(readFileSync(await file.path(), 'utf8'))
  const original = JSON.parse(readFileSync(OPENBOOK_BACKUP, 'utf8'))
  expect(exported.repertoire.white).toEqual(original.repertoire.white)
  expect(exported.repertoire.black).toEqual({})
})

test('a private study asks to link Lichess (Lichess simulated)', async ({
  page,
  context
}) => {
  await signIn(context)
  // The API is not reached: it would call Lichess.
  await page.route('**/api/repertoires/imports', route =>
    route.fulfill({
      status: 422,
      contentType: 'application/problem+json',
      json: { status: 422, detail: 'study_private' }
    })
  )
  await page.goto('/#/repertoire/import')
  await page.getByTestId('import-tab-study').click()
  await page
    .getByTestId('import-study-url')
    .fill('https://lichess.org/study/abcdEFGH')
  await page.getByTestId('import-analyze').click()

  await expect(page.getByTestId('import-error')).toContainText('privée')
  await expect(
    page.getByRole('link', { name: 'Lier mon compte Lichess' })
  ).toBeVisible()
})

test('an unreadable PGN says where it breaks', async ({ page, context }) => {
  await signIn(context)
  await page.goto('/#/repertoire/import')
  await page.getByTestId('import-tab-paste').click()
  await page.getByTestId('import-pgn').fill('1. e4 e5\n2. Nf3 (Nc6\n*')
  await page.getByTestId('import-analyze').click()

  await expect(page.getByTestId('import-error')).toContainText(
    'mal formé (ligne'
  )
})
