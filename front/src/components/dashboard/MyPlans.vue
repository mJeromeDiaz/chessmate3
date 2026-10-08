<template>
  <section
    v-if="plans.length || !hideEmpty"
    class="cm-card my-plans"
    data-testid="my-plans"
  >
    <div class="my-plans__head">
      <h2 class="cm-card__title">Mes sessions</h2>
      <router-link to="/session" class="my-plans__all">Toutes →</router-link>
    </div>
    <div v-if="!plans.length" class="my-plans__empty">
      <span>{{
        loaded ? 'Enregistre une session pour la retrouver ici.' : 'Chargement…'
      }}</span>
      <q-btn
        flat
        dense
        no-caps
        color="primary"
        to="/session/new"
        label="Composer une session"
      />
    </div>
    <div
      v-for="plan in shown"
      :key="plan.id"
      class="my-plans__row"
      data-testid="my-plan"
    >
      <div class="my-plans__main">
        <div class="my-plans__title">{{
          plan.title || 'Session sans titre'
        }}</div>
        <div class="my-plans__meta">{{
          plan.nextAt ? nextText(plan) : repetitionText(plan)
        }}</div>
      </div>
      <q-btn
        dense
        unelevated
        no-caps
        color="primary"
        icon="play_arrow"
        label="Lancer"
        class="my-plans__launch"
        :loading="launcher.launching.value === plan.id"
        data-testid="my-plan-launch"
        @click="launcher.launch(plan.id)"
      />
    </div>
    <div v-if="launcher.inProgress.value" class="my-plans__error">
      Une session est déjà en cours :
      <router-link :to="`/session/${launcher.inProgress.value.id}`"
        >la reprendre</router-link
      >.
    </div>
    <div v-else-if="launcher.error.value" class="my-plans__error">{{
      launcher.error.value
    }}</div>
  </section>
</template>

<script setup>
/**
 * Saved sessions on the dashboard: the next ones to come first (then those on demand), launched in
 * one click; the full list is on the "Mes sessions" page.
 */
import { computed, onMounted, ref } from 'vue'
import { usePlanLaunch } from '@/composables/session/usePlanLaunch'
import { planApi } from '@/services/api'
import { nextText, repetitionText } from '@/utils/session/plans'

defineProps({
  /** Shows nothing until there is a saved session (the new user's dashboard). */
  hideEmpty: { type: Boolean, default: false }
})

/** Rows shown. */
const LIMIT = 3

const launcher = usePlanLaunch()
/** @type {import('vue').Ref<import('@/utils/session/plans').Plan[]>} */
const plans = ref([])
const loaded = ref(false)

/** Scheduled ones by next occurrence, then on-demand ones as listed (latest changed first). */
const shown = computed(() =>
  [...plans.value]
    .sort((a, b) => {
      if (a.nextAt && b.nextAt) return a.nextAt.localeCompare(b.nextAt)
      if (a.nextAt) return -1
      if (b.nextAt) return 1
      return 0
    })
    .slice(0, LIMIT)
)

onMounted(async () => {
  try {
    plans.value = await planApi.list()
  } catch {
    // The rest of the dashboard does not depend on it.
  } finally {
    loaded.value = true
  }
})
</script>

<style scoped lang="scss">
// The dense button's icon pulls the label left: as much room on the right.
.my-plans__launch {
  padding-right: 12px;
}

.my-plans {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.my-plans__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}

.my-plans__all {
  color: var(--cm-brand);
  font-size: 13px;
  font-weight: 700;
  text-decoration: none;
}

.my-plans__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 16px;
  border-radius: 16px;
  background: var(--cm-page);
  color: var(--cm-ink-soft);
  text-align: center;
  font-size: 14px;
}

.my-plans__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 14px;
  background: var(--cm-page);
}

.my-plans__main {
  min-width: 0;
}

.my-plans__title {
  overflow: hidden;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.my-plans__meta {
  color: var(--cm-muted);
  font-size: 12.5px;
}

.my-plans__error {
  color: var(--cm-danger);
  font-size: 13px;
  font-weight: 600;
}
</style>
