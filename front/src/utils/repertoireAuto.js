import { Chess } from 'chess.js'

/**
 * Repertoire from my games (design "Repertoire auto", docs/REPERTOIRE.md): the wording and the
 * figures of the pages, computed from what the API returns. Pure functions.
 *
 * @typedef {{key: string, family: string, color: 'white'|'black', games: number, wins: number, draws: number, losses: number, analysed: number}} Family
 * @typedef {{games: number, analysed: number, wins: number, draws: number, losses: number, families: Family[]}} Synthesis
 * @typedef {{uci: string, san: string, games: number, wins: number, draws: number, losses: number}} Alternative
 * @typedef {{path: string, parent: string, depth: number, uci: string, san: string, fen: string, user: boolean, games: number, wins: number, draws: number, losses: number, analysed: number, errored: number, think: number|null, moveAnalysed: number, moveErrors: number, suggested: boolean, suggestion: {uci: string, san: string}|null, alternatives: Alternative[]}} TreeNode
 * @typedef {{path: string, cut: string, name: string, nodes: string[], games: number, wins: number, draws: number, losses: number, analysed: number, errored: number, fen: string, number: number, tag: Tag, evaluated: boolean, evalCp: number|null, evalMate: number|null, locked: boolean}} Line
 * @typedef {{key: string, family: string, color: 'white'|'black', games: number, wins: number, draws: number, losses: number, trunk: number, nodes: TreeNode[], lines: Line[]}} Tree
 * @typedef {'risk'|'weapon'|'draws'|'solid'} Tag
 * @typedef {{kind: 'error'|'key'|'good'|'alt', ply: number, text: string}} LineEvent
 * @typedef {{fen: string, uci: string, games: number, wins: number, draws: number, losses: number, analysed: number, errored: number, thinkCs: number|null, moveAnalysed: number, moveErrors: number, bestUci: string|null, evalCp: number|null, evalMate: number|null}} GameStat
 */

/** Games a build may read (the API's choices). */
export const GAME_COUNTS = [50, 100, 200, 500, 1000, 2000]

/** Lichess speeds offered. */
export const SPEEDS = [
  { value: 'bullet', label: 'Bullet' },
  { value: 'blitz', label: 'Blitz' },
  { value: 'rapid', label: 'Rapide' },
  { value: 'classical', label: 'Classique' }
]

/** The page's accent, aqua green (buttons, choices on, the "weapon" tag, wins). */
export const ACCENT = '#5EE6B0'

/** Tags of a line: label and colour (design). */
export const TAGS = {
  risk: { label: 'À risque', color: '#FF6FAE' },
  weapon: { label: 'Arme', color: ACCENT },
  draws: { label: 'Nulles', color: '#DCD8E8' },
  solid: { label: 'Solide', color: '#4FB2FF' }
}

/** Wins, draws and losses: label and colour (the line sheet's bar). */
export const OUTCOMES = [
  { key: 'wins', label: 'Victoires', color: ACCENT },
  { key: 'draws', label: 'Nulles', color: '#B4AFC6' },
  { key: 'losses', label: 'Défaites', color: '#FF6FAE' }
]

/** Steps of the page, with the header's labels. */
export const STEPS = ['connect', 'scan', 'select', 'gen', 'tree']
export const STEP_LABELS = {
  connect: 'Compte',
  scan: 'Analyse',
  select: 'Sélection',
  gen: 'Génération',
  tree: 'Répertoire'
}

/**
 * The score of a set of games: wins plus half the draws, in whole percents.
 *
 * @param {{games: number, wins: number, draws: number}} counts
 * @returns {number}
 */
export function score({ games, wins, draws }) {
  return games > 0 ? Math.round((100 * (wins + draws / 2)) / games) : 0
}

/**
 * A share in whole percents.
 *
 * @param {number} part
 * @param {number} total
 * @returns {number}
 */
export function percent(part, total) {
  return total > 0 ? Math.round((100 * part) / total) : 0
}

/**
 * A move with its number: "5.d4", "5…a6" (black, forced) or "a6" (black, after white's move).
 *
 * @param {number} ply 1 for White's first move
 * @param {string} san
 * @param {boolean} [force] number a black move too
 * @returns {string}
 */
export function moveLabel(ply, san, force = false) {
  const number = Math.ceil(ply / 2)
  if (ply % 2 === 1) return `${number}.${san}`
  return force ? `${number}…${san}` : san
}

/**
 * Moves from a ply on, numbered: "5.d4 cxd4 6.Nxd4".
 *
 * @param {{san: string, depth: number}[]} nodes the moves (depth = ply)
 * @returns {string}
 */
export function sequence(nodes) {
  return nodes.map((n, i) => moveLabel(n.depth, n.san, i === 0)).join(' ')
}

/**
 * The user's result in a game: letter and colour of the live feed.
 *
 * @param {number} result 1, 0, -1
 * @returns {{letter: string, color: string}}
 */
export function resultBadge(result) {
  if (result > 0) return { letter: 'V', color: ACCENT }
  if (result === 0) return { letter: 'N', color: '#B4AFC6' }
  return { letter: 'D', color: '#FF6FAE' }
}

/**
 * A thinking time: "45 s", "1 min 20", "3 min".
 *
 * @param {number|null} centiseconds
 * @returns {string}
 */
export function formatThink(centiseconds) {
  if (centiseconds === null || centiseconds === undefined) return ''
  const seconds = Math.round(centiseconds / 100)
  if (seconds < 60) return `${seconds} s`
  const minutes = Math.floor(seconds / 60)
  const rest = seconds % 60
  return rest
    ? `${minutes} min ${String(rest).padStart(2, '0')}`
    : `${minutes} min`
}

/**
 * The cloud evaluation of a line's end, the user's point of view.
 *
 * @param {Line} line
 * @returns {string}
 */
export function evalText(line) {
  if (!line.evaluated) return 'pas encore évaluée'
  if (line.evalMate !== null) {
    return line.evalMate > 0
      ? `mat en ${line.evalMate} pour toi`
      : `mat en ${-line.evalMate} contre toi`
  }
  if (line.evalCp === null) return 'non évaluée par Lichess'
  const pawns = (Math.abs(line.evalCp) / 100).toFixed(1).replace('.', ',')
  if (Math.abs(line.evalCp) < 20) return 'égalité'
  return line.evalCp > 0 ? `+${pawns} pour toi` : `−${pawns} pour toi`
}

/**
 * The families checked at first: the most played one with white and the most played one with
 * black (the families come most played first).
 *
 * @param {Synthesis} synthesis
 * @returns {string[]} family keys
 */
export function defaultFamilies(synthesis) {
  return ['white', 'black']
    .map(color => synthesis.families.find(f => f.color === color)?.key)
    .filter(key => key !== undefined)
}

/** The live feed's pace: a reading's feed lasts about this long at most... */
const FEED_TOTAL_MS = 60_000
/** ...at a pace between these two (one game every so many ms). */
const FEED_PACE_MIN_MS = 30
const FEED_PACE_MAX_MS = 260

/**
 * One game every so many ms in the live feed: slow enough to be read for a short reading, fast
 * enough for 2000 games not to make the user wait for the animation (the reading itself is faster).
 *
 * @param {number} count games asked
 * @returns {number}
 */
export function feedPace(count) {
  return Math.round(
    Math.min(
      FEED_PACE_MAX_MS,
      Math.max(FEED_PACE_MIN_MS, FEED_TOTAL_MS / (count > 0 ? count : 1))
    )
  )
}

/**
 * Albert's words while the games are read.
 *
 * @param {number} progress 0 to 1
 * @param {boolean} done
 * @param {number} games games read
 * @param {number} families families found
 * @returns {string}
 */
export function scanSay(progress, done, games, families) {
  if (done) {
    if (games === 0) {
      return 'Je n’ai trouvé aucune partie à lire avec ces réglages. Essaie d’autres cadences.'
    }
    return families > 0
      ? `${games} parties lues. Tu as un style bien à toi : ${families} ouverture${families > 1 ? 's reviennent' : ' revient'} sans cesse.`
      : `${games} parties lues, mais aucune ouverture ne revient au moins 3 fois. Essaie avec plus de parties.`
  }
  if (progress < 0.35) {
    return 'Je lis tes parties une par une… il faut être patient.'
  }
  if (progress < 0.75) return 'Je repère tes habitudes dans les premiers coups…'
  return 'Presque fini, je compte les ouvertures…'
}

/**
 * A word on an opening family, from where it stands among the user's: their first with a color,
 * their best score, a fragile one.
 *
 * @param {Family} family
 * @param {Synthesis} synthesis
 * @returns {string}
 */
export function familyNote(family, synthesis) {
  const side = family.color === 'white' ? 'les blancs' : 'les noirs'
  const sameColor = synthesis.families.filter(f => f.color === family.color)
  if (sameColor[0]?.key === family.key) return `Ton ouverture n°1 avec ${side}`
  const best = [...synthesis.families].sort((a, b) => score(b) - score(a))[0]
  if (best?.key === family.key) return 'Ton meilleur score'
  const value = score(family)
  if (value < 40) return 'Score fragile : elle te coûte des points'
  if (percent(family.draws, family.games) >= 30) return 'Beaucoup de nulles'
  return `Jouée dans ${percent(family.games, synthesis.games)} % de tes parties`
}

/**
 * Albert's summary of what the user plays, from the most played families of each color.
 *
 * @param {Synthesis} synthesis
 * @returns {string}
 */
export function selectSay(synthesis) {
  const first = color => synthesis.families.find(f => f.color === color)
  const white = first('white')
  const black = first('black')
  const parts = []
  if (white) parts.push(`Avec les blancs, c’est surtout ${white.family}`)
  if (black) parts.push(`avec les noirs, ${black.family}`)
  if (!parts.length) return 'Garde ce qui te sert.'
  return `${parts.join(' ; ')}. Garde ce qui te sert.`
}

/**
 * Sentences shown while the repertoire is built, from the real figures of the trees.
 *
 * @param {Tree[]} trees
 * @returns {string[]}
 */
export function tickerPhrases(trees) {
  const phrases = []
  for (const tree of trees) {
    phrases.push(
      `${tree.family} : ${tree.games} parties, score ${score(tree)} %.`
    )
    const worst = [...tree.lines].sort(
      (a, b) => b.losses / b.games - a.losses / a.games
    )[0]
    if (worst && worst.losses > 0) {
      phrases.push(
        `${worst.name} : tu perds ${percent(worst.losses, worst.games)} % de ces parties.`
      )
    }
    const best = [...tree.lines].sort(
      (a, b) => b.wins / b.games - a.wins / a.games
    )[0]
    if (best && best !== worst && best.wins > 0) {
      phrases.push(
        `${best.name} : ${percent(best.wins, best.games)} % de victoires.`
      )
    }
  }
  return phrases.length ? phrases : ['Je reconstruis tes lignes…']
}

/**
 * Albert's words over the finished repertoire.
 *
 * @param {Line[]} lines
 * @param {number} games
 * @returns {string}
 */
export function treeSay(lines, games) {
  const risk = lines.filter(l => l.tag === 'risk').length
  const weapons = lines.filter(l => l.tag === 'weapon').length
  const riskText = risk
    ? `${risk} ${risk > 1 ? 'te coûtent' : 'te coûte'} des points`
    : 'aucune ne te coûte vraiment de points'
  const weaponText = weapons
    ? `${weapons} ${weapons > 1 ? 'sont de vraies armes' : 'est une vraie arme'}`
    : 'aucune n’est encore une arme'
  return `J’ai tiré ${lines.length} ligne${lines.length > 1 ? 's' : ''} de tes ${games} parties. ${riskText.charAt(0).toUpperCase()}${riskText.slice(1)}, ${weaponText}.`
}

/**
 * The critical moments of the repertoire: the line that costs the most, the best one, the most
 * played one (each once), among the lines shown in full.
 *
 * @param {Line[]} lines
 * @returns {{kind: 'risk'|'weapon'|'played', title: string, line: Line, text: string}[]}
 */
export function criticalMoments(lines) {
  const open = lines.filter(l => !l.locked && l.games > 0)
  const moments = []
  const worst = [...open].sort(
    (a, b) => b.losses / b.games - a.losses / a.games || b.games - a.games
  )[0]
  if (worst && worst.losses > 0) {
    moments.push({
      kind: 'risk',
      title: 'À RISQUE',
      line: worst,
      text: `${percent(worst.losses, worst.games)} % de défaites sur ${worst.games} parties.`
    })
  }
  const best = [...open].sort(
    (a, b) => b.wins / b.games - a.wins / a.games || b.games - a.games
  )[0]
  if (best && best !== worst && best.wins > 0) {
    moments.push({
      kind: 'weapon',
      title: 'TON ARME',
      line: best,
      text: `${percent(best.wins, best.games)} % de victoires sur ${best.games} parties.`
    })
  }
  const played = [...open].sort((a, b) => b.games - a.games)[0]
  if (played && played !== worst && played !== best) {
    moments.push({
      kind: 'played',
      title: 'LA PLUS JOUÉE',
      line: played,
      text: `${played.games} parties.`
    })
  }
  return moments
}

/**
 * What happened along a line, move by move: the user's mistakes Lichess found (with its best
 * move), the moves they think long over, the moves they played safely, the other moves they
 * played.
 *
 * @param {TreeNode[]} nodes the line's nodes, in order
 * @returns {LineEvent[]}
 */
export function lineEvents(nodes) {
  const events = []
  const thinks = nodes
    .filter(n => n.user && n.think !== null)
    .map(n => /** @type {number} */ (n.think))
    .sort((a, b) => a - b)
  const median = thinks.length ? thinks[Math.floor(thinks.length / 2)] : 0
  for (const node of nodes) {
    if (!node.user) continue
    const label = moveLabel(node.depth, node.san, true)
    if (node.suggested) {
      events.push({
        kind: 'good',
        ply: node.depth,
        text: `${label} : le coup conseillé par Lichess, que tu n’as pas encore joué. La ligne s’arrête ici : prolonge-la dans l’éditeur.`
      })
      continue
    }
    if (node.moveErrors > 0) {
      const advice = node.suggestion
        ? ` Lichess conseille ${moveLabel(node.depth, node.suggestion.san, true)}.`
        : ''
      events.push({
        kind: 'error',
        ply: node.depth,
        text: `${label} : une erreur dans ${node.moveErrors} partie${node.moveErrors > 1 ? 's' : ''} analysée${node.moveErrors > 1 ? 's' : ''} sur ${node.moveAnalysed}.${advice}`
      })
    } else if (node.moveAnalysed >= 3) {
      events.push({
        kind: 'good',
        ply: node.depth,
        text: `${label} : jamais une erreur dans ${node.moveAnalysed} parties analysées.`
      })
    }
    if (node.think !== null && node.think >= 3000 && node.think >= 2 * median) {
      events.push({
        kind: 'key',
        ply: node.depth,
        text: `${label} : tu réfléchis ${formatThink(node.think)} en moyenne ici, la position te surprend encore.`
      })
    }
    if (node.alternatives.length) {
      const others = node.alternatives
        .map(
          a =>
            `${moveLabel(node.depth, a.san, true)} (${a.games} parties, score ${score(a)} %)`
        )
        .join(', ')
      events.push({
        kind: 'alt',
        ply: node.depth,
        text: `Tu as aussi joué ${others}.`
      })
    }
  }
  return events
}

/**
 * A failure of the API, worded for the page.
 *
 * @param {any} error an axios error
 * @returns {string}
 */
export function autoErrorText(error) {
  const response = error?.response
  const detail = response?.data?.detail
  const texts = {
    lichess_not_linked: 'Lie d’abord ton compte Lichess.',
    invalid_settings: 'Choisis un nombre de parties et au moins une cadence.',
    invalid_selection:
      'Cette sélection ne correspond plus à tes parties : recharge la page.',
    not_ready: 'La lecture de tes parties n’est pas finie.',
    already_applied: 'Ces répertoires sont déjà créés.',
    nothing_selected: 'Garde au moins une ouverture qui donne une ligne.',
    limit_repertoires:
      'Tu as atteint la limite de 50 répertoires : supprimes-en avant de continuer.'
  }
  if (typeof detail === 'string' && texts[detail]) return texts[detail]
  if (response?.status === 404) {
    return 'Cette analyse a expiré (24 h) : recommence.'
  }
  if (response?.status === 429) {
    return 'Trop de demandes en peu de temps : patiente quelques minutes.'
  }
  if (response?.status === 503) {
    return 'Lichess ne répond pas pour le moment : réessaie dans un instant.'
  }
  if (!response) return 'Le serveur est injoignable. Vérifie ta connexion.'
  return 'Quelque chose s’est mal passé. Réessaie.'
}

/**
 * A move in UCI as SAN from a position, or the UCI when it cannot be played there.
 *
 * @param {string} fen normalized FEN (no move counters)
 * @param {string} uci
 * @returns {string}
 */
export function uciToSan(fen, uci) {
  try {
    const move = new Chess(`${fen} 0 1`).move({
      from: uci.slice(0, 2),
      to: uci.slice(2, 4),
      promotion: uci[4]
    })
    return move?.san ?? uci
  } catch {
    return uci
  }
}

/**
 * What the user's games said of a move of the repertoire (editor): how often and how it went,
 * the thinking time, Lichess' judgments, the evaluation at the end of a line.
 *
 * @param {GameStat} stat
 * @param {'white'|'black'} color the repertoire's
 * @returns {{games: number, score: number, outcomes: {key: string, label: string, color: string, share: number}[], facts: string[]}}
 */
export function gameStatFacts(stat, color) {
  const facts = []
  if (stat.thinkCs !== null && stat.thinkCs !== undefined) {
    facts.push(
      `Tu réfléchis ${formatThink(stat.thinkCs)} en moyenne sur ce coup.`
    )
  }
  if (stat.moveAnalysed > 0) {
    const errors = stat.moveErrors
    facts.push(
      errors > 0
        ? `Une erreur dans ${errors} des ${stat.moveAnalysed} parties analysées par Lichess${stat.bestUci ? `, qui conseille ${uciToSan(stat.fen, stat.bestUci)}` : ''}.`
        : `Jamais une erreur dans les ${stat.moveAnalysed} parties analysées par Lichess.`
    )
  }
  if (stat.evalCp !== null || stat.evalMate !== null) {
    const sign = color === 'white' ? 1 : -1
    facts.push(
      `Fin de ligne : ${evalText({
        evaluated: true,
        evalCp: stat.evalCp === null ? null : sign * stat.evalCp,
        evalMate: stat.evalMate === null ? null : sign * stat.evalMate
      })} (nuage Lichess).`
    )
  }
  return {
    games: stat.games,
    score: score(stat),
    outcomes: OUTCOMES.map(o => ({
      ...o,
      share: percent(stat[o.key], stat.games)
    })),
    facts
  }
}
