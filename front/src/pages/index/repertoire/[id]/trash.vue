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
          aria-label="Retour au répertoire"
        />
        <div class="text-h5 q-ml-sm ellipsis">
          Corbeille<template v-if="store.graph?.id === repertoireId">
            · {{ store.graph.name }}</template
          >
        </div>
      </div>

      <div class="text-body2 text-grey-8">
        Un coup remplacé ou supprimé arrive ici avec tout ce qui n’était atteint
        que par lui. Restauré, il revient avec sa progression.
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
        La corbeille est vide.
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
              {{ REASONS[suite.reason] ?? suite.reason }} ·
              {{ plural(suite.moveCount, 'coup') }} ·
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
                label="Restaurer"
                data-testid="trash-restore"
                @click="openRestore(suite)"
              />
              <q-btn
                flat
                dense
                round
                color="negative"
                icon="delete_forever"
                aria-label="Supprimer définitivement"
                data-testid="trash-discard"
                @click="confirmDiscard(suite)"
              >
                <q-tooltip>Supprimer définitivement</q-tooltip>
              </q-btn>
            </div>
          </q-item-section>
        </q-item>
      </q-list>
    </div>

    <q-dialog v-model="restoring.open" data-testid="restore-dialog">
      <q-card v-if="restoring.suite" style="width: 600px; max-width: 94vw">
        <q-card-section class="text-h6"
          >Restaurer {{ suiteText(restoring.suite) }}</q-card-section
        >
        <q-card-section class="q-pt-none relative-position">
          <template v-if="restoring.preview === null && !restoring.loading">
            <div data-testid="restore-impossible">
              Sa position de départ n’est plus dans le répertoire : restaurez
              d’abord la suite qui la contient.
            </div>
          </template>
          <template v-else-if="restoring.preview">
            <div
              v-if="restoring.preview.conflicts.length"
              class="q-mb-md"
              data-testid="restore-conflicts"
            >
              <div class="q-mb-xs">
                Un seul coup vous est préparé par position : choisissez lequel
                garder.
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
                          label: `${conflict.restored.san} (restauré)`,
                          value: 'restored'
                        },
                        {
                          label: `${conflict.current.san} (actuel)`,
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
          <q-btn v-close-popup flat no-caps label="Annuler" />
          <q-btn
            color="primary"
            no-caps
            label="Restaurer"
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

definePage({ meta: { auth: 'required' } })

/** Why a suite is in the trash (App\Enum\Repertoire\TrashReason). */
const REASONS = {
  replaced: 'Remplacé',
  deleted: 'Supprimé',
  imported: 'Remplacé par un import',
  migrated: 'Mis de côté (ancienne alternative)'
}

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
  const parts = [
    `${plural(p.moves, 'coup')} ${p.moves > 1 ? 'reviennent' : 'revient'}`
  ]
  if (p.joined)
    parts.push(
      `${plural(p.joined, 'coup')} déjà présent${p.joined > 1 ? 's' : ''}`
    )
  if (p.leftOut)
    parts.push(
      `${plural(p.leftOut, 'coup')} laissé${p.leftOut > 1 ? 's' : ''} de côté`
    )
  const text = parts.join(', ')
  return p.replaced
    ? `${text}. ${plural(p.replaced, 'coup actuel', 'coups actuels')} ${p.replaced > 1 ? 'partent' : 'part'} à la corbeille avec sa suite.`
    : `${text}.`
})

/**
 * @param {number} n
 * @param {string} one
 * @param {string} [many]
 */
function plural(n, one, many = `${one}s`) {
  return `${n} ${n > 1 ? many : one}`
}

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
  return new Date(iso).toLocaleString('fr-FR', {
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
    loadError.value = apiErrorMessage(e, { 404: 'Répertoire introuvable.' })
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
      message: `${suiteText(suite)} est de retour dans le répertoire.`,
      actions: [
        {
          label: 'Ouvrir le répertoire',
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
    title: 'Supprimer définitivement ?',
    message: `${suiteText(suite)} et ${plural(suite.moveCount - 1, 'coup')} qui le suivent seront supprimés pour de bon : ni restauration, ni annulation possibles.`,
    cancel: { flat: true, noCaps: true, label: 'Annuler' },
    ok: {
      color: 'negative',
      noCaps: true,
      label: 'Supprimer définitivement'
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
          ? 'Le répertoire a changé entre-temps, ou la position de départ n’y est plus : rien n’a été restauré.'
          : failure.status === 422
            ? 'La restauration dépasserait une limite du répertoire (positions ou profondeur).'
            : 'La restauration n’a pas été enregistrée.'
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
