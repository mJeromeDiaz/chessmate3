<template>
  <q-page padding>
    <div class="recall-page q-gutter-y-md">
      <div class="text-h5">{{ $t('blindfoldRecall.page.title') }}</div>
      <p class="text-body2 text-grey-8">
        {{
          $t('blindfoldRecall.page.lead', {
            n: overview?.rules.unlockExact ?? 3
          })
        }}
      </p>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>
      <div v-if="!overview && !error" class="row justify-center q-pa-lg">
        <q-spinner size="2em" />
      </div>

      <div v-if="overview" class="recall-page__grid">
        <q-card flat bordered data-testid="recall-map">
          <q-card-section
            v-for="stage in STAGES"
            :key="stage"
            class="q-gutter-y-sm"
            :data-testid="`recall-stage-${stage}`"
          >
            <div class="text-subtitle1">{{
              $t('blindfoldRecall.page.stage', {
                n: stage,
                title: stageTitle(stage)
              })
            }}</div>
            <div class="text-caption text-grey">{{ stageGoal(stage) }}</div>
            <div class="recall-page__levels">
              <button
                v-for="p in levelsOf(stage)"
                :key="p.level"
                type="button"
                class="recall-page__level"
                :class="[
                  `recall-page__level--${stateOf(p)}`,
                  { 'recall-page__level--on': p.level === level }
                ]"
                :disabled="!p.unlocked"
                :aria-pressed="p.level === level"
                :data-testid="`recall-level-${p.level}`"
                :data-state="stateOf(p)"
                @click="level = p.level"
              >
                <span class="recall-page__level-number">{{ p.level }}</span>
                <q-icon
                  v-if="stateOf(p) === 'locked'"
                  name="lock"
                  size="16px"
                />
                <q-icon
                  v-else-if="stateOf(p) === 'passed'"
                  name="check_circle"
                  size="16px"
                />
                <span v-else class="recall-page__level-progress">{{
                  $t('blindfoldRecall.page.progress', {
                    exact: Math.min(p.exact, overview.rules.unlockExact),
                    goal: overview.rules.unlockExact
                  })
                }}</span>
              </button>
            </div>
          </q-card-section>
        </q-card>

        <q-card
          v-if="selected"
          flat
          bordered
          class="recall-page__side"
          data-testid="recall-selected"
        >
          <q-card-section class="q-gutter-y-sm">
            <div class="text-overline text-grey">{{
              $t('blindfoldRecall.page.levelOf', {
                n: selected.level,
                stage: selected.stage
              })
            }}</div>
            <div class="text-h6" data-testid="recall-selected-title">{{
              levelTitle(selected.level)
            }}</div>
            <div class="text-body2">{{ levelPieces(selected.level) }}</div>
            <div class="text-body2 text-grey-8">{{
              $t('blindfoldRecall.page.goal', {
                goal: $t(`blindfoldRecall.levels.${selected.level}.goal`)
              })
            }}</div>
            <div class="text-caption text-grey">{{
              $t('blindfoldRecall.page.time', {
                n: rulesOf(selected.level)?.visibleSeconds ?? 0
              })
            }}</div>
            <div class="text-body2" data-testid="recall-selected-progress">{{
              progressText(selected)
            }}</div>
          </q-card-section>
          <q-separator />
          <q-card-section class="q-gutter-y-sm">
            <div class="text-subtitle2">{{
              $t('blindfoldRecall.page.free')
            }}</div>
            <div class="text-caption text-grey">{{
              $t('blindfoldRecall.page.freeHint')
            }}</div>
            <RunLauncher
              v-if="subjectId"
              module="blindfold_recall"
              :subject-id="subjectId"
              :fixed-minutes="overview.rules.freeBudgetSeconds / 60"
              :config="{ level, mode: 'free' }"
              :label="$t('blindfoldRecall.page.play')"
              icon="play_arrow"
              data-testid="recall-play"
            />
          </q-card-section>
          <q-separator />
          <q-card-section class="q-gutter-y-sm">
            <div class="text-subtitle2">{{
              $t('blindfoldRecall.page.timed')
            }}</div>
            <RunLauncher
              v-if="subjectId"
              module="blindfold_recall"
              :subject-id="subjectId"
              :default-minutes="10"
              :config="{ level, mode: 'timed' }"
              data-testid="recall-timed"
            />
          </q-card-section>
        </q-card>
      </div>
    </div>
  </q-page>
</template>

<script setup>
/**
 * Position recall (docs/BLINDFOLD_RECALL.md): the twenty levels in five stages, each open after
 * the exact rebuilds asked at the one before; the chosen level, played without a clock (one long
 * run, its clock hidden) or in a timed run. The rules and the progress come from the API.
 */
import { computed, onMounted, ref } from 'vue'
import RunLauncher from '@/components/training/RunLauncher.vue'
import { blindfoldApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/apiError'
import {
  STAGES,
  levelPieces,
  levelState,
  levelTitle,
  stageGoal,
  stageTitle
} from '@/utils/blindfoldRecall'
import { t } from '@/i18n'

definePage({
  meta: {
    auth: 'required',
    // Open to a guest (docs/GUEST.md).
    guestOpen: true,
    header: {
      title: 'blindfoldRecall.page.title',
      subtitle: 'layout.header.kickers.free'
    }
  }
})

const auth = useAuthStore()
/** @type {import('vue').Ref<{rules: import('@/utils/blindfoldRecall').RecallRules, levels: import('@/utils/blindfoldRecall').LevelProgress[], highest: number}|null>} GET /blindfold/recall */
const overview = ref(null)
const error = ref('')
const level = ref(1)

const subjectId = computed(() => /** @type {any} */ (auth.profile)?.id ?? null)

const selected = computed(
  () => overview.value?.levels.find(p => p.level === level.value) ?? null
)

/** @param {number} stage */
function levelsOf(stage) {
  return overview.value?.levels.filter(p => p.stage === stage) ?? []
}

/** @param {number} n */
function rulesOf(n) {
  return overview.value?.rules.levels.find(l => l.level === n) ?? null
}

/** @param {import('@/utils/blindfoldRecall').LevelProgress} p */
function stateOf(p) {
  const rules = overview.value?.rules
  return rules ? levelState(p, rules.unlockExact, rules.maxLevel) : 'locked'
}

/** @param {import('@/utils/blindfoldRecall').LevelProgress} p */
function progressText(p) {
  const rules = overview.value?.rules
  if (!rules) return ''
  const played = t('blindfoldRecall.page.played', { n: p.played }, p.played)
  if (p.level >= rules.maxLevel || p.exact >= rules.unlockExact)
    return t('blindfoldRecall.page.passedText', {
      played,
      exact: p.exact
    })
  return t('blindfoldRecall.page.toUnlock', {
    played,
    left: rules.unlockExact - p.exact,
    next: p.level + 1
  })
}

onMounted(async () => {
  if (!subjectId.value) auth.fetchProfile().catch(() => {})
  try {
    overview.value = await blindfoldApi.recall()
    level.value = overview.value.highest
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>

<style scoped lang="scss">
.recall-page {
  max-width: 1100px;
  margin: 0 auto;
}

.recall-page__grid {
  display: grid;
  gap: 16px;

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
    align-items: start;
  }
}

.recall-page__side {
  @media (min-width: $breakpoint-md-min) {
    position: sticky;
    top: 16px;
  }
}

.recall-page__levels {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
}

.recall-page__level {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  padding: 10px 4px;
  border: 2px solid var(--cm-line);
  border-radius: 14px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  cursor: pointer;

  &--passed {
    border-color: var(--cm-success);
    background: var(--cm-success-soft);
  }

  &--locked {
    opacity: 0.55;
    cursor: default;
  }

  &--on {
    border-color: var(--cm-brand);
    box-shadow: 0 0 0 2px var(--cm-brand);
  }
}

.recall-page__level-number {
  font-family: var(--cm-heading);
  font-size: 22px;
  font-weight: 800;
  line-height: 1.1;
}

.recall-page__level-progress {
  font-size: 12px;
  color: var(--cm-muted);
}
</style>
