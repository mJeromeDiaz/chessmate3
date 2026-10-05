<template>
  <section class="cm-card profile-card" data-testid="profile-sessions">
    <h2 class="cm-card__title q-mb-xs">Dernières connexions</h2>

    <q-spinner v-if="loading" class="q-my-sm" />
    <div
      v-for="session in sessions"
      v-else
      :key="session.id"
      class="profile-row"
      data-testid="session-row"
    >
      <div class="session__icon" aria-hidden="true">{{
        session.line.icon
      }}</div>
      <div class="profile-row__text">
        <div class="profile-row__title">{{ session.line.device }}</div>
        <div class="profile-row__sub">{{ session.line.meta }}</div>
      </div>
      <span
        v-if="session.current"
        class="session__current"
        data-testid="session-current"
        >Cet appareil</span
      >
      <q-btn
        v-else
        unelevated
        no-caps
        class="profile-btn"
        label="Fermer"
        :loading="busy === session.id"
        data-testid="session-close"
        @click="close(session)"
      />
    </div>

    <q-banner v-if="error" class="profile-error q-mt-sm" rounded>{{
      error
    }}</q-banner>
  </section>
</template>

<script setup>
/**
 * Profile: the active sessions (design "Dernières connexions"), one per sign-in still valid,
 * this device first. Another one can be closed: its refresh token and access tokens stop at once.
 */
import { computed, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { profileApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { sessionLine } from '@/utils/profile'

const $q = useQuasar()
/** @type {import('vue').Ref<Awaited<ReturnType<typeof profileApi.sessions>>>} */
const raw = ref([])
const loading = ref(true)
const busy = ref(null)
const error = ref('')

const sessions = computed(() =>
  raw.value.map(s => ({ ...s, line: sessionLine(s) }))
)

async function load() {
  try {
    raw.value = await profileApi.sessions()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function close(session) {
  $q.dialog({
    title: 'Fermer cette session ?',
    message: `${session.line.device} devra se reconnecter.`,
    cancel: true
  }).onOk(async () => {
    busy.value = session.id
    error.value = ''
    try {
      await profileApi.closeSession(session.id)
      raw.value = raw.value.filter(s => s.id !== session.id)
    } catch (e) {
      error.value = apiErrorMessage(e, {
        404: 'Cette session est déjà fermée.',
        409: 'Pour fermer cette session, déconnecte-toi.'
      })
    } finally {
      busy.value = null
    }
  })
}

onMounted(load)
</script>

<style scoped lang="scss">
.session__icon {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: var(--cm-subtle);
  font-size: 18px;
}

.session__current {
  flex: none;
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--cm-lime-soft);
  color: var(--cm-lime-ink);
  font-weight: 700;
  font-size: 12px;
}
</style>
