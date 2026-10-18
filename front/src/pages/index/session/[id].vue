<template>
  <q-page class="session-page">
    <div
      v-if="!session"
      class="session-page__inner column items-center q-pa-lg"
    >
      <q-spinner v-if="!error" size="3em" />
      <div v-else class="text-negative" data-testid="session-error">{{
        error
      }}</div>
    </div>

    <div v-else class="session-page__inner">
      <div class="session-page__head">
        <div>
          <div class="session-page__kicker">{{
            $t('session.page.kicker', { date: formatDate(session.startedAt) })
          }}</div>
          <h1
            class="session-page__title cm-heading"
            data-testid="session-name"
            >{{ session.title || $t('session.untitled') }}</h1
          >
          <p v-if="session.description" class="session-page__desc">{{
            session.description
          }}</p>
        </div>
        <span
          class="session-page__status"
          :class="`session-page__status--${session.status}`"
          data-testid="session-status"
          >{{ sessionStatusText(session) }}</span
        >
      </div>

      <div class="session-page__pills">
        <span class="session-page__pill">{{
          sessionProgressText(session)
        }}</span>
        <span class="session-page__pill">{{
          $t('session.page.played', {
            duration: formatDuration(session.durationMs)
          })
        }}</span>
      </div>

      <ol class="session-page__steps">
        <li
          v-for="step in session.steps"
          :key="step.index"
          class="session-page__step"
          :class="{
            'session-page__step--current':
              session.status === 'active' && step.index === session.currentIndex
          }"
          data-testid="session-step"
        >
          <span
            class="session-page__badge"
            :style="{
              background: stepModule(step)?.bg,
              color: stepModule(step)?.ink
            }"
            aria-hidden="true"
            >{{ stepModule(step)?.glyph }}</span
          >
          <div class="session-page__step-body">
            <div
              v-if="session.parcours"
              class="session-page__step-title"
              data-testid="session-tier"
            >
              {{
                $t('parcours.session.step', {
                  n: step.index + 1,
                  name: tierName(stepPlan(step))
                })
              }}
            </div>
            <div v-else class="session-page__step-title">
              {{
                $t('session.page.stepTitle', {
                  n: String(step.index + 1).padStart(2, '0'),
                  module: stepModule(step)?.title ?? step.module
                })
              }}
              <span class="session-page__minutes">{{
                $t('session.page.minutes', { n: step.minutes })
              }}</span>
            </div>
            <div
              class="session-page__step-status"
              data-testid="session-step-status"
              >{{ stepStatusText(step) }}</div
            >
            <div v-if="step.notes" class="session-page__notes"
              >« {{ step.notes }} »</div
            >
            <div
              v-if="step.blocked && step.status === 'pending'"
              class="session-page__blocked"
              data-testid="session-step-blocked"
              >⚠ {{ blockedText(step.blocked.reason) }}</div
            >
          </div>
        </li>
      </ol>

      <div
        v-if="session.status === 'active' && current"
        class="session-page__actions"
      >
        <q-btn
          v-if="current.status === 'running' && current.runId"
          color="primary"
          no-caps
          unelevated
          icon="play_arrow"
          :label="$t('session.page.resumeRun')"
          :to="`/training/${current.runId}`"
          data-testid="session-resume"
        />
        <q-btn
          v-else
          color="primary"
          no-caps
          unelevated
          icon="play_arrow"
          :label="
            $t(current.blocked ? 'session.page.retry' : 'session.page.start', {
              module: stepModule(current)?.title ?? current.module
            })
          "
          :loading="step.starting.value"
          data-testid="session-next"
          @click="step.start(session.id)"
        />
        <q-btn
          v-if="
            current.status === 'pending' &&
            (!session.parcours || current.blocked)
          "
          outline
          color="primary"
          no-caps
          :label="$t('session.page.skip')"
          :loading="acting === 'skip'"
          data-testid="session-skip"
          @click="skip"
        />
        <q-space />
        <q-btn
          outline
          color="primary"
          no-caps
          icon="replay"
          :label="$t('session.page.restart')"
          :loading="!!launcher.launching.value"
          data-testid="session-restart"
          @click="confirmRestart"
        />
        <q-btn
          flat
          no-caps
          color="negative"
          :label="$t('session.page.abandon')"
          :loading="acting === 'abandon'"
          data-testid="session-abandon"
          @click="confirmAbandon"
        />
      </div>
      <div
        v-if="step.error.value || launcher.error.value || error"
        class="session-page__error text-negative"
        data-testid="session-action-error"
        >{{ step.error.value || launcher.error.value || error }}</div
      >
      <i18n-t
        v-if="launcher.inProgress.value"
        keypath="session.page.otherInProgress"
        tag="div"
        scope="global"
        class="session-page__error"
        data-testid="session-replay-in-progress"
      >
        <template #resume>
          <router-link :to="`/session/${launcher.inProgress.value.id}`">{{
            $t('session.resume')
          }}</router-link>
        </template>
        <template #abandon>
          <button
            type="button"
            class="session-page__link"
            @click="launcher.abandonAndLaunch()"
            >{{ $t('session.page.abandonAndReplayThis') }}</button
          >
        </template>
      </i18n-t>

      <div v-if="session.status !== 'active'" class="session-page__end">
        <div class="session-page__end-text" data-testid="session-end">{{
          endText
        }}</div>
        <div
          v-if="session.reward"
          class="session-page__reward"
          data-testid="session-reward"
        >
          <CrystalGem :size="22" />{{ rewardLine(session.reward) }}
        </div>
        <q-btn
          color="primary"
          no-caps
          unelevated
          icon="replay"
          :label="$t('session.replay')"
          :loading="!!launcher.launching.value"
          data-testid="session-replay"
          @click="launcher.replay(session)"
        />
        <q-btn
          unelevated
          class="cm-btn-create"
          no-caps
          :label="$t('session.page.composeNew')"
          to="/session/new"
        />
        <q-btn
          outline
          color="primary"
          no-caps
          :label="$t('session.page.dashboard')"
          to="/"
        />
      </div>
    </div>
    <TreasureChest
      v-if="chestOpen && session?.reward"
      :reward="session.reward"
      :results-label="$t('session.page.seeResults')"
      @results="chestOpen = false"
      @close="chestOpen = false"
      @shop="chestOpen = false"
    />
  </q-page>
</template>

<script setup>
/**
 * A training session (docs/TRAINING.md): its frozen program, the state of each step, and what
 * comes next: start (or retry) the current module, resume its run, pass it, abandon the session,
 * or play it again (from the start, its saved session as it is now when it has one). A completed
 * session shows its crowns, and its chest until opened (docs/SHOP.md, § 4 bis).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useQuasar } from 'quasar'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import TreasureChest from '@/components/shop/TreasureChest.vue'
import { sessionApi } from '@/services/api'
import { useShopStore } from '@/stores/shop'
import { usePlanLaunch } from '@/composables/session/usePlanLaunch'
import { useSessionStep } from '@/composables/session/useSessionStep'
import { apiErrorMessage } from '@/utils/apiError'
import { t } from '@/i18n'
import { formatDate, formatDuration } from '@/utils/format'
import { chestToOpen, rewardLine } from '@/utils/shopReward'
import { stepPlan, tierName } from '@/utils/parcours'
import {
  blockedText,
  sessionProgressText,
  sessionStatusText,
  stepModule,
  stepStatusText
} from '@/utils/session/steps'

definePage({ meta: { auth: 'required' } })

const route = useRoute()
const $q = useQuasar()
const shop = useShopStore()
/** @type {import('vue').Ref<import('@/utils/session/steps').TrainingSession|null>} */
const session = ref(null)
const error = ref('')
/** @type {import('vue').Ref<'skip'|'abandon'|null>} */
const acting = ref(null)

const step = useSessionStep({ onRefused: () => load() })
const launcher = usePlanLaunch()

/** The chest of a session completed here (its last module passed) or not opened yet. */
const chestOpen = ref(false)
watch(session, s => {
  if (chestToOpen(s, shop.seenChests)) chestOpen.value = true
})

const current = computed(
  () => session.value?.steps[session.value.currentIndex] ?? null
)

const endText = computed(() => {
  switch (session.value?.status) {
    case 'completed':
      return t('session.page.completed')
    case 'abandoned':
      return t('session.page.abandoned')
    case 'expired':
      return t('session.page.expired')
    default:
      return ''
  }
})

async function load() {
  try {
    session.value = await sessionApi.get(String(route.params.id))
  } catch (e) {
    error.value = apiErrorMessage(e, {
      404: t('session.launchErrors.notFound')
    })
  }
}

/** @param {'skip'|'abandon'} action */
async function act(action) {
  if (!session.value) return
  acting.value = action
  error.value = ''
  try {
    session.value = await sessionApi[action](session.value.id)
  } catch (e) {
    error.value = apiErrorMessage(e, {
      409: t('session.page.runInProgress')
    })
  } finally {
    acting.value = null
  }
}

function skip() {
  act('skip')
}

function confirmAbandon() {
  $q.dialog({
    title: t('session.page.abandonTitle'),
    message: t('session.page.abandonMessage'),
    cancel: true
  }).onOk(() => act('abandon'))
}

function confirmRestart() {
  $q.dialog({
    title: t('session.page.restartTitle'),
    message: t('session.page.restartMessage'),
    cancel: true
  }).onOk(async () => {
    await act('abandon')
    if (session.value?.status === 'abandoned')
      await launcher.replay(session.value)
  })
}

// Another session (a replay) opens on this same page: load it.
watch(
  () => route.params.id,
  (id, previous) => {
    if (id && id !== previous) load()
  }
)

onMounted(load)
</script>

<style scoped lang="scss">
.session-page__inner {
  max-width: 760px;
  margin: 0 auto;
  padding: 20px 16px 40px;

  @media (min-width: $breakpoint-md-min) {
    padding: 32px 40px 48px;
  }
}

.session-page__reward {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 700;
}

.session-page__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.session-page__kicker {
  color: var(--cm-muted);
  font-size: 12px;
  font-weight: 700;
}

.session-page__title {
  margin: 4px 0 0;
  font-size: 28px;
  line-height: 1.15;
}

.session-page__desc {
  margin: 6px 0 0;
  color: var(--cm-ink-soft);
}

.session-page__status {
  flex: none;
  padding: 5px 12px;
  border-radius: 999px;
  background: var(--cm-subtle);
  font-size: 13px;
  font-weight: 700;

  &--active {
    background: var(--cm-lime);
    color: #1b1530;
  }

  &--completed {
    background: var(--cm-brand-soft);
    color: var(--cm-brand-deep);
  }
}

.session-page__pills {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 14px 0 18px;
}

.session-page__pill {
  padding: 5px 12px;
  border-radius: 999px;
  background: var(--cm-surface);
  box-shadow: var(--cm-card-shadow);
  font-size: 13px;
  font-weight: 600;
}

.session-page__steps {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.session-page__step {
  display: flex;
  gap: 12px;
  padding: 12px 14px;
  border: 2px solid transparent;
  border-radius: 18px;
  background: var(--cm-surface);
  box-shadow: var(--cm-card-shadow);

  &--current {
    border-color: var(--cm-brand);
  }
}

.session-page__badge {
  display: grid;
  flex: none;
  place-items: center;
  width: 44px;
  height: 44px;
  border-radius: 14px;
  font-size: 22px;
}

.session-page__step-body {
  flex: 1;
  min-width: 0;
}

.session-page__step-title {
  font-weight: 700;
}

.session-page__minutes {
  margin-left: 6px;
  color: var(--cm-muted);
  font-size: 13px;
  font-weight: 600;
}

.session-page__step-status {
  margin-top: 2px;
  color: var(--cm-ink-soft);
  font-size: 13.5px;
}

.session-page__notes {
  margin-top: 4px;
  color: var(--cm-muted);
  font-size: 13px;
  font-style: italic;
}

.session-page__blocked {
  margin-top: 6px;
  color: var(--cm-danger);
  font-size: 13px;
  font-weight: 700;
}

.session-page__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-top: 20px;
}

.session-page__error {
  margin-top: 10px;
}

.session-page__link {
  padding: 0;
  border: 0;
  background: none;
  font: inherit;
  color: var(--cm-brand);
  text-decoration: underline;
  cursor: pointer;
}

.session-page__end {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-top: 22px;
}

.session-page__end-text {
  width: 100%;
  font-size: 16px;
  font-weight: 700;
}
</style>
