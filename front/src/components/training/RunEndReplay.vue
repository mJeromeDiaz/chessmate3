<template>
  <div class="run-end-replay" data-testid="run-end-replay">
    <div class="run-end-replay__head">
      <button
        type="button"
        class="run-end-replay__back"
        data-testid="run-end-replay-back"
        @click="emit('back')"
      >
        ← {{ backLabel }}
      </button>
      <span class="run-end-replay__muted">#{{ missed.number }}</span>
    </div>
    <div class="run-end-replay__title">{{ missed.title }}</div>
    <div
      v-if="paused"
      class="run-end-replay__pause"
      data-testid="run-end-replay-pause"
    >
      Session en pause le temps de la révision : ce rejeu ne compte pas.
    </div>

    <PuzzlePlayer
      v-if="puzzle"
      class="run-end-replay__puzzle"
      :puzzle="puzzle"
      :feedback="false"
      stacked
      @complete="finished = true"
    >
      <template #result="{ failed }">
        <div data-testid="run-end-replay-result">{{
          failed
            ? 'Solution vue : retiens le motif.'
            : 'Bien joué, c’est revu !'
        }}</div>
      </template>
    </PuzzlePlayer>

    <template v-else>
      <ChessBoard
        ref="board"
        :fen="replay.fen.value"
        :orientation="replay.orientation.value"
        :movable-color="replay.movableColor.value"
        :highlights="replay.highlights.value"
        :arrows="replay.arrows.value"
        @move="onMove"
      />
      <div class="run-end-replay__status" data-testid="run-end-replay-status">{{
        lineStatus
      }}</div>
    </template>

    <footer class="run-end-replay__footer">
      <button
        v-if="hasNext"
        type="button"
        class="run-end-replay__btn"
        data-testid="run-end-replay-next"
        @click="emit('next')"
      >
        Raté suivant →
      </button>
      <button
        v-else
        type="button"
        class="run-end-replay__btn"
        data-testid="run-end-replay-done"
        @click="emit('back')"
      >
        {{ backLabel }}
      </button>
    </footer>
  </div>
</template>

<script setup>
/**
 * One item of the end-of-run review played again, client side only (docs/TRAINING.md § 7): a
 * puzzle through `PuzzlePlayer` (its outcome is not sent), a repertoire unit from its start
 * position through `useLineReplay`. Nothing is recorded: no rating, cycle, card, activity or
 * session time.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import PuzzlePlayer from '@/components/puzzle/PuzzlePlayer.vue'
import { useLineReplay } from '@/composables/repertoire/useLineReplay'
import { playOutcomeSound } from '@/utils/sounds'

const props = defineProps({
  /** @type {import('vue').PropType<ReturnType<typeof import('@/utils/runEnd').missedItems>[number]>} */
  missed: { type: Object, required: true },
  /** Another missed item comes after this one. */
  hasNext: { type: Boolean, default: false },
  /** The run is a session step: say the session waits. */
  paused: { type: Boolean, default: false },
  /** Where "back" goes, in words. */
  backLabel: { type: String, default: 'Retour au bilan' }
})

const emit = defineEmits({
  back: null,
  next: null,
  /** The item was played to its end. */
  reviewed: null
})

const board = ref(null)
const replay = useLineReplay()
const finished = ref(false)

/** A new object for each item: `PuzzlePlayer` starts a new game. */
const puzzle = computed(() => {
  const data = props.missed.item.data.puzzle
  return data ? { ...data } : null
})

const lineStatus = computed(() => {
  switch (replay.phase.value) {
    case 'showing':
      return 'Au tour de l’adversaire…'
    case 'playing':
      return 'Joue le coup de ton répertoire.'
    case 'correcting':
      return 'Ce n’est pas ton coup : joue celui de la flèche.'
    case 'done':
      return replay.mistakes.value
        ? `Ligne terminée, ${replay.mistakes.value} erreur${replay.mistakes.value > 1 ? 's' : ''}.`
        : 'Ligne terminée sans erreur !'
    case 'invalid':
      return 'Cette ligne ne peut pas être rejouée.'
    default:
      return ''
  }
})

/** @param {{uci: string}} move */
async function onMove(move) {
  const verdict = replay.play(move.uci)
  if (verdict === 'correct') return
  if (verdict === 'done') {
    playOutcomeSound(replay.mistakes.value ? 'unitFailed' : 'puzzleDone')
    finished.value = true
    return
  }
  if (verdict === 'wrong') await board.value?.shake()
  await board.value?.setPosition(replay.fen.value, true)
}

watch(finished, done => {
  if (done) emit('reviewed')
})

watch(
  () => props.missed.item,
  item => {
    finished.value = false
    replay.dispose()
    if (item.type !== 'repertoire_unit') return
    void replay
      .load({
        startFen: item.data.startFen,
        moves: item.data.moves,
        orientation: item.data.orientation
      })
      .then(() => {
        // A line that ends on the opponent's move, or has none of the user's.
        if (replay.phase.value === 'done') finished.value = true
      })
  },
  { immediate: true }
)

onBeforeUnmount(() => replay.dispose())
</script>

<style scoped lang="scss">
.run-end-replay {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.run-end-replay__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.run-end-replay__back {
  padding: 0;
  border: none;
  background: none;
  color: var(--cm-ink);
  font-weight: 700;
  font-size: 13px;
  cursor: pointer;
}

.run-end-replay__muted {
  font-size: 12px;
  color: var(--cm-muted);
}

.run-end-replay__title {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 16px;
}

.run-end-replay__pause {
  padding: 8px 12px;
  border-radius: 12px;
  background: var(--cm-subtle);
  font-size: 12.5px;
}

.run-end-replay__status {
  font-size: 14px;
}

.run-end-replay__footer {
  display: flex;
}

.run-end-replay__btn {
  flex: 1;
  height: 48px;
  border: none;
  border-radius: 16px;
  background: var(--cm-ink);
  color: var(--cm-page);
  font-weight: 700;
  font-size: 14px;
  cursor: pointer;
}
</style>
