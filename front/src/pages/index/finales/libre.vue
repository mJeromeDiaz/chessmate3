<template>
  <q-page padding>
    <div class="endgames-page q-gutter-y-md">
      <div class="text-h5">{{ $t('endgame.page.title') }}</div>
      <p class="text-body2 text-grey-8">{{ $t('endgame.page.lead') }}</p>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>
      <div v-if="!overview && !error" class="row justify-center q-pa-lg">
        <q-spinner size="2em" />
      </div>

      <div v-if="overview" class="endgames-page__grid">
        <q-card flat bordered data-testid="endgame-lessons">
          <q-card-section
            v-for="family in FAMILIES"
            :key="family"
            class="q-gutter-y-sm"
            :data-testid="`endgame-family-${family}`"
          >
            <div class="row items-center justify-between">
              <div class="text-subtitle1">{{
                $t(`endgame.families.${family}`)
              }}</div>
              <q-btn
                flat
                dense
                no-caps
                size="sm"
                :label="$t('endgame.page.allFamily')"
                :data-testid="`endgame-family-all-${family}`"
                @click="toggleFamily(family)"
              />
            </div>
            <div class="endgames-page__lessons">
              <span
                v-for="lesson in lessonsOf(family)"
                :key="lesson.key"
                class="endgames-page__lesson-wrap"
              >
                <button
                  type="button"
                  class="endgames-page__lesson"
                  :class="{
                    'endgames-page__lesson--on': chosen.includes(lesson.key)
                  }"
                  :aria-pressed="chosen.includes(lesson.key)"
                  :data-testid="`endgame-lesson-${lesson.key}`"
                  @click="toggleLesson(lesson.key)"
                >
                  <span class="endgames-page__lesson-title">{{
                    lessonTitle(lesson.key)
                  }}</span>
                  <span class="endgames-page__lesson-meta">{{
                    lesson.played
                      ? $t('endgame.page.results', {
                          succeeded: lesson.succeeded,
                          played: lesson.played
                        })
                      : $t('endgame.page.neverPlayed')
                  }}</span>
                </button>
                <button
                  type="button"
                  class="endgames-page__demo"
                  :aria-label="
                    $t('endgame.demo.of', { lesson: lessonTitle(lesson.key) })
                  "
                  :data-testid="`endgame-demo-${lesson.key}`"
                  @click="demo = lesson"
                >
                  <q-icon name="play_arrow" size="18px" />
                </button>
              </span>
            </div>
          </q-card-section>
        </q-card>

        <q-card flat bordered data-testid="endgame-settings">
          <q-card-section class="q-gutter-y-sm">
            <div class="text-subtitle1">{{ $t('endgame.page.settings') }}</div>

            <div class="text-subtitle2">{{ $t('endgame.page.mode') }}</div>
            <q-btn-toggle
              v-model="mode"
              no-caps
              unelevated
              toggle-color="primary"
              :options="modeOptions"
              data-testid="endgame-mode"
            />
            <p class="text-caption text-grey q-mb-none">{{
              $t(`endgame.page.modeHints.${mode}`)
            }}</p>

            <div class="text-subtitle2">{{
              $t('endgame.page.level', { label: eloLabel })
            }}</div>
            <q-slider
              v-model="elo"
              :min="overview.rules.minElo"
              :max="overview.rules.maxElo"
              :step="overview.rules.eloStep"
              snap
              data-testid="endgame-elo"
            />
            <p class="text-caption text-grey q-mb-none">
              {{
                perfect
                  ? $t('endgame.page.perfectHint')
                  : $t('endgame.page.maiaHint', { elo })
              }}
              {{
                $t('endgame.page.xp', { xp, failed: overview.rules.xp.failed })
              }}
            </p>
            <p v-if="!perfect" class="text-caption text-grey q-mb-none">{{
              $t('endgame.page.maiaDownload')
            }}</p>

            <p
              v-if="chosen.length === 0"
              class="text-caption text-warning q-mb-none"
              data-testid="endgame-no-lesson"
              >{{ $t('endgame.page.noLesson') }}</p
            >
            <RunLauncher
              v-if="subjectId"
              module="endgames"
              :subject-id="subjectId"
              :config="{ mode, lessons: chosen, elo }"
              :durations="overview.rules.runMinutes"
              :default-minutes="overview.rules.defaultMinutes"
              :disable="chosen.length === 0"
            />
          </q-card-section>
        </q-card>
      </div>
    </div>

    <q-dialog
      :model-value="!!demo"
      :maximized="$q.screen.lt.sm"
      @hide="demo = null"
    >
      <EndgameDemo
        v-if="demo"
        :lesson-key="demo.key"
        :fen="demo.textbook"
        :goal="demo.goal"
        :result="demo.result"
        :hold-moves="demo.holdMoves"
        @close="demo = null"
      />
    </q-dialog>
  </q-page>
</template>

<script setup>
/**
 * The endgames module's free page, `/finales/libre` (docs/ENDGAMES.md, § 9; `/finales` is the
 * path): the lessons by theme with my results, the mode (learn or review), Maia's Elo (« Parfait »
 * at the top: the tablebase's defence), and the run's length; ▶ beside a lesson plays its
 * demonstration (its textbook position). Maia starts downloading as the page opens, before the
 * first move, unless the slider is at « Parfait ».
 */
import { computed, onMounted, ref, watch } from 'vue'
import EndgameDemo from '@/components/endgame/EndgameDemo.vue'
import RunLauncher from '@/components/training/RunLauncher.vue'
import { useMaia } from '@/composables/maia/useMaia'
import { endgameApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/apiError'
import { FAMILIES, lessonTitle } from '@/utils/endgame'
import { t } from '@/i18n'

definePage({
  meta: {
    auth: 'required',
    header: {
      title: 'endgame.page.title',
      subtitle: 'layout.header.kickers.free'
    }
  }
})

/** The lessons chosen last time, in this browser (a convenience: none when unreadable). */
const CHOICE_KEY = 'dontstayrooky.endgames.lessons'

const auth = useAuthStore()
const maia = useMaia()
/** @type {import('vue').Ref<any>} GET /endgames */
const overview = ref(null)
const error = ref('')
/** @type {import('vue').Ref<'learn'|'review'>} */
const mode = ref('learn')
const elo = ref(1500)
/** @type {import('vue').Ref<string[]>} lesson keys */
const chosen = ref([])
/** @type {import('vue').Ref<any>} the lesson whose demonstration plays */
const demo = ref(null)

const subjectId = computed(() => /** @type {any} */ (auth.profile)?.id ?? null)
const perfect = computed(
  () => !!overview.value && elo.value >= overview.value.rules.maxElo
)
const eloLabel = computed(() =>
  perfect.value ? t('endgame.page.perfect') : String(elo.value)
)
const xp = computed(() => {
  const rules = overview.value?.rules.xp
  if (!rules) return 0
  if (perfect.value) return rules.perfect
  return elo.value >= rules.strongElo ? rules.strong : rules.weak
})
const modeOptions = computed(() =>
  ['learn', 'review'].map(value => ({
    value,
    label: t(`endgame.page.modes.${value}`)
  }))
)

/** @param {string} family */
function lessonsOf(family) {
  return (overview.value?.lessons ?? []).filter(
    (/** @type {any} */ lesson) => lesson.family === family
  )
}

/** @param {string} key */
function toggleLesson(key) {
  chosen.value = chosen.value.includes(key)
    ? chosen.value.filter(k => k !== key)
    : [...chosen.value, key]
}

/** All of a theme's lessons, or none when they all were. @param {string} family */
function toggleFamily(family) {
  const keys = lessonsOf(family).map((/** @type {any} */ l) => l.key)
  const all = keys.every((/** @type {string} */ k) => chosen.value.includes(k))
  chosen.value = all
    ? chosen.value.filter(k => !keys.includes(k))
    : [...new Set([...chosen.value, ...keys])]
}

watch(chosen, keys => {
  try {
    localStorage.setItem(CHOICE_KEY, JSON.stringify(keys))
  } catch {
    // Storage unavailable: the choice is not remembered.
  }
})

/** Maia for the next run: downloaded while the player chooses (46 MB once, cached after). */
watch(perfect, isPerfect => {
  if (overview.value && !isPerfect) maia.preload().catch(() => {})
})

/**
 * The lessons chosen last time that still exist, the first theme's otherwise.
 *
 * @param {{key: string, family: string}[]} lessons
 * @returns {string[]}
 */
function initialChoice(lessons) {
  const known = new Set(lessons.map((/** @type {any} */ l) => l.key))
  try {
    const saved = JSON.parse(localStorage.getItem(CHOICE_KEY) ?? '[]')
    const kept = Array.isArray(saved) ? saved.filter(k => known.has(k)) : []
    if (kept.length) return kept
  } catch {
    // Unreadable: the default below.
  }
  return lessons
    .filter((/** @type {any} */ l) => l.family === FAMILIES[0])
    .map((/** @type {any} */ l) => l.key)
}

onMounted(async () => {
  if (!subjectId.value) auth.fetchProfile().catch(() => {})
  try {
    const value = await endgameApi.overview()
    elo.value = value.elo
    chosen.value = initialChoice(value.lessons)
    overview.value = value
    if (!perfect.value) maia.preload().catch(() => {})
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>

<style scoped lang="scss">
.endgames-page {
  max-width: 1100px;
  margin: 0 auto;
}

.endgames-page__grid {
  display: grid;
  gap: 16px;

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
    align-items: start;
  }
}

.endgames-page__lessons {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.endgames-page__lesson {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  padding: 8px 12px;
  border-radius: 14px;
  border: 2px solid var(--cm-line);
  background: transparent;
  color: inherit;
  cursor: pointer;
  text-align: left;

  &--on {
    border-color: #e0601a;
    background: #ffebdd;
    color: #1b1530;
  }

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }
}

.endgames-page__lesson-wrap {
  display: inline-flex;
  align-items: stretch;
  gap: 2px;
}

.endgames-page__demo {
  display: grid;
  place-items: center;
  width: 32px;
  border: 2px solid var(--cm-line);
  border-radius: 14px;
  background: transparent;
  color: var(--cm-lime-ink);
  cursor: pointer;

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }
}

.endgames-page__lesson-title {
  font-weight: 700;
  font-size: 13px;
}

.endgames-page__lesson-meta {
  font-size: 11px;
  color: var(--cm-muted);

  .endgames-page__lesson--on & {
    color: #b84a0e;
  }
}
</style>
