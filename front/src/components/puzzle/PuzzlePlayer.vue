<template>
  <div class="puzzle-player">
    <div class="puzzle-player__board">
      <ChessBoard
        v-if="game.puzzle.value"
        ref="board"
        :fen="game.fen.value"
        :orientation="game.orientation.value"
        :movable-color="game.movableColor.value"
        :highlights="game.highlights.value"
        :arrows="game.arrows.value"
        @move="onMove"
      />
      <div v-else-if="loading" class="flex flex-center q-pa-xl">
        <q-spinner size="3em" />
      </div>
    </div>

    <div class="puzzle-player__panel column q-gutter-md">
      <slot name="header" />

      <template v-if="puzzle">
        <div class="text-subtitle1" data-testid="puzzle-status">{{
          statusText
        }}</div>
        <slot name="info" />

        <div v-if="game.phase.value !== 'complete'" class="row q-gutter-sm">
          <q-btn
            outline
            no-caps
            icon="lightbulb"
            :label="game.hintShown.value === 0 ? 'Indice' : 'Indice suivant'"
            :disable="
              game.phase.value !== 'playing' || game.hintShown.value >= 2
            "
            data-testid="puzzle-hint"
            @click="game.hint()"
          />
          <q-btn
            outline
            no-caps
            icon="visibility"
            label="Voir la solution"
            :disable="game.phase.value === 'idle' || game.solutionShown.value"
            data-testid="puzzle-solution"
            @click="game.showSolution()"
          />
        </div>

        <div v-else class="column q-gutter-sm" data-testid="puzzle-result">
          <slot name="result" :failed="game.failed.value" />
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
/**
 * One puzzle being played: the board, the status line, hint and solution buttons. Shared by the
 * rated puzzles and Woodpecker; the page owns the API calls (through `resolve`) and fills the
 * `header`, `info` and `result` slots.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import { usePuzzle } from '@/composables/puzzle/usePuzzle'

const props = defineProps({
  /** @type {import('vue').PropType<{fen: string, moves: string[], playerColor: 'white'|'black'}|null>} A new object starts a new game. */
  puzzle: { type: Object, default: null },
  /** After a wrong move: keep searching, or play the solution at once (one try). */
  afterMistake: { type: String, default: 'continue' },
  loading: { type: Boolean, default: false }
})

const emit = defineEmits({
  /** The outcome is known (first mistake, hint, solution, or clean end): submit `report`. */
  resolve: (outcome, report) =>
    typeof outcome === 'string' && Array.isArray(report?.moves),
  /** The final position is on the board. */
  complete: null
})

const board = ref(null)
const game = usePuzzle({
  afterMistake: props.afterMistake,
  onResolve: (outcome, report) => emit('resolve', outcome, report),
  onComplete: () => emit('complete')
})

const statusText = computed(() => {
  const side = game.orientation.value === 'white' ? 'les Blancs' : 'les Noirs'
  switch (game.phase.value) {
    case 'intro':
      return game.solutionShown.value ? 'Solution…' : 'Au tour de l’adversaire…'
    case 'playing':
      return game.failed.value
        ? 'Ce n’est pas le bon coup. Cherchez encore !'
        : `Trouvez le meilleur coup pour ${side}.`
    case 'complete':
      return game.failed.value ? 'Puzzle terminé.' : 'Bravo !'
    default:
      return ''
  }
})

/** @param {{uci: string}} move */
async function onMove(move) {
  const verdict = game.play(move.uci)
  if (verdict === 'correct') return
  if (verdict === 'wrong') await board.value?.shake()
  // Back to the position before the move (the board already shows it played).
  await board.value?.setPosition(game.fen.value, true)
}

watch(
  () => props.puzzle,
  puzzle => {
    game.dispose()
    if (puzzle) game.load(puzzle)
  },
  { immediate: true }
)

onBeforeUnmount(() => game.dispose())
</script>

<style scoped>
.puzzle-player {
  display: grid;
  gap: 24px;
  grid-template-columns: minmax(0, 560px) minmax(260px, 1fr);
  align-items: start;
  max-width: 1000px;
  margin: 0 auto;
}
@media (max-width: 800px) {
  .puzzle-player {
    grid-template-columns: 1fr;
  }
}
</style>
