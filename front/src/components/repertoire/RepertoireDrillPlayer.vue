<template>
  <div
    class="drill-player"
    data-testid="drill-player"
    :data-fen="drill.fen.value"
  >
    <div class="drill-player__board">
      <ChessBoard
        ref="board"
        :fen="drill.fen.value"
        :orientation="drill.orientation.value"
        :movable-color="drill.movableColor.value"
        :highlights="drill.highlights.value"
        :arrows="drill.arrows.value"
        @move="onMove"
      />
    </div>

    <div class="drill-player__panel column q-gutter-sm">
      <slot name="header" />

      <div class="text-subtitle2" data-testid="drill-score">
        {{ unitWord(unit, drill.succeeded.value) }} réussi{{
          drill.succeeded.value > 1 ? 's' : ''
        }}
        · {{ unitWord(unit, drill.failed.value) }} raté{{
          drill.failed.value > 1 ? 's' : ''
        }}
      </div>

      <div class="text-h6" data-testid="drill-label">{{
        labelText(drill.start.value?.label)
      }}</div>

      <q-banner
        v-if="drill.stale.value"
        dense
        rounded
        class="cm-banner--neutral"
        data-testid="drill-stale"
        >Le répertoire a changé : l’unité précédente est ignorée.</q-banner
      >
      <q-banner
        v-if="drill.start.value?.retry"
        dense
        rounded
        class="cm-banner--warning"
        data-testid="drill-retry"
        >Nouvelle tentative : {{ unitName }} raté{{
          unit === 'line' ? 'e' : ''
        }}
        plus tôt dans la séance.</q-banner
      >
      <q-banner
        v-else-if="drill.start.value?.newRound"
        dense
        rounded
        class="cm-banner--info"
        data-testid="drill-new-round"
        >Nouveau tour ({{ drill.start.value.round }}).</q-banner
      >
      <q-banner
        v-if="drill.deviation.value"
        dense
        rounded
        class="cm-banner--warning"
        data-testid="drill-deviation"
        >Déviation :
        {{
          drill.start.value?.label.move ?? drill.deviation.value.san
        }}</q-banner
      >

      <div class="drill-player__moves" data-testid="drill-moves">
        <span
          v-for="(move, i) in drill.moves.value"
          :key="i"
          :class="`drill-move drill-move--${move.kind}`"
          >{{ numbered(i, move.san) }}</span
        >
        <q-btn
          v-if="drill.moves.value.length > 0"
          flat
          dense
          no-caps
          size="sm"
          icon="replay"
          label="Revoir les coups"
          :disable="
            drill.phase.value !== 'playing' && drill.phase.value !== 'unitDone'
          "
          data-testid="drill-replay"
          @click="drill.replay()"
        />
      </div>

      <ProfBubble
        :prof="prof"
        :kicker="bubble.kicker"
        :text="bubble.text"
        :kind="timeline.kind.value"
        :step="timeline.step.value"
        :hint="drill.phase.value === 'correcting'"
        text-testid="drill-status"
      />
      <div
        v-if="drill.comment.value"
        class="text-body2 cm-muted"
        data-testid="drill-comment"
        >{{ drill.comment.value }}</div
      >

      <ResultSheet
        v-if="timeline.kind.value && drill.phase.value === 'unitDone'"
        :kind="timeline.kind.value"
        :step="timeline.step.value"
        :run="timeline.run.value"
        :title="copy.title"
        :sub="copy.sub"
        :xp="runner.xp.value"
        :actions="actions"
        data-testid="drill-result"
      />
      <div v-if="error" class="text-negative">{{ error }}</div>
    </div>
  </div>
</template>

<script setup>
/**
 * The repertoire test in a timed run (docs/REPERTOIRE.md § 15): the board and the unit's moves,
 * driven by `useRepertoireDrill`; the API calls go through the run (`useTimeboxedRun`). Aaron,
 * the module's professor, talks and reacts, and the result sheet closes each unit (design
 * "Animation Puzzle"): a unit succeeded moves on by itself, a failed one waits for "Suivant".
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import ProfBubble from '@/components/feedback/ProfBubble.vue'
import ResultSheet from '@/components/feedback/ResultSheet.vue'
import { useFeedbackTimeline } from '@/composables/feedback/useFeedbackTimeline'
import { useRepertoireDrill } from '@/composables/repertoire/useRepertoireDrill'
import { apiErrorMessage } from '@/utils/apiError'
import { feedbackProf, repertoireCopy } from '@/utils/feedback'
import { labelText, unitWord } from '@/utils/repertoireTest'
import { playOutcomeSound } from '@/utils/sounds'

/** After a unit succeeded, the next one comes by itself once the short success animation played. */
const AUTO_NEXT_MS = 1200

const props = defineProps({
  /** @type {import('vue').PropType<ReturnType<typeof import('@/composables/training/useTimeboxedRun').useTimeboxedRun>>} */
  runner: { type: Object, required: true }
})

const drill = useRepertoireDrill()
const timeline = useFeedbackTimeline()
const board = ref(null)
const loading = ref(false)
const error = ref('')
/** @type {ReturnType<typeof setTimeout>|undefined} */
let autoNext

const unit = computed(() => drill.start.value?.unit ?? 'segment')
const unitName = computed(() => (unit.value === 'line' ? 'Ligne' : 'Tronçon'))

const status = computed(() => {
  switch (drill.phase.value) {
    case 'showing':
      return 'Au tour de l’adversaire…'
    case 'playing':
      return `À toi : coup ${drill.index.value + 1} sur ${drill.total.value}.`
    case 'submitting':
      return 'Vérification…'
    case 'correcting':
      return `Ce n’est pas le coup préparé : joue ${drill.expected.value?.san ?? 'le coup indiqué'}.`
    case 'replaying':
      return 'Revoir les coups…'
    case 'unitDone':
      return drill.outcome.value?.success ? 'Bravo !' : ''
    default:
      return ''
  }
})

/**
 * Move numbers: moves listed from the initial position, or from the question's ply after a
 * reload in the middle of a unit.
 *
 * @param {number} i index in the list
 * @param {string} san
 */
function numbered(i, san) {
  const ply = drill.firstPly.value + i
  const number = Math.floor(ply / 2) + 1
  if (ply % 2 === 0) return `${number}.${san}`
  return i === 0 ? `${number}…${san}` : san
}

/** @param {{uci: string}} move */
async function onMove(move) {
  error.value = ''
  if (drill.phase.value === 'correcting') {
    const verdict = drill.correct(move.uci)
    if (verdict === false) {
      await board.value?.shake()
      await board.value?.setPosition(drill.fen.value, true)
      return
    }
    await follow(verdict)
    return
  }
  const report = drill.attempt(move.uci)
  if (!report) {
    await board.value?.setPosition(drill.fen.value, true)
    return
  }
  let result
  try {
    result = await props.runner.submit(report)
  } catch (e) {
    // Network error: the answer may or may not be recorded; the server says what comes next.
    const message = apiErrorMessage(e)
    await next()
    error.value = message
    return
  }
  const verdict = drill.resolve(result)
  if (verdict === 'correct') await board.value?.shake()
  await follow(verdict)
}

/** @param {import('@/composables/repertoire/useRepertoireDrill').Verdict} verdict */
async function follow(verdict) {
  if (verdict === 'next') await next()
  else if (verdict === 'unitSucceeded') {
    playOutcomeSound('puzzleDone')
    timeline.play('win')
    autoNext = setTimeout(next, AUTO_NEXT_MS)
  } else if (verdict === 'unitFailed') {
    playOutcomeSound('unitFailed')
    timeline.play('miss')
  }
}

/** The prepared move missed in the unit, numbered: "5.Bc4" ('' if none). */
const missedMove = computed(() => {
  const i = drill.moves.value.findIndex(m => m.kind === 'corrected')
  return i >= 0 ? numbered(i, drill.moves.value[i].san) : ''
})

const copy = computed(() =>
  repertoireCopy(timeline.kind.value ?? 'win', {
    unit: unit.value,
    moves: drill.total.value,
    expected: missedMove.value,
    retry: drill.outcome.value?.retry ?? false
  })
)

const reacting = computed(
  () => timeline.kind.value !== null && timeline.step.value >= 2
)

const prof = computed(() =>
  feedbackProf('repertoire', reacting.value ? timeline.kind.value : null)
)

/** What Aaron says: the question, the correction, then the reaction to the unit. */
const bubble = computed(() => {
  if (reacting.value)
    return { kicker: copy.value.kicker, text: copy.value.bubble }
  const kicker =
    drill.phase.value === 'correcting'
      ? 'ATTENTION'
      : drill.phase.value === 'playing'
        ? 'À TOI'
        : 'RÉPERTOIRE'
  return { kicker, text: status.value }
})

/** The sheet's button after a failed unit (a success moves on by itself). */
const actions = computed(() =>
  drill.outcome.value && !drill.outcome.value.success
    ? [
        {
          label: 'Suivant →',
          primary: true,
          loading: loading.value,
          testid: 'drill-next',
          onClick: next
        }
      ]
    : []
)

async function next() {
  if (props.runner.phase.value !== 'running' || loading.value) return
  loading.value = true
  error.value = ''
  try {
    await props.runner.next()
  } catch (e) {
    error.value = apiErrorMessage(e, { 404: 'Séance introuvable.' })
  } finally {
    loading.value = false
  }
}

watch(
  () => props.runner.item.value,
  item => {
    if (item?.type !== 'repertoire_move') return
    // A new unit starts: the sheet of the previous one goes away.
    if (item.data.start) timeline.reset()
    drill.present(item)
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  clearTimeout(autoNext)
  drill.dispose()
})
</script>

<style scoped>
.drill-player {
  display: grid;
  gap: 24px;
  grid-template-columns: minmax(0, 560px) minmax(260px, 1fr);
  align-items: start;
  max-width: 1000px;
  margin: 0 auto;
}
@media (max-width: 800px) {
  .drill-player {
    grid-template-columns: 1fr;
  }
}
.drill-player__moves {
  line-height: 1.8;
}
.drill-move {
  margin-right: 0.4em;
}
.drill-move--context {
  color: #9e9e9e;
}
.drill-move--opponent {
  color: #424242;
}
.drill-move--user {
  font-weight: 600;
}
.drill-move--corrected {
  font-weight: 600;
  color: #c10015;
}
</style>
