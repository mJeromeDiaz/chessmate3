<template>
  <q-page class="frozen" data-testid="account-deletion-page">
    <section class="cm-card frozen__card">
      <h1 class="frozen__title">{{ $t('auth.deletion.title') }}</h1>

      <template v-if="auth.isAuthenticated && auth.isFrozen">
        <i18n-t
          keypath="auth.deletion.frozen"
          tag="p"
          data-testid="frozen-date"
          scope="global"
        >
          <template #date
            ><strong>{{
              deletionDay(auth.profile?.deletionScheduledAt)
            }}</strong></template
          >
        </i18n-t>
        <p class="cm-muted">
          {{ $t('auth.deletion.frozenHint') }}
        </p>
        <div class="frozen__actions">
          <q-btn
            unelevated
            no-caps
            color="dark"
            :label="$t('auth.deletion.cancel')"
            :loading="cancelling"
            data-testid="frozen-cancel"
            @click="cancel"
          />
          <q-btn
            outline
            no-caps
            :label="$t('auth.deletion.export')"
            :loading="exporting"
            data-testid="frozen-export"
            @click="exportData"
          />
          <q-btn
            flat
            no-caps
            :label="$t('auth.deletion.logout')"
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
          <i18n-t
            v-if="scheduledAt"
            keypath="auth.deletion.scheduledOn"
            scope="global"
          >
            <template #date
              ><strong>{{ deletionDay(scheduledAt) }}</strong></template
            >
          </i18n-t>
          <template v-else>{{ $t('auth.deletion.scheduled') }}</template>
          {{ $t('auth.deletion.signedOut') }}
        </p>
        <p class="cm-muted">
          {{ $t('auth.deletion.changeMind') }}
        </p>
        <q-btn
          unelevated
          no-caps
          color="dark"
          to="/login"
          :label="$t('auth.deletion.signInAgain')"
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
import { t } from '@/i18n'

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
    error.value = t('auth.deletion.cancelFailed')
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
