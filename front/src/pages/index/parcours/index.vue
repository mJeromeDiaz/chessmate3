<template>
  <q-page padding>
    <div class="parcours-page q-gutter-y-md">
      <header class="parcours-page__head">
        <ProfAvatar
          class="parcours-page__prof"
          :image="lizy.bust"
          :bg="lizy.colors.bg"
          :deep="lizy.colors.deep"
          :ink="lizy.colors.ink"
          :glyph="lizy.glyph"
          radius="50%"
        />
        <div>
          <div class="parcours-page__kicker">{{
            $t('parcours.page.kicker')
          }}</div>
          <h1 class="text-h5 q-my-none">{{ $t('parcours.page.title') }}</h1>
        </div>
      </header>
      <p class="text-body2 text-grey-8">{{ $t('parcours.page.lead') }}</p>

      <q-banner
        v-if="current"
        rounded
        class="parcours-page__current"
        data-testid="parcours-current"
      >
        {{ $t('parcours.page.inProgress', { title: current.title }) }}
        <span class="text-grey-8">{{ $t('parcours.page.untilMidnight') }}</span>
        <template #action>
          <q-btn
            color="primary"
            unelevated
            no-caps
            icon="play_arrow"
            :label="$t('parcours.page.resume')"
            :loading="resuming.starting.value"
            data-testid="parcours-resume"
            @click="resume"
          />
        </template>
      </q-banner>

      <div
        v-if="launcher.inProgress.value"
        class="parcours-page__notice"
        data-testid="parcours-in-progress"
      >
        <span>{{ $t('session.builder.inProgress') }}</span>
        <router-link :to="`/session/${launcher.inProgress.value.id}`">{{
          $t('session.builder.resume')
        }}</router-link>
        <button type="button" @click="launcher.abandonAndLaunch()">
          {{ $t('session.builder.abandonAndLaunch') }}
        </button>
      </div>
      <q-banner
        v-if="error || launcher.error.value || resuming.error.value"
        rounded
        class="bg-negative text-white"
        data-testid="parcours-error"
        >{{ error || launcher.error.value || resuming.error.value }}</q-banner
      >
      <div v-if="!overview && !error" class="row justify-center q-pa-lg">
        <q-spinner size="2em" />
      </div>

      <template v-if="overview">
        <section>
          <div class="text-subtitle1 q-mb-sm">{{
            $t('parcours.page.theme')
          }}</div>
          <div class="parcours-page__themes" role="radiogroup">
            <button
              v-for="entry in overview.themes"
              :key="entry.theme"
              type="button"
              role="radio"
              :aria-checked="theme === entry.theme"
              class="parcours-theme cm-card"
              :class="{ 'parcours-theme--on': theme === entry.theme }"
              :data-testid="`parcours-theme-${entry.theme}`"
              @click="theme = entry.theme"
            >
              <span class="parcours-theme__name">{{
                themeName(entry.theme)
              }}</span>
              <span class="parcours-theme__desc">{{
                $t(`parcours.themes.${entry.theme}.desc`)
              }}</span>
              <ol class="parcours-theme__tiers">
                <li v-for="(plan, i) in entry.tiers" :key="i">{{
                  tierName(plan)
                }}</li>
              </ol>
            </button>
          </div>
        </section>

        <section v-if="theme === 'opening'" data-testid="parcours-repertoires">
          <div class="text-subtitle1 q-mb-sm">{{
            $t('parcours.page.repertoire')
          }}</div>
          <div v-if="!overview.repertoires.length" class="text-body2">
            {{ $t('parcours.page.noRepertoire') }}
            <router-link to="/repertoire/new">{{
              $t('parcours.page.createRepertoire')
            }}</router-link>
          </div>
          <q-option-group
            v-else
            v-model="repertoireId"
            :options="repertoireOptions"
            type="radio"
          />
        </section>

        <section>
          <div class="text-subtitle1 q-mb-sm">{{
            $t('parcours.page.effort')
          }}</div>
          <q-btn-toggle
            v-model="effort"
            no-caps
            unelevated
            toggle-color="primary"
            :options="effortOptions"
            data-testid="parcours-effort"
          />
          <div
            v-if="chosenEffort"
            class="text-caption text-grey q-mt-xs"
            data-testid="parcours-effort-line"
            >{{
              $t('parcours.page.effortLine', {
                perTier: chosenEffort.perTier,
                total: chosenEffort.total,
                bonus: chosenEffort.bonusXp
              })
            }}</div
          >
        </section>

        <p class="text-caption text-grey q-mb-none">
          {{
            $t('parcours.page.rating', {
              rating: overview.rating,
              low: Math.max(0, overview.rating - overview.ratingGap),
              high: overview.rating + overview.ratingGap
            })
          }}
          {{ $t('parcours.page.unrated') }}
        </p>

        <q-btn
          color="primary"
          unelevated
          no-caps
          icon="route"
          :label="$t('parcours.page.launch')"
          :disable="!canLaunch"
          :loading="!!launcher.launching.value"
          data-testid="parcours-launch"
          @click="launch"
        />
      </template>
    </div>
  </q-page>
</template>

<script setup>
/**
 * The parcours (docs/PARCOURS.md): a theme (and the repertoire of an opening parcours), an
 * effort, then its five tiers played as a session, the first one started at once. A parcours
 * already in progress today is offered to resume.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import ProfAvatar from '@/components/session/ProfAvatar.vue'
import { usePlanLaunch } from '@/composables/session/usePlanLaunch'
import { useSessionStep } from '@/composables/session/useSessionStep'
import { parcoursApi, sessionApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import {
  effortName,
  launchErrorText,
  themeName,
  tierName
} from '@/utils/parcours'
import { PROFS } from '@/utils/prof/profs'
import { t } from '@/i18n'

definePage({ meta: { auth: 'required' } })

const lizy = PROFS.lizy
const router = useRouter()
const launcher = usePlanLaunch()
const resuming = useSessionStep()
/** @type {import('vue').Ref<import('@/utils/parcours').ParcoursOverview|null>} */
const overview = ref(null)
/** @type {import('vue').Ref<import('@/utils/session/steps').TrainingSession|null>} the parcours in progress today */
const current = ref(null)
const error = ref('')
const theme = ref('mates')
const effort = ref('steady')
/** @type {import('vue').Ref<string|null>} */
const repertoireId = ref(null)

const effortOptions = computed(() =>
  (overview.value?.efforts ?? []).map(e => ({
    value: e.effort,
    label: `${effortName(e.effort)} · ${e.total}`
  }))
)
const chosenEffort = computed(
  () => overview.value?.efforts.find(e => e.effort === effort.value) ?? null
)
/** The repertoires, those without an opening to draw puzzles from greyed out. */
const repertoireOptions = computed(() =>
  (overview.value?.repertoires ?? []).map(r => ({
    value: r.id,
    label: r.opening
      ? `${r.name} · ${r.opening.name}`
      : `${r.name} · ${t('parcours.page.noOpening')}`,
    disable: !r.opening
  }))
)
const canLaunch = computed(
  () =>
    !!overview.value &&
    !!chosenEffort.value &&
    (theme.value !== 'opening' || !!repertoireId.value)
)

// The first repertoire that can make a parcours, chosen for the player.
watch(overview, o => {
  repertoireId.value ??= o?.repertoires.find(r => r.opening)?.id ?? null
})

function launch() {
  if (!canLaunch.value) return
  const payload = {
    theme: theme.value,
    effort: effort.value,
    repertoireId: theme.value === 'opening' ? repertoireId.value : null
  }
  launcher.begin('parcours', () => parcoursApi.launch(payload), launchErrorText)
}

/** The parcours in progress: its tier being played, or the next one started. */
async function resume() {
  const session = current.value
  if (!session) return
  const step = session.steps[session.currentIndex]
  if (step?.status === 'running' && step.runId)
    await router.push(`/training/${step.runId}`)
  else if (!(await resuming.start(session.id)))
    await router.push(`/session/${session.id}`)
}

onMounted(async () => {
  sessionApi
    .current()
    .then(s => (current.value = s?.parcours ? s : null))
    .catch(() => {})
  try {
    overview.value = await parcoursApi.overview()
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>

<style scoped lang="scss">
.parcours-page {
  max-width: 900px;
  margin: 0 auto;
}
.parcours-page__head {
  display: flex;
  align-items: center;
  gap: 16px;
}
.parcours-page__prof {
  width: 72px;
  height: 72px;
  flex: none;
}
@media (min-width: 1024px) {
  .parcours-page__prof {
    width: 104px;
    height: 104px;
  }
}
.parcours-page__kicker {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #b84a0e;
}
.parcours-page__current {
  background: #ffebdd;
  color: #1b1530;
}
.parcours-page__notice {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 16px;
  align-items: center;
  button {
    background: none;
    border: 0;
    padding: 0;
    color: var(--q-primary);
    cursor: pointer;
    text-decoration: underline;
    font: inherit;
  }
}
.parcours-page__themes {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
}
@media (min-width: 600px) {
  .parcours-page__themes {
    grid-template-columns: 1fr 1fr;
  }
}
.parcours-theme {
  display: flex;
  flex-direction: column;
  gap: 4px;
  text-align: left;
  font: inherit;
  color: inherit;
  cursor: pointer;
  border: 2px solid transparent;
}
.parcours-theme--on {
  border-color: #ff8a3d;
}
.parcours-theme__name {
  font-size: 1.1rem;
  font-weight: 700;
}
.parcours-theme__desc {
  font-size: 0.85rem;
  opacity: 0.75;
}
.parcours-theme__tiers {
  margin: 6px 0 0;
  padding-left: 1.3em;
  font-size: 0.85rem;
}
.body--dark .parcours-page__current {
  background: #3a2a1f;
  color: inherit;
}
.body--dark .parcours-page__kicker {
  color: #ffb37f;
}
</style>
