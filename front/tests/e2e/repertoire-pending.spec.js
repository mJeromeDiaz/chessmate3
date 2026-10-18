import { expect, test } from '@playwright/test'
import { playMove, seedPending, signIn } from './helpers.js'

/**
 * The pending positions of a repertoire (docs/REPERTOIRE.md, "En suspens"), on the real API: one
 * seeded as an analysis of the user's games would leave it (no Lichess), answered on the board
 * (the move enters the repertoire), then the answer undone (pending again).
 */
test('a pending position answered on the board, then undone', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/repertoire/import')
  await page.getByTestId('import-tab-paste').click()
  await page.getByTestId('import-pgn').fill('1. e4 e5 2. Nf3 Nc6 3. Bc4 *')
  await page.getByTestId('import-analyze').click()
  await expect(page.getByTestId('import-preview')).toBeVisible()
  await page.getByTestId('import-name').fill('Italienne en suspens')
  await page.getByTestId('import-apply').click()
  await expect(page.getByTestId('editor-name')).toHaveText(
    'Italienne en suspens'
  )
  const id = /#\/repertoire\/([^/?]+)/.exec(page.url())?.[1] ?? ''
  expect(id).not.toBe('')
  seedPending(id, 'e4 c5')

  await page.goto(`/#/repertoire/${id}/pending`)
  const item = page.getByTestId('pending-item')
  await expect(item).toHaveCount(1)
  await expect(item).toContainText('1.e4 c5')
  await expect(item).toContainText('Jamais rencontrée · 30 % des parties')
  await expect(item).toHaveAttribute('data-status', 'open')

  await item.getByTestId('pending-answer').click()
  const dialog = page.getByTestId('pending-dialog')
  await expect(dialog.getByTestId('chess-board')).toBeVisible()
  await playMove(page, 'g1f3', dialog)

  await expect(item).toHaveAttribute('data-status', 'answered')
  await expect(page.getByTestId('pending-open')).toContainText('0 à préparer')
  const notice = page.locator('.q-notification', {
    hasText: 'Coup ajouté après 1.e4 c5'
  })
  await expect(notice).toBeVisible()

  // Undone: the move leaves the repertoire, the position is pending again.
  await notice.getByRole('button', { name: 'Annuler' }).click()
  await expect(item).toHaveAttribute('data-status', 'open')
  await expect(page.getByTestId('pending-open')).toContainText('1 à préparer')
})
