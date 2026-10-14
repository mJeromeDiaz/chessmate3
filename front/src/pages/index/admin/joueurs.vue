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
          :label="$t('admin.joueurs.rechercherEmailHandleNom')"
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
          :label="$t('admin.joueurs.suspension')"
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
            <th class="text-left">{{ $t('admin.joueurs.joueur') }}</th>
            <th class="text-left">{{ $t('admin.joueurs.inscrit') }}</th>
            <th class="text-left">{{ $t('admin.joueurs.methode') }}</th>
            <th class="text-left">{{ $t('admin.joueurs.activite') }}</th>
            <th class="text-right">{{ $t('admin.joueurs.temps30J') }}</th>
            <th class="text-right">{{ $t('admin.joueurs.eloLichess') }}</th>
            <th class="text-right">{{ $t('admin.joueurs.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!store.playersLoading && store.players.length === 0">
            <td colspan="7" class="cm-muted">{{
              $t('admin.joueurs.aucunJoueur')
            }}</td>
          </tr>
          <tr v-for="p in store.players" :key="p.id" data-testid="player-row">
            <td>
              <div class="admin-player__name">{{ playerName(p) }}</div>
              <div v-if="p.email && playerName(p) !== p.email" class="cm-muted">
                {{ p.email }}
              </div>
              <div class="admin-player__badges">
                <span v-if="p.isAdmin" class="admin__status">{{
                  $t('admin.joueurs.admin')
                }}</span>
                <span
                  v-if="p.email && !p.emailVerified"
                  class="admin__status"
                  >{{ $t('admin.joueurs.emailNonVerifie') }}</span
                >
                <span
                  v-if="p.deletionScheduledAt"
                  class="admin__status admin__status--danger"
                  >{{ $t('admin.joueurs.suppressionProgrammee') }}</span
                >
                <span
                  v-if="p.suspendedAt"
                  class="admin__status admin__status--danger"
                  data-testid="player-suspended"
                  >{{
                    $t('admin.joueurs.suspendedOn', {
                      date: formatDate(p.suspendedAt)
                    })
                  }}</span
                >
              </div>
              <div
                v-if="p.suspensionReason"
                class="cm-muted admin-player__reason"
              >
                {{ $t('admin.joueurs.note', { note: p.suspensionReason }) }}
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
                >{{
                  p.active
                    ? $t('admin.joueurs.active')
                    : $t('admin.joueurs.inactive')
                }}</span
              >
              <div class="cm-muted">
                {{
                  p.lastActivityAt
                    ? $t('admin.joueurs.lastExercise', {
                        date: formatDate(p.lastActivityAt)
                      })
                    : $t('admin.joueurs.noExercise')
                }}
              </div>
            </td>
            <td class="text-right">{{ formatHours(p.trainingMs30d) }}</td>
            <td class="text-right">
              <template v-if="p.lichess?.rating">
                {{ p.lichess.rating }}
                <span class="cm-muted">{{
                  PERFS.includes(p.lichess.perf)
                    ? $t(`onboarding.perfs.${p.lichess.perf}`)
                    : p.lichess.perf
                }}</span>
              </template>
              <span v-else-if="p.lichess" class="cm-muted">{{
                $t('admin.joueurs.nonClasse')
              }}</span>
              <span v-else class="cm-muted">—</span>
            </td>
            <td class="text-right no-wrap">
              <q-btn
                v-if="p.suspendedAt"
                flat
                dense
                no-caps
                :label="$t('admin.joueurs.reactiver')"
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
                :label="$t('admin.joueurs.suspendre')"
                :loading="busy === p.id"
                data-testid="player-suspend"
                @click="suspend(p)"
              />
            </td>
          </tr>
        </tbody>
      </q-markup-table>

      <div class="admin__pager">
        <span class="cm-muted">{{
          $t('admin.joueurs.total', { n: store.playerTotal })
        }}</span>
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
import { t } from '@/i18n'

/**
 * Players (docs/EARLY_ACCESS.md): every account, how it signed up, how much it plays; suspend one
 * (every session closed at once) or lift its suspension. An admin's account cannot be suspended.
 */
definePage({ meta: { auth: 'admin' } })

/** Lichess speeds with a name (onboarding.perfs). */
const PERFS = ['rapid', 'blitz', 'classical']
const SUSPENSION_OPTIONS = [
  [null, 'all'],
  [true, 'suspended'],
  [false, 'notSuspended']
].map(([value, key]) => ({
  value,
  get label() {
    return t(`admin.joueurs.filters.${key}`)
  }
}))

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
    title: t('admin.joueurs.suspendTitle', { name: playerName(p) }),
    message: t('admin.joueurs.suspendMessage'),
    prompt: {
      model: '',
      type: 'textarea',
      label: t('admin.joueurs.internalNote'),
      counter: true,
      maxlength: 500
    },
    cancel: { flat: true, noCaps: true, label: t('common.actions.cancel') },
    ok: { noCaps: true, label: t('admin.joueurs.suspend'), color: 'negative' }
  }).onOk(async reason => {
    busy.value = p.id
    try {
      await store.suspendPlayer(p.id, reason.trim() || null)
    } catch (e) {
      $q.notify({
        type: 'negative',
        message: apiErrorMessage(e, {
          409: t('admin.joueurs.adminRefused')
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
    title: t('admin.joueurs.reactivateTitle', { name: playerName(p) }),
    message: t('admin.joueurs.reactivateMessage'),
    cancel: { flat: true, noCaps: true, label: t('common.actions.cancel') },
    ok: { noCaps: true, label: t('admin.joueurs.reactivate'), color: 'primary' }
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
