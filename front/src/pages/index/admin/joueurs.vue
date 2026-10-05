<template>
  <AdminPage testid="admin-players">
    <section class="cm-card">
      <div class="admin__filters">
        <q-input
          v-model="store.playerSearch"
          outlined
          dense
          clearable
          debounce="350"
          label="Rechercher (email, handle, nom)"
          class="admin__filter admin__filter--grow"
          data-testid="player-search"
          @update:model-value="reload"
        />
        <q-select
          v-model="store.playerSuspended"
          :options="SUSPENSION_OPTIONS"
          emit-value
          map-options
          outlined
          dense
          label="Suspension"
          class="admin__filter"
          data-testid="player-filter-suspended"
          @update:model-value="reload"
        />
      </div>

      <q-banner
        v-if="store.playersError"
        class="cm-banner--danger q-mb-md"
        rounded
      >
        {{ store.playersError }}
      </q-banner>

      <q-markup-table
        flat
        dense
        class="admin__table"
        data-testid="player-table"
      >
        <thead>
          <tr>
            <th class="text-left">Joueur</th>
            <th class="text-left">Inscrit</th>
            <th class="text-left">Méthode</th>
            <th class="text-left">Activité</th>
            <th class="text-right">Temps 30 j</th>
            <th class="text-right">Elo Lichess</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!store.playersLoading && store.players.length === 0">
            <td colspan="7" class="cm-muted">Aucun joueur.</td>
          </tr>
          <tr v-for="p in store.players" :key="p.id" data-testid="player-row">
            <td>
              <div class="admin-player__name">{{ playerName(p) }}</div>
              <div v-if="p.email && playerName(p) !== p.email" class="cm-muted">
                {{ p.email }}
              </div>
              <div class="admin-player__badges">
                <span v-if="p.isAdmin" class="admin__status">Admin</span>
                <span v-if="p.email && !p.emailVerified" class="admin__status"
                  >Email non vérifié</span
                >
                <span
                  v-if="p.deletionScheduledAt"
                  class="admin__status admin__status--danger"
                  >Suppression programmée</span
                >
                <span
                  v-if="p.suspendedAt"
                  class="admin__status admin__status--danger"
                  data-testid="player-suspended"
                  >Suspendu le {{ formatDate(p.suspendedAt) }}</span
                >
              </div>
              <div
                v-if="p.suspensionReason"
                class="cm-muted admin-player__reason"
              >
                Note : {{ p.suspensionReason }}
              </div>
            </td>
            <td>{{ formatDate(p.createdAt) }}</td>
            <td>
              {{ METHOD_LABELS[p.signupMethod] }}
              <code v-if="p.keyHint" class="q-ml-xs">{{ p.keyHint }}…</code>
            </td>
            <td>
              <span
                :class="[
                  'admin__status',
                  { 'admin__status--active': p.active }
                ]"
                >{{ p.active ? 'Actif' : 'Inactif' }}</span
              >
              <div class="cm-muted">
                {{
                  p.lastActivityAt
                    ? `Dernier exercice ${formatDate(p.lastActivityAt)}`
                    : 'Aucun exercice'
                }}
              </div>
            </td>
            <td class="text-right">{{ formatHours(p.trainingMs30d) }}</td>
            <td class="text-right">
              <template v-if="p.lichess?.rating">
                {{ p.lichess.rating }}
                <span class="cm-muted">{{
                  PERF_LABELS[p.lichess.perf] ?? p.lichess.perf
                }}</span>
              </template>
              <span v-else-if="p.lichess" class="cm-muted">Non classé</span>
              <span v-else class="cm-muted">—</span>
            </td>
            <td class="text-right no-wrap">
              <q-btn
                v-if="p.suspendedAt"
                flat
                dense
                no-caps
                label="Réactiver"
                :loading="busy === p.id"
                data-testid="player-unsuspend"
                @click="unsuspend(p)"
              />
              <q-btn
                v-else-if="!p.isAdmin"
                flat
                dense
                no-caps
                color="negative"
                label="Suspendre"
                :loading="busy === p.id"
                data-testid="player-suspend"
                @click="suspend(p)"
              />
            </td>
          </tr>
        </tbody>
      </q-markup-table>

      <div class="admin__pager">
        <span class="cm-muted">{{ store.playerTotal }} joueur(s)</span>
        <q-pagination
          v-if="pages > 1"
          v-model="store.playerPage"
          :max="pages"
          :max-pages="7"
          direction-links
          boundary-links
          @update:model-value="store.loadPlayers()"
        />
      </div>
    </section>
  </AdminPage>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { PAGE_SIZE, useAdminStore } from '@/stores/admin'
import AdminPage from '@/components/admin/AdminPage.vue'
import { apiErrorMessage } from '@/utils/apiError'
import { formatDate } from '@/utils/format'
import { formatHours } from '@/utils/dashboard/stats'
import { METHOD_LABELS, playerName } from '@/utils/admin/invitations'

/**
 * Players (docs/EARLY_ACCESS.md): every account, how it signed up, how much it plays; suspend one
 * (every session closed at once) or lift its suspension. An admin's account cannot be suspended.
 */
definePage({ meta: { auth: 'admin' } })

const PERF_LABELS = { rapid: 'rapide', blitz: 'blitz', classical: 'classique' }
const SUSPENSION_OPTIONS = [
  { value: null, label: 'Tous' },
  { value: true, label: 'Suspendus' },
  { value: false, label: 'Non suspendus' }
]

const $q = useQuasar()
const store = useAdminStore()
/** @type {import('vue').Ref<string|null>} the row being suspended or reactivated */
const busy = ref(null)
const pages = computed(() => Math.ceil(store.playerTotal / PAGE_SIZE))

function reload() {
  store.playerPage = 1
  store.loadPlayers()
}

/** @param {import('@/stores/admin').Player} p */
function suspend(p) {
  $q.dialog({
    title: `Suspendre ${playerName(p)} ?`,
    message:
      'Toutes ses sessions sont fermées tout de suite, et il ne pourra plus se connecter. Son agenda et ses rappels s’arrêtent. Rien n’est supprimé.',
    prompt: {
      model: '',
      type: 'textarea',
      label: 'Note interne (jamais montrée au joueur)',
      counter: true,
      maxlength: 500
    },
    cancel: { flat: true, noCaps: true, label: 'Annuler' },
    ok: { noCaps: true, label: 'Suspendre', color: 'negative' }
  }).onOk(async reason => {
    busy.value = p.id
    try {
      await store.suspendPlayer(p.id, reason.trim() || null)
    } catch (e) {
      $q.notify({
        type: 'negative',
        message: apiErrorMessage(e, {
          409: 'Un compte admin ne peut pas être suspendu : retirez d’abord son rôle.'
        })
      })
    } finally {
      busy.value = null
    }
  })
}

/** @param {import('@/stores/admin').Player} p */
function unsuspend(p) {
  $q.dialog({
    title: `Réactiver ${playerName(p)} ?`,
    message: 'Il pourra se reconnecter ; son agenda et ses rappels reprennent.',
    cancel: { flat: true, noCaps: true, label: 'Annuler' },
    ok: { noCaps: true, label: 'Réactiver', color: 'primary' }
  }).onOk(async () => {
    busy.value = p.id
    try {
      await store.unsuspendPlayer(p.id)
    } catch (e) {
      $q.notify({ type: 'negative', message: apiErrorMessage(e) })
    } finally {
      busy.value = null
    }
  })
}

onMounted(() => store.loadPlayers())
</script>

<style scoped lang="scss">
.admin-player__name {
  font-weight: 600;
}

.admin-player__reason {
  max-width: 280px;
  font-size: 12px;
  overflow-wrap: anywhere;
}

.admin-player__badges {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 2px;
}
</style>
