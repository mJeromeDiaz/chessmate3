<template>
  <div
    class="chess-board"
    :class="{ 'chess-board--shake': shaking }"
    data-testid="chess-board"
    :data-fen="currentFen"
  >
    <div ref="container" class="chess-board__surface" />
  </div>
</template>

<script setup>
/**
 * Generic, reusable chessboard (puzzles today; Woodpecker and repertoire review later).
 *
 * Display and interaction only: it knows the rules (chess.js) to offer legal moves and the
 * promotion dialog, but nothing about puzzles. The parent owns the position (`fen` prop) and
 * decides what a move means; see docs/PUZZLES.md, "Front components", for the full API.
 */
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { Chess } from 'chess.js'
import {
  BORDER_TYPE,
  COLOR,
  Chessboard,
  INPUT_EVENT_TYPE
} from 'cm-chessboard/src/Chessboard.js'
import { Markers } from 'cm-chessboard/src/extensions/markers/Markers.js'
import { Arrows } from 'cm-chessboard/src/extensions/arrows/Arrows.js'
import {
  PROMOTION_DIALOG_RESULT_TYPE,
  PromotionDialog
} from 'cm-chessboard/src/extensions/promotion-dialog/PromotionDialog.js'
import 'cm-chessboard/assets/chessboard.css'
import 'cm-chessboard/assets/extensions/markers/markers.css'
import 'cm-chessboard/assets/extensions/arrows/arrows.css'
import 'cm-chessboard/assets/extensions/promotion-dialog/promotion-dialog.css'

/**
 * @typedef {'white'|'black'} Color
 * @typedef {{from: string, to: string, promotion?: string}} MoveInput
 * @typedef {{square: string, type: 'lastMove'|'hint'|'error'|'success'}} Highlight
 * @typedef {{from: string, to: string, type?: 'hint'|'solution'}} Arrow
 */

// Marker and arrow types are matched by reference: module-level constants only.
const MARKERS = {
  lastMove: { class: 'cb-marker-last-move', slice: 'markerSquare' },
  hint: { class: 'cb-marker-hint', slice: 'markerCircle' },
  error: { class: 'cb-marker-error', slice: 'markerSquare' },
  success: { class: 'cb-marker-success', slice: 'markerSquare' },
  selected: { class: 'cb-marker-selected', slice: 'markerSquare' }
}
const ARROWS = {
  hint: { class: 'arrow-info' },
  solution: { class: 'arrow-success' }
}
const SHAKE_MS = 400

const props = defineProps({
  /** Position to show (FEN). The board animates to each new value. */
  fen: { type: String, required: true },
  /** Side shown at the bottom. */
  orientation: { type: String, default: 'white' },
  /** Side the user may move, or null to disable input. */
  movableColor: { type: String, default: null },
  /** @type {import('vue').PropType<Highlight[]>} Coloured squares (last move, hint, error...). */
  highlights: { type: Array, default: () => [] },
  /** @type {import('vue').PropType<Arrow[]>} */
  arrows: { type: Array, default: () => [] },
  /** Piece animation duration in ms (0 disables animations). */
  animationDuration: { type: Number, default: 250 },
  /** Show dots on the legal destinations of the picked piece. */
  showLegalMoves: { type: Boolean, default: true }
})

const emit = defineEmits({
  /** A legal move made by the user: {from, to, promotion?, uci, san}. */
  move: payload => typeof payload?.uci === 'string'
})

const container = ref(null)
/** @type {import('vue').ShallowRef<any>} */
const board = shallowRef(null)
const shaking = ref(false)
const currentFen = ref(props.fen)
let rules = new Chess(props.fen)

/** @param {string} color */
const toBoardColor = color => (color === 'black' ? COLOR.black : COLOR.white)

onMounted(() => {
  board.value = new Chessboard(container.value, {
    position: props.fen,
    orientation: toBoardColor(props.orientation),
    responsive: true,
    assetsUrl: `${import.meta.env.BASE_URL || '/'}chessboard/`,
    style: {
      borderType: BORDER_TYPE.none,
      showCoordinates: true,
      animationDuration: props.animationDuration
    },
    extensions: [
      { class: Markers, props: { autoMarkers: MARKERS.selected } },
      { class: Arrows },
      { class: PromotionDialog }
    ]
  })
  renderDecorations()
  updateInput()
})

onBeforeUnmount(() => {
  board.value?.destroy()
  board.value = null
})

watch(
  () => props.fen,
  fen => {
    setPosition(fen, true)
  }
)
watch(
  () => props.orientation,
  value => board.value?.setOrientation(toBoardColor(value), false)
)
watch(() => props.movableColor, updateInput)
watch(() => [props.highlights, props.arrows], renderDecorations, { deep: true })

/**
 * Shows a position, animated by default. Resolves once the animation is over.
 *
 * @param {string} fen
 * @param {boolean} [animated]
 * @returns {Promise<void>}
 */
async function setPosition(fen, animated = true) {
  rules = new Chess(fen)
  if (board.value) {
    await board.value.setPosition(fen, animated && props.animationDuration > 0)
  }
  // Exposed as data-fen once the animation is over: the position the user can act on.
  currentFen.value = fen
}

/**
 * Shakes the board (wrong move feedback). Resolves when the shake ends.
 *
 * @returns {Promise<void>}
 */
function shake() {
  shaking.value = true
  return new Promise(resolve =>
    setTimeout(() => {
      shaking.value = false
      resolve()
    }, SHAKE_MS)
  )
}

function renderDecorations() {
  const b = board.value
  if (!b) return
  for (const type of Object.values(MARKERS)) {
    if (type !== MARKERS.selected) b.removeMarkers(type)
  }
  for (const { square, type } of props.highlights) {
    if (MARKERS[type]) b.addMarker(MARKERS[type], square)
  }
  b.removeArrows()
  for (const { from, to, type = 'hint' } of props.arrows) {
    b.addArrow(ARROWS[type] ?? ARROWS.hint, from, to)
  }
}

function updateInput() {
  const b = board.value
  if (!b) return
  b.disableMoveInput()
  if (props.movableColor) {
    b.enableMoveInput(onInput, toBoardColor(props.movableColor))
  }
}

/**
 * cm-chessboard input handler: validates against the rules, asks for the promotion piece.
 *
 * @param {any} event
 * @returns {boolean|undefined}
 */
function onInput(event) {
  const markers = board.value?.getExtension(Markers)
  switch (event.type) {
    case INPUT_EVENT_TYPE.moveInputStarted: {
      const moves = rules.moves({ square: event.squareFrom, verbose: true })
      if (props.showLegalMoves) markers?.addLegalMovesMarkers(moves)
      return moves.length > 0
    }
    case INPUT_EVENT_TYPE.validateMoveInput: {
      markers?.removeLegalMovesMarkers()
      const candidates = rules
        .moves({ square: event.squareFrom, verbose: true })
        .filter(m => m.to === event.squareTo)
      if (candidates.length === 0) return false
      if (candidates.some(m => m.promotion)) {
        askPromotion(event.squareFrom, event.squareTo)
        return true
      }
      commit({ from: event.squareFrom, to: event.squareTo })
      return true
    }
    case INPUT_EVENT_TYPE.moveInputCanceled:
      markers?.removeLegalMovesMarkers()
      return undefined
    default:
      return undefined
  }
}

/**
 * @param {string} from
 * @param {string} to
 */
function askPromotion(from, to) {
  const color = rules.turn() === 'w' ? COLOR.white : COLOR.black
  board.value.showPromotionDialog(to, color, result => {
    if (result?.type === PROMOTION_DIALOG_RESULT_TYPE.pieceSelected) {
      // result.piece is e.g. "wq": keep the piece letter.
      commit({ from, to, promotion: result.piece.charAt(1) })
    } else {
      board.value?.setPosition(rules.fen(), true)
    }
  })
}

/**
 * Emits a user move. The board already shows it; the parent answers by changing `fen`
 * (accepted) or by restoring it (rejected).
 *
 * @param {MoveInput} input
 */
function commit(input) {
  const probe = new Chess(rules.fen())
  const move = probe.move(input)
  emit('move', {
    from: move.from,
    to: move.to,
    promotion: move.promotion,
    uci: move.from + move.to + (move.promotion ?? ''),
    san: move.san
  })
}

defineExpose({ setPosition, shake })
</script>

<style lang="scss">
.chess-board {
  width: 100%;
  max-width: min(92vw, 70vh, 560px);
  margin: 0 auto;
}
.chess-board__surface {
  width: 100%;
  aspect-ratio: 1;
}
.chess-board--shake {
  animation: chess-board-shake 0.4s ease-in-out;
}
@keyframes chess-board-shake {
  0%,
  100% {
    transform: translateX(0);
  }
  20%,
  60% {
    transform: translateX(-8px);
  }
  40%,
  80% {
    transform: translateX(8px);
  }
}
.cm-chessboard .markers .marker.cb-marker-last-move {
  fill: #9bc700;
  opacity: 0.35;
}
.cm-chessboard .markers .marker.cb-marker-selected {
  fill: #1976d2;
  opacity: 0.3;
}
.cm-chessboard .markers .marker.cb-marker-hint {
  stroke: #1976d2;
  stroke-width: 3px;
  opacity: 0.8;
}
.cm-chessboard .markers .marker.cb-marker-error {
  fill: #e53935;
  opacity: 0.6;
}
.cm-chessboard .markers .marker.cb-marker-success {
  fill: #43a047;
  opacity: 0.5;
}
</style>
