<template>
  <div
    class="endgame-detail"
    data-testid="step-detail"
    :data-step="step.key"
    :data-kind="step.kind"
  >
    <div class="endgame-detail__head">
      <div class="endgame-detail__crumb-row">
        <span class="endgame-detail__crumb">{{
          $t(`${texts}.detail.crumb`, {
            chapter: pathChapterName(capacity, chapter)
          })
        }}</span>
        <button
          v-if="closable"
          type="button"
          class="endgame-detail__close"
          :aria-label="$t('mindMapPath.sheet.close')"
          @click="emit('close')"
          >✕</button
        >
      </div>

      <div v-if="boss" class="endgame-detail__boss">
        <BossPortrait
          class="endgame-detail__portrait"
          :character="step.character"
          :mood="beaten ? 'sadness' : 'pride'"
          :label="title"
        />
        <div class="endgame-detail__boss-text">
          <span class="endgame-detail__tag">{{ $t(`${texts}.bossTag`) }}</span>
          <h3 class="endgame-detail__title" data-testid="boss-name">{{
            title
          }}</h3>
          <p class="endgame-detail__tagline">{{ bossLine(step, 'tagline') }}</p>
        </div>
      </div>
      <h3 v-else class="endgame-detail__title">{{ title }}</h3>

      <span
        class="endgame-detail__status"
        :class="{ 'endgame-detail__status--on': statusOn }"
        data-testid="step-detail-status"
        >{{ statusText }}</span
      >
    </div>

    <div class="endgame-detail__body">
      <div v-if="boss" class="endgame-detail__bubble">
        « {{ bossLine(step, 'taunt') }} »
      </div>
      <p class="endgame-detail__goal">{{ stepDesc(capacity, step.key) }}</p>

      <div class="endgame-detail__tiles">
        <div class="endgame-detail__tile">
          <span class="endgame-detail__tile-label">{{ itemsLabel }}</span>
          <span class="endgame-detail__tile-value">{{ positions }}</span>
        </div>
        <div v-if="boss" class="endgame-detail__tile">
          <span class="endgame-detail__tile-label">{{
            $t(`${texts}.detail.lives`)
          }}</span>
          <span class="endgame-detail__tile-value endgame-detail__lives"
            ><q-icon v-for="i in BOSS_LIVES" :key="i" name="favorite"
          /></span>
        </div>
        <div v-else class="endgame-detail__tile" data-testid="step-passes">
          <span class="endgame-detail__tile-label">{{
            $t(`${texts}.detail.passes`)
          }}</span>
          <span class="endgame-detail__tile-value"
            >{{ step.passes }} / {{ step.required }}</span
          >
        </div>
      </div>

      <!-- A lesson: how its next pass is played. -->
      <div v-if="!boss && !challenges" class="endgame-detail__section">
        <span class="endgame-detail__label">{{
          $t(`endgame.path.detail.modes.${mode}.title`)
        }}</span>
        <p class="endgame-detail__text">{{
          $t(`endgame.path.detail.modes.${mode}.text`, {
            variants: LESSON_POSITIONS - 1,
            count: LESSON_POSITIONS
          })
        }}</p>
        <p class="endgame-detail__note">{{
          $t('endgame.path.detail.lessonRule')
        }}</p>
      </div>

      <!-- A tier of the défis: how a pass is played. -->
      <div v-else-if="!boss" class="endgame-detail__section">
        <span class="endgame-detail__label">{{
          $t('challenge.path.detail.passTitle')
        }}</span>
        <p class="endgame-detail__text">{{ challengePassText }}</p>
        <p class="endgame-detail__note">{{
          $t('challenge.path.detail.passRule')
        }}</p>
      </div>

      <!-- A boss: where its positions come from, how it is fought. -->
      <div v-else class="endgame-detail__section">
        <span class="endgame-detail__label">{{
          $t(`${texts}.detail.drawnFrom`)
        }}</span>
        <p class="endgame-detail__text">{{ drawnFrom }}</p>
        <p class="endgame-detail__note">{{
          ashes
            ? $t('endgame.path.detail.ashesRule')
            : $t(`${texts}.detail.bossRule`, {
                from: BOSS_GAPS[0],
                to: BOSS_GAPS[1]
              })
        }}</p>
      </div>

      <div class="endgame-detail__rewards">
        <span v-if="step.crystals" class="endgame-detail__reward"
          ><CrystalGem :size="20" />{{
            $t('mindMapPath.sheet.crystals', { n: step.crystals })
          }}</span
        >
        <span class="endgame-detail__reward endgame-detail__reward--xp">{{
          boss
            ? $t('mindMapPath.sheet.xp', { xp: step.xp })
            : $t(`${texts}.detail.masteryXp`, { xp: step.xp })
        }}</span>
        <span
          v-for="badge in step.badges"
          :key="badge"
          class="endgame-detail__reward"
          ><q-icon name="military_tech" />{{ badgeName(badge) }}</span
        >
      </div>
      <p v-if="boss && step.crystals && beaten" class="endgame-detail__note">{{
        $t(`${texts}.detail.crystalsOnce`)
      }}</p>
      <p
        v-else-if="boss && challenges && !step.crystals && !beaten"
        class="endgame-detail__note"
        data-testid="step-paid"
        >{{ $t('challenge.path.detail.openingPaid') }}</p
      >

      <div v-if="error" class="endgame-detail__error" role="alert">{{
        error
      }}</div>
      <q-btn
        unelevated
        no-caps
        size="lg"
        class="endgame-detail__go"
        :label="buttonLabel"
        :loading="busy"
        :disable="busy"
        :data-testid="boss ? 'boss-fight' : 'step-start'"
        @click="emit('start', step)"
      />
      <q-btn
        v-if="!boss && !challenges && demoable"
        flat
        no-caps
        icon="slideshow"
        class="endgame-detail__demo"
        :label="$t('endgame.demo.button')"
        data-testid="step-demo"
        @click="emit('demo', step)"
      />
    </div>
  </div>
</template>

<script setup>
/**
 * A step of the endgames path in full (design « Parcours echiqueens », its panel), or of the
 * défis' (docs/DEFIS.md, `capacity`): where it is, its state, what it asks (Lizy's explanation for
 * a lesson or a tier, the boss's words for a boss), its positions (puzzles, lines) and passes or
 * lives, how the next pass is played, what it gives, and the button that plays it; a lesson's
 * demonstration below it. Nothing is ever locked.
 */
import { computed } from 'vue'
import BossPortrait from '@/components/mindMap/BossPortrait.vue'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import {
  BOSS_COUNT,
  BOSS_GAPS,
  CHALLENGES,
  isOpening,
  PASS_COUNT,
  stepPlan
} from '@/utils/challengePath'
import {
  ASHES,
  ASHES_POSITIONS,
  BOSS_POSITIONS,
  bossBeaten,
  LESSON_POSITIONS,
  lessonState,
  lessonStatusText,
  lessonsOf,
  nextMode
} from '@/utils/endgamePath'
import {
  badgeName,
  BOSS_LIVES,
  bossLine,
  bossName,
  ENDGAMES,
  isBoss,
  pathChapterName,
  stepDesc,
  stepTitle
} from '@/utils/mindMapPath'
import { t } from '@/i18n'

const props = defineProps({
  /** The path's capacity (the API's): the endgames', or the défis'. */
  capacity: { type: String, default: ENDGAMES },
  /** @type {import('vue').PropType<import('@/utils/mindMapPath').PathStep>} */
  step: { type: Object, required: true },
  /** @type {import('vue').PropType<import('@/utils/mindMapPath').PathChapter>} its chapter */
  chapter: { type: Object, required: true },
  /** Its run being started. */
  busy: { type: Boolean, default: false },
  /** Why it could not be started. */
  error: { type: String, default: '' },
  /** A close button (a phone's sheet). */
  closable: { type: Boolean, default: false },
  /** A lesson's demonstration can be shown (its textbook position known). */
  demoable: { type: Boolean, default: false }
})

const emit = defineEmits(['start', 'close', 'demo'])

const challenges = computed(() => props.capacity === CHALLENGES)
/** The messages of the path's page. */
const texts = computed(() =>
  challenges.value ? 'challenge.path' : 'endgame.path'
)
/** A tier of the défis: puzzles, or lines of an opening's repertoire. */
const lines = computed(
  () => challenges.value && stepPlan(props.step.key).kind === 'lines'
)
const boss = computed(() => isBoss(props.step.kind))
const ashes = computed(() => !challenges.value && props.chapter.key === ASHES)
const beaten = computed(() => boss.value && bossBeaten(props.step))
const mode = computed(() => nextMode(props.step))
const title = computed(() =>
  boss.value ? bossName(props.step) : stepTitle(props.capacity, props.step.key)
)
const itemsLabel = computed(() => {
  if (!challenges.value) return t('endgame.path.detail.positions')
  return lines.value
    ? t('challenge.path.detail.lines')
    : t('challenge.path.detail.puzzles')
})
const positions = computed(() => {
  if (challenges.value) {
    if (boss.value) return BOSS_COUNT
    return lines.value
      ? t('challenge.path.detail.upTo', { n: PASS_COUNT })
      : PASS_COUNT
  }
  if (!boss.value) return LESSON_POSITIONS
  return ashes.value ? ASHES_POSITIONS : BOSS_POSITIONS
})
/** How a tier's pass of the défis is played. */
const challengePassText = computed(() =>
  lines.value
    ? t('challenge.path.detail.passLines', { n: PASS_COUNT })
    : t('challenge.path.detail.passPuzzles', { n: PASS_COUNT })
)
const statusText = computed(() => {
  if (boss.value)
    return beaten.value
      ? t(`${texts.value}.bossBeaten`)
      : t(`${texts.value}.bossReady`)
  return lessonStatusText(props.step, texts.value)
})
const statusOn = computed(() =>
  boss.value ? beaten.value : lessonState(props.step) !== 'new'
)
/** The lessons (tiers) a boss draws its positions from; an opening's boss, its opening's puzzles. */
const drawnFrom = computed(() => {
  if (ashes.value) return t('endgame.path.ashes.all')
  if (challenges.value && isOpening(props.chapter.key))
    return t('challenge.path.detail.openingDrawn', {
      opening: props.chapter.meta?.opening ?? ''
    })
  return lessonsOf(props.chapter)
    .map(s => stepTitle(props.capacity, s.key))
    .join(', ')
})
const buttonLabel = computed(() => {
  if (boss.value)
    return beaten.value
      ? t('mindMapPath.boss.fightAgain')
      : t('mindMapPath.boss.fight')
  const state = lessonState(props.step)
  if (state === 'new') return t(`${texts.value}.detail.start`)
  return state === 'mastered'
    ? t(`${texts.value}.detail.reviewAgain`)
    : t(`${texts.value}.detail.review`)
})
</script>

<style scoped lang="scss">
.endgame-detail {
  display: flex;
  flex-direction: column;
  border-radius: 24px;
  background: var(--cm-surface);
  box-shadow: var(--cm-card-shadow);
  min-width: 0;
}

.endgame-detail__head {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 18px 20px 16px;
  border-bottom: 1px solid var(--cm-line);
}

.endgame-detail__crumb-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.endgame-detail__crumb {
  flex: 1;
  min-width: 0;
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-muted);
}

.endgame-detail__close {
  flex: none;
  width: 40px;
  height: 40px;
  border: none;
  border-radius: 12px;
  background: var(--cm-subtle);
  color: inherit;
  font-size: 15px;
  font-weight: 800;
  cursor: pointer;
}

.endgame-detail__boss {
  display: flex;
  align-items: center;
  gap: 14px;
}

.endgame-detail__portrait {
  flex: none;
  width: 110px;
}

.endgame-detail__boss-text {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
}

.endgame-detail__tag {
  align-self: flex-start;
  padding: 3px 8px;
  border-radius: 6px;
  background: var(--cm-subtle);
  color: var(--cm-ink-soft);
  font-size: 11.5px;
  font-weight: 800;
  letter-spacing: 0.08em;
}

.endgame-detail__title {
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 26px;
  line-height: 1.1;
  text-wrap: balance;
}

.endgame-detail__tagline {
  margin: 0;
  font-size: 13.5px;
  line-height: 1.4;
  color: var(--cm-muted);
}

.endgame-detail__status {
  align-self: flex-start;
  display: inline-flex;
  align-items: center;
  height: 30px;
  padding: 0 12px;
  border-radius: 999px;
  background: var(--cm-inset);
  color: var(--cm-ink-soft);
  font-size: 13px;
  font-weight: 800;

  &--on {
    background: var(--cm-lime-soft);
    color: var(--cm-lime-ink);
  }
}

.endgame-detail__body {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 18px 20px 22px;
}

.endgame-detail__bubble {
  padding: 10px 14px;
  border-radius: 16px;
  background: var(--cm-orange-soft);
  color: var(--cm-orange-ink);
  font-size: 14px;
  font-weight: 700;
  line-height: 1.4;
}

.endgame-detail__goal {
  margin: 0;
  font-size: 15.5px;
  line-height: 1.5;
  text-wrap: pretty;
}

.endgame-detail__tiles {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.endgame-detail__tile {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 10px 14px;
  border-radius: 14px;
  background: var(--cm-inset);
}

.endgame-detail__tile-label {
  font-size: 12.5px;
  font-weight: 700;
  color: var(--cm-muted);
}

.endgame-detail__tile-value {
  font-size: 15px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}

.endgame-detail__lives {
  display: flex;
  gap: 2px;
  color: #e53935;
}

.endgame-detail__section {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.endgame-detail__label {
  font-size: 14px;
  font-weight: 800;
}

.endgame-detail__text {
  margin: 0;
  font-size: 14px;
  line-height: 1.5;
  color: var(--cm-ink-soft);
}

.endgame-detail__note {
  margin: 0;
  font-size: 13px;
  line-height: 1.45;
  color: var(--cm-muted);
}

.endgame-detail__rewards {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.endgame-detail__reward {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--cm-brand-soft);
  color: var(--cm-brand-deep);
  font-size: 13px;
  font-weight: 800;

  &--xp {
    background: var(--cm-lime-soft);
    color: var(--cm-lime-ink);
  }
}

.endgame-detail__error {
  padding: 10px 12px;
  border-radius: 12px;
  background: var(--cm-danger-soft);
  color: var(--cm-danger);
  font-size: 13.5px;
  font-weight: 700;
}

.endgame-detail__demo {
  margin-top: -8px;
  color: var(--cm-lime-ink);
  font-weight: 800;
}

.endgame-detail__go {
  height: 52px;
  border-radius: 16px;
  background: var(--cm-lime);
  color: #1b1530;
  font-weight: 800;
}
</style>
