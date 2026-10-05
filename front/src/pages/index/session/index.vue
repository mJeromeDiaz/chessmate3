<template>
  <q-page class="plans-page">
    <div class="plans-page__inner">
      <header class="plans-page__head">
        <h1 class="plans-page__title cm-heading">Mes sessions</h1>
        <q-btn
          unelevated
          no-caps
          color="dark"
          label="+ Nouvelle session"
          to="/session/new"
          data-testid="plans-new"
        />
      </header>

      <router-link
        v-if="current"
        :to="`/session/${current.id}`"
        class="plans-page__current"
        data-testid="plans-current"
      >
        <span
          >Session du jour en cours :
          <strong>{{ current.title || 'Session sans titre' }}</strong> ·
          {{ sessionProgressText(current) }}</span
        >
        <span class="plans-page__current-go">Reprendre →</span>
      </router-link>

      <div
        v-if="launcher.inProgress.value"
        class="plans-page__notice"
        data-testid="plans-in-progress"
      >
        <span>Une session est déjà en cours aujourd’hui.</span>
        <router-link :to="`/session/${launcher.inProgress.value.id}`"
          >La reprendre</router-link
        >
        <button type="button" @click="launcher.abandonAndLaunch()">
          L’abandonner et lancer celle-ci
        </button>
      </div>
      <div
        v-if="launcher.error.value || error"
        class="plans-page__notice plans-page__notice--error"
        data-testid="plans-error"
        >{{ launcher.error.value || error }}</div
      >

      <div v-if="!loaded" class="column items-center q-pa-lg">
        <q-spinner size="3em" />
      </div>
      <div
        v-else-if="!plans.length"
        class="plans-page__empty"
        data-testid="plans-empty"
      >
        <p>Aucune session enregistrée.</p>
        <q-btn
          color="primary"
          no-caps
          unelevated
          label="Composer ma première session"
          to="/session/new"
        />
      </div>
      <div v-else class="plans-page__grid">
        <article
          v-for="plan in plans"
          :key="plan.id"
          class="plans-page__card"
          data-testid="plan-card"
        >
          <div class="plans-page__card-head">
            <h2 class="plans-page__card-title">{{
              plan.title || 'Session sans titre'
            }}</h2>
            <span v-if="plan.public" class="plans-page__badge">Publique</span>
          </div>
          <div class="plans-page__glyphs" aria-hidden="true">
            <span
              v-for="(step, i) in plan.steps"
              :key="i"
              class="plans-page__glyph"
              :style="{
                background: stepModule(step)?.bg,
                color: stepModule(step)?.ink
              }"
              >{{ stepModule(step)?.glyph }}</span
            >
          </div>
          <div class="plans-page__meta">
            {{ plan.steps.length }} module{{
              plan.steps.length > 1 ? 's' : ''
            }}
            · {{ formatMinutes(plan.totalMinutes) }}
          </div>
          <div class="plans-page__repetition" data-testid="plan-repetition">{{
            repetitionText(plan)
          }}</div>
          <div v-if="plan.nextAt" class="plans-page__next">{{
            nextText(plan)
          }}</div>
          <div
            v-if="plan.reminderEnabled || plan.calendarEnabled"
            class="plans-page__extras"
          >
            <span v-if="plan.reminderEnabled"
              >🔔&#xFE0E; {{ reminderText(plan) }}</span
            >
            <span v-if="plan.calendarEnabled">📅&#xFE0E; Calendrier</span>
          </div>
          <div class="plans-page__actions">
            <q-btn
              color="primary"
              no-caps
              unelevated
              icon="play_arrow"
              label="Lancer"
              :loading="launcher.launching.value === plan.id"
              data-testid="plan-launch"
              @click="launcher.launch(plan.id)"
            />
            <q-btn
              flat
              no-caps
              label="Modifier"
              :to="`/session/plans/${plan.id}`"
              data-testid="plan-edit"
            />
            <q-space />
            <q-btn
              v-if="plan.repetition !== 'on_demand'"
              flat
              round
              dense
              icon="event"
              :loading="downloading === plan.id"
              :aria-label="`Ajouter ${plan.title || 'la session'} à mon agenda (.ics)`"
              data-testid="plan-ics"
              @click="downloadIcs(plan)"
            >
              <q-tooltip>Télécharger pour mon agenda (.ics)</q-tooltip>
            </q-btn>
            <q-btn
              flat
              round
              dense
              icon="delete"
              color="negative"
              :aria-label="`Supprimer ${plan.title || 'la session'}`"
              data-testid="plan-delete"
              @click="confirmDelete(plan)"
            />
          </div>
        </article>
      </div>

      <RecentSessions class="plans-page__history" />
    </div>
  </q-page>
</template>

<script setup>
/**
 * "Mes sessions" (docs/TRAINING.md, saved sessions): the saved sessions, launched on demand, the
 * session of the day in progress, and the latest sessions played.
 */
import { onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import RecentSessions from '@/components/dashboard/RecentSessions.vue'
import { usePlanLaunch } from '@/composables/session/usePlanLaunch'
import { calendarApi, planApi, sessionApi } from '@/services/api'
import { downloadText } from '@/utils/download'
import { apiErrorMessage } from '@/utils/apiError'
import { formatMinutes } from '@/utils/session/catalog'
import {
  REMINDER_CHANNELS,
  REMINDER_DELAYS,
  icsFileName,
  nextText,
  repetitionText
} from '@/utils/session/plans'
import { sessionProgressText, stepModule } from '@/utils/session/steps'

definePage({ meta: { auth: 'required' } })

const $q = useQuasar()
const launcher = usePlanLaunch()
/** @type {import('vue').Ref<import('@/utils/session/plans').Plan[]>} */
const plans = ref([])
/** @type {import('vue').Ref<import('@/utils/session/steps').TrainingSession|null>} */
const current = ref(null)
const loaded = ref(false)
const error = ref('')

/**
 * "30 min avant · email, navigateur".
 *
 * @param {import('@/utils/session/plans').Plan} plan
 */
function reminderText(plan) {
  const delay = REMINDER_DELAYS.find(d => d.value === plan.reminderMinutes)
  const channels = plan.reminderChannels
    .map(c => REMINDER_CHANNELS.find(r => r.value === c)?.label.toLowerCase())
    .join(', ')
  return `${delay?.label ?? ''} · ${channels}`
}

/** @param {import('@/utils/session/plans').Plan} plan */
/** @type {import('vue').Ref<string|null>} */
const downloading = ref(null)

/**
 * One session as an .ics file, imported once into an agenda (the profile's calendar address
 * follows later changes instead).
 *
 * @param {{id: string, title: string}} plan
 */
async function downloadIcs(plan) {
  downloading.value = plan.id
  try {
    const ics = await calendarApi.planIcs(plan.id)
    downloadText(ics, icsFileName(plan.title), 'text/calendar')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    downloading.value = null
  }
}

function confirmDelete(plan) {
  $q.dialog({
    title: 'Supprimer cette session ?',
    message:
      'Les sessions déjà jouées restent dans ton historique ; seule la session enregistrée disparaît.',
    cancel: true
  }).onOk(async () => {
    try {
      await planApi.remove(plan.id)
      plans.value = plans.value.filter(p => p.id !== plan.id)
    } catch (e) {
      error.value = apiErrorMessage(e)
    }
  })
}

onMounted(async () => {
  try {
    const [list, active] = await Promise.all([
      planApi.list(),
      sessionApi.current().catch(() => null)
    ])
    plans.value = list
    current.value = active
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loaded.value = true
  }
})
</script>

<style scoped lang="scss">
.plans-page__inner {
  max-width: 980px;
  margin: 0 auto;
  padding: 20px 16px 40px;

  @media (min-width: $breakpoint-md-min) {
    padding: 32px 40px 48px;
  }
}

.plans-page__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}

.plans-page__title {
  margin: 0;
  font-size: 28px;
}

.plans-page__current {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 14px;
  padding: 12px 16px;
  border-radius: 16px;
  background: var(--cm-lime);
  color: #1b1530;
  text-decoration: none;
}

.plans-page__current-go {
  font-weight: 800;
}

.plans-page__notice {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 12px;
  margin-bottom: 14px;
  padding: 10px 14px;
  border-radius: 14px;
  background: var(--cm-orange-soft);
  color: var(--cm-orange-ink);
  font-size: 13.5px;
  font-weight: 600;

  a,
  button {
    border: none;
    background: none;
    color: inherit;
    font: inherit;
    font-weight: 800;
    text-decoration: underline;
    cursor: pointer;
  }

  &--error {
    background: var(--cm-danger-soft);
    color: var(--cm-danger);
  }
}

.plans-page__empty {
  padding: 28px 16px;
  border-radius: 20px;
  background: var(--cm-surface);
  text-align: center;
}

.plans-page__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 14px;
}

.plans-page__card {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 16px;
  border-radius: 20px;
  background: var(--cm-surface);
}

.plans-page__card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.plans-page__card-title {
  margin: 0;
  overflow: hidden;
  font-size: 17px;
  font-weight: 700;
  line-height: 1.3;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.plans-page__badge {
  flex: none;
  padding: 2px 9px;
  border-radius: 999px;
  background: var(--cm-brand-soft);
  color: var(--cm-brand-deep);
  font-size: 11px;
  font-weight: 700;
}

.plans-page__glyphs {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.plans-page__glyph {
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  border-radius: 9px;
  font-size: 15px;
}

.plans-page__meta,
.plans-page__next,
.plans-page__extras {
  color: var(--cm-muted);
  font-size: 13px;
}

.plans-page__extras {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 12px;
}

.plans-page__repetition {
  font-size: 14px;
  font-weight: 700;
}

.plans-page__actions {
  display: flex;
  align-items: center;
  gap: 4px;
  margin-top: 6px;
}

.plans-page__history {
  margin-top: 22px;
}
</style>
