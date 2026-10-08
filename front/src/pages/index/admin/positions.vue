<template>
  <AdminPage testid="admin-positions">
    <template #actions>
      <q-btn
        unelevated
        no-caps
        color="primary"
        icon="add"
        label="Nouvelle position"
        data-testid="position-new"
        @click="edit(null)"
      />
    </template>

    <section class="cm-card">
      <div class="admin__filters">
        <q-select
          v-model="active"
          :options="ACTIVE_OPTIONS"
          emit-value
          map-options
          outlined
          dense
          label="Statut"
          class="admin__filter"
          data-testid="position-filter-active"
          @update:model-value="reload"
        />
        <q-select
          v-model="tag"
          :options="TAG_OPTIONS"
          emit-value
          map-options
          outlined
          dense
          label="Étiquette"
          class="admin__filter"
          data-testid="position-filter-tag"
          @update:model-value="reload"
        />
      </div>

      <q-banner v-if="error" class="cm-banner--danger q-mb-md" rounded>{{
        error
      }}</q-banner>

      <q-markup-table
        flat
        dense
        class="admin__table"
        data-testid="position-table"
      >
        <thead>
          <tr>
            <th class="text-left">Position</th>
            <th class="text-left">Évaluation</th>
            <th class="text-left">Libellés</th>
            <th class="text-left">Plan</th>
            <th class="text-right">Elo</th>
            <th class="text-right">Jouée</th>
            <th class="text-left">Statut</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && positions.length === 0">
            <td colspan="8" class="cm-muted"
              >Aucune position. Ajoute la première avec « Nouvelle position
              ».</td
            >
          </tr>
          <tr v-for="p in positions" :key="p.id" data-testid="position-row">
            <td>
              <div
                >{{ p.turn === 'white' ? 'Trait Blancs' : 'Trait Noirs'
                }}<span v-if="p.tag"> · {{ tagLabel(p.tag) }}</span></div
              >
              <code class="position-fen">{{ p.fen }}</code>
            </td>
            <td class="no-wrap">
              <strong>{{ p.engine }}</strong>
              <span
                v-if="p.nearBorder"
                class="position-border"
                title="À moins de 0,2 d’une frontière"
                >⚠</span
              >
            </td>
            <td class="position-ideas">{{ p.ideas.join(' · ') }}</td>
            <td>{{ planLabel(p.plan) }}</td>
            <td class="text-right">{{ p.rating }}</td>
            <td class="text-right">{{ p.played }}</td>
            <td>
              <span
                :class="[
                  'admin__status',
                  p.active ? 'admin__status--active' : ''
                ]"
                >{{ p.active ? 'Active' : 'Inactive' }}</span
              >
            </td>
            <td class="text-right no-wrap">
              <q-btn
                flat
                dense
                no-caps
                label="Modifier"
                data-testid="position-edit"
                @click="edit(p)"
              />
              <q-btn
                v-if="p.played === 0"
                flat
                dense
                no-caps
                color="negative"
                label="Supprimer"
                :disable="busy === p.id"
                data-testid="position-delete"
                @click="remove(p)"
              />
            </td>
          </tr>
        </tbody>
      </q-markup-table>

      <div class="admin__pager">
        <span class="cm-muted">{{ total }} position(s)</span>
        <q-pagination
          v-if="pages > 1"
          v-model="page"
          :max="pages"
          :max-pages="7"
          direction-links
          boundary-links
          @update:model-value="load"
        />
      </div>
    </section>

    <PositionForm
      :open="formOpen"
      :position="editing"
      @close="formOpen = false"
      @saved="onSaved"
    />
  </AdminPage>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { adminApi } from '@/services/api'
import AdminPage from '@/components/admin/AdminPage.vue'
import PositionForm from '@/components/admin/PositionForm.vue'
import { apiErrorMessage } from '@/utils/apiError'
import { PLAN_OPTIONS, TAG_OPTIONS } from '@/utils/admin/evaluation'

/**
 * Positions to evaluate (docs/EVALUATION.md): the catalogue entered by hand. A position already
 * played can't be deleted: deactivate it (it is no longer served).
 */
definePage({ meta: { auth: 'admin' } })

const PAGE_SIZE = 20
const ACTIVE_OPTIONS = [
  { value: null, label: 'Toutes' },
  { value: true, label: 'Actives' },
  { value: false, label: 'Inactives' }
]

const $q = useQuasar()
/** @type {import('vue').Ref<any[]>} */
const positions = ref([])
const total = ref(0)
const page = ref(1)
/** @type {import('vue').Ref<boolean|null>} */
const active = ref(null)
/** @type {import('vue').Ref<string|null>} */
const tag = ref(null)
const loading = ref(false)
const error = ref('')
/** @type {import('vue').Ref<string|null>} the row being deleted */
const busy = ref(null)
const formOpen = ref(false)
/** @type {import('vue').Ref<object|null>} */
const editing = ref(null)
const pages = computed(() => Math.ceil(total.value / PAGE_SIZE))

/** @param {string|null} value */
const planLabel = value =>
  value ? (PLAN_OPTIONS.find(o => o.value === value)?.label ?? value) : '—'
/** @param {string} value */
const tagLabel = value =>
  TAG_OPTIONS.find(o => o.value === value)?.label ?? value

async function load() {
  loading.value = true
  error.value = ''
  try {
    const result = await adminApi.evaluationPositions({
      page: page.value,
      itemsPerPage: PAGE_SIZE,
      active: active.value,
      tag: tag.value
    })
    positions.value = result.member
    total.value = result.totalItems
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function reload() {
  page.value = 1
  load()
}

/** @param {object|null} position */
function edit(position) {
  editing.value = position
  formOpen.value = true
}

function onSaved() {
  formOpen.value = false
  load()
}

/** @param {{id: string}} p */
function remove(p) {
  $q.dialog({
    title: 'Supprimer cette position ?',
    message: 'Elle n’a jamais été jouée : elle disparaît du catalogue.',
    cancel: { flat: true, noCaps: true, label: 'Annuler' },
    ok: { noCaps: true, label: 'Supprimer', color: 'negative' }
  }).onOk(async () => {
    busy.value = p.id
    try {
      await adminApi.deleteEvaluationPosition(p.id)
      await load()
    } catch (e) {
      $q.notify({
        type: 'negative',
        message: apiErrorMessage(e, {
          409: 'Déjà jouée : désactive-la plutôt.'
        })
      })
    } finally {
      busy.value = null
    }
  })
}

onMounted(load)
</script>

<style scoped lang="scss">
.position-fen {
  display: block;
  max-width: 260px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.position-ideas {
  max-width: 320px;
  white-space: normal;
}

.position-border {
  margin-left: 4px;
  color: var(--cm-orange-ink);
}
</style>
