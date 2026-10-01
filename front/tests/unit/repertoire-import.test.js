import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick } from 'vue'

vi.mock('@/services/api', () => ({
  repertoireApi: {
    createImport: vi.fn(),
    getImport: vi.fn(),
    applyImport: vi.fn()
  }
}))

const { repertoireApi } = await import('@/services/api')
const { useImport, importError, POLL_MS } =
  await import('@/composables/repertoire/useImport')
const { bulkChoices, defaultChoices, failureText, pathText, warningText } =
  await import('@/utils/repertoireImport')

const conflicts = [
  {
    fen: 'root',
    path: [],
    candidates: [
      { uci: 'e2e4', san: 'e4', origin: 'both' },
      { uci: 'd2d4', san: 'd4', origin: 'file' }
    ],
    choice: 'e2e4'
  },
  {
    fen: 'after',
    path: ['d4', 'd5'],
    candidates: [
      { uci: 'c2c4', san: 'c4', origin: 'file' },
      { uci: 'c1f4', san: 'Bf4', origin: 'file' }
    ],
    choice: 'c2c4'
  }
]
const analyzed = (extra = {}) => ({
  id: 'i1',
  status: 'analyzed',
  progress: 100,
  label: 'london.pgn',
  suggestedName: null,
  suggestedColor: 'black',
  error: null,
  preview: { conflicts, warnings: [], lines: 3 },
  ...extra
})

describe('import wording and choices', () => {
  it('words failures and warnings', () => {
    expect(failureText('syntax', 12)).toBe(
      'Le fichier est mal formé (ligne 12).'
    )
    expect(failureText('surprise')).toBe('L’import a échoué. Réessayez.')
    expect(warningText({ type: 'illegal_move', game: 2, move: '7...h5' })).toBe(
      'Partie 2, 7...h5 : coup illégal ou ambigu, la ligne s’arrête avant lui.'
    )
    expect(warningText({ type: 'start_not_found', game: 3 })).toContain(
      'Partie 3 :'
    )
    // An OpenBook backup has no games.
    expect(
      warningText(
        { type: 'illegal_move', game: 1, move: '2...Qxh7' },
        'openbook'
      )
    ).toBe('2...Qxh7 : coup illégal ou ambigu, la ligne s’arrête avant lui.')
    expect(
      warningText({ type: 'unreachable', game: 1, count: 2 }, 'openbook')
    ).toBe(
      '2 positions du fichier ne sont atteintes par aucune ligne depuis la position initiale : ignorées.'
    )
    expect(
      warningText({ type: 'invalid_position', game: 1, move: 'x' }, 'openbook')
    ).toContain('Position ignorée')
  })

  it('writes the moves leading to a conflict', () => {
    expect(pathText([])).toBe('1.')
    expect(pathText(['e4'])).toBe('1.e4 1…')
    expect(pathText(['d4', 'd5'])).toBe('1.d4 d5 2.')
  })

  it('keeps the defaults, or chooses one side everywhere', () => {
    expect(defaultChoices(conflicts)).toEqual({ root: 'e2e4', after: 'c2c4' })
    expect(bulkChoices(conflicts, 'existing')).toEqual({
      root: 'e2e4',
      after: 'c2c4'
    })
    expect(bulkChoices(conflicts, 'file')).toEqual({
      root: 'd2d4',
      after: 'c2c4'
    })
  })

  it('reads the codes of API errors', () => {
    expect(
      importError({
        response: { status: 422, data: { detail: 'study_private' } }
      }).code
    ).toBe('study_private')
    expect(
      importError({ response: { status: 422, data: { violations: [{}] } } })
        .code
    ).toBe('invalid')
    expect(importError({ response: { status: 409, data: {} } }).code).toBe(
      'stale'
    )
    expect(
      importError({
        response: {
          status: 503,
          headers: { 'x-lichess-unavailable': 'busy' },
          data: {}
        }
      }).message
    ).toContain('occupé')
  })
})

describe('useImport', () => {
  let scope
  beforeEach(() => {
    vi.useFakeTimers()
    vi.resetAllMocks()
  })
  afterEach(() => {
    scope?.stop()
    vi.useRealTimers()
  })
  const mount = () => {
    scope = effectScope()
    return scope.run(useImport)
  }

  it('goes from a small file straight to its preview', async () => {
    repertoireApi.createImport.mockResolvedValue(analyzed())
    const imp = mount()

    await imp.start({ pgn: '1. d4 *', fileName: 'london.pgn' })

    expect(imp.phase.value).toBe('preview')
    expect(imp.destination.value).toMatchObject({
      mode: 'new',
      name: 'london.pgn',
      color: 'black'
    })
    expect(imp.choices.value).toEqual({ root: 'e2e4', after: 'c2c4' })
  })

  it('polls a worker, then applies to an existing repertoire with the choices', async () => {
    repertoireApi.createImport.mockResolvedValue({
      id: 'i1',
      status: 'analyzing',
      progress: 0
    })
    repertoireApi.getImport
      .mockResolvedValueOnce({ id: 'i1', status: 'analyzing', progress: 40 })
      .mockResolvedValueOnce(analyzed())
    const imp = mount()

    await imp.start({ pgn: 'big' })
    expect(imp.phase.value).toBe('analyzing')
    await vi.advanceTimersByTimeAsync(POLL_MS)
    expect(imp.current.value.progress).toBe(40)
    await vi.advanceTimersByTimeAsync(POLL_MS)
    expect(imp.phase.value).toBe('preview')

    repertoireApi.getImport.mockResolvedValue(analyzed())
    imp.destination.value = {
      ...imp.destination.value,
      mode: 'existing',
      repertoireId: 'r1',
      baseVersion: 7
    }
    await nextTick()
    await vi.advanceTimersByTimeAsync(300)
    expect(repertoireApi.getImport).toHaveBeenLastCalledWith('i1', {
      repertoireId: 'r1'
    })

    await imp.chooseAll('file')
    expect(repertoireApi.getImport).toHaveBeenLastCalledWith('i1', {
      repertoireId: 'r1',
      choices: { root: 'd2d4', after: 'c2c4' }
    })
    repertoireApi.applyImport.mockResolvedValue({
      id: 'i1',
      status: 'done',
      repertoireId: 'r1'
    })
    await imp.apply()

    expect(repertoireApi.applyImport).toHaveBeenCalledWith('i1', {
      repertoireId: 'r1',
      baseVersion: 7,
      choices: { root: 'd2d4', after: 'c2c4' }
    })
    expect(imp.phase.value).toBe('done')
  })

  it('asks the preview again for the choices made, which may reveal conflicts further on', async () => {
    repertoireApi.createImport.mockResolvedValue(
      analyzed({ preview: { conflicts: [conflicts[0]], warnings: [] } })
    )
    const imp = mount()
    await imp.start({ pgn: 'x' })
    expect(imp.choices.value).toEqual({ root: 'e2e4' })
    repertoireApi.getImport.mockResolvedValue(
      analyzed({
        preview: {
          conflicts: [{ ...conflicts[0], choice: 'd2d4' }, conflicts[1]],
          warnings: []
        }
      })
    )

    imp.setChoices({ root: 'd2d4' })
    await vi.advanceTimersByTimeAsync(300)

    expect(repertoireApi.getImport).toHaveBeenCalledWith('i1', {
      color: 'black',
      choices: { root: 'd2d4' }
    })
    expect(imp.choices.value).toEqual({ root: 'd2d4', after: 'c2c4' })
  })

  it('comes back to the preview when a worker refuses the application', async () => {
    repertoireApi.createImport.mockResolvedValue(analyzed())
    const imp = mount()
    await imp.start({ pgn: 'x' })
    repertoireApi.applyImport.mockResolvedValue({
      id: 'i1',
      status: 'applying'
    })
    repertoireApi.getImport.mockResolvedValue(
      analyzed({ error: 'limit_positions' })
    )

    await imp.apply()
    expect(imp.phase.value).toBe('applying')
    await vi.advanceTimersByTimeAsync(POLL_MS)

    expect(imp.phase.value).toBe('preview')
    expect(imp.error.value.code).toBe('limit_positions')
  })

  it('reports a failed analysis and a refused study', async () => {
    repertoireApi.createImport
      .mockResolvedValueOnce({
        id: 'i1',
        status: 'failed',
        error: 'syntax',
        errorLine: 3
      })
      .mockRejectedValueOnce({
        response: { status: 422, data: { detail: 'study_private' } }
      })
    const imp = mount()

    await imp.start({ pgn: '1. e4 (' })
    expect(imp.phase.value).toBe('failed')
    expect(imp.error.value.message).toBe('Le fichier est mal formé (ligne 3).')

    imp.reset()
    await imp.start({ studyUrl: 'https://lichess.org/study/abcdEFGH' })
    expect(imp.phase.value).toBe('source')
    expect(imp.error.value.code).toBe('study_private')
  })
})
