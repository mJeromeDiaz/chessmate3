import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'

vi.mock('@/services/api', () => ({
  repertoireApi: {
    createAutoBuild: vi.fn(),
    getAutoBuild: vi.fn(),
    fetchAutoBuild: vi.fn(),
    selectAutoBuild: vi.fn(),
    exploreAutoBuild: vi.fn(),
    evaluateAutoBuild: vi.fn(),
    applyAutoBuild: vi.fn()
  }
}))

const { repertoireApi } = await import('@/services/api')
const { useAutoBuild, PHASE_MS, STORAGE_KEY, TOAST_MS } =
  await import('@/composables/repertoire/useAutoBuild')
const {
  autoErrorText,
  criticalMoments,
  defaultFamilies,
  evalText,
  explorationSay,
  familyNote,
  feedPace,
  formatThink,
  gameStatFacts,
  lineEvents,
  moveLabel,
  pendingItems,
  sanPath,
  scanSay,
  score,
  sequence,
  stopText,
  treeSay,
  unconfirmedLines,
  uciToSan
} = await import('@/utils/repertoireAuto')

/** A line as the API sends it. */
function line(overrides = {}) {
  return {
    path: 'e2e4',
    cut: 'e2e4',
    name: 'Line',
    nodes: ['e2e4'],
    games: 10,
    wins: 5,
    draws: 2,
    losses: 3,
    analysed: 0,
    errored: 0,
    fen: 'x',
    number: 1,
    tag: 'solid',
    evaluated: true,
    evalCp: null,
    evalMate: null,
    locked: false,
    ...overrides
  }
}

/** A node as the API sends it. */
function node(overrides = {}) {
  return {
    path: 'e2e4',
    parent: '',
    depth: 1,
    uci: 'e2e4',
    san: 'e4',
    fen: 'x',
    user: true,
    games: 10,
    wins: 5,
    draws: 2,
    losses: 3,
    analysed: 0,
    errored: 0,
    think: null,
    moveAnalysed: 0,
    moveErrors: 0,
    suggested: false,
    suggestion: null,
    alternatives: [],
    ...overrides
  }
}

const synthesis = {
  games: 100,
  analysed: 10,
  wins: 50,
  draws: 10,
  losses: 40,
  families: [
    {
      key: 'white:Italian Game',
      family: 'Italian Game',
      color: 'white',
      games: 40,
      wins: 25,
      draws: 5,
      losses: 10,
      analysed: 4
    },
    {
      key: 'black:Sicilian Defense',
      family: 'Sicilian Defense',
      color: 'black',
      games: 30,
      wins: 10,
      draws: 2,
      losses: 18,
      analysed: 3
    },
    {
      key: 'white:Ruy Lopez',
      family: 'Ruy Lopez',
      color: 'white',
      games: 20,
      wins: 14,
      draws: 4,
      losses: 2,
      analysed: 2
    },
    {
      key: 'black:French Defense',
      family: 'French Defense',
      color: 'black',
      games: 4,
      wins: 1,
      draws: 2,
      losses: 1,
      analysed: 1
    }
  ]
}

describe('repertoire from my games: wording', () => {
  it('numbers moves', () => {
    expect(moveLabel(9, 'd4')).toBe('5.d4')
    expect(moveLabel(10, 'a6')).toBe('a6')
    expect(moveLabel(10, 'a6', true)).toBe('5…a6')
    expect(
      sequence([
        { depth: 10, san: 'a6' },
        { depth: 11, san: 'Be3' },
        { depth: 12, san: 'e5' }
      ])
    ).toBe('5…a6 6.Be3 e5')
  })

  it('computes scores and thinking times', () => {
    expect(score({ games: 10, wins: 5, draws: 2 })).toBe(60)
    expect(score({ games: 0, wins: 0, draws: 0 })).toBe(0)
    expect(formatThink(4500)).toBe('45 s')
    expect(formatThink(8000)).toBe('1 min 20')
    expect(formatThink(18000)).toBe('3 min')
    expect(formatThink(null)).toBe('')
  })

  it('words the evaluation of a line end from the user’s side', () => {
    expect(evalText(line({ evaluated: false }))).toBe('pas encore évaluée')
    expect(evalText(line({ evalCp: null }))).toBe('non évaluée par Lichess')
    expect(evalText(line({ evalCp: 64 }))).toBe('+0,6 pour toi')
    expect(evalText(line({ evalCp: -130 }))).toBe('−1,3 pour toi')
    expect(evalText(line({ evalCp: 10 }))).toBe('égalité')
    expect(evalText(line({ evalMate: -2 }))).toBe('mat en 2 contre toi')
  })

  it('checks the most played families that matter', () => {
    expect(defaultFamilies(synthesis)).toEqual([
      'white:Italian Game',
      'black:Sicilian Defense'
    ])
    expect(
      defaultFamilies({
        ...synthesis,
        families: synthesis.families.filter(f => f.color === 'white')
      })
    ).toEqual(['white:Italian Game'])
  })

  it('notes each family', () => {
    expect(familyNote(synthesis.families[0], synthesis)).toBe(
      'Ton ouverture n°1 avec les blancs'
    )
    expect(familyNote(synthesis.families[1], synthesis)).toBe(
      'Ton ouverture n°1 avec les noirs'
    )
    expect(familyNote(synthesis.families[2], synthesis)).toBe(
      'Ton meilleur score'
    )
  })

  it('paces the live feed for about a minute at most', () => {
    expect(feedPace(50)).toBe(260)
    expect(feedPace(500)).toBe(120)
    expect(feedPace(2000)).toBe(30)
    expect(feedPace(undefined)).toBe(260)
  })

  it('lets Albert speak while reading', () => {
    expect(scanSay(0.1, false, 10, 0)).toBe(
      'Je lis tes parties une par une… il faut être patient.'
    )
    expect(scanSay(1, true, 200, 6)).toContain('6 ouvertures reviennent')
    expect(scanSay(1, true, 0, 0)).toContain('aucune partie')
    expect(
      treeSay([line({ tag: 'risk' }), line({ tag: 'weapon' }), line()], 200)
    ).toBe(
      'J’ai tiré 3 lignes de tes 200 parties. 1 te coûte des points, 1 est une vraie arme.'
    )
  })

  it('finds the critical moments, each line once', () => {
    const risky = line({ name: 'Risky', wins: 1, draws: 0, losses: 9 })
    const weapon = line({
      name: 'Weapon',
      wins: 9,
      draws: 0,
      losses: 1,
      games: 10
    })
    const played = line({
      name: 'Played',
      games: 40,
      wins: 20,
      draws: 0,
      losses: 20
    })
    const locked = line({ name: 'Locked', wins: 0, losses: 10, locked: true })
    const moments = criticalMoments([risky, weapon, played, locked])
    expect(moments.map(m => [m.kind, m.line.name])).toEqual([
      ['risk', 'Risky'],
      ['weapon', 'Weapon'],
      ['played', 'Played']
    ])
  })

  it('tells what happened along a line', () => {
    const events = lineEvents([
      node({ depth: 3, san: 'Nf3', think: 800 }),
      node({ depth: 4, san: 'Nc6', user: false }),
      node({
        depth: 5,
        san: 'Bc4',
        think: 6000,
        moveAnalysed: 4,
        moveErrors: 2,
        suggestion: { uci: 'd2d4', san: 'd4' }
      }),
      node({
        depth: 7,
        san: 'c3',
        think: 900,
        moveAnalysed: 3,
        alternatives: [
          { uci: 'd2d3', san: 'd3', games: 4, wins: 2, draws: 0, losses: 2 }
        ]
      })
    ])
    expect(events.map(e => [e.kind, e.ply])).toEqual([
      ['error', 5],
      ['key', 5],
      ['good', 7],
      ['alt', 7]
    ])
    expect(events[0].text).toBe(
      '3.Bc4 : une erreur dans 2 parties analysées sur 4. Lichess conseille 3.d4.'
    )
    expect(events[1].text).toContain('tu réfléchis 1 min en moyenne')
    expect(events[3].text).toBe(
      'Tu as aussi joué 4.d3 (4 parties, score 50 %).'
    )
  })

  it('words what the games said of a move, in the editor', () => {
    const fen = 'r1bqkbnr/pppp1ppp/2n5/4p3/4P3/5N2/PPPP1PPP/RNBQKB1R w KQkq -'
    expect(uciToSan(fen, 'f1c4')).toBe('Bc4')
    expect(uciToSan(fen, 'a1a8')).toBe('a1a8')
    const summary = gameStatFacts(
      {
        fen,
        uci: 'f1b5',
        games: 10,
        wins: 5,
        draws: 2,
        losses: 3,
        analysed: 4,
        errored: 1,
        thinkCs: 4500,
        moveAnalysed: 4,
        moveErrors: 1,
        bestUci: 'f1c4',
        evalCp: 60,
        evalMate: null
      },
      'black'
    )
    expect([summary.games, summary.score]).toEqual([10, 60])
    expect(summary.outcomes.map(o => o.share)).toEqual([50, 20, 30])
    expect(summary.facts).toEqual([
      'Tu réfléchis 45 s en moyenne sur ce coup.',
      'Une erreur dans 1 des 4 parties analysées par Lichess, qui conseille Bc4.',
      'Fin de ligne : −0,6 pour toi (nuage Lichess).'
    ])
  })

  it('words the API’s refusals', () => {
    expect(
      autoErrorText({
        response: { status: 409, data: { detail: 'lichess_not_linked' } }
      })
    ).toBe('Lie d’abord ton compte Lichess.')
    expect(autoErrorText({ response: { status: 404, data: {} } })).toContain(
      'expiré'
    )
    expect(autoErrorText({})).toContain('injoignable')
  })
})

describe('useAutoBuild', () => {
  /** @type {import('vue').EffectScope} */
  let scope

  beforeEach(() => {
    vi.useFakeTimers()
    sessionStorage.clear()
    Object.values(repertoireApi).forEach(fn => fn.mockReset())
    scope = effectScope()
  })

  afterEach(() => {
    scope.stop()
    vi.useRealTimers()
  })

  const run = () => scope.run(() => useAutoBuild())
  const game = (id, family = 'Italian Game') => ({
    id,
    color: 'white',
    result: 1,
    opponent: 'Opp',
    rating: 1800,
    family,
    perf: 'blitz'
  })
  const fetching = {
    id: 'b1',
    status: 'fetching',
    maxGames: 50,
    perfs: ['blitz'],
    games: 0,
    selection: null,
    trees: [],
    pendingEvaluations: 0,
    repertoireIds: []
  }

  it('reads the games batch by batch, waits when Lichess asks to, then checks the main families', async () => {
    repertoireApi.createAutoBuild.mockResolvedValue(fetching)
    repertoireApi.fetchAutoBuild
      .mockResolvedValueOnce({
        ...fetching,
        games: 2,
        batch: [game('g1'), game('g2', 'Sicilian Defense')]
      })
      .mockRejectedValueOnce({
        response: { status: 503, headers: { 'retry-after': '2' } }
      })
      .mockResolvedValueOnce({
        ...fetching,
        status: 'ready',
        games: 3,
        batch: [game('g3')],
        synthesis
      })
    const auto = run()
    auto.settings.count = 50
    auto.settings.perfs = ['blitz']

    const started = auto.start()
    await vi.advanceTimersByTimeAsync(2 * feedPace(50))
    expect(repertoireApi.createAutoBuild).toHaveBeenCalledWith({
      maxGames: 50,
      perfs: ['blitz']
    })
    expect(auto.step.value).toBe('scan')
    expect(auto.shown.value).toBe(2)
    expect(auto.found.value['white:Italian Game'].n).toBe(1)
    expect(auto.waiting.value).toContain('patiente quelques secondes')

    await vi.advanceTimersByTimeAsync(2000 + feedPace(50))
    await started
    expect(auto.waiting.value).toBe('')
    expect(auto.shown.value).toBe(3)
    expect(auto.scanDone.value).toBe(true)
    expect(auto.feed.value.map(g => g.id)).toEqual(['g1', 'g2', 'g3'])
    expect(auto.selected.value).toEqual([
      'white:Italian Game',
      'black:Sicilian Defense'
    ])
    expect(sessionStorage.getItem(STORAGE_KEY)).toBe('b1')
  })

  it('builds the repertoire: selection, evaluations, phases, then the tree', async () => {
    const ready = { ...fetching, status: 'ready', games: 100, synthesis }
    repertoireApi.createAutoBuild.mockResolvedValue(ready)
    const withTrees = {
      ...ready,
      selection: { families: ['white:Italian Game'], choices: [], removed: [] },
      trees: [{ key: 'white:Italian Game', lines: [] }]
    }
    repertoireApi.selectAutoBuild.mockResolvedValue({
      ...withTrees,
      pendingEvaluations: 3,
      exploration: {
        calls: 0,
        budget: 300,
        capped: false,
        frontier: 4,
        explored: 0
      }
    })
    repertoireApi.evaluateAutoBuild.mockResolvedValue({
      ...withTrees,
      pendingEvaluations: 0
    })
    repertoireApi.exploreAutoBuild.mockResolvedValue({
      ...withTrees,
      pendingEvaluations: 3,
      exploration: {
        calls: 4,
        budget: 300,
        capped: false,
        frontier: 0,
        explored: 4
      }
    })
    const auto = run()
    await auto.start()
    auto.toSelect()
    auto.selected.value = ['white:Italian Game']

    const generating = auto.generate()
    expect(auto.step.value).toBe('gen')
    expect(auto.phase.value).toBe(1)
    await vi.advanceTimersByTimeAsync(PHASE_MS)
    expect(auto.phase.value).toBe(2)
    expect(repertoireApi.selectAutoBuild).toHaveBeenCalledWith('b1', {
      families: ['white:Italian Game'],
      choices: {},
      removed: {},
      kept: {},
      suspended: {}
    })
    await vi.advanceTimersByTimeAsync(4 * PHASE_MS)
    await generating
    expect(repertoireApi.exploreAutoBuild).toHaveBeenCalledWith('b1', {})
    expect(repertoireApi.evaluateAutoBuild).toHaveBeenCalledTimes(1)
    expect(auto.phase.value).toBe(5)
    expect(auto.step.value).toBe('tree')
  })

  it('removes a line with an undo, and chooses another move', async () => {
    const tree = {
      ...fetching,
      status: 'ready',
      synthesis,
      selection: {
        families: ['white:Italian Game'],
        choices: { 'white:Italian Game': { '': 'd2d4' } },
        removed: []
      },
      trees: [],
      pendingEvaluations: 0
    }
    repertoireApi.createAutoBuild.mockResolvedValue(tree)
    repertoireApi.selectAutoBuild.mockResolvedValue(tree)
    const auto = run()
    await auto.start()

    await auto.removeLine('white:Italian Game', {
      cut: 'e2e4 c7c5',
      name: 'Sicilian'
    })
    expect(repertoireApi.selectAutoBuild).toHaveBeenLastCalledWith('b1', {
      families: ['white:Italian Game'],
      choices: { 'white:Italian Game': { '': 'd2d4' } },
      removed: { 'white:Italian Game': ['e2e4 c7c5'] },
      kept: {},
      suspended: {}
    })
    expect(auto.toast.value?.text).toBe('« Sicilian » retirée')

    await auto.undo()
    expect(repertoireApi.selectAutoBuild).toHaveBeenLastCalledWith('b1', {
      families: ['white:Italian Game'],
      choices: { 'white:Italian Game': { '': 'd2d4' } },
      removed: {},
      kept: {},
      suspended: {}
    })
    expect(auto.toast.value).toBeNull()

    await auto.chooseMove('white:Italian Game', 'e2e4 e7e5', 'g1f3')
    expect(repertoireApi.selectAutoBuild).toHaveBeenLastCalledWith('b1', {
      families: ['white:Italian Game'],
      choices: { 'white:Italian Game': { '': 'd2d4', 'e2e4 e7e5': 'g1f3' } },
      removed: {},
      kept: {},
      suspended: {}
    })

    await auto.removeLine('white:Italian Game', {
      cut: 'e2e4 e7e6',
      name: 'French'
    })
    await vi.advanceTimersByTimeAsync(TOAST_MS)
    expect(auto.toast.value).toBeNull()
  })

  it('says why the build could not start', async () => {
    repertoireApi.createAutoBuild.mockRejectedValue({
      response: { status: 409, data: { detail: 'lichess_not_linked' } }
    })
    const auto = run()

    await auto.start()

    expect(auto.step.value).toBe('connect')
    expect(auto.error.value).toBe('Lie d’abord ton compte Lichess.')
  })

  it('picks up the build of the tab after a reload', async () => {
    sessionStorage.setItem(STORAGE_KEY, 'b1')
    repertoireApi.getAutoBuild.mockResolvedValue({
      ...fetching,
      status: 'ready',
      games: 80,
      synthesis,
      selection: { families: ['white:Ruy Lopez'], choices: [], removed: [] }
    })
    const auto = run()

    await auto.resume()

    expect(auto.step.value).toBe('tree')
    expect(auto.selected.value).toEqual(['white:Ruy Lopez'])
    expect(auto.shown.value).toBe(80)
  })
})

describe('repertoire from my games: exploration, short lines, pending', () => {
  /** @type {import('vue').EffectScope} */
  let scope

  beforeEach(() => {
    vi.useFakeTimers()
    sessionStorage.clear()
    Object.values(repertoireApi).forEach(fn => fn.mockReset())
    scope = effectScope()
  })

  afterEach(() => {
    scope.stop()
    vi.useRealTimers()
  })

  const pendingNode = (overrides = {}) => ({
    path: 'e2e4 c7c5',
    parent: 'e2e4',
    depth: 2,
    fen: 'x',
    uci: 'c7c5',
    san: 'c5',
    sans: ['e4', 'c5'],
    reason: 'reply_uncovered',
    games: 3000,
    share: 30,
    score: 48,
    ...overrides
  })
  const tree = (key, lines, pending = []) => ({
    key,
    family: key.split(':')[1],
    color: 'white',
    lines,
    pending,
    nodes: []
  })
  const shortLine = (overrides = {}) =>
    line({
      path: 'e2e4 e7e5 g1f3',
      cut: 'e2e4 e7e5',
      name: 'Italian',
      depth: 3,
      short: true,
      confirmed: false,
      stop: { kind: 'no_popular_reply', san: null, path: null },
      ...overrides
    })

  it('lists and counts the pending positions of every opening, the most played reply first', () => {
    const items = pendingItems([
      tree(
        'white:Italian Game',
        [],
        [pendingNode({ games: 500, sans: ['e4', 'e6'] })]
      ),
      tree(
        'black:Sicilian Defense',
        [],
        [
          pendingNode(),
          pendingNode({ games: null, reason: 'short_line', sans: ['d4'] })
        ]
      )
    ])

    expect(items.map(p => [p.family, sanPath(p.sans)])).toEqual([
      ['Sicilian Defense', '1.e4 c5'],
      ['Italian Game', '1.e4 e6'],
      ['Sicilian Defense', '1.d4']
    ])
    expect(items).toHaveLength(3)
  })

  it('words where a short line stops, and says when no reply passes the thresholds', () => {
    expect(stopText(shortLine())).toBe(
      'Aucune réponse adverse ne passe les seuils Lichess (5 % et 100 parties).'
    )
    expect(
      stopText(
        shortLine({
          stop: { kind: 'reply_uncovered', san: 'Nc6', path: 'x' }
        })
      )
    ).toBe(
      'Coupée par 2…Nc6 : une réponse fréquente que tu n’as jamais rencontrée.'
    )
    expect(stopText(shortLine({ stop: { kind: 'unexplored' } }))).toBe(
      'Suite pas encore explorée sur Lichess.'
    )
    expect(
      unconfirmedLines([
        tree('white:Italian Game', [
          shortLine(),
          shortLine({ path: 'b', confirmed: true }),
          line({ path: 'c', short: false })
        ])
      ]).map(l => [l.key, l.path])
    ).toEqual([['white:Italian Game', 'e2e4 e7e5 g1f3']])
  })

  it('says when the exploration stopped at its cap', () => {
    expect(
      explorationSay({ calls: 300, budget: 300, capped: true, frontier: 12 })
    ).toBe('Analyse arrêtée à 300 requêtes : relance pour continuer.')
    expect(
      explorationSay({ calls: 10, budget: 300, capped: false, frontier: 1 })
    ).toBe('1 position adverse à explorer sur Lichess…')
    expect(
      explorationSay({ calls: 10, budget: 300, capped: false, frontier: 0 })
    ).toBeNull()
  })

  const built = (exploration, lines = [shortLine()]) => ({
    id: 'b1',
    status: 'ready',
    games: 6,
    synthesis,
    selection: {
      families: ['white:Italian Game'],
      choices: [],
      removed: [],
      kept: [],
      suspended: []
    },
    trees: [tree('white:Italian Game', lines)],
    pendingEvaluations: 0,
    repertoireIds: [],
    exploration
  })
  const done = {
    calls: 3,
    budget: 300,
    capped: false,
    frontier: 0,
    explored: 3
  }

  it('keeps a short line, or sets it aside with an undo', async () => {
    repertoireApi.createAutoBuild.mockResolvedValue(built(done))
    repertoireApi.selectAutoBuild.mockResolvedValue(built(done))
    const auto = run()
    await auto.start()
    expect(auto.unconfirmed.value).toHaveLength(1)

    await auto.confirmLine('white:Italian Game', shortLine(), 'keep')
    expect(repertoireApi.selectAutoBuild).toHaveBeenLastCalledWith('b1', {
      families: ['white:Italian Game'],
      choices: {},
      removed: {},
      kept: { 'white:Italian Game': ['e2e4 e7e5 g1f3'] },
      suspended: {}
    })

    await auto.confirmLine('white:Italian Game', shortLine(), 'suspend')
    expect(repertoireApi.selectAutoBuild).toHaveBeenLastCalledWith('b1', {
      families: ['white:Italian Game'],
      choices: {},
      removed: {},
      kept: { 'white:Italian Game': [] },
      suspended: { 'white:Italian Game': ['e2e4 e7e5'] }
    })
    expect(auto.toast.value?.text).toBe('« Italian » mise en suspens.')
    expect(repertoireApi.exploreAutoBuild).not.toHaveBeenCalled()
  })

  it('keeps every short line not answered yet at once', async () => {
    const lines = [
      shortLine(),
      shortLine({ path: 'e2e4 c7c5 g1f3', cut: 'e2e4 c7c5' }),
      shortLine({ path: 'd2d4 d7d5 c2c4', confirmed: true })
    ]
    repertoireApi.createAutoBuild.mockResolvedValue(built(done, lines))
    repertoireApi.selectAutoBuild.mockResolvedValue(built(done, lines))
    const auto = run()
    await auto.start()

    await auto.keepAllShort()
    expect(repertoireApi.selectAutoBuild).toHaveBeenCalledTimes(1)
    expect(repertoireApi.selectAutoBuild).toHaveBeenLastCalledWith('b1', {
      families: ['white:Italian Game'],
      choices: {},
      removed: {},
      kept: {
        'white:Italian Game': ['e2e4 e7e5 g1f3', 'e2e4 c7c5 g1f3']
      },
      suspended: {}
    })
  })

  it('stops at the cap, then goes on from there when resumed', async () => {
    const capped = {
      calls: 300,
      budget: 300,
      capped: true,
      frontier: 5,
      explored: 300
    }
    repertoireApi.createAutoBuild.mockResolvedValue(
      built({ calls: 0, budget: 300, capped: false, frontier: 9, explored: 0 })
    )
    repertoireApi.exploreAutoBuild
      .mockResolvedValueOnce(
        built({ ...capped, capped: false, calls: 10, explored: 10 })
      )
      .mockResolvedValueOnce(built(capped))
      .mockResolvedValueOnce(built(done))
    const auto = run()
    await auto.start()

    await auto.exploreAll()
    expect(repertoireApi.exploreAutoBuild).toHaveBeenCalledTimes(2)
    expect(auto.exploration.value?.capped).toBe(true)
    await auto.exploreAll()
    expect(repertoireApi.exploreAutoBuild).toHaveBeenCalledTimes(2)

    await auto.resumeExploration()
    expect(repertoireApi.exploreAutoBuild).toHaveBeenLastCalledWith('b1', {
      resume: true
    })
    expect(auto.exploration.value?.frontier).toBe(0)
  })

  it('explores further on one line only', async () => {
    repertoireApi.createAutoBuild.mockResolvedValue(built(done))
    repertoireApi.exploreAutoBuild
      .mockResolvedValueOnce(built({ ...done, explored: 5 }))
      .mockResolvedValueOnce(built({ ...done, explored: 5 }))
    const auto = run()
    await auto.start()

    await auto.exploreLine('white:Italian Game', shortLine())
    expect(repertoireApi.exploreAutoBuild).toHaveBeenCalledWith('b1', {
      family: 'white:Italian Game',
      cut: 'e2e4 e7e5'
    })
    expect(repertoireApi.exploreAutoBuild).toHaveBeenCalledTimes(2)
    expect(auto.exploring.value).toBe(false)
  })

  const run = () => scope.run(() => useAutoBuild())
})
