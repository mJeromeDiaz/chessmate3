<template>
  <q-dialog :model-value="!!id" @update:model-value="emit('close')">
    <q-card class="invitation-detail" data-testid="invitation-detail">
      <q-card-section class="row items-center no-wrap">
        <div class="text-h6 col">Invitation</div>
        <q-btn
          flat
          round
          dense
          icon="close"
          aria-label="Fermer"
          @click="emit('close')"
        />
      </q-card-section>

      <q-card-section v-if="loading" class="q-pt-none">
        <q-skeleton height="160px" />
      </q-card-section>
      <q-card-section v-else-if="error" class="q-pt-none">
        <q-banner class="cm-banner--danger" rounded>{{ error }}</q-banner>
      </q-card-section>

      <template v-else-if="invitation">
        <q-card-section class="q-pt-none">
          <dl class="invitation-detail__facts">
            <dt>Email</dt>
            <dd>{{ invitation.email }}</dd>
            <dt>Statut</dt>
            <dd>{{ STATUS_LABELS[invitation.status] }}</dd>
            <dt>Clé</dt>
            <dd
              ><code>{{ invitation.keyHint }}…</code></dd
            >
            <dt>Créée</dt>
            <dd
              >{{ formatDate(invitation.createdAt) }} par
              {{ account(invitation.createdBy) }}</dd
            >
            <dt>Expire</dt>
            <dd>{{
              invitation.expiresAt ? formatDate(invitation.expiresAt) : 'Jamais'
            }}</dd>
            <dt>Email d’invitation</dt>
            <dd>
              {{ EMAIL_LABELS[invitation.emailStatus] }}
              <template v-if="invitation.emailSentAt">
                le {{ formatDate(invitation.emailSentAt) }}</template
              >
              · {{ invitation.sendCount }} envoi(s)
            </dd>
            <template v-if="invitation.usedAt">
              <dt>Utilisée</dt>
              <dd
                >{{ formatDate(invitation.usedAt) }} par
                {{ account(invitation.usedBy) }}</dd
              >
            </template>
            <template v-if="invitation.revokedAt">
              <dt>Révoquée</dt>
              <dd>{{ formatDate(invitation.revokedAt) }}</dd>
            </template>
          </dl>
        </q-card-section>

        <q-card-section class="q-pt-none">
          <div class="text-subtitle2 q-mb-sm">Journal</div>
          <ol class="invitation-detail__log" data-testid="invitation-log">
            <li v-for="line in invitation.logs ?? []" :key="line.id">
              <span class="cm-muted">{{ formatDate(line.createdAt) }}</span>
              <strong>{{ ACTION_LABELS[line.action] ?? line.action }}</strong>
              <span v-if="line.actor">· {{ account(line.actor) }}</span>
              <span v-if="line.details?.method"
                >·
                {{
                  METHOD_LABELS[line.details.method] ?? line.details.method
                }}</span
              >
              <span v-if="line.details?.accountCreated === false"
                >· adresse déjà inscrite, aucun compte créé</span
              >
            </li>
          </ol>
        </q-card-section>
      </template>
    </q-card>
  </q-dialog>
</template>

<script setup>
import { ref, watch } from 'vue'
import { adminApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { formatDate } from '@/utils/format'
import {
  ACTION_LABELS,
  EMAIL_LABELS,
  METHOD_LABELS,
  STATUS_LABELS
} from '@/utils/admin/invitations'

/** One invitation and its log, read when opened. */
const props = defineProps({
  /** The invitation to show; null: closed. */
  id: { type: String, default: null }
})
const emit = defineEmits(['close'])

/** @type {import('vue').Ref<import('@/stores/admin').Invitation|null>} */
const invitation = ref(null)
const loading = ref(false)
const error = ref('')

/**
 * @param {{email: string|null, handle: string|null}|null} a
 * @returns {string}
 */
function account(a) {
  if (!a) return 'un compte supprimé'
  return a.email ?? (a.handle ? `@${a.handle}` : 'un compte sans email')
}

watch(
  () => props.id,
  async id => {
    invitation.value = null
    error.value = ''
    if (!id) return
    loading.value = true
    try {
      const data = await adminApi.invitation(id)
      if (props.id === id) invitation.value = data
    } catch (e) {
      if (props.id === id) error.value = apiErrorMessage(e)
    } finally {
      if (props.id === id) loading.value = false
    }
  },
  { immediate: true }
)
</script>

<style scoped lang="scss">
.invitation-detail {
  width: 600px;
  max-width: 94vw;
}

.invitation-detail__facts {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 6px 16px;
  margin: 0;
  font-size: 14px;

  dt {
    color: var(--cm-ink-soft);
  }

  dd {
    margin: 0;
  }
}

.invitation-detail__log {
  margin: 0;
  padding-left: 18px;
  font-size: 13px;

  li {
    margin-bottom: 4px;
  }
}
</style>
