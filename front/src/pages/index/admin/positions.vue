<template>
  <AdminPage testid="admin-positions">
    <template #actions>
      <q-btn
        unelevated
        no-caps
        color="primary"
        icon="add"
        :label="$t('admin.positions.nouvellePosition')"
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
          :label="$t('admin.positions.statut')"
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
          :label="$t('admin.positions.etiquette')"
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
            <th class="text-left">{{ $t('admin.positions.position') }}</th>
            <th class="text-left">{{ $t('admin.positions.evaluation') }}</th>
            <th class="text-left">{{ $t('admin.positions.libelles') }}</th>
            <th class="text-left">{{ $t('admin.positions.plan') }}</th>
            <th class="text-right">{{ $t('admin.positions.elo') }}</th>
            <th class="text-right">{{ $t('admin.positions.jouee') }}</th>
            <th class="text-left">{{ $t('admin.positions.statut') }}</th>
            <th class="text-right">{{ $t('admin.positions.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && positions.length === 0">
            <td colspan="8" class="cm-muted">{{
              $t('admin.positions.aucunePositionAjouteLa')
            }}</td>
          </tr>
          <tr v-for="p in positions" :key="p.id" data-testid="position-row">
            <td>
              <div
                >{{
                  p.turn === 'white'
                    ? $t('admin.positions.turnWhite')
                    : $t('admin.positions.turnBlack')
                }}<span v-if="p.tag">{{
                  $t('admin.positions.tag', { tag: tagLabel(p.tag) })
                }}</span></div
              >
              <code class="position-fen">{{ p.fen }}</code>
            </td>
            <td class="no-wrap">
              <strong>{{ engineLabel(p.evalCp) }}</strong>
              <span
                v-if="p.nearBorder"
                class="position-border"
                :title="$t('admin.positions.aMoinsDe0')"
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
                >{{
                  p.active
                    ? $t('admin.positions.active')
                    : $t('admin.positions.inactive')
                }}</span
              >
            </td>
            <td class="text-right no-wrap">
              <q-btn
                flat
                dense
                no-caps
                :label="$t('admin.positions.modifier')"
                data-testid="position-edit"
                @click="edit(p)"
              />
              <q-btn
                v-if="p.played === 0"
                flat
                dense
                no-caps
                color="negative"
                :label="$t('admin.positions.supprimer')"
                :disable="busy === p.id"
                data-testid="position-delete"
                @click="remove(p)"
              />
            </td>
          </tr>
        </tbody>
      </q-markup-table>

      <div class="admin__pager">
        <span class="cm-muted">{{
          $t('admin.positions.total', { n: total })
        }}</span>
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
import { engineLabel } from '@/utils/evaluation'
import { t } from '@/i18n'

/**
 * Positions to evaluate (docs/EVALUATION.md): the catalogue entered by hand. A position already
 * played can't be deleted: deactivate it (it is no longer served).
 */
definePage({ meta: { auth: 'admin' } })

const PAGE_SIZE = 20
const ACTIVE_OPTIONS = [
  [null, 'all'],
  [true, 'active'],
  [false, 'inactive']
].map(([value, key]) => ({
  value,
  get label() {
    return t(`admin.positions.filters.${key}`)
  }
}))

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
    title: t('admin.positions.deleteTitle'),
    message: t('admin.positions.deleteMessage'),
    cancel: { flat: true, noCaps: true, label: t('common.actions.cancel') },
    ok: { noCaps: true, label: t('admin.positions.delete'), color: 'negative' }
  }).onOk(async () => {
    busy.value = p.id
    try {
      await adminApi.deleteEvaluationPosition(p.id)
      await load()
    } catch (e) {
      $q.notify({
        type: 'negative',
        message: apiErrorMessage(e, {
          409: t('admin.positions.played')
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
