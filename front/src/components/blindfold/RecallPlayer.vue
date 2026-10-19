<template>
  <PlayLayout
    class="recall-player"
    data-testid="recall-player"
    :data-phase="game.phase.value"
    :data-placed="game.placedCount.value"
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
        text-testid="recall-status"
      />
    </template>

    <template #chips>
      <span
        v-for="chip in infoChips"
        :key="chip"
        class="play-chip"
        data-testid="recall-info"
        >{{ chip }}</span
      >
    </template>

    <template #board>
      <div class="recall-player__board">
        <ChessBoard
          :fen="game.fen.value"
          any-position
          :animation-duration="0"
          :show-legal-moves="false"
          :highlights="game.highlights.value"
          :square-input="game.phase.value === 'build'"
          @square="game.click"
        />
        <div
          v-if="game.phase.value === 'hidden'"
          class="recall-player__veil"
          data-testid="recall-veil"
        >
          <div class="recall-player__veil-label">{{
            $t('blindfoldRecall.player.hidden')
          }}</div>
          <div class="recall-player__veil-count">{{
            game.countdown.value
          }}</div>
        </div>
      </div>
    </template>

    <template v-if="game.phase.value === 'show'" #controls>
      <div class="recall-player__count" data-testid="recall-countdown">{{
        $t('blindfoldRecall.player.seconds', { n: game.countdown.value })
      }}</div>
      <q-btn
        unelevated
        no-caps
        class="play-btn play-btn--skip"
        :label="$t('blindfoldRecall.player.memorized')"
        data-testid="recall-memorized"
        @click="game.memorized()"
      />
    </template>
    <template
      v-else-if="['build', 'sending'].includes(game.phase.value)"
      #controls
    >
      <q-btn
        unelevated
        no-caps
        class="play-btn play-btn--hint"
        :label="$t('blindfoldRecall.player.clear')"
        :disable="sending || !game.placedCount.value"
        data-testid="recall-clear"
        @click="game.clear()"
      />
      <q-btn
        unelevated
        no-caps
        class="play-btn play-btn--skip"
        :label="$t('blindfoldRecall.player.done')"
        :loading="sending"
        :disable="sending"
        data-testid="recall-done"
        @click="game.submit()"
      />
    </template>

    <div class="column q-gutter-sm">
      <div
        v-if="announced && game.phase.value !== 'show'"
        class="recall-player__announced"
        data-testid="recall-announced"
      >
        <div class="recall-player__announced-label">{{
          $t('blindfoldRecall.player.announced')
        }}</div>
        <div class="recall-player__announced-line">{{ announced }}</div>
      </div>

      <template v-if="['build', 'sending'].includes(game.phase.value)">
        <PiecePalette
          :model-value="game.selected.value"
          :disable="sending"
          @update:model-value="game.select"
        />
        <div class="text-caption cm-muted" data-testid="recall-placed">{{
          $t(
            'blindfoldRecall.player.placed',
            { n: game.placedCount.value },
            game.placedCount.value
          )
        }}</div>
      </template>

      <template v-if="game.phase.value === 'complete' && game.result.value">
        <q-btn-toggle
          :model-value="game.view.value"
          no-caps
          unelevated
          toggle-color="primary"
          :options="viewOptions"
          data-testid="recall-view"
          @update:model-value="game.show"
        />
        <div class="recall-player__legend text-caption">
          <template v-if="game.view.value === 'answer'">
            <span
              ><i class="recall-player__dot recall-player__dot--ok" />{{
                $t('blindfoldRecall.player.legend.found')
              }}</span
            >
            <span
              ><i class="recall-player__dot recall-player__dot--missing" />{{
                $t('blindfoldRecall.player.legend.missing')
              }}</span
            >
          </template>
          <template v-else>
            <span
              ><i class="recall-player__dot recall-player__dot--ok" />{{
                $t('blindfoldRecall.player.legend.right')
              }}</span
            >
            <span
              ><i class="recall-player__dot recall-player__dot--wrong" />{{
                $t('blindfoldRecall.player.legend.wrong')
              }}</span
            >
          </template>
        </div>
        <div data-testid="recall-result">
          <ResultSheet
            v-if="timeline.kind.value"
            :kind="timeline.kind.value"
            :step="timeline.step.value"
            :run="timeline.run.value"
            :title="copy.title"
            :sub="copy.sub"
            :xp="xp"
            :actions="actions"
            :confetti="game.result.value.status === 'exact'"
          />
        </div>
      </template>
    </div>
  </PlayLayout>
</template>

<script setup>
/**
 * A position to rebuild in a run (docs/BLINDFOLD_RECALL.md): shown for its level's time (or until
 * "J'ai mémorisé"), hidden a few seconds, then rebuilt on an empty board with the palette; at the
 * last levels, moves are announced once it is hidden and the position to rebuild is the one after
 * them. "J'ai terminé" sends the rebuild (`resolve`); the page gives the server's correction back
 * (`result`): the position with the pieces found and missed, or the rebuild with its right and
 * wrong pieces, and the result sheet. The logic lives in {@see useRecall}. Noctis, the blindfold
 * professor, talks and reacts.
 */
import { computed, onBeforeUnmount, watch } from 'vue'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import PlayLayout from '@/components/chess/PlayLayout.vue'
import ProfBubble from '@/components/feedback/ProfBubble.vue'
import ResultSheet from '@/components/feedback/ResultSheet.vue'
import PiecePalette from '@/components/blindfold/PiecePalette.vue'
import { useFeedbackTimeline } from '@/composables/feedback/useFeedbackTimeline'
import { useRecall } from '@/composables/blindfold/useRecall'
import {
  announcedLine,
  levelTitle,
  percentText,
  recallKind
} from '@/utils/blindfoldRecall'
import { feedbackProf } from '@/utils/feedback'
import { playOutcomeSound } from '@/utils/sounds'
import { t } from '@/i18n'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/composables/training/useTimeboxedRun').RunItem>} the blindfold_recall item; a new one starts a new position */
  item: { type: Object, required: true },
  /** @type {import('vue').PropType<import('@/utils/blindfoldRecall').RecallResult|null>} this position's correction, once sent */
  result: { type: Object, default: null },
  /** The submission's `xp` (undefined until it answered). */
  xp: { type: [Number, null], default: undefined },
  /** @type {import('vue').PropType<import('@/components/feedback/ResultSheet.vue').SheetAction[]>} the result sheet's buttons */
  actions: { type: Array, default: () => [] }
})

const emit = defineEmits({
  /** "J'ai terminé": the pieces placed. */
  resolve: placement => Array.isArray(placement)
})

const timeline = useFeedbackTimeline()
const game = useRecall({ onSubmit: placement => emit('resolve', placement) })

const sending = computed(() => game.phase.value === 'sending')

const announced = computed(() =>
  announcedLine(props.item.data.fen, props.item.data.announced ?? [])
)

const viewOptions = computed(() => [
  { value: 'answer', label: t('blindfoldRecall.player.views.answer') },
  { value: 'mine', label: t('blindfoldRecall.player.views.mine') }
])

/** The level and its time to memorize, above the board. */
const infoChips = computed(() => {
  const d = props.item.data
  return [
    t('blindfoldRecall.player.level', { n: d.level }),
    levelTitle(d.level),
    t('blindfoldRecall.player.toMemorize', { n: d.visibleSeconds })
  ]
})

/** What the sheet says: the verdict, the pieces found, the accuracy; a level opened. */
const copy = computed(() => {
  const r = game.result.value
  if (!r) return { title: '', sub: '' }
  const counts = t('blindfoldRecall.player.counts', {
    correct: r.correct,
    expected: r.expected,
    accuracy: percentText(r.accuracy)
  })
  const extra = r.extra
    ? ` · ${t('blindfoldRecall.player.extra', { n: r.extra }, r.extra)}`
    : ''
  return {
    title: t(`blindfoldRecall.player.verdicts.${r.status}`),
    sub:
      counts +
      extra +
      (r.unlockedLevel
        ? ` · ${t('blindfoldRecall.player.unlocked', { n: r.unlockedLevel })}`
        : '')
  }
})

const reacting = computed(
  () => timeline.kind.value !== null && timeline.step.value >= 2
)
const prof = computed(() =>
  feedbackProf('blindfold_recall', reacting.value ? timeline.kind.value : null)
)

/** What the professor says: the phase's instruction, then the reaction to the verdict. */
const bubble = computed(() => {
  const r = game.result.value
  if (reacting.value && r)
    return {
      kicker: t(`blindfoldRecall.player.kickers.${r.status}`),
      text: t(`blindfoldRecall.player.bubbles.${r.status}`)
    }
  switch (game.phase.value) {
    case 'show':
      return {
        kicker: t('blindfoldRecall.player.kickers.memorize'),
        text: t('blindfoldRecall.player.memorizeText')
      }
    case 'hidden':
      return {
        kicker: t('blindfoldRecall.player.kickers.visualize'),
        text: announced.value
          ? t('blindfoldRecall.player.listenText')
          : t('blindfoldRecall.player.visualizeText')
      }
    default:
      return {
        kicker: t('blindfoldRecall.player.kickers.rebuild'),
        text: announced.value
          ? t('blindfoldRecall.player.rebuildAfterText')
          : t('blindfoldRecall.player.rebuildText')
      }
  }
})

watch(
  () => props.item.id,
  () => {
    timeline.reset()
    game.load(/** @type {any} */ (props.item.data))
  },
  { immediate: true }
)

watch(
  () => props.result,
  value => {
    if (!value || game.phase.value === 'complete') return
    game.correct(/** @type {any} */ (value))
    const kind = recallKind(value.status)
    playOutcomeSound(kind === 'miss' ? 'puzzleMissed' : 'puzzleDone')
    timeline.play(kind)
  }
)

onBeforeUnmount(() => game.dispose())

defineExpose({
  /** The submission failed: the rebuild can be sent again (the parent calls it). */
  unlock: () => game.unlock()
})
</script>

<style scoped lang="scss">
.recall-player__board {
  position: relative;
  user-select: none;
}

// The pause, position hidden: the board is covered.
.recall-player__veil {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: var(--cm-ink);
  color: var(--cm-page);
  border-radius: 4px;
  opacity: 0.92;
}

.recall-player__veil-label {
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.recall-player__veil-count {
  font-family: var(--cm-heading);
  font-size: 72px;
  font-weight: 800;
  line-height: 1.1;
}

.recall-player__count {
  flex: none;
  align-self: center;
  font-family: var(--cm-heading);
  font-size: 32px;
  font-weight: 800;
  min-width: 2.2em;
  text-align: center;
}

.recall-player__announced {
  padding: 10px 14px;
  border-radius: 12px;
  background: var(--cm-brand-soft);
  color: var(--cm-ink);
}

.recall-player__announced-label {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--cm-muted);
}

.recall-player__announced-line {
  font-family: var(--cm-heading);
  font-size: 22px;
  font-weight: 800;
}

.recall-player__legend {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;

  span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
}

.recall-player__dot {
  display: inline-block;
  width: 12px;
  height: 12px;
  border-radius: 3px;

  &--ok {
    background: var(--cm-success);
  }

  &--wrong {
    background: var(--cm-danger);
  }

  &--missing {
    border: 2px solid var(--cm-orange-line);
    border-radius: 50%;
  }
}
</style>
