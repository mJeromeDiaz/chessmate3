<template>
  <section
    v-if="sessions.length || !hideEmpty"
    class="cm-card sessions"
    data-testid="recent-sessions"
  >
    <h2 class="cm-card__title">{{ $t('dashboard.recent.title') }}</h2>
    <div v-if="!sessions.length" class="sessions__empty">
      <span>{{
        loaded ? $t('dashboard.recent.empty') : $t('common.loading')
      }}</span>
      <q-btn
        flat
        dense
        no-caps
        color="primary"
        to="/session/new"
        :label="$t('dashboard.recent.prepare')"
      />
    </div>
    <div v-for="session in sessions" :key="session.id" class="sessions__item">
      <router-link
        :to="`/session/${session.id}`"
        class="sessions__row"
        data-testid="recent-session"
      >
        <div class="sessions__main">
          <div class="sessions__title">{{
            session.title || $t('session.untitled')
          }}</div>
          <div class="sessions__meta"
            >{{ formatDate(session.startedAt) }} ·
            {{ sessionProgressText(session) }} ·
            {{ formatDuration(session.durationMs) }}</div
          >
        </div>
        <span
          class="sessions__status"
          :class="`sessions__status--${session.status}`"
          >{{
            session.status === 'active'
              ? $t('session.resumeArrow')
              : sessionStatusText(session)
          }}</span
        >
      </router-link>
      <q-btn
        v-if="session.status !== 'active'"
        flat
        round
        dense
        color="primary"
        icon="replay"
        :loading="launcher.launching.value === session.id"
        :aria-label="
          $t('session.replayNamed', {
            title: session.title || $t('session.replayThis')
          })
        "
        data-testid="recent-session-replay"
        @click="launcher.replay(session)"
      >
        <q-tooltip>{{ $t('session.replay') }}</q-tooltip>
      </q-btn>
    </div>
    <div v-if="launcher.inProgress.value" class="sessions__notice">
      <i18n-t keypath="session.inProgressOr" scope="global">
        <template #resume
          ><router-link :to="`/session/${launcher.inProgress.value.id}`">{{
            $t('session.resume')
          }}</router-link></template
        >
        <template #abandon
          ><button
            type="button"
            class="sessions__link"
            @click="launcher.abandonAndLaunch()"
            >{{ $t('session.abandonAndReplay') }}</button
          ></template
        >
      </i18n-t>
    </div>
    <div
      v-else-if="launcher.error.value"
      class="sessions__notice text-negative"
      >{{ launcher.error.value }}</div
    >
  </section>
</template>

<script setup>
/**
 * The latest training sessions (design "Dashboard"): title, day, modules played, time; the active
 * one can be resumed, the others played again.
 */
import { onMounted, ref } from 'vue'
import { usePlanLaunch } from '@/composables/session/usePlanLaunch'
import { sessionApi } from '@/services/api'
import { formatDate, formatDuration } from '@/utils/format'
import { sessionProgressText, sessionStatusText } from '@/utils/session/steps'

defineProps({
  /** Shows nothing until there is a session (the new user's dashboard). */
  hideEmpty: { type: Boolean, default: false }
})

/** Rows shown. */
const LIMIT = 5

/** @type {import('vue').Ref<import('@/utils/session/steps').TrainingSession[]>} */
const sessions = ref([])
const launcher = usePlanLaunch()
const loaded = ref(false)

onMounted(async () => {
  try {
    sessions.value = (await sessionApi.list()).slice(0, LIMIT)
  } catch {
    // The empty state stays: the rest of the dashboard does not depend on it.
  } finally {
    loaded.value = true
  }
})
</script>

<style scoped lang="scss">
.sessions {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.sessions__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 16px;
  border-radius: 16px;
  background: var(--cm-inset);
  color: var(--cm-ink-soft);
  text-align: center;
  font-size: 14px;
}

.sessions__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 14px;
  background: var(--cm-inset);
  color: var(--cm-ink);
  text-decoration: none;
}

.sessions__item {
  display: flex;
  align-items: center;
  gap: 6px;

  .sessions__row {
    flex: 1;
    min-width: 0;
  }
}

.sessions__notice {
  font-size: 13px;
  color: var(--cm-ink-soft);
}

.sessions__link {
  padding: 0;
  border: 0;
  background: none;
  font: inherit;
  color: var(--cm-brand);
  text-decoration: underline;
  cursor: pointer;
}

.sessions__main {
  min-width: 0;
}

.sessions__title {
  overflow: hidden;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sessions__meta {
  color: var(--cm-muted);
  font-size: 12.5px;
}

.sessions__status {
  flex: none;
  padding: 3px 10px;
  border-radius: 999px;
  background: var(--cm-subtle);
  font-size: 12px;
  font-weight: 700;

  &--active {
    background: var(--cm-lime);
    color: #1b1530;
  }
}
</style>
