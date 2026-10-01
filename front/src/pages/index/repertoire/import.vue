<template>
  <q-page padding>
    <div class="import-page q-gutter-y-md">
      <div class="row items-center">
        <q-btn
          flat
          round
          dense
          icon="arrow_back"
          to="/repertoire"
          aria-label="Mes répertoires"
        />
        <div class="text-h5 q-ml-sm">Importer un répertoire</div>
      </div>

      <!-- 1. The source -->
      <template v-if="imp.phase.value === 'source'">
        <q-tabs
          v-model="source"
          dense
          no-caps
          align="left"
          active-color="primary"
        >
          <q-tab
            name="file"
            label="Fichier (PGN, OpenBook)"
            data-testid="import-tab-file"
          />
          <q-tab
            name="paste"
            label="Coller un PGN"
            data-testid="import-tab-paste"
          />
          <q-tab
            name="study"
            label="Étude Lichess"
            data-testid="import-tab-study"
          />
        </q-tabs>
        <q-separator />

        <q-file
          v-if="source === 'file'"
          v-model="file"
          outlined
          accept=".pgn,.json,text/plain,application/x-chess-pgn,application/json"
          label="Fichier .pgn, ou sauvegarde OpenBook .json (1 Mo au plus)"
          data-testid="import-file"
        >
          <template #prepend><q-icon name="attach_file" /></template>
        </q-file>
        <q-input
          v-else-if="source === 'paste'"
          v-model="pasted"
          outlined
          type="textarea"
          rows="10"
          label="PGN : une ou plusieurs parties, variantes et commentaires compris"
          input-class="import-page__pgn"
          data-testid="import-pgn"
        />
        <q-input
          v-else
          v-model="studyUrl"
          outlined
          label="Lien de l’étude ou du chapitre"
          placeholder="https://lichess.org/study/…"
          hint="Une étude publique se lit directement ; une étude privée ou non répertoriée demande votre autorisation."
          data-testid="import-study-url"
        />

        <q-banner
          v-if="imp.error.value"
          rounded
          class="bg-negative text-white"
          data-testid="import-error"
        >
          {{ imp.error.value.message }}
          <template v-if="imp.error.value.code === 'study_private'" #action>
            <q-btn
              v-if="lichessLinked"
              flat
              no-caps
              color="white"
              label="Autoriser l’accès à mes études"
              :loading="granting"
              data-testid="import-grant"
              @click="grant"
            />
            <q-btn
              v-else
              flat
              no-caps
              color="white"
              label="Lier mon compte Lichess"
              to="/profile"
            />
          </template>
        </q-banner>

        <div class="row q-gutter-sm">
          <q-btn
            color="primary"
            no-caps
            label="Analyser"
            :loading="imp.busy.value"
            :disable="!ready"
            data-testid="import-analyze"
            @click="analyze"
          />
          <q-btn flat no-caps label="Annuler" to="/repertoire" />
        </div>
        <div class="text-caption text-grey-8">
          Chaque coup est vérifié. Vos coups (ceux de la couleur du répertoire)
          deviennent les coups testés, un seul par position ; ceux de
          l’adversaire, ses réponses.
        </div>
      </template>

      <!-- 2. A worker at work -->
      <div
        v-else-if="
          imp.phase.value === 'analyzing' || imp.phase.value === 'applying'
        "
        class="q-pa-lg"
        data-testid="import-progress"
      >
        <div class="q-mb-sm">
          {{
            imp.phase.value === 'analyzing'
              ? 'Analyse du fichier…'
              : 'Import dans le répertoire…'
          }}
        </div>
        <q-linear-progress
          :value="(imp.current.value?.progress ?? 0) / 100"
          size="12px"
          rounded
          color="primary"
        />
        <div class="text-caption text-grey-8 q-mt-sm">
          Un gros fichier prend un moment : vous pouvez laisser cette page
          ouverte.
        </div>
      </div>

      <!-- 3. The preview -->
      <template v-else-if="imp.phase.value === 'preview' && imp.current.value">
        <div class="text-body2 text-grey-8">
          {{ sourceLabel
          }}<template v-if="imp.current.value.source !== 'openbook'">
            · {{ imp.current.value.games }} partie{{
              imp.current.value.games > 1 ? 's' : ''
            }}</template
          >
        </div>

        <q-card flat bordered class="q-pa-md">
          <div class="text-subtitle1 q-mb-sm">Destination</div>
          <q-option-group
            v-model="imp.destination.value.mode"
            inline
            :options="[
              { label: 'Nouveau répertoire', value: 'new' },
              {
                label: 'Un de mes répertoires',
                value: 'existing',
                disable: store.repertoires.length === 0
              }
            ]"
            data-testid="import-destination"
          />
          <div
            v-if="imp.destination.value.mode === 'new'"
            class="row q-col-gutter-md q-mt-xs"
          >
            <q-input
              v-model="imp.destination.value.name"
              class="col-12 col-sm-8"
              label="Nom"
              maxlength="80"
              :rules="[v => !!v?.trim() || 'Nom requis']"
              data-testid="import-name"
            />
            <div class="col-12 col-sm-4">
              <q-btn-toggle
                v-model="imp.destination.value.color"
                no-caps
                unelevated
                toggle-color="primary"
                :options="[
                  { label: 'Blancs', value: 'white' },
                  { label: 'Noirs', value: 'black' }
                ]"
                data-testid="import-color"
              />
            </div>
          </div>
          <q-select
            v-else
            v-model="target"
            class="q-mt-sm"
            outlined
            :options="targetOptions"
            emit-value
            map-options
            label="Répertoire"
            data-testid="import-target"
          />
        </q-card>

        <q-banner
          v-if="imp.error.value"
          rounded
          class="bg-negative text-white"
          data-testid="import-error"
          >{{ imp.error.value.message }}</q-banner
        >

        <div class="relative-position">
          <ImportPreview
            v-if="imp.current.value.preview"
            :choices="imp.choices.value"
            :preview="imp.current.value.preview"
            :source="imp.current.value.source"
            @update:choices="c => imp.setChoices(c)"
            @choose-all="side => imp.chooseAll(side)"
          />
          <q-inner-loading :showing="imp.previewLoading.value" />
        </div>

        <div class="row items-center q-gutter-sm">
          <q-btn
            color="primary"
            no-caps
            label="Importer"
            :loading="imp.busy.value"
            :disable="!canApply"
            data-testid="import-apply"
            @click="imp.apply()"
          />
          <q-btn flat no-caps label="Recommencer" @click="restart" />
          <div class="text-caption text-grey-8">
            L’import compte comme une seule modification : le bouton Annuler de
            l’éditeur le défait.
          </div>
        </div>
      </template>

      <!-- Failed analysis -->
      <template v-else-if="imp.phase.value === 'failed'">
        <q-banner
          rounded
          class="bg-negative text-white"
          data-testid="import-error"
          >{{ imp.error.value?.message }}</q-banner
        >
        <q-btn color="primary" no-caps label="Recommencer" @click="restart" />
      </template>
    </div>
  </q-page>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useQuasar } from 'quasar'
import ImportPreview from '@/components/repertoire/ImportPreview.vue'
import { useImport } from '@/composables/repertoire/useImport'
import { useRepertoireStore } from '@/stores/repertoire'
import { useAuthStore } from '@/stores/auth'
import { profileApi } from '@/services/api'
import { readText } from '@/utils/download'
import { MAX_IMPORT_BYTES } from '@/utils/repertoireImport'
import { apiErrorMessage } from '@/utils/apiError'

definePage({ meta: { auth: 'required' } })

/** Where the OAuth callback page sends the user back after a grant (sessionStorage). */
const RETURN_KEY = 'chessmate.oauthReturn'

const route = useRoute()
const router = useRouter()
const $q = useQuasar()
const store = useRepertoireStore()
const auth = useAuthStore()
const imp = useImport()

/** @type {import('vue').Ref<'file'|'paste'|'study'>} */
const source = ref('file')
/** @type {import('vue').Ref<File|null>} */
const file = ref(null)
const pasted = ref('')
const studyUrl = ref('')
const granting = ref(false)

const lichessLinked = computed(() =>
  (auth.profile?.identities ?? []).some(i => i.provider === 'lichess')
)
const ready = computed(
  () =>
    (source.value === 'file' && !!file.value) ||
    (source.value === 'paste' && !!pasted.value.trim()) ||
    (source.value === 'study' && !!studyUrl.value.trim())
)
const sourceLabel = computed(() => {
  const current = imp.current.value
  if (!current) return ''
  if (current.source === 'study') return `Étude ${current.label ?? ''}`
  if (current.source === 'openbook')
    return `Sauvegarde OpenBook${current.label ? ` (${current.label})` : ''}`
  return current.label ?? 'PGN collé'
})
const targetOptions = computed(() =>
  store.repertoires.map(r => ({
    label: `${r.name} (${r.color === 'white' ? 'Blancs' : 'Noirs'})`,
    value: r.id
  }))
)
/** The existing repertoire chosen, with the version its preview is based on. */
const target = computed({
  get: () => imp.destination.value.repertoireId,
  set: id => {
    const chosen = store.repertoires.find(r => r.id === id)
    imp.destination.value = {
      ...imp.destination.value,
      repertoireId: id,
      baseVersion: chosen?.version ?? null
    }
  }
})
const canApply = computed(() => {
  const d = imp.destination.value
  const preview = imp.current.value?.preview
  if (!preview || preview.positionsAfter > preview.maxPositions) return false
  return d.mode === 'existing' ? !!d.repertoireId : !!d.name.trim()
})

async function analyze() {
  if (source.value === 'file') {
    if (file.value.size > MAX_IMPORT_BYTES) {
      imp.error.value = {
        code: 'too_large',
        message: 'Le fichier dépasse 1 Mo.'
      }
      return
    }
    await imp.start({
      pgn: await readText(file.value),
      fileName: file.value.name
    })
  } else if (source.value === 'paste') {
    await imp.start({ pgn: pasted.value })
  } else {
    await imp.start({ studyUrl: studyUrl.value.trim() })
  }
}

/** Asks Lichess for study:read, then comes back here to read the study again. */
async function grant() {
  granting.value = true
  try {
    const { authorizationUrl } = await profileApi.startGrant('lichess')
    try {
      sessionStorage.setItem(
        RETURN_KEY,
        `/repertoire/import?study=${encodeURIComponent(studyUrl.value.trim())}`
      )
    } catch {
      // Without storage the callback page falls back to this page, without the URL.
    }
    window.location.assign(authorizationUrl)
  } catch (e) {
    granting.value = false
    $q.notify({ type: 'negative', message: apiErrorMessage(e) })
  }
}

function restart() {
  imp.reset()
}

// Applied: open the repertoire.
watch(
  () => imp.phase.value,
  phase => {
    if (phase !== 'done' || !imp.current.value?.repertoireId) return
    $q.notify({ type: 'positive', message: 'Répertoire importé.' })
    router.push(`/repertoire/${imp.current.value.repertoireId}`)
  }
)

// A new repertoire keeps the color chosen; an existing one decides it.
watch(
  () => imp.destination.value.mode,
  mode => {
    if (
      mode === 'existing' &&
      !imp.destination.value.repertoireId &&
      store.repertoires.length
    ) {
      target.value = store.repertoires[0].id
    }
  }
)

onMounted(async () => {
  store.fetchList().catch(() => {})
  if (!auth.profile) auth.fetchProfile().catch(() => {})
  // Back from the study:read grant: read the study again.
  if (typeof route.query.study === 'string' && route.query.study) {
    source.value = 'study'
    studyUrl.value = route.query.study
    router.replace({ path: '/repertoire/import' })
    await analyze()
  }
})
</script>

<style lang="scss">
.import-page {
  max-width: 820px;
  margin: 0 auto;
}
.import-page__pgn {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.85rem;
}
</style>
