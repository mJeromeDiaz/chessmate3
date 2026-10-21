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
          :label="$t('admin.invitations.statut')"
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
          :label="$t('admin.invitations.rechercherUnEmail')"
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
            <th class="text-left">{{ $t('admin.invitations.email2') }}</th>
            <th class="text-left">{{ $t('admin.invitations.statut') }}</th>
            <th class="text-left">{{ $t('admin.invitations.cle') }}</th>
            <th class="text-left">{{ $t('admin.invitations.creee') }}</th>
            <th class="text-left">{{ $t('admin.invitations.expire') }}</th>
            <th class="text-left">{{
              $t('admin.invitations.emailDinvitation')
            }}</th>
            <th class="text-right">{{ $t('admin.invitations.actions2') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-if="!store.invitationsLoading && store.invitations.length === 0"
          >
            <td colspan="7" class="cm-muted">{{
              $t('admin.invitations.aucuneInvitation')
            }}</td>
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
            <td>{{
              inv.expiresAt
                ? formatDate(inv.expiresAt)
                : $t('admin.invitations.never')
            }}</td>
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
                :label="$t('admin.invitations.detail')"
                @click="detailId = inv.id"
              />
              <q-btn
                v-if="invitationActions(inv).resend"
                flat
                dense
                no-caps
                :label="$t('admin.invitations.renvoyer')"
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
                :label="$t('admin.invitations.revoquer')"
                :disable="busy === inv.id"
                data-testid="invitation-revoke"
                @click="revoke(inv)"
              />
            </td>
          </tr>
        </tbody>
      </q-markup-table>

      <div class="admin__pager">
        <span class="cm-muted">{{
          $t('admin.invitations.total', { n: store.invitationTotal })
        }}</span>
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
import { t } from '@/i18n'

/** Invitations (docs/EARLY_ACCESS.md): create, list and filter, resend, revoke, read the log. */
definePage({
  meta: {
    auth: 'admin',
    header: {
      title: 'layout.header.titles.admin.invitations',
      subtitle: 'layout.header.kickers.admin'
    }
  }
})

const STATUS_OPTIONS = computed(() => [
  { value: null, label: t('admin.invitations.all') },
  ...Object.entries(STATUS_LABELS).map(([value, label]) => ({ value, label }))
])

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
    title: t('admin.invitations.resendTitle'),
    message: t('admin.invitations.resendMessage', { email: inv.email }),
    cancel: { flat: true, noCaps: true, label: t('common.actions.cancel') },
    ok: { noCaps: true, label: t('admin.invitations.resend'), color: 'primary' }
  }).onOk(async () => {
    busy.value = inv.id
    try {
      showKey(await store.resendInvitation(inv.id), true)
    } catch (e) {
      $q.notify({
        type: 'negative',
        message: apiErrorMessage(e, {
          409: t('admin.invitations.closed')
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
    title: t('admin.invitations.revokeTitle'),
    message: t('admin.invitations.revokeMessage', { email: inv.email }),
    cancel: { flat: true, noCaps: true, label: t('common.actions.cancel') },
    ok: {
      noCaps: true,
      label: t('admin.invitations.revoke'),
      color: 'negative'
    }
  }).onOk(async () => {
    busy.value = inv.id
    try {
      await store.revokeInvitation(inv.id)
    } catch (e) {
      $q.notify({
        type: 'negative',
        message: apiErrorMessage(e, {
          409: t('admin.invitations.used')
        })
      })
    } finally {
      busy.value = null
    }
  })
}

onMounted(() => store.loadInvitations())
</script>
