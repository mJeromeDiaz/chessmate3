<template>
  <div class="coords-player" :data-mode="mode" data-testid="coordinates-player">
    <slot name="header" />

    <div class="coords-player__ask">
      <div class="coords-player__label">{{
        $t(`coordinates.player.${mode}`)
      }}</div>
      <div
        v-if="mode !== 'name'"
        class="coords-player__target"
        data-testid="coordinates-target"
        >{{ series.target.value ?? '—' }}</div
      >
      <div
        v-else
        class="coords-player__target coords-player__target--typed"
        data-testid="coordinates-typed"
        >{{ typed || '·'
        }}<span class="coords-player__caret">{{
          typed.length === 1 ? '_' : ''
        }}</span></div
      >
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

    <div v-if="mode !== 'color'" class="coords-player__board">
      <ChessBoard
        :fen="EMPTY_BOARD"
        :orientation="orientation"
        :coordinates="false"
        :animation-duration="0"
        :highlights="highlights"
        :square-input="mode === 'find'"
        @square="onSquare"
      />
    </div>

    <div
      v-if="mode === 'name'"
      class="coords-player__keypad"
      data-testid="coordinates-keypad"
    >
      <div class="coords-player__keys">
        <button
          v-for="f in FILES"
          :key="f"
          type="button"
          class="coords-player__key"
          :class="{ 'coords-player__key--on': typed === f }"
          :disabled="!playing || typed.length === 1"
          :data-testid="`coordinates-key-${f}`"
          @click="type(f)"
          >{{ f }}</button
        >
      </div>
      <div class="coords-player__keys">
        <button
          v-for="r in RANKS"
          :key="r"
          type="button"
          class="coords-player__key"
          :disabled="!playing || typed.length !== 1"
          :data-testid="`coordinates-key-${r}`"
          @click="type(r)"
          >{{ r }}</button
        >
        <button
          type="button"
          class="coords-player__key coords-player__key--erase"
          :disabled="!typed"
          :aria-label="$t('coordinates.player.erase')"
          data-testid="coordinates-key-erase"
          @click="typed = ''"
          >⌫</button
        >
      </div>
      <div class="coords-player__keyhint">{{
        $t('coordinates.player.keysName')
      }}</div>
    </div>

    <div
      v-if="mode === 'color'"
      class="coords-player__colors"
      data-testid="coordinates-colors"
    >
      <button
        type="button"
        class="coords-player__color coords-player__color--light"
        :disabled="!playing"
        data-testid="coordinates-light"
        @click="onSquare('light')"
        >{{ $t('coordinates.player.light') }}</button
      >
      <button
        type="button"
        class="coords-player__color coords-player__color--dark"
        :disabled="!playing"
        data-testid="coordinates-dark"
        @click="onSquare('dark')"
        >{{ $t('coordinates.player.dark') }}</button
      >
      <div class="coords-player__keyhint">{{
        $t('coordinates.player.keysColor')
      }}</div>
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
      <span v-if="mode !== 'color'">{{
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
 * A coordinates series (docs/COORDINATES.md), in its mode: find the square named on an empty
 * board without coordinates (a click), name the square lit on it (a file then a rank, on the
 * keypad or the keyboard), or tell the colour of the square named, without board (« Claire » /
 * « Foncée », or the keyboard). The answer is judged at once (green, or red with the right answer)
 * and the next square comes straight away; answers go to the server in batches
 * ({@see useCoordinatesSeries}), the last ones before the run closes. Answers stop counting at zero.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import { useCoordinatesSeries } from '@/composables/coordinates/useCoordinatesSeries'
import { ORIENTATIONS, answerText } from '@/utils/coordinates'
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
const FILES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']
const RANKS = ['1', '2', '3', '4', '5', '6', '7', '8']

const series = useCoordinatesSeries(props.runner)
/** @type {import('vue').Ref<import('@/composables/coordinates/useCoordinatesSeries').LastAnswer|null>} */
const feedback = ref(null)
/** The name being typed (name mode): '', a file, then sent with the rank. */
const typed = ref('')
/** @type {ReturnType<typeof setTimeout>|undefined} */
let feedbackTimer

const mode = computed(() => props.item.data.mode ?? 'find')
const playing = computed(() => props.runner.phase.value === 'running')

const orientation = computed(() =>
  props.item.data.orientation === 'black' ? 'black' : 'white'
)

/**
 * The board: the square to name lit (name mode); the answer just given, green, or red and the
 * right square in green.
 */
const highlights = computed(() => {
  const f = feedback.value
  if (!f) {
    const lit = mode.value === 'name' ? series.target.value : null
    return lit ? [{ square: lit, type: 'hint' }] : []
  }
  if (mode.value === 'name')
    return [{ square: f.target, type: f.correct ? 'success' : 'error' }]
  if (f.correct) return [{ square: f.clicked, type: 'success' }]
  return [
    { square: f.clicked, type: 'error' },
    { square: f.target, type: 'success' }
  ]
})

const feedbackText = computed(() => {
  const f = feedback.value
  if (!f) return ' '
  if (f.correct) return t('coordinates.player.right')
  return mode.value === 'color'
    ? t('coordinates.player.wrongColor', {
        square: f.target,
        color: answerText(f.expected)
      })
    : t('coordinates.player.wrong', { square: f.target })
})

/** @param {string} answer a square, or light / dark */
function onSquare(answer) {
  if (!playing.value) return
  const ok = series.answer(answer)
  if (ok === null) return
  feedback.value = series.last.value
  clearTimeout(feedbackTimer)
  feedbackTimer = setTimeout(() => (feedback.value = null), FEEDBACK_MS)
}

/**
 * The keypad (name mode): a file, then a rank sends the square.
 *
 * @param {string} key
 */
function type(key) {
  if (!playing.value) return
  if (FILES.includes(key)) {
    if (typed.value.length === 0) typed.value = key
    return
  }
  if (RANKS.includes(key) && typed.value.length === 1) {
    const square = `${typed.value}${key}`
    typed.value = ''
    onSquare(square)
  }
}

/** The keyboard: letters and digits (name mode), C / F or the arrows (colour mode). */
function onKey(/** @type {KeyboardEvent} */ event) {
  if (event.ctrlKey || event.metaKey || event.altKey) return
  const target = /** @type {HTMLElement|null} */ (event.target)
  if (target?.closest('input, textarea, [contenteditable="true"]')) return
  const key = event.key.toLowerCase()
  if (mode.value === 'name') {
    if (key === 'backspace') typed.value = ''
    else if (FILES.includes(key) || RANKS.includes(key)) type(key)
    else return
  } else if (mode.value === 'color') {
    if (key === 'c' || key === 'arrowleft') onSquare('light')
    else if (key === 'f' || key === 'arrowright') onSquare('dark')
    else return
  } else return
  event.preventDefault()
}

watch(
  () => props.item.id,
  () => {
    typed.value = ''
    series.load(props.item)
  },
  { immediate: true }
)

onMounted(() => window.addEventListener('keydown', onKey))

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
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

.coords-player__target--typed {
  letter-spacing: 0.04em;
}

.coords-player__caret {
  color: var(--cm-faint);
}

.coords-player__keypad,
.coords-player__colors {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.coords-player__keys {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 6px;
}

.coords-player__key {
  width: 44px;
  height: 48px;
  border: none;
  border-radius: 12px;
  background: var(--cm-surface);
  box-shadow:
    inset 0 0 0 2px var(--cm-line),
    0 3px 0 var(--cm-dash);
  color: var(--cm-ink);
  font-family: var(--cm-heading);
  font-size: 20px;
  font-weight: 800;
  cursor: pointer;

  &--on {
    background: var(--cm-brand);
    color: #fff;
    box-shadow: 0 3px 0 var(--cm-brand-deep);
  }

  &--erase {
    font-size: 18px;
  }

  &:disabled {
    opacity: 0.45;
    cursor: default;
  }
}

.coords-player__colors {
  flex-direction: row;
  flex-wrap: wrap;
  justify-content: center;
  gap: 14px;
  padding: 12px 0;
}

.coords-player__color {
  width: min(42vw, 200px);
  height: 140px;
  border: none;
  border-radius: 20px;
  font-family: var(--cm-heading);
  font-size: 24px;
  font-weight: 800;
  cursor: pointer;

  &--light {
    background: #f0d9b5;
    color: #5b3d1f;
    box-shadow: 0 6px 0 #c9ac80;
  }

  &--dark {
    background: #b58863;
    color: #fff;
    box-shadow: 0 6px 0 #8a6342;
  }

  &:active {
    transform: translateY(3px);
  }

  &:disabled {
    opacity: 0.5;
    cursor: default;
  }
}

.coords-player__keyhint {
  flex-basis: 100%;
  text-align: center;
  font-size: 12.5px;
  color: var(--cm-muted);
}

.coords-player__pardon {
  color: #2d8fd9;
  font-weight: 600;
}
</style>
