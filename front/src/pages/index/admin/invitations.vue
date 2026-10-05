<template>
  <AdminPage testid="admin-invitations">
    <InvitationForm @created="showKey($event, false)" />

    <section class="cm-card q-mt-md">
      <div class="admin__filters">
        <q-select
          v-model="store.invitationStatus"
          :options="STATUS_OPTIONS"
          emit-value
          map-options
          outlined
          dense
          label="Statut"
          class="admin__filter"
          data-testid="invitation-filter-status"
          @update:model-value="reload"
        />
        <q-input
          v-model="store.invitationEmail"
          outlined
          dense
          clearable
          debounce="350"
          label="Rechercher un email"
          class="admin__filter admin__filter--grow"
          data-testid="invitation-filter-email"
          @update:model-value="reload"
        />
      </div>

      <q-banner
        v-if="store.invitationsError"
        class="cm-banner--danger q-mb-md"
        rounded
      >
        {{ store.invitationsError }}
      </q-banner>

      <q-markup-table
        flat
        dense
        class="admin__table"
        data-testid="invitation-table"
      >
        <thead>
          <tr>
            <th class="text-left">Email</th>
            <th class="text-left">Statut</th>
            <th class="text-left">Clé</th>
            <th class="text-left">Créée</th>
            <th class="text-left">Expire</th>
            <th class="text-left">Email d’invitation</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-if="!store.invitationsLoading && store.invitations.length === 0"
          >
            <td colspan="7" class="cm-muted">Aucune invitation.</td>
          </tr>
          <tr
            v-for="inv in store.invitations"
            :key="inv.id"
            data-testid="invitation-row"
          >
            <td>{{ inv.email }}</td>
            <td>
              <span
                :class="['admin__status', `admin__status--${inv.status}`]"
                >{{ STATUS_LABELS[inv.status] }}</span
              >
            </td>
            <td
              ><code>{{ inv.keyHint }}…</code></td
            >
            <td>{{ formatDate(inv.createdAt) }}</td>
            <td>{{ inv.expiresAt ? formatDate(inv.expiresAt) : 'Jamais' }}</td>
            <td>
              <span
                :class="{ 'text-negative': inv.emailStatus === 'failed' }"
                >{{ EMAIL_LABELS[inv.emailStatus] }}</span
              >
            </td>
            <td class="text-right no-wrap">
              <q-btn
                flat
                dense
                no-caps
                label="Détail"
                @click="detailId = inv.id"
              />
              <q-btn
                v-if="invitationActions(inv).resend"
                flat
                dense
                no-caps
                label="Renvoyer"
                :loading="busy === inv.id"
                data-testid="invitation-resend"
                @click="resend(inv)"
              />
              <q-btn
                v-if="invitationActions(inv).revoke"
                flat
                dense
                no-caps
                color="negative"
                label="Révoquer"
                :disable="busy === inv.id"
                data-testid="invitation-revoke"
                @click="revoke(inv)"
              />
            </td>
          </tr>
        </tbody>
      </q-markup-table>

      <div class="admin__pager">
        <span class="cm-muted">{{ store.invitationTotal }} invitation(s)</span>
        <q-pagination
          v-if="pages > 1"
          v-model="store.invitationPage"
          :max="pages"
          :max-pages="7"
          direction-links
          boundary-links
          @update:model-value="store.loadInvitations()"
        />
      </div>
    </section>

    <KeyDialog
      :invitation="shown"
      :resent="shownResent"
      @close="shown = null"
    />
    <InvitationDetail :id="detailId" @close="detailId = null" />
  </AdminPage>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { PAGE_SIZE, useAdminStore } from '@/stores/admin'
import AdminPage from '@/components/admin/AdminPage.vue'
import InvitationForm from '@/components/admin/InvitationForm.vue'
import KeyDialog from '@/components/admin/KeyDialog.vue'
import InvitationDetail from '@/components/admin/InvitationDetail.vue'
import { apiErrorMessage } from '@/utils/apiError'
import { formatDate } from '@/utils/format'
import {
  EMAIL_LABELS,
  STATUS_LABELS,
  invitationActions
} from '@/utils/admin/invitations'

/** Invitations (docs/EARLY_ACCESS.md): create, list and filter, resend, revoke, read the log. */
definePage({ meta: { auth: 'admin' } })

const STATUS_OPTIONS = [
  { value: null, label: 'Tous' },
  ...Object.entries(STATUS_LABELS).map(([value, label]) => ({ value, label }))
]

const $q = useQuasar()
const store = useAdminStore()
/** @type {import('vue').Ref<{email: string, key: string}|null>} the key shown once */
const shown = ref(null)
const shownResent = ref(false)
/** @type {import('vue').Ref<string|null>} */
const detailId = ref(null)
/** @type {import('vue').Ref<string|null>} the row being resent or revoked */
const busy = ref(null)

const pages = computed(() => Math.ceil(store.invitationTotal / PAGE_SIZE))

/**
 * @param {{email: string, key: string}} invitation
 * @param {boolean} resent
 */
function showKey(invitation, resent) {
  shownResent.value = resent
  shown.value = { email: invitation.email, key: invitation.key }
}

function reload() {
  store.invitationPage = 1
  store.loadInvitations()
}

/** @param {import('@/stores/admin').Invitation} inv */
function resend(inv) {
  $q.dialog({
    title: 'Renvoyer l’invitation ?',
    message: `Une nouvelle clé part vers ${inv.email} ; l’ancienne cesse de fonctionner.`,
    cancel: { flat: true, noCaps: true, label: 'Annuler' },
    ok: { noCaps: true, label: 'Renvoyer', color: 'primary' }
  }).onOk(async () => {
    busy.value = inv.id
    try {
      showKey(await store.resendInvitation(inv.id), true)
    } catch (e) {
      $q.notify({
        type: 'negative',
        message: apiErrorMessage(e, {
          409: 'Cette invitation a déjà été utilisée ou révoquée.'
        })
      })
    } finally {
      busy.value = null
    }
  })
}

/** @param {import('@/stores/admin').Invitation} inv */
function revoke(inv) {
  $q.dialog({
    title: 'Révoquer l’invitation ?',
    message: `La clé envoyée à ${inv.email} ne permettra plus de s’inscrire.`,
    cancel: { flat: true, noCaps: true, label: 'Annuler' },
    ok: { noCaps: true, label: 'Révoquer', color: 'negative' }
  }).onOk(async () => {
    busy.value = inv.id
    try {
      await store.revokeInvitation(inv.id)
    } catch (e) {
      $q.notify({
        type: 'negative',
        message: apiErrorMessage(e, {
          409: 'Cette invitation a déjà été utilisée.'
        })
      })
    } finally {
      busy.value = null
    }
  })
}

onMounted(() => store.loadInvitations())
</script>
