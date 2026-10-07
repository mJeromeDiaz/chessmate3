import { Chess } from 'chess.js'
import { formatDuration, formatRatingDelta } from '@/utils/format'
import { numberedLine } from '@/utils/chess/lichess'
import { moduleProf } from '@/utils/runEnd'

/**
 * The end-of-exercise feedback (design "Animation Puzzle"): a sheet sliding up with the verdict,
 * and the module's professor reacting in a bubble. Pure, so that it can be tested alone.
 *
 * @typedef {'win'|'help'|'miss'} FeedbackKind win: solved alone; help: solved after a hint (or a
 *   blindfold peek); miss: a wrong move, the solution shown, or given up
 *
 * @typedef {object} FeedbackTheme
 * @property {string} sheetBg
 * @property {string} accent
 * @property {string} titleInk
 * @property {string} subInk
 * @property {string} btnBg
 * @property {string} btnShadow
 * @property {string} stripeA promotion square, light stripe
 * @property {string} stripeB promotion square, dark stripe
 * @property {string} promoInk
 * @property {string} sparkInk
 * @property {string[]} confetti
 *
 * @typedef {object} FeedbackCopy
 * @property {string} title
 * @property {string} sub
 * @property {string} kicker the bubble's kicker, e.g. "BRAVO"
 * @property {string} bubble what the professor says
 */

/** Colours of each verdict (the design's, whatever the module). */
export const FEEDBACK_THEMES = {
  win: {
    sheetBg: '#E4F7A8',
    accent: '#C6F432',
    titleInk: '#3F5600',
    subInk: '#5A7A00',
    btnBg: '#5A7A00',
    btnShadow: '#3F5600',
    stripeA: '#5A7A00',
    stripeB: '#3F5600',
    promoInk: '#C6F432',
    sparkInk: '#8DBA0A',
    confetti: ['#C6F432', '#1B1530', '#FFFFFF', '#8DBA0A', '#E4F7A8']
  },
  help: {
    sheetBg: '#FFF5D1',
    accent: '#FFD43B',
    titleInk: '#1B1530',
    subInk: '#8A6A00',
    btnBg: '#1B1530',
    btnShadow: '#4C475E',
    stripeA: '#B88A00',
    stripeB: '#8A6A00',
    promoInk: '#FFD43B',
    sparkInk: '#E0AE00',
    confetti: ['#FFD43B', '#1B1530', '#FFFFFF', '#E0AE00', '#FFF5D1']
  },
  miss: {
    sheetBg: '#FFE6F1',
    accent: '#FF6FAE',
    titleInk: '#1B1530',
    subInk: '#C02670',
    btnBg: '#1B1530',
    btnShadow: '#4C475E',
    stripeA: '',
    stripeB: '',
    promoInk: '',
    sparkInk: '',
    confetti: []
  }
}

/** Kicker of the professor's bubble once the verdict is known. */
const KICKERS = { win: 'BRAVO', help: 'PAS MAL', miss: 'CORRECTION' }

/**
 * Lichess themes that describe a puzzle's length, phase or stake rather than its motif: never a
 * title ("Fourchette !" yes, "Milieu de partie !" no).
 */
const NOT_MOTIFS = new Set([
  'opening',
  'middlegame',
  'endgame',
  'short',
  'long',
  'veryLong',
  'oneMove',
  'advantage',
  'crushing',
  'equality',
  'master',
  'masterVsMaster',
  'superGM',
  'mix',
  'playerGames',
  'pawnEndgame',
  'rookEndgame',
  'bishopEndgame',
  'knightEndgame',
  'queenEndgame',
  'queenRookEndgame'
])

/**
 * The verdict of a puzzle played to its end.
 *
 * @param {{mistaken: boolean, solutionShown: boolean, hinted: boolean}} game mistaken: a wrong
 *   move was played; hinted: a hint was used (the rating counts it as a failure, the feedback
 *   as a success with help)
 * @returns {FeedbackKind}
 */
export function puzzleKind({ mistaken, solutionShown, hinted }) {
  if (mistaken || solutionShown) return 'miss'
  return hinted ? 'help' : 'win'
}

/**
 * The verdict of a blindfold puzzle, from the server's status.
 *
 * @param {string|null|undefined} status solved, helped or failed
 * @returns {FeedbackKind}
 */
export function blindfoldKind(status) {
  if (status === 'solved') return 'win'
  return status === 'helped' ? 'help' : 'miss'
}

/**
 * The puzzle's motif among its Lichess themes (the first that is one), or null.
 *
 * @param {string[]|undefined} themes
 * @returns {string|null}
 */
export function motifTheme(themes) {
  return (themes ?? []).find(t => !NOT_MOTIFS.has(t)) ?? null
}

/**
 * The player's first move of a puzzle, numbered: "23.Nd5+" or "23…Qxe2".
 *
 * @param {{fen: string, moves: string[]}} puzzle moves[0] is the opponent's, moves[1] the player's
 * @returns {string} '' when it cannot be read
 */
export function keyMove(puzzle) {
  try {
    const chess = new Chess(puzzle.fen)
    play(chess, puzzle.moves[0])
    const turn = chess.turn()
    const depth = (chess.moveNumber() - 1) * 2 + (turn === 'b' ? 1 : 0)
    const san = play(chess, puzzle.moves[1]).san
    return numberedLine([san], { turn, depth })
  } catch {
    return ''
  }
}

/**
 * @param {Chess} chess
 * @param {string} uci
 */
function play(chess, uci) {
  return chess.move({
    from: uci.slice(0, 2),
    to: uci.slice(2, 4),
    promotion: uci[4]
  })
}

/**
 * What the sheet and the professor say after a puzzle (rated, Woodpecker, timed run).
 *
 * @param {FeedbackKind} kind
 * @param {object} facts
 * @param {string} facts.move the key move, numbered ('' if unknown)
 * @param {string|null} [facts.motif] the motif's label, e.g. "Fourchette"
 * @param {number|null} [facts.durationMs]
 * @param {number|null} [facts.ratingDelta] rated puzzles only
 * @returns {FeedbackCopy}
 */
export function puzzleCopy(
  kind,
  { move, motif = null, durationMs = null, ratingDelta = null }
) {
  const time = durationMs === null ? '' : ` · ${formatDuration(durationMs)}`
  const elo =
    ratingDelta === null ? '' : ` · Elo ${formatRatingDelta(ratingDelta)}`
  if (kind === 'win') {
    return {
      title: motif ? `${motif} !` : 'Bravo !',
      sub: `${move ? `${move} trouvé seul` : 'Trouvé seul'}${time}${elo}`,
      kicker: KICKERS.win,
      bubble: motif
        ? `Impeccable. Le motif « ${motif.toLowerCase()} » était là, et tu l’as vu sans moi.`
        : 'Impeccable. Tu as vu le coup sans moi.'
    }
  }
  if (kind === 'help') {
    return {
      title: 'Bien joué !',
      sub: `Trouvé avec un indice${time}${elo}`,
      kicker: KICKERS.help,
      bubble:
        'Tu as eu le bon réflexe une fois mis sur la piste. La prochaine fois, seul !'
    }
  }
  return {
    title: 'Raté !',
    sub: `${move ? `La solution : ${move} !` : 'Regarde la solution.'}${elo}`,
    kicker: KICKERS.miss,
    bubble: motif
      ? `Le motif à retenir : « ${motif.toLowerCase()} ». Rejoue la solution dans ta tête.`
      : 'Cherche d’abord les échecs, les prises et les menaces : la solution part souvent de là.'
  }
}

/**
 * What the sheet and the professor say after a blindfold puzzle.
 *
 * @param {FeedbackKind} kind
 * @param {{move: string, durationMs?: number|null}} facts
 * @returns {FeedbackCopy}
 */
export function blindfoldCopy(kind, { move, durationMs = null }) {
  const time = durationMs === null ? '' : ` · ${formatDuration(durationMs)}`
  if (kind === 'win') {
    return {
      title: 'De mémoire !',
      sub: `${move ? `${move} trouvé à l’aveugle` : 'Trouvé à l’aveugle'}${time}`,
      kicker: KICKERS.win,
      bubble: 'Tu as tout gardé en tête. Belle mémoire !'
    }
  }
  if (kind === 'help') {
    return {
      title: 'Bien joué !',
      sub: `Trouvé avec un coup d’œil${time}`,
      kicker: KICKERS.help,
      bubble:
        'Un coup d’œil, et tu as retrouvé le fil. La prochaine fois, sans regarder !'
    }
  }
  return {
    title: 'Raté !',
    sub: move ? `La solution : ${move} !` : 'Regarde la solution.',
    kicker: KICKERS.miss,
    bubble:
      'La position t’a échappé. Mémorise-la pièce par pièce, en partant des rois.'
  }
}

/**
 * What the sheet and the professor say after a repertoire unit.
 *
 * @param {FeedbackKind} kind win or miss (no help in the repertoire test)
 * @param {{unit: 'segment'|'line', moves: number, expected?: string, retry?: boolean}} facts
 *   moves: moves asked; expected: the prepared move missed ("5.Bc4"); retry: it comes back later
 * @returns {FeedbackCopy}
 */
export function repertoireCopy(
  kind,
  { unit, moves, expected = '', retry = false }
) {
  const line = unit === 'line'
  const name = line ? 'Ligne' : 'Tronçon'
  if (kind !== 'miss') {
    return {
      title: `${name} réussi${line ? 'e' : ''} !`,
      sub: `${moves} coup${moves > 1 ? 's' : ''} préparé${moves > 1 ? 's' : ''} sans faute`,
      kicker: KICKERS.win,
      bubble: 'Ta préparation tient la route. On enchaîne !'
    }
  }
  return {
    title: 'Raté !',
    sub: expected
      ? `Le coup préparé : ${expected}`
      : `${name} raté${line ? 'e' : ''}`,
    kicker: KICKERS.miss,
    bubble: retry
      ? `Ce coup-là, il faut le connaître par cœur. ${line ? 'Elle' : 'Il'} revient plus tard dans la séance.`
      : 'Ce coup-là, il faut le connaître par cœur. Revois-le après la séance.'
  }
}

/**
 * The professor of a module for the feedback: neutral while playing, pleased after a success
 * (with or without help), thoughtful after a miss.
 *
 * @param {string} module the API's module (puzzles, woodpecker, repertoire, blindfold)
 * @param {FeedbackKind|null} kind null while playing
 */
export function feedbackProf(module, kind) {
  return moduleProf(
    module,
    kind === null ? 'neutral' : kind === 'miss' ? 'sad' : 'happy'
  )
}

/**
 * The XP chip: "+12 XP", "…" while the server has not answered, '' when nothing is gained.
 *
 * @param {number|null|undefined} xp the submission's `xp` (undefined: not answered yet)
 */
export function xpText(xp) {
  if (xp === undefined) return '…'
  return xp === null ? '' : `+${xp} XP`
}
