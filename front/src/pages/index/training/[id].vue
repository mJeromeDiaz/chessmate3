<template>
  <q-page padding>
    <TierBanner
      v-if="tierBetween && runner.run.value"
      :key="runner.run.value.id"
      :tier="parcoursRun.tier.value"
      :solved="runner.run.value.summary?.successCount ?? 0"
      :count="parcoursRun.count.value"
      :next-name="nextTierName"
      :starting="sessionStep.starting.value"
      :error="sessionStep.error.value"
      @continue="sessionStep.start(String(runner.run.value.parentId))"
    />

    <div
      v-else-if="
        runner.phase.value === 'ended' &&
        runner.run.value &&
        !holdEnd &&
        !(parcoursRun.active.value && !session)
      "
      class="training-page"
    >
      <RunRecap :run="runner.run.value">
        <template #actions>
          <q-btn
            v-if="hasResult"
            outline
            no-caps
            icon="emoji_events"
            :label="$t('training.page.seeResult')"
            data-testid="run-end-open"
            @click="endOpen = true"
          />
          <template v-if="runner.run.value.parentId">
            <q-btn
              v-if="nextStep"
              color="primary"
              no-caps
              unelevated
              icon="skip_next"
              :label="
                $t('training.page.nextModule', {
                  module: stepModule(nextStep)?.title ?? nextStep.module
                })
              "
              :loading="sessionStep.starting.value"
              data-testid="session-next"
              @click="sessionStep.start(runner.run.value.parentId)"
            />
            <q-btn
              :color="nextStep ? undefined : 'primary'"
              :flat="!!nextStep"
              no-caps
              :label="
                nextStep
                  ? $t('training.page.seeSession')
                  : $t('training.page.seeSessionReview')
              "
              :to="`/session/${runner.run.value.parentId}`"
              data-testid="session-open"
            />
            <div v-if="sessionStep.error.value" class="text-negative q-ml-sm">{{
              sessionStep.error.value
            }}</div>
          </template>
          <q-btn
            v-else
            color="primary"
            no-caps
            :label="backLabel(runner.run.value)"
            :to="subjectPath(runner.run.value)"
            data-testid="run-back"
          />
        </template>
      </RunRecap>
      <RepertoireRunUnits
        v-if="isRepertoire"
        :run-id="runner.run.value.id"
        class="q-mt-md"
      />
      <RunResult
        v-if="hasResult && resultRun"
        v-model="endOpen"
        :run="resultRun"
        :reviewable="!parcoursOver"
        :live="seenRunning.has(runner.run.value.id)"
        :next-step="nextStep"
        :starting="sessionStep.starting.value"
        :next-error="sessionStep.error.value"
        @next="sessionStep.start(runner.run.value.parentId)"
      />
      <TreasureChest
        v-if="chestOpen && session?.reward"
        :reward="session.reward"
        :results-label="
          hasResult
            ? $t('training.page.seeMyResults')
            : $t('training.page.seeReview')
        "
        @results="leaveChest(true)"
        @close="leaveChest(false)"
        @shop="chestOpen = false"
      />
    </div>

    <RepertoireDrillPlayer
      v-else-if="
        runner.phase.value === 'running' && isRepertoire && runner.item.value
      "
      :runner="runner"
    >
      <template #header>
        <ParcoursHeader
          v-if="parcoursRun.active.value"
          :tier="parcoursRun.tier.value"
          :tiers="parcoursRun.tiers"
          :name="parcoursRun.name.value"
          :exercise="parcoursRun.exercise.value"
          :count="parcoursRun.count.value"
          @stop="confirmStop"
        />
        <RunHeader
          v-else
          :remaining-ms="runner.remainingMs.value"
          :budget-seconds="runner.run.value?.budgetSeconds ?? 1"
          @stop="confirmStop"
        />
        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </template>
    </RepertoireDrillPlayer>

    <CoordinatesPlayer
      v-else-if="
        (runner.phase.value === 'running' || runner.phase.value === 'timeUp') &&
        runner.item.value?.type === 'coordinates_series'
      "
      :runner="runner"
      :item="runner.item.value"
    >
      <template #header>
        <RunHeader
          :remaining-ms="runner.remainingMs.value"
          :budget-seconds="runner.run.value?.budgetSeconds ?? 1"
          @stop="confirmStop"
        />
        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </template>
    </CoordinatesPlayer>

    <BlindfoldPuzzlePlayer
      v-else-if="
        runner.phase.value === 'running' &&
        runner.item.value?.type === 'blindfold_puzzle'
      "
      ref="blindfoldPlayer"
      :item="runner.item.value"
      :xp="runner.xp.value"
      :actions="blindfoldActions"
      :pardons="{ count: blindfoldPardons, busy: pardonBusy }"
      @resolve="onBlindfoldResolve"
      @pardon="onBlindfoldPardon"
    >
      <template #header>
        <RunHeader
          :remaining-ms="runner.remainingMs.value"
          :budget-seconds="runner.run.value?.budgetSeconds ?? 1"
          @stop="confirmStop"
        >
          <div class="text-subtitle2" data-testid="run-progress">
            {{
              $t('training.page.blindfoldProgress', {
                played: runner.played.value,
                solved: runner.solved.value
              })
            }}
          </div>
        </RunHeader>
        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </template>
    </BlindfoldPuzzlePlayer>

    <EvaluationPlayer
      v-else-if="evalItem && (runner.phase.value === 'running' || holdEnd)"
      ref="evalPlayer"
      :item="evalItem"
      :result="evalResult"
      :xp="runner.xp.value"
      :actions="evaluationActions"
      @resolve="onEvaluationResolve"
    >
      <template #header>
        <RunHeader
          :remaining-ms="runner.remainingMs.value"
          :budget-seconds="runner.run.value?.budgetSeconds ?? 1"
          @stop="confirmStop"
        >
          <div class="text-subtitle2" data-testid="run-progress">
            {{
              $t(
                'training.page.evaluationProgress',
                { n: evalItem.data.index, solved: runner.solved.value },
                runner.solved.value > 1 ? 2 : 1
              )
            }}
          </div>
        </RunHeader>
        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </template>
    </EvaluationPlayer>

    <FreeRunPanel
      v-else-if="
        runner.phase.value === 'running' &&
        runner.item.value?.type === 'free_timer'
      "
      :item="runner.item.value"
    >
      <template #header>
        <RunHeader
          :remaining-ms="runner.remainingMs.value"
          :budget-seconds="runner.run.value?.budgetSeconds ?? 1"
          @stop="confirmStop"
        />
        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </template>
    </FreeRunPanel>

    <PuzzlePlayer
      v-else-if="streakItem && (streakLive || holdEnd)"
      ref="streakPlayer"
      :puzzle="streakItem.data.puzzle"
      :loading="loading"
      after-mistake="showSolution"
      module="puzzle_streak"
      :help="false"
      :aids="streakAids"
      :xp="runner.xp.value"
      :actions="streakActions"
      @resolve="onStreakResolve"
      @aid="onStreakAid"
    >
      <template #header>
        <RunHeader
          v-if="streakItem.data.mode === 'timed'"
          :remaining-ms="runner.remainingMs.value"
          :budget-seconds="runner.run.value?.budgetSeconds ?? 1"
          @stop="confirmStop"
        >
          <div class="text-subtitle2" data-testid="run-progress">
            {{
              $t('training.page.streakTimed', {
                now: streakNow,
                best: streakBest
              })
            }}
          </div>
        </RunHeader>
        <div v-else class="row items-center q-gutter-sm">
          <div class="text-h5 text-weight-medium" data-testid="run-progress">{{
            $t('training.page.streak', { now: streakNow })
          }}</div>
          <q-space />
          <q-btn
            flat
            no-caps
            color="negative"
            icon="stop"
            :label="$t('training.stop')"
            data-testid="run-stop"
            @click="confirmStop"
          />
        </div>
        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </template>

      <template #chips>
        <span class="play-chip" data-testid="streak-level">{{
          streakLevel
        }}</span>
        <span class="play-chip lt-md"
          >{{ $t('training.page.noHelp')
          }}<q-tooltip>{{ $t('training.page.noHelpTip') }}</q-tooltip></span
        >
      </template>
    </PuzzlePlayer>

    <PuzzlePlayer
      v-else-if="parcoursItem && (runner.phase.value === 'running' || holdEnd)"
      :puzzle="parcoursItem.data.puzzle"
      :loading="loading"
      after-mistake="showSolution"
      module="parcours_puzzles"
      :xp="runner.xp.value"
      :actions="parcoursActions"
      @resolve="onParcoursResolve"
    >
      <template #header>
        <ParcoursHeader
          :tier="parcoursItem.data.tier"
          :tiers="parcoursRun.tiers"
          :name="parcoursRun.name.value"
          :exercise="parcoursItem.data.position + 1"
          :count="parcoursItem.data.count"
          @stop="confirmStop"
        />
        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </template>

      <template #chips>
        <span class="play-chip" data-testid="parcours-zone">{{
          $t(
            `parcours.run.zones.${ratingZone(parcoursItem.data.position, parcoursItem.data.count)}`
          )
        }}</span>
      </template>
    </PuzzlePlayer>

    <PuzzlePlayer
      v-else-if="runner.phase.value === 'running' && rushItem"
      :puzzle="rushItem.data.puzzle"
      :loading="loading"
      after-mistake="stop"
      module="puzzle_rush"
      :help="false"
      quick
      @resolve="onRushResolve"
    >
      <template #header>
        <RunHeader
          :remaining-ms="runner.remainingMs.value"
          :budget-seconds="runner.run.value?.budgetSeconds ?? 1"
          @stop="confirmStop"
        >
          <div class="text-subtitle2" data-testid="run-progress">{{
            rushProgress(rushCounts.solved, rushCounts.failed)
          }}</div>
        </RunHeader>
        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </template>

      <template #chips>
        <span class="play-chip" data-testid="rush-level">{{
          $t('puzzleRush.level', { rating: rushItem.data.targetRating })
        }}</span>
        <span class="play-chip lt-md"
          >{{ $t('puzzleRush.oneTry')
          }}<q-tooltip>{{ $t('puzzleRush.oneTryTip') }}</q-tooltip></span
        >
      </template>
    </PuzzlePlayer>

    <PuzzlePlayer
      v-else-if="runner.phase.value === 'running' && puzzle"
      :puzzle="puzzle"
      :loading="loading"
      after-mistake="showSolution"
      :module="runner.run.value?.module ?? 'puzzles'"
      :xp="runner.xp.value"
      :rating-delta="runner.result.value?.data?.ratingDelta ?? null"
      :actions="missActions"
      :replaceable="runner.item.value?.type === 'woodpecker_puzzle'"
      @resolve="onResolve"
      @replace="onReplace"
    >
      <template #header>
        <RunHeader
          :remaining-ms="runner.remainingMs.value"
          :budget-seconds="runner.run.value?.budgetSeconds ?? 1"
          @stop="confirmStop"
        >
          <div class="text-subtitle2" data-testid="run-progress">
            {{
              $t('training.page.puzzlesProgress', {
                played: runner.played.value,
                solved: runner.solved.value
              })
            }}
          </div>
        </RunHeader>
        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </template>

      <template #chips>
        <span class="play-chip lt-md"
          >{{ $t('training.page.oneTry')
          }}<q-tooltip>{{ $t('training.page.oneTryTip') }}</q-tooltip></span
        >
      </template>
    </PuzzlePlayer>

    <div
      v-else
      class="column items-center q-gutter-md q-pa-lg"
      data-testid="run-waiting"
    >
      <q-spinner size="3em" />
      <div v-if="runner.phase.value === 'timeUp'" class="text-h6">{{
        $t('training.page.timeUp')
      }}</div>
      <div v-if="error" class="text-negative">{{ error }}</div>
    </div>

    <PurchaseSheet
      :offer="purchase.sheet.value"
      :balance="shop.state?.balance ?? 0"
      :inventory="shop.state?.inventory ?? {}"
      :busy="purchase.busy.value"
      :error="purchase.error.value"
      @close="purchase.close()"
      @buy="purchase.buy"
    />

    <OvertimeDialog
      :model-value="runner.phase.value === 'decision'"
      :streak="streakNow"
      :decision-ms="runner.decisionMs.value"
      :busy="extending"
      :error="overtimeError"
      @extend="onExtend"
      @end="onEndOvertime"
    />
  </q-page>
</template>

<script setup>
/**
 * A timed run (docs/TRAINING.md): countdown on the server's clock, items one after the other,
 * recap at the end. Reloading or coming back before the end resumes the same run and item. The
 * module decides the player: puzzles (Woodpecker, rated puzzles), the repertoire test
 * (docs/REPERTOIRE.md § 15), free study (a timer), a coordinates series, blindfold puzzles (docs/BLINDFOLD.md),
 * positions to evaluate (docs/EVALUATION.md), a puzzle streak (docs/PUZZLE_STREAK.md: no free
 * help, the store's helps and passes, bought in place when out of stock; its overtime asked in
 * `OvertimeDialog`) or a tier of a parcours (docs/PARCOURS.md: no clock, its count of puzzles or
 * lines; between two tiers, Lizy's banner starts the next one; "Terminer" abandons the parcours)
 * or a puzzle rush (docs/PUZZLE_RUSH.md: one try, no help, the next puzzle at once; the counts and
 * the accuracy while playing, the score only at the end).
 */
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useQuasar } from 'quasar'
import BlindfoldPuzzlePlayer from '@/components/blindfold/BlindfoldPuzzlePlayer.vue'
import CoordinatesPlayer from '@/components/coordinates/CoordinatesPlayer.vue'
import EvaluationPlayer from '@/components/evaluation/EvaluationPlayer.vue'
import PuzzlePlayer from '@/components/puzzle/PuzzlePlayer.vue'
import OvertimeDialog from '@/components/puzzleStreak/OvertimeDialog.vue'
import ParcoursHeader from '@/components/parcours/ParcoursHeader.vue'
import TierBanner from '@/components/parcours/TierBanner.vue'
import PurchaseSheet from '@/components/shop/PurchaseSheet.vue'
import FreeRunPanel from '@/components/training/FreeRunPanel.vue'
import RepertoireDrillPlayer from '@/components/repertoire/RepertoireDrillPlayer.vue'
import RepertoireRunUnits from '@/components/repertoire/RepertoireRunUnits.vue'
import RunHeader from '@/components/training/RunHeader.vue'
import RunRecap from '@/components/training/RunRecap.vue'
import RunResult from '@/components/training/RunResult.vue'
import TreasureChest from '@/components/shop/TreasureChest.vue'
import { useSessionStep } from '@/composables/session/useSessionStep'
import { useRunAlerts } from '@/composables/training/useRunAlerts'
import { useTimeboxedRun } from '@/composables/training/useTimeboxedRun'
import { useAidPurchase } from '@/composables/shop/useAidPurchase'
import { useParcoursRun } from '@/composables/parcours/useParcoursRun'
import {
  puzzleStreakApi,
  sessionApi,
  trainingApi,
  woodpeckerApi
} from '@/services/api'
import { useGamificationStore } from '@/stores/gamification'
import { useShopStore } from '@/stores/shop'
import { useTrainingStore } from '@/stores/training'
import { apiErrorMessage } from '@/utils/apiError'
import { t } from '@/i18n'
import { levelAt } from '@/utils/puzzleStreak'
import { ratingZone, stepPlan, tierName } from '@/utils/parcours'
import { rushProgress } from '@/utils/puzzleRush'
import { stepModule } from '@/utils/session/steps'
import { resultKind } from '@/utils/runResult'
import { chestToOpen } from '@/utils/shopReward'
import { backLabel, subjectPath } from '@/utils/training'

definePage({ meta: { auth: 'required' } })

/**
 * After a solved puzzle, the next one comes by itself once the short success animation played
 * (speed matters); after a miss, the solution and the result sheet wait for "Suivant".
 */
const AUTO_NEXT_MS = 1200

/**
 * A rush's puzzle answered, solved or missed: the next one comes once the professor reacted and
 * the board flashed (the clock is running, so this stays short).
 */
const RUSH_NEXT_MS = 400

/** Item types played on the puzzle board. */
const PUZZLE_ITEMS = ['woodpecker_puzzle', 'puzzle']

/** Modules saying what "Terminer" leaves behind (training.page.stop). */
const STOP_MESSAGES = [
  'repertoire',
  'puzzles',
  'free',
  'coordinates',
  'blindfold',
  'evaluation',
  'puzzle_streak',
  'puzzle_rush'
]
const route = useRoute()
const router = useRouter()
const $q = useQuasar()
const store = useTrainingStore()
const gamification = useGamificationStore()
const shop = useShopStore()
const runner = useTimeboxedRun()
const loading = ref(false)
const error = ref('')

/** A new object for each item: the player starts a new game. */
const puzzle = computed(() =>
  PUZZLE_ITEMS.includes(runner.item.value?.type ?? '')
    ? runner.item.value?.data.puzzle
    : null
)

/** The result sheet's button after a failed item or a failed request (a success moves on by itself). */
const missActions = computed(() =>
  (runner.result.value && !runner.result.value.success) || error.value
    ? [
        {
          label: t('training.page.next'),
          primary: true,
          disable: loading.value,
          testid: 'run-next',
          onClick: next
        }
      ]
    : []
)

/** The store's blindfold pardons held (the item says it), and a pardon being spent. */
const blindfoldPardons = ref(0)
const pardonBusy = ref(false)
watch(
  () => runner.item.value,
  item => {
    if (item?.type === 'blindfold_puzzle')
      blindfoldPardons.value = item.data.pardons ?? 0
  }
)
/** @type {import('vue').Ref<{pardon: () => boolean}|null>} */
const blindfoldPlayer = ref(null)

/** The blindfold puzzle just missed, still pardonable on its sheet (nothing served since). */
const pardonableMiss = computed(() => {
  const result = runner.result.value
  return (
    runner.item.value?.type === 'blindfold_puzzle' &&
    result?.itemId === runner.item.value.id &&
    result.data?.status === 'failed' &&
    !result.data?.pardoned
  )
})

/**
 * A blindfold puzzle never moves on by itself: the final position stays to be looked at. A miss
 * can still be pardoned there (the store's pardon).
 */
const blindfoldActions = computed(() => [
  ...(pardonableMiss.value
    ? [
        {
          label: `Pardonner · ${blindfoldPardons.value}`,
          loading: pardonBusy.value,
          testid: 'blindfold-pardon-miss',
          onClick: onBlindfoldPardon
        }
      ]
    : []),
  {
    label: t('training.page.next'),
    primary: true,
    disable: (!runner.result.value && !error.value) || loading.value,
    testid: 'run-next',
    onClick: next
  }
])

/**
 * "Pardonner" (docs/BLINDFOLD.md): on the puzzle on screen, its mistakes are erased; on the one
 * just missed, it counts as solved and its XP is topped up. Out of stock, the store's sheet opens
 * in place, the clock running on.
 */
async function onBlindfoldPardon() {
  const item = runner.item.value
  const runId = runner.run.value?.id
  if (!item || !runId || runner.phase.value !== 'running' || pardonBusy.value)
    return
  if (blindfoldPardons.value <= 0) {
    await openAidSheet('blindfold_pardon')
    return
  }
  pardonBusy.value = true
  error.value = ''
  try {
    const answer = await trainingApi.pardon(runId, item.id)
    blindfoldPardons.value = answer.data.pardons
    if (answer.data.pardon === 'loss') {
      runner.pardonResult(answer.data.xp)
      $q.notify({
        type: 'positive',
        message: t('training.page.pardoned', { xp: answer.data.xp })
      })
    } else {
      blindfoldPlayer.value?.pardon()
    }
  } catch (e) {
    const detail = String(/** @type {any} */ (e)?.response?.data?.detail ?? '')
    if (detail === 'no_pardon') {
      blindfoldPardons.value = 0
      await openAidSheet('blindfold_pardon')
    } else {
      error.value = apiErrorMessage(e, {
        409:
          detail === 'too_late'
            ? t('training.page.tooLateServed')
            : t('training.page.noPardon')
      })
    }
  } finally {
    pardonBusy.value = false
  }
}

const isRepertoire = computed(() => runner.run.value?.module === 'repertoire')

/**
 * The position being evaluated, kept once the run closed on the last one: its correction stays on
 * screen (`holdEnd`) until "Voir le résultat".
 *
 * @type {import('vue').Ref<import('@/composables/training/useTimeboxedRun').RunItem|null>}
 */
const evalItem = ref(null)
const holdEnd = ref(false)
/** @type {import('vue').Ref<{unlock: () => void}|null>} */
const evalPlayer = ref(null)
watch(
  () => runner.item.value,
  item => {
    if (item?.type === 'evaluation_position') evalItem.value = item
    else if (item) evalItem.value = null
  }
)
/** The verdict and correction of the position on screen. */
const evalResult = computed(() =>
  evalItem.value && runner.result.value?.itemId === evalItem.value.id
    ? runner.result.value.data
    : null
)
const evaluationActions = computed(() => [
  {
    label:
      runner.phase.value === 'running'
        ? t('training.page.nextPosition')
        : t('training.page.seeResult'),
    primary: true,
    disable: loading.value,
    testid: 'run-next',
    onClick: async () => {
      holdEnd.value = false
      if (runner.phase.value === 'running') await next()
    }
  }
])

/**
 * The puzzle streak's puzzle on screen, kept once the run closed on a miss: its solution stays on
 * screen (`holdEnd`) until "Voir le résultat"; also while the run waits for the overtime decision.
 *
 * @type {import('vue').Ref<import('@/composables/training/useTimeboxedRun').RunItem|null>}
 */
const streakItem = ref(null)
watch(
  () => runner.item.value,
  item => {
    if (item?.type === 'streak_puzzle') streakItem.value = item
    else if (item) streakItem.value = null
  }
)
const streakLive = computed(() =>
  ['running', 'timeUp', 'decision'].includes(runner.phase.value)
)
/** The verdict on the streak's puzzle on screen, once answered. */
const streakResult = computed(() =>
  streakItem.value && runner.result.value?.itemId === streakItem.value.id
    ? runner.result.value
    : null
)
/** Puzzles solved in the streak in progress (the broken one's length after a miss). */
const streakNow = computed(
  () => streakResult.value?.data?.streak ?? streakItem.value?.data?.streak ?? 0
)
/** The run's best streak so far. */
const streakBest = computed(() =>
  Math.max(
    streakNow.value,
    streakResult.value?.data?.best ?? 0,
    streakItem.value?.data?.best ?? 0
  )
)
/** The streak's rules, for the difficulty shown (fetched once per page). */
/** @type {import('vue').Ref<import('@/utils/puzzleStreak').StreakRules|null>} */
const streakRules = ref(null)
watch(streakItem, item => {
  if (item && !streakRules.value)
    puzzleStreakApi
      .overview()
      .then(o => (streakRules.value = o.rules))
      .catch(() => {})
})
const streakLevel = computed(() => {
  const item = streakItem.value
  if (!item) return ''
  return streakRules.value
    ? levelAt(item.data.ramp, item.data.streak, streakRules.value)
    : `≈ ${item.data.targetRating}`
})
/** After a miss: a new streak (session step), or the result (the streak page, or overtime). */
const streakActions = computed(() =>
  (streakResult.value &&
    !streakResult.value.success &&
    streakResult.value.data?.status !== 'passed') ||
  error.value
    ? [
        {
          label:
            runner.phase.value === 'ended'
              ? t('training.page.seeResult')
              : error.value
                ? t('training.page.next')
                : t('training.page.newStreak'),
          primary: true,
          disable: loading.value,
          testid: 'run-next',
          onClick: async () => {
            holdEnd.value = false
            if (runner.phase.value === 'running') await next()
          }
        }
      ]
    : []
)

/**
 * A streak's puzzle answered: solved, the next one comes by itself; missed, its solution stays
 * (held when the miss ended the run).
 *
 * @param {string} _outcome
 * @param {{moves: string[], hintLevel: number, solutionShown: boolean}} report
 */
async function onStreakResolve(_outcome, report) {
  holdEnd.value = true
  try {
    const result = await runner.submit(report)
    if (!result || result.success) holdEnd.value = false
    if (result?.success) setTimeout(next, AUTO_NEXT_MS)
  } catch (e) {
    holdEnd.value = false
    error.value = apiErrorMessage(e)
  }
}

/** The puzzle rush's puzzle on screen. */
const rushItem = computed(() =>
  runner.item.value?.type === 'rush_puzzle' ? runner.item.value : null
)
/** Solved and failed so far: the last answer's counts, else the puzzle's (a reload). */
const rushCounts = computed(() => {
  const answered = runner.result.value?.data
  if (typeof answered?.solved === 'number')
    return { solved: answered.solved, failed: answered.failed ?? 0 }
  return {
    solved: rushItem.value?.data.solved ?? 0,
    failed: rushItem.value?.data.failed ?? 0
  }
})

/**
 * A rush's puzzle answered: solved or missed, the next one comes by itself, no solution played
 * (the missed ones are replayed from the result).
 *
 * @param {string} _outcome
 * @param {{moves: string[], hintLevel: number, solutionShown: boolean}} report
 */
async function onRushResolve(_outcome, report) {
  try {
    await runner.submit(report)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
  setTimeout(next, RUSH_NEXT_MS)
}

/**
 * A parcours' puzzle on screen, kept once its tier closed on it: its solution (or its success)
 * stays on screen (`holdEnd`) before Lizy's banner.
 *
 * @type {import('vue').Ref<import('@/composables/training/useTimeboxedRun').RunItem|null>}
 */
const parcoursItem = ref(null)
watch(
  () => runner.item.value,
  item => {
    if (item?.type === 'parcours_puzzle') parcoursItem.value = item
    else if (item) parcoursItem.value = null
  }
)
/** After a miss: the next puzzle, or, the tier over, what comes next. */
const parcoursActions = computed(() => {
  const result = runner.result.value
  const missed =
    !!parcoursItem.value &&
    result?.itemId === parcoursItem.value.id &&
    !result.success
  return missed || error.value
    ? [
        {
          label: t('training.page.next'),
          primary: true,
          disable: loading.value,
          testid: 'run-next',
          onClick: async () => {
            holdEnd.value = false
            if (runner.phase.value === 'running') await next()
          }
        }
      ]
    : []
})

/**
 * A parcours' puzzle answered: solved, the next one comes by itself (the tier's last one shows its
 * success first); missed, its solution waits for "Suivant".
 *
 * @param {string} _outcome
 * @param {{moves: string[], hintLevel: number, solutionShown: boolean}} report
 */
async function onParcoursResolve(_outcome, report) {
  holdEnd.value = true
  try {
    const result = await runner.submit(report)
    if (!result) holdEnd.value = false
    else if (result.success) {
      if (runner.phase.value === 'running') {
        holdEnd.value = false
        setTimeout(next, AUTO_NEXT_MS)
      } else setTimeout(() => (holdEnd.value = false), AUTO_NEXT_MS)
    } else if (runner.phase.value === 'running') holdEnd.value = false
  } catch (e) {
    holdEnd.value = false
    error.value = apiErrorMessage(e)
  }
}

/**
 * The store's aids (docs/PUZZLE_STREAK.md): the stock the server announced with the puzzle, the
 * helps spent on it (one per move to find), the aid being spent.
 */
const aidStock = ref({ help: 0, pass: 0 })
const itemHelps = ref(0)
const aidBusy = ref(/** @type {''|'help'|'pass'} */ (''))
watch(streakItem, item => {
  if (!item) return
  aidStock.value = { help: 0, pass: 0, ...item.data.aids }
  itemHelps.value = item.data.helps ?? 0
})
const streakAids = computed(() => {
  const moves = streakItem.value?.data.puzzle.moves.length ?? 0
  return {
    ...aidStock.value,
    canHelp: itemHelps.value < Math.floor(moves / 2),
    busy: aidBusy.value
  }
})
/** @type {import('vue').Ref<{lightPiece: () => void, pass: () => Promise<void>}|null>} */
const streakPlayer = ref(null)

/**
 * "Aide" or "Pass": spent on the server (the help lights up the piece, the pass plays the
 * solution, then the next puzzle comes); out of stock, the store's sheet opens in place, the
 * clock running on.
 *
 * @param {'help'|'pass'} kind
 */
async function onStreakAid(kind) {
  const item = streakItem.value
  const runId = runner.run.value?.id
  if (!item || !runId || runner.phase.value !== 'running' || aidBusy.value)
    return
  if (aidStock.value[kind] <= 0) {
    await openAidSheet(`puzzle_streak_${kind}`)
    return
  }
  aidBusy.value = kind
  error.value = ''
  try {
    if (kind === 'help') {
      const answer = await trainingApi.help(runId, item.id)
      aidStock.value = answer.data.aids
      itemHelps.value = answer.data.helps
      streakPlayer.value?.lightPiece()
      return
    }
    const result = await runner.submit({ moves: [], pass: true })
    if (result?.data?.status !== 'passed') {
      // Refused (none left after all) or too late: the stock as the store has it.
      if (runner.phase.value === 'running') await syncAidStock()
      return
    }
    aidStock.value = result.data.aids
    await streakPlayer.value?.pass()
    await next()
  } catch (e) {
    const detail = String(/** @type {any} */ (e)?.response?.data?.detail ?? '')
    if (detail === 'no_help') {
      aidStock.value = { ...aidStock.value, help: 0 }
      await openAidSheet(`puzzle_streak_${kind}`)
    } else {
      error.value = apiErrorMessage(e, {
        409:
          detail === 'help_limit'
            ? t('training.page.noMoreHelp')
            : t('training.page.tooLatePlayed')
      })
    }
  } finally {
    aidBusy.value = ''
  }
}

/** The stock of aids from the store (after a refused pass). */
async function syncAidStock() {
  const state = await shop.load().catch(() => null)
  if (!state) return
  aidStock.value = {
    help: state.inventory.puzzle_streak_help ?? 0,
    pass: state.inventory.puzzle_streak_pass ?? 0
  }
}

/** The store's purchase sheet in place, for an aid out of stock (streak aids, blindfold pardons). */
const purchase = useAidPurchase(inventory => {
  aidStock.value = {
    help: inventory.puzzle_streak_help ?? 0,
    pass: inventory.puzzle_streak_pass ?? 0
  }
  blindfoldPardons.value = inventory.blindfold_pardon ?? 0
})

/** @param {string} item */
async function openAidSheet(item) {
  const message = await purchase.open(item)
  if (message) error.value = message
}

const extending = ref(false)
const overtimeError = ref('')

/** Overtime: 5 more minutes on the streak in progress. */
async function onExtend() {
  extending.value = true
  overtimeError.value = ''
  try {
    await runner.extend()
  } catch (e) {
    overtimeError.value = apiErrorMessage(e, {
      409: t('training.page.tooLateDecide')
    })
    await runner.resume(String(route.params.id)).catch(() => {})
  } finally {
    extending.value = false
  }
}

/** Overtime refused: the run ends at its time, the streak counted. */
function onEndOvertime() {
  runner.stop().catch(e => (overtimeError.value = apiErrorMessage(e)))
}

/** @param {{guess: number|null, plan: string|null}} answer */
async function onEvaluationResolve(answer) {
  // Held before the answer leaves: the last one closes the run, and the player must not be
  // unmounted (its sheet lost, the review opened) before the correction is shown.
  holdEnd.value = true
  try {
    const result = await runner.submit({ evaluation: answer })
    if (result) return
    holdEnd.value = false
    evalPlayer.value?.unlock()
  } catch (e) {
    holdEnd.value = false
    error.value = apiErrorMessage(e)
    evalPlayer.value?.unlock()
  }
}

/**
 * @param {string} _outcome
 * @param {{moves: string[], hintLevel: number, solutionShown: boolean}} report
 */
async function onResolve(_outcome, report) {
  try {
    const result = await runner.submit(report)
    if (result?.success) setTimeout(next, AUTO_NEXT_MS)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

/** Woodpecker: the puzzle leaves the set, the run serves its replacement (the clock runs on). */
async function onReplace() {
  const subjectId = runner.run.value?.subjectId
  const current = puzzle.value
  if (!subjectId || !current) return
  error.value = ''
  try {
    await woodpeckerApi.replacePuzzle(subjectId, current.id)
  } catch (e) {
    error.value = apiErrorMessage(e, {
      409: t('training.page.setFinished'),
      422: t('training.page.noOtherPuzzle')
    })
    return
  }
  await next()
}

/**
 * A blindfold puzzle's outcome: no automatic next puzzle, the final position stays on the board.
 *
 * @param {string} _status
 * @param {{moves: string[], hintLevel: number, solutionShown: boolean}} report
 */
async function onBlindfoldResolve(_status, report) {
  try {
    await runner.submit(report)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function next() {
  if (runner.phase.value !== 'running' || loading.value) return
  loading.value = true
  error.value = ''
  try {
    await runner.next()
  } catch (e) {
    error.value = apiErrorMessage(e, { 404: t('training.page.notFound') })
  } finally {
    loading.value = false
  }
}

function confirmStop() {
  if (parcoursRun.active.value) {
    $q.dialog({
      title: t('training.page.stopTitle'),
      message: t('parcours.run.stop'),
      cancel: true
    }).onOk(stopParcours)
    return
  }
  $q.dialog({
    title: t('training.page.stopTitle'),
    message: t(
      `training.page.stop.${STOP_MESSAGES.includes(runner.run.value?.module ?? '') ? runner.run.value?.module : 'default'}`
    ),
    cancel: true
  }).onOk(() => runner.stop().catch(e => (error.value = apiErrorMessage(e))))
}

/** The session this run is a step of, once the run is over. */
/** @type {import('vue').Ref<import('@/utils/session/steps').TrainingSession|null>} */
const session = ref(null)
const sessionStep = useSessionStep()
useRunAlerts(runner, { session })
const parcoursRun = useParcoursRun(runner, session)
/** The parcours abandoned from this page ("Terminer"), its session read again meanwhile. */
const abandonedSession = ref(/** @type {string|null} */ (null))

/** "Terminer" in a parcours: the tier closed, then the whole parcours abandoned (no bonus). */
async function stopParcours() {
  const parentId = runner.run.value?.parentId ?? null
  abandonedSession.value = parentId
  try {
    await runner.stop()
    if (!parentId) return
    session.value = await sessionApi.abandon(parentId)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

/** The session's next module to play, if it goes on. */
const nextStep = computed(() => {
  const s = session.value
  if (!s || s.status !== 'active' || s.id === abandonedSession.value)
    return null
  const step = s.steps[s.currentIndex]
  return step && step.status === 'pending' ? step : null
})

/** A parcours' tier over, the next one to come: Lizy's banner instead of the recap. */
const tierBetween = computed(
  () =>
    runner.phase.value === 'ended' &&
    !holdEnd.value &&
    parcoursRun.active.value &&
    !!nextStep.value
)
const nextTierName = computed(() =>
  nextStep.value ? tierName(stepPlan(nextStep.value)) : ''
)
/** The parcours is over (completed, abandoned or expired): its result is the whole score. */
const parcoursOver = computed(
  () =>
    parcoursRun.active.value &&
    !!session.value?.parcours &&
    (session.value.status !== 'active' ||
      session.value.id === abandonedSession.value)
)
/** The run whose result is shown: this one, or a whole parcours' score on its last run. */
const resultRun = computed(() => {
  const run = runner.run.value
  const score = parcoursRun.score.value
  if (!run || !parcoursOver.value || !score) return run
  return {
    ...run,
    module: 'parcours_puzzles',
    summary: {
      ...run.summary,
      itemCount: score.played,
      successCount: score.solved
    }
  }
})

/**
 * The end-of-run result (`RunResult`): opens when the run is over (also on a run reopened once
 * over), unless nothing was played; for a parcours, only once it is over.
 */
const endOpen = ref(false)
const hasResult = computed(
  () => !!resultRun.value && resultKind(resultRun.value) !== null
)
/** The review waits for the last correction to be left (`holdEnd`). */
let endWaiting = false
/**
 * The chest of the session this run completed (docs/SHOP.md, § 4 bis): between the streak
 * celebration and the result, until opened.
 */
const chestOpen = ref(false)
/** @type {Promise<unknown>|null} the session being read after the run's end */
let sessionLoading = null

function openEnd() {
  if (holdEnd.value) {
    endWaiting = true
    return
  }
  const run = runner.run.value
  if (!hasResult.value && !run?.parentId) return
  // The day's first exercise may have been in this run: its streak celebration comes first.
  gamification
    .celebrateStreak({ afterExercise: false })
    .then(() => sessionLoading)
    .catch(() => {})
    .then(() => {
      if (runner.run.value?.id !== run?.id || tierBetween.value) return
      if (chestToOpen(session.value, shop.seenChests)) chestOpen.value = true
      else if (hasResult.value) endOpen.value = true
    })
}

/**
 * Leaves the chest for the run's result (closed or "Voir mes résultats"); without a result, "Voir
 * le bilan" goes to the session's recap.
 *
 * @param {boolean} toResults
 */
function leaveChest(toResults) {
  chestOpen.value = false
  if (hasResult.value) endOpen.value = true
  else if (toResults && runner.run.value?.parentId)
    router.push(`/session/${runner.run.value.parentId}`)
}

watch(holdEnd, held => {
  if (!held && endWaiting) {
    endWaiting = false
    openEnd()
  }
})
/** Runs seen running on this page: their end is celebrated. */
const seenRunning = reactive(new Set())

// The run is over: nothing in progress any more for the rest of the app; a session step offers
// the next module (the session follows its runs on the server).
watch(
  () => runner.phase.value,
  phase => {
    if (phase === 'running' && runner.run.value)
      seenRunning.add(runner.run.value.id)
    if (phase !== 'ended') return
    if (store.current?.id === runner.run.value?.id) store.current = null
    const parentId = runner.run.value?.parentId
    sessionLoading = parentId
      ? sessionApi
          .get(parentId)
          .then(s => (session.value = s))
          .catch(() => {})
      : null
    openEnd()
  },
  { immediate: true }
)

// Also when a session's next module replaces this run: the page stays, the run changes.
watch(
  () => route.params.id,
  async id => {
    if (!id) return
    loading.value = true
    error.value = ''
    session.value = null
    endOpen.value = false
    chestOpen.value = false
    holdEnd.value = false
    evalItem.value = null
    streakItem.value = null
    parcoursItem.value = null
    try {
      await runner.resume(String(id))
    } catch (e) {
      error.value = apiErrorMessage(e, { 404: t('training.page.notFound') })
    } finally {
      loading.value = false
    }
  },
  { immediate: true }
)
</script>

<style scoped>
.training-page {
  max-width: 700px;
  margin: 0 auto;
}
</style>
