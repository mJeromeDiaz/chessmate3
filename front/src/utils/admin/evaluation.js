/**
 * The admin's position form (docs/EVALUATION.md): the evaluation as chess players write it, the
 * choices, the body sent to the API. Pure, so that it can be tested alone.
 *
 * @typedef {object} PositionBody
 * @property {string} fen
 * @property {number} evalCp centipawns, White's point of view; ±10 000 for a won position
 * @property {string[]} ideas 1 to 3
 * @property {string|null} plan
 * @property {string|null} tip
 * @property {string|null} tag
 * @property {number} rating
 * @property {string|null} source
 * @property {boolean} active
 */

/** A won position (mate, won endgame), as the API stores it. */
export const WON_CP = 10000
export const ADVANTAGE_CP = 70
export const WINNING_CP = 200
export const MAX_IDEAS = 3
export const DEFAULT_RATING = 1500

export const PLAN_OPTIONS = [
  { value: null, label: 'Aucun (pas de question « plan »)' },
  { value: 'king_attack', label: 'Attaque sur le roi' },
  { value: 'queenside', label: 'Jeu à l’aile dame' },
  { value: 'center_space', label: 'Centre et espace' },
  { value: 'simplify', label: 'Simplifier en finale' },
  { value: 'active_defense', label: 'Défense active' }
]

export const TAG_OPTIONS = [
  { value: null, label: 'Aucune' },
  { value: 'opening', label: 'Ouverture' },
  { value: 'middlegame', label: 'Milieu de partie' },
  { value: 'structure', label: 'Structure' },
  { value: 'endgame', label: 'Finale' }
]

/** The five categories, White's point of view. */
export const CATEGORIES = {
  2: '+− Blancs gagnent',
  1: '± Avantage Blancs',
  0: '= Équilibré',
  '-1': '∓ Avantage Noirs',
  '-2': '−+ Noirs gagnent'
}

export const VERDICTS = {
  ok: 'Lichess est d’accord',
  mismatch: 'Lichess voit une autre catégorie',
  drift: 'Même catégorie, mais à plus d’un pion de Lichess',
  unverified: 'Lichess ne connaît pas cette position',
  unavailable: 'Lichess ne répond pas, réessaie plus tard'
}

/**
 * Reads an evaluation as typed: "+1,4", "-0.3", "1.4", "0", "gagné" / "+−" (White wins),
 * "perdu" / "−+" (Black wins). Null when it can't be read.
 *
 * @param {string|null|undefined} text
 * @returns {number|null} centipawns
 */
export function parseEval(text) {
  const t = String(text ?? '')
    .trim()
    .toLowerCase()
    .replace(/−/g, '-')
    .replace(/\s+/g, '')
  if (t === '') return null
  if (['+-', 'gagné', 'gagne', '1-0', '#'].includes(t)) return WON_CP
  if (['-+', 'perdu', '0-1', '-#'].includes(t)) return -WON_CP
  if (!/^[+-]?\d+([.,]\d+)?$/.test(t)) return null
  const cp = Math.round(Number(t.replace(',', '.')) * 100)
  return Math.max(-WON_CP, Math.min(WON_CP, cp))
}

/**
 * The evaluation as shown to players: "+1,4", "−0,3", "0,0", "+−", "−+".
 *
 * @param {number} cp
 */
export function evalLabel(cp) {
  if (Math.abs(cp) >= WON_CP) return cp > 0 ? '+−' : '−+'
  const pawns = (Math.abs(cp) / 100).toFixed(1).replace('.', ',')
  return cp > 0 ? `+${pawns}` : cp < 0 ? `−${pawns}` : pawns
}

/** @param {number} cp */
export function category(cp) {
  const a = Math.abs(cp)
  const s = Math.sign(cp)
  return a >= WINNING_CP ? 2 * s : a >= ADVANTAGE_CP ? s : 0
}

/** Within 0.2 of a category's border: the answer would be a coin toss. @param {number} cp */
export function nearBorder(cp) {
  return [ADVANTAGE_CP, WINNING_CP].some(
    border => Math.abs(Math.abs(cp) - border) < 20
  )
}

/**
 * The side to move of a FEN, null when there is none to read.
 *
 * @param {string} fen
 * @returns {'white'|'black'|null}
 */
export function turnOf(fen) {
  const side = String(fen ?? '')
    .trim()
    .split(/\s+/)[1]
  return side === 'w' ? 'white' : side === 'b' ? 'black' : null
}

/**
 * The empty form, or one filled from a position of the API.
 *
 * @param {Partial<PositionBody & {evalCp: number}>|null} [position]
 */
export function formFrom(position = null) {
  return {
    fen: position?.fen ?? '',
    eval: position ? evalLabel(position.evalCp ?? 0).replace('−', '-') : '',
    ideas: [...(position?.ideas ?? []), '', '', ''].slice(0, MAX_IDEAS),
    plan: position?.plan ?? null,
    tip: position?.tip ?? '',
    tag: position?.tag ?? null,
    rating: position?.rating ?? DEFAULT_RATING,
    source: position?.source ?? '',
    active: position?.active ?? true
  }
}

/**
 * The body sent to the API, or the reason it can't be sent yet.
 *
 * @param {ReturnType<typeof formFrom>} form
 * @returns {{body: PositionBody, error: null}|{body: null, error: string}}
 */
export function bodyFrom(form) {
  const evalCp = parseEval(form.eval)
  const ideas = form.ideas.map(i => i.trim()).filter(Boolean)
  if (!form.fen.trim()) return { body: null, error: 'La FEN est obligatoire.' }
  if (evalCp === null)
    return {
      body: null,
      error:
        'Évaluation illisible : écris par exemple +1,4, -0,3, 0 ou « gagné ».'
    }
  if (ideas.length === 0)
    return { body: null, error: 'Au moins un libellé est obligatoire.' }
  const blank = /** @param {string} t */ t =>
    t.trim() === '' ? null : t.trim()
  return {
    body: {
      fen: form.fen.trim(),
      evalCp,
      ideas,
      plan: form.plan,
      tip: blank(form.tip),
      tag: form.tag,
      rating: Number(form.rating) || DEFAULT_RATING,
      source: blank(form.source),
      active: form.active
    },
    error: null
  }
}
