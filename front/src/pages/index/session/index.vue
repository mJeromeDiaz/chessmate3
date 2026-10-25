<template>
  <q-page class="plans-page">
    <div class="plans-page__inner">
      <header class="plans-page__head">
        <h1 class="plans-page__title cm-heading">{{
          $t('session.list.title')
        }}</h1>
        <div class="plans-page__head-actions">
          <q-btn
            unelevated
            no-caps
            class="cm-btn-generate"
            :label="$t('session.list.coach')"
            to="/session/auto"
            data-testid="plans-coach"
          />
          <q-btn
            unelevated
            no-caps
            class="cm-btn-create"
            :label="$t('session.list.new')"
            to="/session/new"
            data-testid="plans-new"
          />
        </div>
      </header>

      <router-link
        v-if="current"
        :to="`/session/${current.id}`"
        class="plans-page__current"
        data-testid="plans-current"
      >
        <i18n-t keypath="session.list.current" tag="span" scope="global">
          <template #title>
            <strong>{{ current.title || $t('session.untitled') }}</strong>
          </template>
          <template #progress>{{ sessionProgressText(current) }}</template>
        </i18n-t>
        <span class="plans-page__current-go">{{
          $t('session.resumeArrow')
        }}</span>
      </router-link>

      <router-link
        to="/defis"
        class="plans-page__parcours cm-card"
        data-testid="plans-challenges"
      >
        <img :src="PROFS.lizy.bust" alt="" class="plans-page__parcours-prof" />
        <span class="plans-page__parcours-body">
          <strong>{{ $t('challenge.card.title') }}</strong>
          <span>{{ $t('challenge.card.desc') }}</span>
        </span>
        <span class="plans-page__current-go"
          >{{ $t('challenge.card.go') }} →</span
        >
      </router-link>

      <div
        v-if="launcher.inProgress.value"
        class="plans-page__notice"
        data-testid="plans-in-progress"
      >
        <span>{{ $t('session.builder.inProgress') }}</span>
        <router-link :to="`/session/${launcher.inProgress.value.id}`">{{
          $t('session.builder.resume')
        }}</router-link>
        <button type="button" @click="launcher.abandonAndLaunch()">
          {{ $t('session.builder.abandonAndLaunch') }}
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
        <p>{{ $t('session.list.empty') }}</p>
        <q-btn
          class="cm-btn-create"
          no-caps
          unelevated
          :label="$t('session.list.composeFirst')"
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
              plan.title || $t('session.untitled')
            }}</h2>
            <span
              v-if="plan.origin === 'coach'"
              class="plans-page__badge plans-page__badge--coach"
              :title="$t('session.list.coachTitle')"
              >{{ $t('session.list.coachBadge') }}</span
            >
            <span v-if="plan.public" class="plans-page__badge">{{
              $t('session.list.public')
            }}</span>
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
            {{
              $t('session.list.meta', {
                modules: $t('session.builder.modules', plan.steps.length),
                minutes: formatMinutes(plan.totalMinutes)
              })
            }}
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
            <span v-if="plan.calendarEnabled"
              >📅&#xFE0E; {{ $t('session.list.calendar') }}</span
            >
          </div>
          <div class="plans-page__actions">
            <q-btn
              color="primary"
              no-caps
              unelevated
              icon="play_arrow"
              :label="$t('session.launch')"
              :loading="launcher.launching.value === plan.id"
              data-testid="plan-launch"
              @click="launcher.launch(plan.id)"
            />
            <q-btn
              flat
              no-caps
              :label="$t('session.list.edit')"
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
              :aria-label="
                $t('session.list.icsLabel', {
                  title: plan.title || $t('session.replayThis')
                })
              "
              data-testid="plan-ics"
              @click="downloadIcs(plan)"
            >
              <q-tooltip>{{ $t('session.list.icsTooltip') }}</q-tooltip>
            </q-btn>
            <q-btn
              flat
              round
              dense
              icon="delete"
              color="negative"
              :aria-label="
                $t('session.list.delete', {
                  title: plan.title || $t('session.replayThis')
                })
              "
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
import { t } from '@/i18n'
import { formatMinutes } from '@/utils/session/catalog'
import {
  REMINDER_CHANNELS,
  REMINDER_DELAYS,
  icsFileName,
  nextText,
  repetitionText
} from '@/utils/session/plans'
import { sessionProgressText, stepModule } from '@/utils/session/steps'
import { PROFS } from '@/utils/prof/profs'

definePage({
  meta: {
    auth: 'required',
    header: {
      title: 'session.list.title',
      subtitle: 'layout.header.kickers.create'
    }
  }
})

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
  return t('session.list.reminder', { delay: delay?.label ?? '', channels })
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
    title: t('session.list.deleteTitle'),
    message: t('session.list.deleteMessage'),
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

.plans-page__head-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
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

.plans-page__parcours {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 16px;
  margin-bottom: 14px;
  color: inherit;
  text-decoration: none;
}

.plans-page__parcours-prof {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: #ff8a3d;
  object-fit: cover;
}

.plans-page__parcours-body {
  display: flex;
  flex: 1 1 220px;
  flex-direction: column;
  gap: 2px;
  font-size: 14px;
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
  border-radius: 22px;
  background: var(--cm-surface);
  box-shadow: var(--cm-card-shadow);
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
  border-radius: 22px;
  background: var(--cm-surface);
  box-shadow: var(--cm-card-shadow);

  @media (min-width: $breakpoint-md-min) {
    border-radius: 24px;
  }
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

  &--coach {
    background: #5ee6b0;
    color: #0b3b29;
  }
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
