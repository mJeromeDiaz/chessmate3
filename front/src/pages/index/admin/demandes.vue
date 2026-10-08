<template>
  <AdminPage testid="admin-requests">
    <section class="cm-card">
      <div class="admin__filters">
        <q-select
          v-model="store.requestInvited"
          :options="FILTER_OPTIONS"
          emit-value
          map-options
          outlined
          dense
          label="Afficher"
          class="admin__filter"
          data-testid="request-filter"
          @update:model-value="reload"
        />
      </div>

      <q-banner
        v-if="store.requestsError"
        class="cm-banner--danger q-mb-md"
        rounded
      >
        {{ store.requestsError }}
      </q-banner>

      <q-markup-table
        flat
        dense
        class="admin__table"
        data-testid="request-table"
      >
        <thead>
          <tr>
            <th class="text-left">#</th>
            <th class="text-left">Email</th>
            <th class="text-left">Demandée</th>
            <th class="text-left">Invitation</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!store.requestsLoading && store.requests.length === 0">
            <td colspan="5" class="cm-muted">Aucune demande.</td>
          </tr>
          <tr
            v-for="(req, i) in store.requests"
            :key="req.id"
            data-testid="request-row"
          >
            <td class="cm-muted">{{
              (store.requestPage - 1) * PAGE_SIZE + i + 1
            }}</td>
            <td>
              {{ req.email }}
              <span
                v-if="req.hasAccount"
                class="admin__status admin__status--used q-ml-xs"
                data-testid="request-has-account"
                >A déjà un compte</span
              >
            </td>
            <td>{{ formatDate(req.createdAt) }}</td>
            <td>
              <template v-if="req.invitation">
                <span
                  :class="[
                    'admin__status',
                    `admin__status--${req.invitation.status}`
                  ]"
                  >{{ STATUS_LABELS[req.invitation.status] }}</span
                >
                <span class="cm-muted q-ml-xs"
                  >le {{ formatDate(req.invitedAt) }} ·
                  <code>{{ req.invitation.keyHint }}…</code></span
                >
              </template>
              <span v-else-if="req.invitedAt" class="cm-muted"
                >Invitée le {{ formatDate(req.invitedAt) }}</span
              >
              <span v-else class="cm-muted">En attente</span>
            </td>
            <td class="text-right no-wrap">
              <q-btn
                v-if="!req.invitedAt"
                flat
                dense
                no-caps
                color="primary"
                label="Inviter"
                :loading="busy === req.id"
                data-testid="request-invite"
                @click="invite(req)"
              />
              <q-btn
                flat
                dense
                no-caps
                color="negative"
                label="Supprimer"
                :disable="busy === req.id"
                data-testid="request-delete"
                @click="remove(req)"
              />
            </td>
          </tr>
        </tbody>
      </q-markup-table>

      <div class="admin__pager">
        <span class="cm-muted">{{ store.requestTotal }} demande(s)</span>
        <q-pagination
          v-if="pages > 1"
          v-model="store.requestPage"
          :max="pages"
          :max-pages="7"
          direction-links
          boundary-links
          @update:model-value="store.loadRequests()"
        />
      </div>
    </section>

    <KeyDialog :invitation="shown" :resent="false" @close="shown = null" />
  </AdminPage>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { PAGE_SIZE, useAdminStore } from '@/stores/admin'
import AdminPage from '@/components/admin/AdminPage.vue'
import KeyDialog from '@/components/admin/KeyDialog.vue'
import { apiErrorMessage } from '@/utils/apiError'
import { formatDate } from '@/utils/format'
import { STATUS_LABELS } from '@/utils/admin/invitations'

/**
 * The early access waiting list (docs/EARLY_ACCESS.md): who asked, in order of arrival; invite
 * (a 7-day key, emailed and shown once) or delete a request.
 */
definePage({ meta: { auth: 'admin' } })

const FILTER_OPTIONS = [
  { value: false, label: 'En attente' },
  { value: true, label: 'Invitées' },
  { value: null, label: 'Toutes' }
]

const $q = useQuasar()
const store = useAdminStore()
/** @type {import('vue').Ref<{email: string, key: string}|null>} the key shown once */
const shown = ref(null)
/** @type {import('vue').Ref<string|null>} the row being invited or deleted */
const busy = ref(null)

const pages = computed(() => Math.ceil(store.requestTotal / PAGE_SIZE))

function reload() {
  store.requestPage = 1
  store.loadRequests()
}

/** @param {import('@/stores/admin').AccessRequest} req */
function invite(req) {
  $q.dialog({
    title: 'Inviter cette adresse ?',
    message: req.hasAccount
      ? `${req.email} a déjà un compte. Une clé (7 jours) lui sera quand même envoyée.`
      : `Une clé valable 7 jours part vers ${req.email}.`,
    cancel: { flat: true, noCaps: true, label: 'Annuler' },
    ok: { noCaps: true, label: 'Inviter', color: 'primary' }
  }).onOk(async () => {
    busy.value = req.id
    try {
      const invited = await store.inviteRequest(req.id)
      shown.value = { email: invited.email, key: invited.key ?? '' }
    } catch (e) {
      $q.notify({
        type: 'negative',
        message: apiErrorMessage(e, {
          409: 'Cette demande a déjà été invitée.'
        })
      })
      store.loadRequests()
    } finally {
      busy.value = null
    }
  })
}

/** @param {import('@/stores/admin').AccessRequest} req */
function remove(req) {
  $q.dialog({
    title: 'Supprimer la demande ?',
    message: `L’adresse ${req.email} sort de la liste d’attente.${req.invitation ? ' Son invitation reste valable.' : ''}`,
    cancel: { flat: true, noCaps: true, label: 'Annuler' },
    ok: { noCaps: true, label: 'Supprimer', color: 'negative' }
  }).onOk(async () => {
    busy.value = req.id
    try {
      await store.deleteRequest(req.id)
    } catch (e) {
      $q.notify({ type: 'negative', message: apiErrorMessage(e) })
    } finally {
      busy.value = null
    }
  })
}

onMounted(() => store.loadRequests())
</script>
