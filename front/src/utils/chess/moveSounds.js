import { Chess } from 'chess.js'

const BASE = `${import.meta.env.BASE_URL ?? '/'}media/son/`

/** The file of each kind of move (no check sound of its own: a check plays the move sound). */
export const MOVE_SOUND_FILES = {
  move: `${BASE}Move.mp3`,
  capture: `${BASE}Capture.mp3`,
  check: `${BASE}Move.mp3`
}

/**
 * The kind of the single legal move leading from one position to the next, or null when the
 * new position is not one move away (a new puzzle, a jump in a move tree, a takeback).
 *
 * @param {string} before FEN
 * @param {string} after FEN
 * @returns {null|'move'|'capture'|'check'}
 */
export function moveKind(before, after) {
  const target = after.split(' ').slice(0, 2).join(' ')
  let moves
  try {
    moves = new Chess(before).moves({ verbose: true })
  } catch {
    return null
  }
  const move = moves.find(
    m => m.after.split(' ').slice(0, 2).join(' ') === target
  )
  if (!move) return null
  if (move.san.includes('+') || move.san.includes('#')) return 'check'

  return move.captured ? 'capture' : 'move'
}

/** One preloaded element per file, replayed from the start for each move. */
const players = new Map()

/**
 * Plays the sound of a kind of move. Silent failures: no file, no audio support, autoplay
 * refused before the first user gesture.
 *
 * @param {'move'|'capture'|'check'} kind
 */
export function playMoveSound(kind) {
  const src = MOVE_SOUND_FILES[kind]
  try {
    let audio = players.get(src)
    if (!audio) {
      audio = new Audio(src)
      players.set(src, audio)
    }
    audio.currentTime = 0
    audio.play()?.catch(() => {})
  } catch {
    // No audio support.
  }
}
