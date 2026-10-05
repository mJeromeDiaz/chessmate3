<template>
  <q-page class="frozen" data-testid="account-deletion-page">
    <section class="cm-card frozen__card">
      <h1 class="frozen__title">Suppression du compte</h1>

      <template v-if="auth.isAuthenticated && auth.isFrozen">
        <p data-testid="frozen-date">
          Ton compte sera supprimé définitivement le
          <strong>{{ deletionDay(auth.profile?.deletionScheduledAt) }}</strong
          >, avec toutes tes données.
        </p>
        <p class="cm-muted">
          D’ici là, il est gelé : annule la suppression pour t’entraîner de
          nouveau, ou récupère tes données.
        </p>
        <div class="frozen__actions">
          <q-btn
            unelevated
            no-caps
            color="dark"
            label="Annuler la suppression"
            :loading="cancelling"
            data-testid="frozen-cancel"
            @click="cancel"
          />
          <q-btn
            outline
            no-caps
            label="Exporter mes données"
            :loading="exporting"
            data-testid="frozen-export"
            @click="exportData"
          />
          <q-btn
            flat
            no-caps
            label="Me déconnecter"
            data-testid="frozen-logout"
            @click="logout"
          />
        </div>
        <div v-if="error || exportError" class="frozen__error">{{
          error || exportError
        }}</div>
      </template>

      <template v-else>
        <p data-testid="scheduled-date">
          <template v-if="scheduledAt">
            Ton compte sera supprimé définitivement le
            <strong>{{ deletionDay(scheduledAt) }}</strong
            >.
          </template>
          <template v-else
            >La suppression de ton compte est programmée.</template
          >
          Tu as été déconnecté de tous tes appareils.
        </p>
        <p class="cm-muted">
          Tu as changé d’avis ? Reconnecte-toi d’ici là pour annuler. Un email
          te rappelle la date.
        </p>
        <q-btn
          unelevated
          no-caps
          color="dark"
          to="/login"
          label="Me reconnecter"
          data-testid="scheduled-login"
        />
      </template>
    </section>
  </q-page>
</template>

<script setup>
/**
 * The account deletion page (docs/AUTH.md): right after the confirmation (signed out, the date in
 * the query), and the only page of a frozen account once signed in again (the router keeps it
 * here): cancel, export or sign out.
 */
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDataExport } from '@/composables/profile/useDataExport'
import { useAuthStore } from '@/stores/auth'
import { deletionDay } from '@/utils/profile'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const cancelling = ref(false)
const error = ref('')
const { exporting, error: exportError, exportData } = useDataExport()

const scheduledAt = computed(() =>
  typeof route.query.at === 'string' ? route.query.at : null
)

// Signed in and not frozen (cancelled elsewhere): nothing to do here.
watch(
  () => auth.isAuthenticated && !auth.isFrozen,
  usable => {
    if (usable) router.replace('/')
  },
  { immediate: true }
)

async function cancel() {
  cancelling.value = true
  error.value = ''
  try {
    await auth.cancelDeletion()
  } catch {
    error.value = 'L’annulation a échoué. Réessaie.'
  } finally {
    cancelling.value = false
  }
}

async function logout() {
  await auth.logout()
  await router.replace('/login')
}
</script>

<style scoped lang="scss">
.frozen {
  display: flex;
  justify-content: center;
  padding: 24px 16px 48px;
}

.frozen__card {
  width: 560px;
  max-width: 100%;
}

.frozen__title {
  margin: 0 0 12px;
  font-size: 22px;
}

.frozen__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 16px;
}

.frozen__error {
  margin-top: 12px;
  color: var(--cm-danger);
  font-size: 13px;
}
</style>
