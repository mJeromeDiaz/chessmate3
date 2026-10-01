import { describe, expect, it } from 'vitest'
import { normalizeAfter, normalizeFen } from '@/utils/chess/normalizeFen'
// Shared with the API tests (tests/Unit/Chess/RulesTest.php): both normalizers must agree.
import vectors from '../../../api/tests/Fixtures/Chess/normalization.json'

const INITIAL = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1'

describe('normalizeFen', () => {
  it.each(vectors.normalize)('$description', ({ fen, normalized }) => {
    expect(normalizeFen(fen)).toBe(normalized)
  })

  it.each(vectors.invalid)('rejects: $description', ({ fen }) => {
    expect(() => normalizeFen(fen)).toThrow()
  })

  it.each(vectors.transpositions)('$description', ({ a, b, same }) => {
    expect(normalizeAfter(INITIAL, a) === normalizeAfter(INITIAL, b)).toBe(same)
  })

  it('rejects an illegal move', () => {
    expect(() => normalizeAfter(INITIAL, ['e2e5'])).toThrow()
  })
})
