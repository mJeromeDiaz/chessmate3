<template>
  <q-page padding>
    <PuzzlePlayer
      :puzzle="attempt?.puzzle ?? null"
      :loading="loading"
      module="puzzles"
      :xp="store.result ? store.result.xp : undefined"
      :rating-delta="store.result?.ratingDelta ?? null"
      :actions="actions"
      :skippable="!replayId"
      with-settings
      :settings-active="filtersActive(store.filters)"
      @resolve="onResolve"
      @skip="onSkip"
      @settings="settingsOpen = true"
    >
      <template #header>
        <div class="gt-sm row items-center q-gutter-sm puzzle-page__bar">
          <div class="text-h6">{{ $t('puzzle.play.title') }}</div>
          <RatingBadge />
          <q-space />
          <q-btn
            flat
            dense
            no-caps
            icon="history"
            :label="$t('puzzle.settings.history')"
            to="/puzzle/history"
          />
        </div>

        <q-banner
          v-if="store.rating?.lichessImportAvailable"
          rounded
          class="gt-sm cm-banner--info q-mt-sm"
        >
          {{ $t('puzzle.play.lichessLinked') }}
          <template #action>
            <q-btn
              flat
              no-caps
              color="primary"
              :label="$t('puzzle.settings.import')"
              :loading="importing"
              @click="importLichess"
            />
          </template>
        </q-banner>

        <q-banner
          v-if="error"
          rounded
          class="bg-negative text-white q-mt-sm"
          data-testid="puzzle-error"
        >
          {{ error }}
          <template v-if="heldBy" #action>
            <q-btn
              flat
              no-caps
              color="white"
              :label="$t('puzzle.play.joinRun')"
              :to="`/training/${heldBy}`"
              data-testid="puzzle-join-run"
            />
          </template>
        </q-banner>
      </template>

      <template v-if="!replayId && filtersActive(store.filters)" #chips>
        <span
          v-for="key in store.filters.themes"
          :key="key"
          class="play-chip play-chip--accent"
          data-testid="puzzle-theme-filter"
          >{{ store.themeLabel(key) }}</span
        >
        <span
          v-if="store.filters.difficulty !== 'normal'"
          class="play-chip play-chip--accent"
          data-testid="puzzle-difficulty-filter"
          >{{ difficultyLabel }}</span
        >
      </template>

      <template #result>
        <q-btn
          v-if="attempt"
          flat
          dense
          no-caps
          icon="open_in_new"
          :label="$t('puzzle.play.sourceGame')"
          class="self-start"
          :href="attempt.puzzle.gameUrl"
          target="_blank"
          rel="noopener noreferrer"
        />
      </template>

      <template #footer>
        <q-btn
          outline
          no-caps
          icon="timer"
          :label="$t('puzzle.play.timedRun')"
          class="full-width puzzle-page__run"
          data-testid="puzzle-run-open"
          @click="runDialog = true"
        />
        <q-btn
          outline
          no-caps
          icon="bolt"
          :label="$t('puzzleRush.launch.open')"
          class="full-width puzzle-page__run q-mt-sm"
          data-testid="rush-open"
          @click="rushDialog = true"
        />
      </template>
    </PuzzlePlayer>

    <PurchaseSheet
      :offer="purchase.sheet.value"
      :balance="purchase.shop.state?.balance ?? 0"
      :inventory="purchase.shop.state?.inventory ?? {}"
      :busy="purchase.busy.value"
      :error="purchase.error.value"
      @close="purchase.close()"
      @buy="purchase.buy"
    />

    <PuzzleRunDialog v-model="runDialog" />
    <RushLaunchDialog v-model="rushDialog" />
    <PuzzleSettingsDialog v-model="settingsOpen" />
  </q-page>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useQuasar } from 'quasar'
import PuzzlePlayer from '@/components/puzzle/PuzzlePlayer.vue'
import PuzzleRunDialog from '@/components/puzzle/PuzzleRunDialog.vue'
import RushLaunchDialog from '@/components/puzzleRush/RushLaunchDialog.vue'
import PuzzleSettingsDialog from '@/components/puzzle/PuzzleSettingsDialog.vue'
import RatingBadge from '@/components/puzzle/RatingBadge.vue'
import PurchaseSheet from '@/components/shop/PurchaseSheet.vue'
import { useAidPurchase } from '@/composables/shop/useAidPurchase'
import { useGamificationStore } from '@/stores/gamification'
import { usePuzzleStore } from '@/stores/puzzle'
import { useTrainingStore } from '@/stores/training'
import { apiErrorMessage } from '@/utils/apiError'
import { t } from '@/i18n'
import {
  DIFFICULTIES,
  LICHESS_IMPORT_ERRORS,
  filtersActive
} from '@/utils/puzzle'

definePage({ meta: { auth: 'required' } })

const store = usePuzzleStore()
const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const loading = ref(false)
const importing = ref(false)
const error = ref('')
const runDialog = ref(false)
const rushDialog = ref(false)
const settingsOpen = ref(false)
/** The submission in flight: "Passer" waits for it before asking for the next puzzle. */
let submitting = Promise.resolve()
/** The timed run holding the pending puzzle (free play answered 409), to join it. */
const heldBy = ref(/** @type {string|null} */ (null))
const training = useTrainingStore()

/**
 * A 409 of free play means a timed run holds the pending puzzle: point to it.
 *
 * @param {unknown} e
 * @returns {Promise<boolean>} whether it was that
 */
async function heldByRun(e) {
  const response = /** @type {any} */ (e)?.response
  // The API's 409 detail tells it apart from "already submitted".
  if (
    response?.status !== 409 ||
    !/timed run/.test(response.data?.detail ?? '')
  )
    return false
  const run = await training.fetchCurrent().catch(() => null)
  if (!run) return false
  heldBy.value = run.id
  // Free play refused: the pending puzzle is being played in a timed run.
  error.value = t('puzzle.play.heldByRun')
  return true
}

const attempt = computed(() => store.attempt)

const difficultyLabel = computed(
  () =>
    DIFFICULTIES.find(d => d.value === store.filters.difficulty)?.label ?? ''
)

const gamification = useGamificationStore()

/**
 * After the result sheet, the day's first puzzle shows the streak celebration first.
 *
 * @param {() => void} action
 */
function afterStreak(action) {
  return async () => {
    await gamification.celebrateStreak({ afterExercise: !!store.result })
    action()
  }
}

/** The store's pardons for rated puzzles held, and a pardon being spent. */
const pardons = ref(0)
const pardoning = ref(false)
const purchase = useAidPurchase(
  inventory => (pardons.value = inventory.puzzle_pardon ?? 0)
)

/** A rated puzzle just missed that lost rating points: a pardon brings them back. */
const pardonable = computed(
  () =>
    !replayId.value &&
    store.result?.rated === true &&
    store.result.status === 'failed' &&
    !store.result.pardoned &&
    (store.result.ratingDelta ?? 0) < 0
)

/**
 * "Pardonner" on the miss sheet (docs/PUZZLES.md): the rating comes back; out of stock, the
 * store's sheet opens in place.
 */
async function pardon() {
  if (pardoning.value || !pardonable.value) return
  if (pardons.value <= 0) {
    const message = await purchase.open('puzzle_pardon')
    if (message) error.value = message
    return
  }
  pardoning.value = true
  try {
    pardons.value = await store.pardon()
    $q.notify({
      type: 'positive',
      message: t('puzzle.play.pardoned')
    })
  } catch (e) {
    const detail = String(/** @type {any} */ (e)?.response?.data?.detail ?? '')
    if (detail === 'no_pardon') {
      pardons.value = 0
      const message = await purchase.open('puzzle_pardon')
      if (message) error.value = message
    } else {
      error.value = apiErrorMessage(e, {
        409:
          detail === 'too_late'
            ? t('puzzle.play.tooLate')
            : t('puzzle.play.noPardon')
      })
    }
  } finally {
    pardoning.value = false
  }
}

/**
 * The result sheet's buttons: the next puzzle once the submission answered (asking earlier would
 * hand back the same, still pending, attempt), an unrated replay after a failure, and the store's
 * pardon after a rated one.
 */
const actions = computed(() => {
  const next_ = {
    label: t(replayId.value ? 'puzzle.play.backToPuzzles' : 'puzzle.play.next'),
    primary: true,
    disable: !store.result && !error.value,
    testid: 'puzzle-next',
    onClick: afterStreak(next)
  }
  const current = attempt.value
  if (store.result?.status !== 'failed' || !current) return [next_]
  return [
    ...(pardonable.value
      ? [
          {
            label: t('puzzle.play.pardon', { n: pardons.value }),
            loading: pardoning.value,
            testid: 'puzzle-pardon',
            onClick: pardon
          }
        ]
      : []),
    {
      label: t('puzzle.play.retry'),
      testid: 'puzzle-retry',
      onClick: afterStreak(() => replay(current.puzzle.id))
    },
    next_
  ]
})
const replayId = computed(() =>
  typeof route.query.replay === 'string' ? route.query.replay : null
)

/**
 * Submitted as soon as the rated outcome is known: a reload after a mistake can't erase it.
 *
 * @param {string} _outcome
 * @param {{moves: string[], hintLevel: number, solutionShown: boolean}} report
 */
function onResolve(_outcome, report) {
  submitting = store
    .submit(report)
    .then(() => {})
    .catch(async e => {
      if (await heldByRun(e)) return
      error.value = apiErrorMessage(e, {
        409: t('puzzle.play.alreadySubmitted'),
        404: t('puzzle.play.attemptNotFound')
      })
    })
}

/** "Passer" confirmed: once its failure is recorded, straight to the next puzzle. */
async function onSkip() {
  loading.value = true
  await submitting
  if (error.value) {
    loading.value = false
    return
  }
  await afterStreak(next)()
}

/** @param {() => Promise<object>} request */
async function begin(request) {
  loading.value = true
  error.value = ''
  heldBy.value = null
  try {
    await request()
  } catch (e) {
    if (await heldByRun(e)) return
    error.value = apiErrorMessage(e, {
      404: replayId.value
        ? t('puzzle.play.notInHistory')
        : t('puzzle.play.noMatch'),
      422: t('puzzle.play.unknownTheme')
    })
  } finally {
    loading.value = false
  }
}

function next() {
  if (replayId.value) {
    router.replace('/puzzle')
    return
  }
  begin(() => store.next())
}

/** @param {string} id */
function replay(id) {
  begin(() => store.replay(id))
}

async function importLichess() {
  importing.value = true
  try {
    await store.importLichessRating()
  } catch (e) {
    error.value = apiErrorMessage(e, LICHESS_IMPORT_ERRORS)
  } finally {
    importing.value = false
  }
}

watch(replayId, id => (id ? replay(id) : begin(() => store.next())))

onMounted(() => {
  // Read again: the stock may have moved since the menus read it.
  purchase.shop
    .load()
    .then(state => (pardons.value = state?.inventory.puzzle_pardon ?? 0))
    .catch(() => {})
  store.fetchThemes().catch(() => {})
  store.fetchRating().catch(() => {})
  if (replayId.value) replay(replayId.value)
  else begin(() => store.next())
})
</script>

<style scoped>
.puzzle-page__run {
  border-radius: 14px;
  min-height: 44px;
}
</style>
