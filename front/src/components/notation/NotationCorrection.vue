<template>
  <div
    class="notation-correction"
    data-testid="notation-correction"
    :data-frame="frame"
  >
    <div class="notation-correction__table" role="table">
      <div class="notation-correction__head" role="row">
        <span role="columnheader" />
        <span role="columnheader">{{ $t('notation.correction.yours') }}</span>
        <span role="columnheader">{{ $t('notation.correction.right') }}</span>
      </div>
      <button
        v-for="(row, i) in correction.rows"
        :key="i"
        type="button"
        role="row"
        class="notation-correction__row"
        :class="[
          `notation-correction__row--${row.state}`,
          { 'notation-correction__row--now': i === activeRow }
        ]"
        :data-state="row.state"
        :data-testid="`notation-correction-row-${i}`"
        @click="goToRow(i)"
      >
        <span class="notation-correction__number" role="cell">{{
          row.number
        }}</span>
        <span class="notation-correction__yours" role="cell">{{
          row.yours ?? '—'
        }}</span>
        <span class="notation-correction__right" role="cell">{{
          row.right ?? '—'
        }}</span>
      </button>
    </div>
    <div class="notation-correction__nav">
      <q-btn
        flat
        round
        icon="chevron_left"
        :aria-label="$t('notation.correction.prev')"
        :disable="frame === 0"
        data-testid="notation-correction-prev"
        @click="go(frame - 1)"
      />
      <span class="notation-correction__step">{{
        $t('notation.correction.step', { n: frame, total: last })
      }}</span>
      <q-btn
        flat
        round
        icon="chevron_right"
        :aria-label="$t('notation.correction.next')"
        :disable="frame === last"
        data-testid="notation-correction-next"
        @click="go(frame + 1)"
      />
    </div>
  </div>
</template>

<script setup>
/**
 * A wrong written line beside the right one (docs/NOTATION.md, § 3 bis): a row per half-move, what
 * was written and the solution's; the board follows (`board`): the solution replayed once by
 * itself, a longer pause on the mistake, then ‹ › or a row to step through it.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { correctionBoard } from '@/utils/notation'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/notation').Correction>} */
  correction: { type: Object, required: true },
  /** @type {import('vue').PropType<import('@/utils/notation').SanMove[]>} the solution */
  expected: { type: Array, required: true }
})

const emit = defineEmits({
  /** What the board shows: `{fen, highlights, arrows}`. */
  board: payload => typeof payload === 'object'
})

/** Between two half-moves replayed by themselves; on the mistake, longer. */
const REPLAY_MS = 900
const MISTAKE_MS = 2000

/** The solution's half-moves shown played. */
const frame = ref(0)
const last = computed(() => props.correction.fens.length - 1)
/** The row of the half-move on the board (at the mistake, the wrong one's). */
const activeRow = computed(() =>
  frame.value === props.correction.mistakeAt ? frame.value : frame.value - 1
)

/** @type {ReturnType<typeof setTimeout>|undefined} */
let replayTimer

function replay() {
  clearTimeout(replayTimer)
  frame.value = 0
  const step = () => {
    if (frame.value >= last.value) return
    frame.value++
    replayTimer = setTimeout(
      step,
      frame.value === props.correction.mistakeAt ? MISTAKE_MS : REPLAY_MS
    )
  }
  replayTimer = setTimeout(step, REPLAY_MS)
}

/** @param {number} to */
function go(to) {
  clearTimeout(replayTimer)
  frame.value = Math.max(0, Math.min(to, last.value))
}

/** A row: the board after its half-move, or before the wrong one with the right one shown. */
function goToRow(/** @type {number} */ i) {
  go(i === props.correction.mistakeAt ? i : i + 1)
}

watch(
  () => props.correction,
  () => replay(),
  { immediate: true }
)

watch(
  [frame, () => props.correction],
  () =>
    emit(
      'board',
      correctionBoard(props.correction, props.expected, frame.value)
    ),
  { immediate: true }
)

onBeforeUnmount(() => clearTimeout(replayTimer))
</script>

<style scoped lang="scss">
.notation-correction {
  display: flex;
  flex-direction: column;
  gap: 8px;
  width: 100%;
}

.notation-correction__table {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.notation-correction__head,
.notation-correction__row {
  display: grid;
  grid-template-columns: 48px 1fr 1fr;
  align-items: center;
  gap: 8px;
  padding: 4px 10px;
}

.notation-correction__head {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--cm-muted);
}

.notation-correction__row {
  border: none;
  border-radius: 10px;
  background: var(--cm-surface);
  box-shadow: inset 0 0 0 2px var(--cm-line);
  color: var(--cm-ink);
  font-family: var(--cm-heading);
  font-size: 17px;
  font-weight: 700;
  text-align: left;
  cursor: pointer;

  &--now {
    box-shadow: inset 0 0 0 2px var(--cm-brand);
    background: var(--cm-brand-soft);
  }

  &--ok .notation-correction__yours {
    color: var(--cm-success);
  }

  &--wrong .notation-correction__yours,
  &--missing .notation-correction__yours {
    color: var(--cm-danger);
  }

  &--extra .notation-correction__yours {
    color: var(--cm-danger);
    text-decoration: line-through;
  }

  &--after .notation-correction__yours {
    color: var(--cm-muted);
  }
}

.notation-correction__number {
  color: var(--cm-muted);
  font-size: 14px;
}

.notation-correction__right {
  color: var(--cm-success);
}

.notation-correction__nav {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.notation-correction__step {
  min-width: 64px;
  text-align: center;
  font-weight: 700;
  color: var(--cm-muted);
}
</style>
