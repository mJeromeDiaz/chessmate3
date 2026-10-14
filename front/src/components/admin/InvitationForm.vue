<template>
  <q-form
    class="cm-card invitation-form"
    data-testid="invitation-form"
    @submit="submit"
  >
    <h2 class="cm-card__title">{{
      $t('admin.invitationForm.inviterQuelquun')
    }}</h2>

    <div class="invitation-form__row">
      <q-input
        v-model="email"
        type="email"
        :label="$t('admin.invitationForm.email')"
        outlined
        dense
        class="invitation-form__email"
        :rules="[v => !!v || $t('admin.invitationForm.required')]"
        lazy-rules
        data-testid="invitation-email"
      />
      <q-select
        v-model="expiry"
        :options="EXPIRY_OPTIONS"
        emit-value
        map-options
        outlined
        dense
        :label="$t('admin.invitationForm.expiration')"
        class="invitation-form__expiry"
        data-testid="invitation-expiry"
      />
      <q-input
        v-if="expiry === 'date'"
        v-model="date"
        type="date"
        :label="$t('admin.invitationForm.expireLe')"
        outlined
        dense
        stack-label
        :min="tomorrow"
        :rules="[v => !!v || $t('admin.invitationForm.pickDate')]"
        lazy-rules
        data-testid="invitation-date"
      />
      <q-btn
        type="submit"
        color="primary"
        no-caps
        :label="$t('admin.invitationForm.envoyerLinvitation')"
        :loading="loading"
        data-testid="invitation-submit"
      />
    </div>

    <q-banner v-if="error" class="cm-banner--danger q-mt-sm" rounded>{{
      error
    }}</q-banner>
  </q-form>
</template>

<script setup>
import { ref } from 'vue'
import { useAdminStore } from '@/stores/admin'
import { apiErrorMessage } from '@/utils/apiError'
import { endOfDay } from '@/utils/admin/invitations'
import { t } from '@/i18n'

/**
 * Creates an invitation: an email address and an expiry (7 days, a chosen date up to a year away,
 * or never). Emits the created invitation, whose key is shown once.
 */
const emit = defineEmits({ created: invitation => !!invitation?.key })

const EXPIRY_OPTIONS = ['default', 'date', 'never'].map(value => ({
  value,
  get label() {
    return t(`admin.invitationForm.expiry.${value}`)
  }
}))

const store = useAdminStore()
const email = ref('')
const expiry = ref('default')
const date = ref('')
const loading = ref(false)
const error = ref('')
const tomorrow = new Date(Date.now() + 86_400_000).toISOString().slice(0, 10)

async function submit() {
  loading.value = true
  error.value = ''
  try {
    const created = await store.createInvitation({
      email: email.value.trim(),
      ...(expiry.value === 'date' ? { expiresAt: endOfDay(date.value) } : {}),
      ...(expiry.value === 'never' ? { neverExpires: true } : {})
    })
    email.value = ''
    emit('created', created)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}
</script>

<style scoped lang="scss">
.invitation-form__row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 12px;
  margin-top: 12px;
}

.invitation-form__email {
  flex: 1 1 240px;
}

.invitation-form__expiry {
  flex: 0 0 160px;
}
</style>
