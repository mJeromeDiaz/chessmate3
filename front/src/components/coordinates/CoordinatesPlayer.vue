<template>
  <div class="coords-player" data-testid="coordinates-player">
    <slot name="header" />

    <div class="coords-player__ask">
      <div class="coords-player__label">{{
        $t('coordinates.player.find')
      }}</div>
      <div class="coords-player__target" data-testid="coordinates-target">{{
        series.target.value ?? '—'
      }}</div>
      <div
        class="coords-player__feedback"
        :class="
          feedback &&
          (feedback.correct
            ? 'coords-player__feedback--ok'
            : 'coords-player__feedback--fail')
        "
        data-testid="coordinates-feedback"
        >{{ feedbackText }}</div
      >
    </div>

    <div class="coords-player__board">
      <ChessBoard
        :fen="EMPTY_BOARD"
        :orientation="orientation"
        :coordinates="false"
        :animation-duration="0"
        :highlights="highlights"
        square-input
        @square="onSquare"
      />
    </div>

    <div class="coords-player__counts" data-testid="coordinates-counts">
      <span
        ><b>{{ series.answered.value }}</b>
        {{ $t('coordinates.player.answers', series.answered.value) }}</span
      >
      <span
        ><b>{{ series.correct.value }}</b>
        {{ $t('coordinates.player.correct', series.correct.value) }}</span
      >
      <span v-if="series.answered.value > 0">{{
        $t('coordinates.player.percent', {
          n: Math.floor((series.correct.value / series.answered.value) * 100)
        })
      }}</span>
      <span>{{
        $t('coordinates.player.bottom', { side: ORIENTATIONS[orientation] })
      }}</span>
      <span
        v-if="item.data.pardon"
        class="coords-player__pardon"
        data-testid="coordinates-pardon"
        >{{ $t('coordinates.player.pardon')
        }}<q-tooltip>{{ $t('coordinates.player.pardonTip') }}</q-tooltip></span
      >
    </div>
  </div>
</template>

<script setup>
/**
 * A coordinates series (docs/COORDINATES.md): an empty board without coordinates, a square's name,
 * a click. The answer is judged at once (green, or red with the right square shown) and the next
 * square comes straight away; answers go to the server in batches ({@see useCoordinatesSeries}),
 * the last ones before the run closes. Clicks stop counting at zero.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import { useCoordinatesSeries } from '@/composables/coordinates/useCoordinatesSeries'
import { ORIENTATIONS } from '@/utils/coordinates'
import { t } from '@/i18n'

const props = defineProps({
  /** The timed run (useTimeboxedRun). */
  runner: { type: Object, required: true },
  /** @type {import('vue').PropType<import('@/composables/training/useTimeboxedRun').RunItem>} the series (coordinates_series) */
  item: { type: Object, required: true }
})

const EMPTY_BOARD = '8/8/8/8/8/8/8/8 w - - 0 1'
/** How long the colours of an answer stay on the board. */
const FEEDBACK_MS = 400

const series = useCoordinatesSeries(props.runner)
/** @type {import('vue').Ref<import('@/composables/coordinates/useCoordinatesSeries').LastAnswer|null>} */
const feedback = ref(null)
/** @type {ReturnType<typeof setTimeout>|undefined} */
let feedbackTimer

const orientation = computed(() =>
  props.item.data.orientation === 'black' ? 'black' : 'white'
)

/** The answer just given, on the board: green, or red and the right square in green. */
const highlights = computed(() => {
  const f = feedback.value
  if (!f) return []
  if (f.correct) return [{ square: f.clicked, type: 'success' }]
  return [
    { square: f.clicked, type: 'error' },
    { square: f.target, type: 'success' }
  ]
})

const feedbackText = computed(() => {
  const f = feedback.value
  if (!f) return ' '
  return f.correct
    ? t('coordinates.player.right')
    : t('coordinates.player.wrong', { square: f.target })
})

/** @param {string} square */
function onSquare(square) {
  if (props.runner.phase.value !== 'running') return
  const ok = series.answer(square)
  if (ok === null) return
  feedback.value = series.last.value
  clearTimeout(feedbackTimer)
  feedbackTimer = setTimeout(() => (feedback.value = null), FEEDBACK_MS)
}

watch(
  () => props.item.id,
  () => series.load(props.item),
  { immediate: true }
)

onBeforeUnmount(() => {
  clearTimeout(feedbackTimer)
  // Leaving the page: what was not sent yet goes now (the run goes on without the page).
  series.flush().catch(() => {})
})
</script>

<style scoped lang="scss">
.coords-player {
  display: flex;
  flex-direction: column;
  gap: 12px;
  max-width: 560px;
  margin: 0 auto;
}

.coords-player__ask {
  text-align: center;
}

.coords-player__label {
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cm-muted);
}

.coords-player__target {
  font-family: var(--cm-heading);
  font-size: 56px;
  font-weight: 800;
  line-height: 1.1;
  color: var(--cm-ink);
}

.coords-player__feedback {
  min-height: 1.4em;
  font-weight: 600;
  white-space: pre;

  &--ok {
    color: var(--cm-success);
  }

  &--fail {
    color: var(--cm-danger);
  }
}

.coords-player__board {
  user-select: none;
}

.coords-player__counts {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 4px 16px;
  color: var(--cm-muted);

  b {
    color: var(--cm-ink);
  }
}

.coords-player__pardon {
  color: #2d8fd9;
  font-weight: 600;
}
</style>
