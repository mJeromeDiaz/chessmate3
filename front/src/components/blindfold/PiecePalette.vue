<template>
  <div class="piece-palette" role="toolbar" data-testid="recall-palette">
    <div v-for="row in ROWS" :key="row.side" class="piece-palette__row">
      <button
        v-for="piece in row.pieces"
        :key="piece"
        type="button"
        class="piece-palette__piece"
        :class="{ 'piece-palette__piece--on': modelValue === piece }"
        :aria-pressed="modelValue === piece"
        :aria-label="pieceName(piece)"
        :title="pieceName(piece)"
        :disabled="disable"
        :data-testid="`recall-piece-${piece}`"
        @click="emit('update:modelValue', piece)"
      >
        <svg viewBox="0 0 40 40" aria-hidden="true">
          <use :href="`${SPRITE}#${symbol(piece)}`" />
        </svg>
      </button>
      <button
        v-if="row.side === 'b'"
        type="button"
        class="piece-palette__piece piece-palette__piece--eraser"
        :class="{ 'piece-palette__piece--on': modelValue === null }"
        :aria-pressed="modelValue === null"
        :aria-label="$t('blindfoldRecall.player.eraser')"
        :title="$t('blindfoldRecall.player.eraser')"
        :disabled="disable"
        data-testid="recall-eraser"
        @click="emit('update:modelValue', null)"
      >
        <q-icon name="backspace" size="22px" />
      </button>
    </div>
  </div>
</template>

<script setup>
/**
 * The pieces to place when rebuilding a position (docs/BLINDFOLD_RECALL.md): all twelve, White's
 * then Black's (never only those of the position: that would tell), and the eraser. The chosen
 * piece is the v-model (a FEN letter, null for the eraser).
 */
import { PALETTE } from '@/utils/blindfoldRecall'
import { t } from '@/i18n'

defineProps({
  /** @type {import('vue').PropType<string|null>} the chosen piece, null for the eraser */
  modelValue: { type: String, default: null },
  disable: { type: Boolean, default: false }
})

const emit = defineEmits({
  'update:modelValue': piece => piece === null || PALETTE.includes(piece)
})

/** The board's piece sprite (cm-chessboard), its symbols "wk", "bp"... */
const SPRITE = `${import.meta.env.BASE_URL || '/'}chessboard/pieces/standard.svg`

const ROWS = [
  { side: 'w', pieces: PALETTE.slice(0, 6) },
  { side: 'b', pieces: PALETTE.slice(6) }
]

/** @param {string} piece a FEN letter */
function symbol(piece) {
  return (piece === piece.toUpperCase() ? 'w' : 'b') + piece.toLowerCase()
}

/** @param {string} piece a FEN letter */
function pieceName(piece) {
  return t(
    `blindfoldRecall.player.pieceNames.${piece === piece.toUpperCase() ? 'white' : 'black'}.${piece.toLowerCase()}`
  )
}
</script>

<style scoped lang="scss">
.piece-palette {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.piece-palette__row {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.piece-palette__piece {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 46px;
  height: 46px;
  padding: 3px;
  border: 2px solid var(--cm-line);
  border-radius: 10px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  cursor: pointer;

  svg {
    width: 100%;
    height: 100%;
  }

  &--on {
    border-color: var(--cm-brand);
    background: var(--cm-brand-soft);
    box-shadow: 0 0 0 2px var(--cm-brand);
  }

  &:disabled {
    opacity: 0.5;
    cursor: default;
  }
}
</style>
