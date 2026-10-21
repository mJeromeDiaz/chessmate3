<template>
  <PlayLayout
    class="vision-player"
    data-testid="vision-player"
    :data-phase="phase"
    :data-ask="item.data.ask"
    :data-follow-up="item.data.followUp ? 'true' : 'false'"
    :data-mood="gargoyle ? mood : null"
  >
    <template v-if="$slots.header" #header>
      <slot name="header" />
    </template>

    <template #prof>
      <ProfBubble
        large
        :prof="prof"
        :kicker="bubble.kicker"
        :text="bubble.text"
        :kind="timeline.kind.value"
        :step="timeline.step.value"
        text-testid="vision-status"
      />
    </template>

    <template #chips>
      <span v-if="phase === 'show'" class="play-chip">{{
        $t('vision.player.toMemorize', { n: item.data.visibleSeconds })
      }}</span>
      <span
        v-if="item.data.followUp && phase !== 'complete'"
        class="play-chip"
        data-testid="vision-same-track"
        >{{ $t('vision.player.sameTrack') }}</span
      >
      <span
        v-if="['ask', 'sending', 'complete'].includes(phase)"
        class="play-chip play-chip--accent"
        data-testid="vision-question"
        >{{ question }}</span
      >
    </template>

    <template #board>
      <BoardDistractions
        class="vision-player__board"
        :level="item.data.distraction ?? 'none'"
        :active="['show', 'hidden', 'announce', 'ask'].includes(phase)"
      >
        <ChessBoard
          :fen="boardFen"
          any-position
          :animation-duration="0"
          :show-legal-moves="false"
          :highlights="highlights"
          :square-input="phase === 'ask' && answersOnBoard"
          @square="onSquare"
        />
        <div
          v-if="
            (phase === 'hidden' || phase === 'announce') && !item.data.keepShown
          "
          class="vision-player__veil"
          data-testid="vision-veil"
        >
          <div class="vision-player__veil-label">{{
            phase === 'announce'
              ? $t('vision.player.announced')
              : $t('vision.player.hidden')
          }}</div>
          <div
            v-if="phase === 'announce'"
            class="vision-player__veil-moves"
            data-testid="vision-announced"
            >{{ announcedSoFar }}</div
          >
        </div>
      </BoardDistractions>
    </template>

    <template v-if="phase === 'show'" #controls>
      <div class="vision-player__count" data-testid="vision-countdown">{{
        $t('vision.player.seconds', { n: countdown })
      }}</div>
      <q-btn
        unelevated
        no-caps
        class="play-btn play-btn--skip"
        :label="$t('vision.player.memorized')"
        data-testid="vision-memorized"
        @click="hide"
      />
    </template>
    <template
      v-else-if="MANY_SQUARES.includes(item.data.ask) && phase !== 'complete'"
      #controls
    >
      <q-btn
        unelevated
        no-caps
        class="play-btn play-btn--skip"
        :label="$t('vision.player.validate')"
        :disable="phase !== 'ask'"
        :loading="phase === 'sending'"
        data-testid="vision-validate"
        @click="send({ squares: chosen })"
      />
    </template>

    <template v-else-if="item.data.choices && phase !== 'complete'" #controls>
      <q-btn
        v-for="choice in item.data.choices"
        :key="choice"
        unelevated
        no-caps
        class="play-btn play-btn--icon"
        :label="choiceText(choice)"
        :disable="phase !== 'ask'"
        :data-testid="`vision-choice-${choice}`"
        @click="send({ choice })"
      />
    </template>

    <div class="column q-gutter-sm">
      <div
        v-if="seconds && ['ask', 'sending'].includes(phase)"
        class="vision-player__clock"
        :class="{ 'vision-player__clock--low': leftMs <= 4000 }"
        role="progressbar"
        :aria-valuenow="Math.ceil(leftMs / 1000)"
        aria-valuemin="0"
        :aria-valuemax="seconds"
        data-testid="vision-clock"
      >
        <div
          class="vision-player__clock-fill"
          :style="{ width: `${(leftMs / (seconds * 1000)) * 100}%` }"
        />
      </div>
      <div
        v-if="
          announcedLine &&
          (['ask', 'sending', 'complete'].includes(phase) ||
            (item.data.keepShown && phase === 'announce'))
        "
        class="vision-player__line"
        data-testid="vision-line"
      >
        <span class="vision-player__line-label">{{
          $t('vision.player.announced')
        }}</span>
        {{ phase === 'announce' ? announcedSoFar : announcedLine }}
      </div>

      <template
        v-if="PIECE_ANSWER.includes(item.data.ask) && phase !== 'complete'"
      >
        <PiecePalette
          :model-value="null"
          :disable="phase !== 'ask'"
          @update:model-value="onPiece"
        />
        <q-btn
          v-if="item.data.ask === 'what'"
          outline
          no-caps
          :label="$t('vision.player.empty')"
          :disable="phase !== 'ask'"
          data-testid="vision-empty"
          @click="send({ placement: [] })"
        />
      </template>

      <div
        v-if="MANY_SQUARES.includes(item.data.ask) && phase === 'ask'"
        class="text-caption cm-muted"
        data-testid="vision-chosen"
      >
        {{ $t('vision.player.selected', { n: chosen.length }, chosen.length) }}
      </div>

      <div v-if="phase === 'complete' && result" data-testid="vision-result">
        <ResultSheet
          :kind="timeline.kind.value ?? (right ? 'win' : 'miss')"
          :step="timeline.step.value"
          :run="timeline.run.value"
          :title="
            right
              ? $t('vision.player.kickers.right')
              : result.status === 'late'
                ? $t('vision.player.kickers.late')
                : $t('vision.player.kickers.wrong')
          "
          :sub="right ? '' : verdict"
          :xp="xp"
          :actions="actions"
          :confetti="right"
        />
      </div>
    </div>
  </PlayLayout>
</template>

<script setup>
/**
 * A question of the vision module (docs/VISION.md): the position shown for its seconds (or until
 * « J'ai mémorisé »), hidden, the moves announced one by one, then the question about the position
 * they reach, answered on an empty board: a click (where is a piece, a piece en prise, where the
 * tracked piece stands), a piece of the palette (« Case vide » too for what stands on a square),
 * several squares then « Valider » (the attackers, the captures or the stops of a path), a choice
 * (a knight's jumps, yes or no). A piece's path is written « Td1, Td5, Txd7 »; a question on the
 * path just asked about (`followUp`) shows nothing again. A boss's distractions play around the
 * board ({@see BoardDistractions}). A question with its time (`seconds`, the Gargouille) shows its
 * bar once asked; at zero an empty answer goes (`pass`). The Gargouille's music plays through the
 * fight, its wolf cries at a miss (the « Sons » preference).
 * The page gives the server's verdict back (`result`): the position reached, the right squares in
 * green and the wrong ones in red, and the result sheet. Noctis, the blindfold professor, talks.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import BoardDistractions from '@/components/mindMap/BoardDistractions.vue'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import PlayLayout from '@/components/chess/PlayLayout.vue'
import PiecePalette from '@/components/blindfold/PiecePalette.vue'
import ProfBubble from '@/components/feedback/ProfBubble.vue'
import ResultSheet from '@/components/feedback/ResultSheet.vue'
import { useFeedbackTimeline } from '@/composables/feedback/useFeedbackTimeline'
import { feedbackProf } from '@/utils/feedback'
import { moodImage } from '@/utils/mindMapPath'
import { numberedLine } from '@/utils/notation'
import {
  playAmbientSound,
  playBossVerdict,
  playOutcomeSound
} from '@/utils/sounds'
import {
  LIT_TARGET,
  MANY_SQUARES,
  ONE_SQUARE,
  PIECE_ANSWER,
  choiceText,
  correctionHighlights,
  gargoyleMood,
  movesText,
  questionText,
  toggleSquare,
  verdictText
} from '@/utils/vision'
import { t } from '@/i18n'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/composables/training/useTimeboxedRun').RunItem>} the vision question; a new one starts again */
  item: { type: Object, required: true },
  /** @type {import('vue').PropType<Record<string, any>|null>} the server's verdict on it */
  result: { type: Object, default: null },
  /** The submission's `xp` (undefined until it answered). */
  xp: { type: [Number, null], default: undefined },
  /** @type {import('vue').PropType<import('@/components/feedback/ResultSheet.vue').SheetAction[]>} the result sheet's buttons */
  actions: { type: Array, default: () => [] }
})

const emit = defineEmits({ resolve: null })

const EMPTY_BOARD = '8/8/8/8/8/8/8/8 w - - 0 1'
/** Between two moves announced, and after the last one. */
const ANNOUNCE_MS = 1500

const timeline = useFeedbackTimeline()

/** @type {import('vue').Ref<'show'|'hidden'|'announce'|'ask'|'sending'|'complete'>} */
const phase = ref('show')
const countdown = ref(0)
/** The moves announced so far. */
const announcedCount = ref(0)
/** The attackers clicked. */
const chosen = ref(/** @type {string[]} */ ([]))
/** @type {ReturnType<typeof setInterval>|undefined} */
let timer
/** @type {ReturnType<typeof setTimeout>|undefined} */
let pause

const right = computed(() => props.result?.status === 'right')
/** A question's time once asked (the Gargouille), null without one. */
const seconds = computed(() => props.item.data.seconds ?? null)
const askedAt = ref(0)
const now = ref(Date.now())
const leftMs = computed(() =>
  seconds.value
    ? Math.max(0, askedAt.value + seconds.value * 1000 - now.value)
    : 0
)
/** @type {ReturnType<typeof setInterval>|undefined} */
let clock
/** The fight's music, stopped when the player leaves. */
let stopMusic = () => {}
/** The whispers under the moves announced (strong distractions). */
let stopWhispers = () => {}
const strong = computed(() => props.item.data.distraction === 'strong')
const answersOnBoard = computed(() =>
  [...ONE_SQUARE, ...MANY_SQUARES].includes(props.item.data.ask)
)
const question = computed(() => questionText(props.item.data))
const verdict = computed(() =>
  props.result ? verdictText(props.item.data, props.result) : ''
)
const sans = computed(() =>
  (props.item.data.announced ?? []).map(
    (/** @type {{san: string}} */ m) => m.san
  )
)
/** @param {string[]} line */
const numbered = line => numberedLine(line, props.item.data.fen)
/** The moves announced (a numbered line, or a piece's path), in the player's letters. */
const announcedLine = computed(() => movesText(props.item.data, numbered))
const announcedSoFar = computed(() =>
  announcedCount.value
    ? movesText(props.item.data, numbered, announcedCount.value)
    : '…'
)

/** The position shown, an empty board to answer on, then the position reached. */
const boardFen = computed(() => {
  if (phase.value === 'show') return props.item.data.fen
  // The Compteur: the position stays in sight while its line is announced.
  if (
    props.item.data.keepShown &&
    (phase.value === 'hidden' || phase.value === 'announce')
  )
    return props.item.data.fen
  if (phase.value === 'complete' && props.result?.finalFen)
    return props.result.finalFen
  return EMPTY_BOARD
})

const highlights = computed(() => {
  if (phase.value === 'complete' && props.result)
    return correctionHighlights(props.item.data, props.result)
  if (!['ask', 'sending'].includes(phase.value)) return []
  const target = props.item.data.target
  const asked =
    target && LIT_TARGET.includes(props.item.data.ask)
      ? [{ square: target, type: 'hint' }]
      : []
  return [
    ...asked,
    ...chosen.value.map(square => ({ square, type: 'lastMove' }))
  ]
})

/** The Gargouille's fight: its face speaks in place of the professor. */
const gargoyle = computed(() => props.item.data.kind === 'gargoyle')
/** The answers right in a row before this question (the fight's, kept from one question to the next). */
const streak = ref(0)
/** The share of the question's time used when answered. */
const answeredRatio = ref(/** @type {number|null} */ (null))
/** The last verdict, counted in the streak when the next question comes. */
let lastRight = /** @type {boolean|null} */ (null)
const mood = computed(() =>
  gargoyleMood({
    phase: phase.value,
    position: props.item.data.position ?? 1,
    count: props.item.data.count ?? 0,
    streak: streak.value,
    result: props.result,
    leftRatio:
      phase.value === 'ask' && seconds.value
        ? leftMs.value / (seconds.value * 1000)
        : 1,
    answeredRatio: answeredRatio.value
  })
)

const prof = computed(() =>
  gargoyle.value
    ? {
        name: t('mindMapPath.characters.gargouille.name'),
        image: moodImage('gargouille', mood.value),
        glyph: '♜︎',
        bg: '#EEEBFA',
        deep: '#6B5BD6',
        ink: '#1B1530',
        accentInk: '#6B5BD6',
        title: ''
      }
    : feedbackProf(
        'blindfold_recall',
        timeline.kind.value !== null && timeline.step.value >= 2
          ? timeline.kind.value
          : null
      )
)

const bubble = computed(() => {
  const key =
    phase.value === 'complete'
      ? right.value
        ? 'right'
        : 'wrong'
      : phase.value === 'show'
        ? 'memorize'
        : phase.value === 'hidden'
          ? 'visualize'
          : phase.value === 'announce'
            ? 'listen'
            : 'ask'
  const text =
    key !== 'ask'
      ? t(`vision.player.texts.${key}`)
      : PIECE_ANSWER.includes(props.item.data.ask)
        ? t(
            `vision.player.texts.${props.item.data.ask === 'what' ? 'askWhat' : 'askPiece'}`
          )
        : MANY_SQUARES.includes(props.item.data.ask)
          ? t('vision.player.texts.askMany')
          : props.item.data.choices
            ? t('vision.player.texts.askChoice')
            : props.item.data.followUp
              ? t('vision.player.texts.followUp')
              : t('vision.player.texts.askClick')
  return { kicker: t(`vision.player.kickers.${key}`), text }
})

function stopTimers() {
  clearInterval(timer)
  clearTimeout(pause)
}

/** The position hidden: the pause, then the moves, then the question. */
function hide() {
  if (phase.value !== 'show') return
  stopTimers()
  phase.value = 'hidden'
  if (strong.value) playAmbientSound('bell', { volume: 0.7 })
  pause = setTimeout(
    () => (sans.value.length ? announce() : (phase.value = 'ask')),
    (props.item.data.hiddenSeconds ?? 2) * 1000
  )
}

function announce() {
  phase.value = 'announce'
  announcedCount.value = 0
  timer = setInterval(() => {
    if (announcedCount.value < sans.value.length) {
      announcedCount.value++
      return
    }
    clearInterval(timer)
    phase.value = 'ask'
  }, ANNOUNCE_MS)
}

/** @param {Record<string, any>} report */
function send(report) {
  if (phase.value !== 'ask') return
  phase.value = 'sending'
  emit('resolve', report)
}

/** @param {string} square */
function onSquare(square) {
  if (phase.value !== 'ask') return
  if (MANY_SQUARES.includes(props.item.data.ask))
    chosen.value = toggleSquare(chosen.value, square)
  else if (ONE_SQUARE.includes(props.item.data.ask)) send({ squares: [square] })
}

/** @param {string|null} piece a FEN letter (the eraser: nothing) */
function onPiece(piece) {
  if (!piece) return
  // The piece gone: a choice, on no square.
  if (props.item.data.ask === 'missing') {
    send({ choice: piece })
    return
  }
  if (!props.item.data.target) return
  send({ placement: [{ square: props.item.data.target, piece }] })
}

/** Lets the player answer again (a refused submission). */
function unlock() {
  if (phase.value === 'sending') phase.value = 'ask'
}

defineExpose({ unlock })

watch(
  () => props.item.id,
  () => {
    stopTimers()
    timeline.reset()
    if (lastRight !== null) streak.value = lastRight ? streak.value + 1 : 0
    lastRight = null
    answeredRatio.value = null
    chosen.value = []
    announcedCount.value = 0
    // Nothing to show: a knight's question, a question on the path just asked about.
    if (!props.item.data.visibleSeconds || props.item.data.followUp) {
      phase.value = 'ask'
      return
    }
    phase.value = 'show'
    countdown.value = props.item.data.visibleSeconds
    timer = setInterval(() => {
      countdown.value--
      if (countdown.value <= 0) hide()
    }, 1000)
  },
  { immediate: true }
)

watch(
  () => props.result,
  value => {
    if (!value || phase.value === 'complete') return
    answeredRatio.value =
      seconds.value && askedAt.value
        ? Math.min(1, (Date.now() - askedAt.value) / (seconds.value * 1000))
        : null
    lastRight = value.status === 'right'
    phase.value = 'complete'
    const kind = value.status === 'right' ? 'win' : 'miss'
    if (kind === 'miss' && strong.value)
      playAmbientSound(value.lives === 0 ? 'evilLaugh' : 'wolf', {
        volume: 0.7
      })
    // A mind map boss (its lives): the bosses' verdicts.
    else if (typeof props.item.data.lives === 'number')
      playBossVerdict(kind !== 'miss')
    else playOutcomeSound(kind === 'miss' ? 'puzzleMissed' : 'puzzleDone')
    timeline.play(kind)
  }
)

// The question's time runs once it is asked; at zero, nothing answered.
watch(phase, value => {
  clearInterval(clock)
  stopWhispers()
  if (value === 'announce' && strong.value)
    stopWhispers = playAmbientSound('whispers', { loop: true, volume: 0.4 })
  if (value !== 'ask' || !seconds.value) return
  askedAt.value = Date.now()
  now.value = askedAt.value
  clock = setInterval(() => {
    now.value = Date.now()
    if (leftMs.value <= 0) {
      clearInterval(clock)
      send({ pass: true })
    }
  }, 100)
})

onMounted(() => {
  if (strong.value)
    stopMusic = playAmbientSound('gargoyleFight', { loop: true, volume: 0.35 })
})

onBeforeUnmount(() => {
  stopTimers()
  clearInterval(clock)
  stopMusic()
  stopWhispers()
})
</script>

<style scoped lang="scss">
.vision-player__board {
  position: relative;
}

.vision-player__veil {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  border-radius: 12px;
  background: color-mix(in srgb, var(--cm-ink) 88%, transparent);
  color: var(--cm-page);
  text-align: center;
}

.vision-player__veil-label {
  font-size: 14px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.vision-player__veil-moves {
  font-family: var(--cm-heading);
  font-size: 28px;
  font-weight: 800;
}

.vision-player__count {
  align-self: center;
  min-width: 56px;
  font-family: var(--cm-heading);
  font-size: 22px;
  font-weight: 800;
  color: var(--cm-ink);
  text-align: center;
}

.vision-player__clock {
  height: 10px;
  border-radius: 999px;
  background: var(--cm-subtle);
  overflow: hidden;

  &--low .vision-player__clock-fill {
    background: #e5484d;
  }
}

.vision-player__clock-fill {
  height: 100%;
  border-radius: inherit;
  background: var(--cm-brand);
  transition: width 0.1s linear;
}

.vision-player__line {
  font-weight: 600;
  color: var(--cm-ink);
}

.vision-player__line-label {
  margin-right: 6px;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cm-muted);
}
</style>
