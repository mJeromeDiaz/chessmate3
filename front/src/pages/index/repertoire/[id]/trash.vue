<template>
  <q-page padding>
    <div class="trash-page q-gutter-y-md">
      <div class="row items-center no-wrap">
        <q-btn
          flat
          round
          dense
          icon="arrow_back"
          :to="`/repertoire/${repertoireId}`"
          :aria-label="$t('repertoire.trash.back')"
        />
        <div class="text-h5 q-ml-sm ellipsis">
          {{
            store.graph?.id === repertoireId
              ? $t('repertoire.trash.named', { name: store.graph.name })
              : $t('repertoire.trash.title')
          }}
        </div>
      </div>

      <div class="text-body2 cm-muted">
        {{ $t('repertoire.trash.lead') }}
      </div>

      <q-banner v-if="loadError" rounded class="bg-negative text-white">{{
        loadError
      }}</q-banner>

      <q-list v-else-if="suites === null" bordered separator>
        <q-item v-for="i in 3" :key="i">
          <q-item-section><q-skeleton type="text" /></q-item-section>
        </q-item>
      </q-list>

      <div
        v-else-if="suites.length === 0"
        class="text-grey q-pa-lg text-center"
        data-testid="trash-empty"
      >
        {{ $t('repertoire.trash.empty') }}
      </div>

      <q-list v-else bordered separator>
        <q-item
          v-for="suite in suites"
          :key="suite.id"
          data-testid="trash-suite"
        >
          <q-item-section>
            <q-item-label class="text-weight-medium">{{
              suiteText(suite)
            }}</q-item-label>
            <q-item-label caption>
              {{ reasonText(suite.reason) }} ·
              {{ $t('repertoire.trash.moves', suite.moveCount) }} ·
              {{ formatDate(suite.createdAt) }}
            </q-item-label>
          </q-item-section>
          <q-item-section side>
            <div class="row no-wrap q-gutter-xs">
              <q-btn
                flat
                dense
                no-caps
                icon="restore"
                :label="$t('repertoire.trash.restore')"
                data-testid="trash-restore"
                @click="openRestore(suite)"
              />
              <q-btn
                flat
                dense
                round
                color="negative"
                icon="delete_forever"
                :aria-label="$t('repertoire.trash.discard')"
                data-testid="trash-discard"
                @click="confirmDiscard(suite)"
              >
                <q-tooltip>{{ $t('repertoire.trash.discard') }}</q-tooltip>
              </q-btn>
            </div>
          </q-item-section>
        </q-item>
      </q-list>
    </div>

    <q-dialog v-model="restoring.open" data-testid="restore-dialog">
      <q-card v-if="restoring.suite" style="width: 600px; max-width: 94vw">
        <q-card-section class="text-h6">{{
          $t('repertoire.trash.restoreTitle', {
            suite: suiteText(restoring.suite)
          })
        }}</q-card-section>
        <q-card-section class="q-pt-none relative-position">
          <template v-if="restoring.preview === null && !restoring.loading">
            <div data-testid="restore-impossible">
              {{ $t('repertoire.trash.impossible') }}
            </div>
          </template>
          <template v-else-if="restoring.preview">
            <div
              v-if="restoring.preview.conflicts.length"
              class="q-mb-md"
              data-testid="restore-conflicts"
            >
              <div class="q-mb-xs">
                {{ $t('repertoire.trash.conflictsHint') }}
              </div>
              <q-list bordered separator>
                <q-item
                  v-for="conflict in restoring.preview.conflicts"
                  :key="conflict.fen"
                  data-testid="restore-conflict"
                >
                  <q-item-section>
                    <q-item-label class="text-weight-medium">{{
                      pathText(conflict.path)
                    }}</q-item-label>
                    <q-option-group
                      :model-value="conflict.choice"
                      inline
                      dense
                      :options="[
                        {
                          label: $t('repertoire.trash.restored', {
                            san: conflict.restored.san
                          }),
                          value: 'restored'
                        },
                        {
                          label: $t('repertoire.trash.current', {
                            san: conflict.current.san
                          }),
                          value: 'current'
                        }
                      ]"
                      @update:model-value="
                        choice => choose(conflict.fen, choice)
                      "
                    />
                  </q-item-section>
                </q-item>
              </q-list>
            </div>
            <div data-testid="restore-summary">{{ summary }}</div>
          </template>
          <q-inner-loading :showing="restoring.loading" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn
            v-close-popup
            flat
            no-caps
            :label="$t('common.actions.cancel')"
          />
          <q-btn
            color="primary"
            no-caps
            :label="$t('repertoire.trash.restore')"
            :disable="!restoring.preview || restoring.loading"
            :loading="restoring.saving"
            data-testid="restore-confirm"
            @click="confirmRestore"
          />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useQuasar } from 'quasar'
import { useRepertoireStore } from '@/stores/repertoire'
import { apiErrorMessage } from '@/utils/apiError'
import { pathText } from '@/utils/repertoireImport'
import { intlLocale, t } from '@/i18n'

definePage({ meta: { auth: 'required' } })

/** Why a suite is in the trash (App\Enum\Repertoire\TrashReason). */
const REASONS = ['replaced', 'deleted', 'imported', 'migrated']

/** @param {string} reason */
const reasonText = reason =>
  REASONS.includes(reason) ? t(`repertoire.trash.reasons.${reason}`) : reason

const route = useRoute()
const router = useRouter()
const $q = useQuasar()
const store = useRepertoireStore()

const repertoireId = computed(() => /** @type {string} */ (route.params.id))
/** @type {import('vue').Ref<import('@/stores/repertoire').TrashedSuite[]|null>} */
const suites = ref(null)
const loadError = ref('')

/** The restoration being previewed: the suite, the choices sent, the API's preview. */
const restoring = reactive({
  open: false,
  /** @type {import('@/stores/repertoire').TrashedSuite|null} */
  suite: null,
  /** @type {Record<string, 'restored'|'current'>} */
  choices: {},
  /** @type {import('@/stores/repertoire').RestorePreview|null} */
  preview: null,
  loading: false,
  saving: false
})

const summary = computed(() => {
  const p = restoring.preview
  if (!p) return ''
  const s = 'repertoire.trash.summary.'
  const parts = [t(`${s}back`, p.moves)]
  if (p.joined) parts.push(t(`${s}joined`, p.joined))
  if (p.leftOut) parts.push(t(`${s}leftOut`, p.leftOut))
  const text = parts.join(', ')
  return p.replaced
    ? t(`${s}replaced`, { text, n: p.replaced }, p.replaced)
    : t(`${s}done`, { text })
})

/**
 * The suite's first move with the moves leading to it: "1.e4 e5 2.Nf3 Nc6 3.Bb5".
 *
 * @param {import('@/stores/repertoire').TrashedSuite} suite
 */
function suiteText(suite) {
  return [...suite.path, suite.san]
    .map((san, ply) => {
      const number = Math.floor(ply / 2) + 1
      if (ply % 2 === 0) return `${number}.${san}`
      return ply === suite.path.length ? `${number}…${san}` : san
    })
    .join(' ')
}

/** @param {string} iso */
function formatDate(iso) {
  return new Date(iso).toLocaleString(intlLocale(), {
    dateStyle: 'medium',
    timeStyle: 'short'
  })
}

async function load() {
  loadError.value = ''
  suites.value = null
  try {
    if (store.graph?.id !== repertoireId.value)
      await store.load(repertoireId.value)
    suites.value = await store.fetchTrash()
  } catch (e) {
    loadError.value = apiErrorMessage(e, {
      404: t('repertoire.stats.notFound')
    })
  }
}

/** @param {import('@/stores/repertoire').TrashedSuite} suite */
async function openRestore(suite) {
  Object.assign(restoring, {
    open: true,
    suite,
    choices: {},
    preview: null,
    loading: true,
    saving: false
  })
  await refreshPreview()
}

async function refreshPreview() {
  if (!restoring.suite) return
  restoring.loading = true
  try {
    const data = await store.previewRestore(
      restoring.suite.id,
      restoring.choices
    )
    restoring.preview = data.restorable ? data.preview : null
  } catch (e) {
    restoring.open = false
    $q.notify({ type: 'negative', message: apiErrorMessage(e) })
    await load()
  } finally {
    restoring.loading = false
  }
}

/**
 * @param {string} fen
 * @param {'restored'|'current'} choice
 */
function choose(fen, choice) {
  restoring.choices = { ...restoring.choices, [fen]: choice }
  refreshPreview()
}

async function confirmRestore() {
  const suite = restoring.suite
  if (!suite) return
  restoring.saving = true
  const change = await store.restore(suite.id, restoring.choices)
  restoring.saving = false
  restoring.open = false
  if (change) {
    $q.notify({
      type: 'positive',
      message: t('repertoire.trash.isBack', { suite: suiteText(suite) }),
      actions: [
        {
          label: t('repertoire.trash.open'),
          color: 'white',
          noCaps: true,
          handler: () => router.push(`/repertoire/${repertoireId.value}`)
        }
      ]
    })
  }
  await load()
}

/** @param {import('@/stores/repertoire').TrashedSuite} suite */
function confirmDiscard(suite) {
  $q.dialog({
    title: t('repertoire.trash.discardTitle'),
    message: t('repertoire.trash.discardMessage', {
      suite: suiteText(suite),
      moves: t('repertoire.trash.moves', suite.moveCount - 1)
    }),
    cancel: { flat: true, noCaps: true, label: t('common.actions.cancel') },
    ok: {
      color: 'negative',
      noCaps: true,
      label: t('repertoire.trash.discard')
    },
    persistent: false
  }).onOk(async () => {
    try {
      await store.discard(suite.id)
      suites.value = (suites.value ?? []).filter(s => s.id !== suite.id)
    } catch (e) {
      $q.notify({ type: 'negative', message: apiErrorMessage(e) })
      await load()
    }
  })
}

// A refused restoration: the store reloaded the graph; say why.
watch(
  () => store.failure,
  failure => {
    if (!failure || failure.operation !== 'restore') return
    $q.notify({
      type: 'negative',
      message:
        failure.status === 409
          ? t('repertoire.trash.stale')
          : failure.status === 422
            ? t('repertoire.trash.limit')
            : t('repertoire.trash.failed')
    })
  }
)

watch(repertoireId, id => id && load())
onMounted(load)
</script>

<style lang="scss">
.trash-page {
  max-width: 820px;
  margin: 0 auto;
}
</style>
