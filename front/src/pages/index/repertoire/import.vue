<template>
  <q-page padding>
    <div class="import-page q-gutter-y-md">
      <div class="row items-center">
        <div class="text-h5">{{ $t('repertoire.importPage.title') }}</div>
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
            :label="$t('repertoire.importPage.tabs.file')"
            data-testid="import-tab-file"
          />
          <q-tab
            name="paste"
            :label="$t('repertoire.importPage.tabs.paste')"
            data-testid="import-tab-paste"
          />
          <q-tab
            name="study"
            :label="$t('repertoire.importPage.tabs.study')"
            data-testid="import-tab-study"
          />
        </q-tabs>
        <q-separator />

        <q-file
          v-if="source === 'file'"
          v-model="file"
          outlined
          accept=".pgn,.json,text/plain,application/x-chess-pgn,application/json"
          :label="$t('repertoire.importPage.file')"
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
          :label="$t('repertoire.importPage.paste')"
          input-class="import-page__pgn"
          data-testid="import-pgn"
        />
        <q-input
          v-else
          v-model="studyUrl"
          outlined
          :label="$t('repertoire.importPage.study')"
          placeholder="https://lichess.org/study/…"
          :hint="$t('repertoire.importPage.studyHint')"
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
              :label="$t('repertoire.importPage.grant')"
              :loading="granting"
              data-testid="import-grant"
              @click="grant"
            />
            <q-btn
              v-else
              flat
              no-caps
              color="white"
              :label="$t('repertoire.importPage.link')"
              to="/profile"
            />
          </template>
        </q-banner>

        <div class="row q-gutter-sm">
          <q-btn
            color="primary"
            no-caps
            :label="$t('repertoire.importPage.analyze')"
            :loading="imp.busy.value"
            :disable="!ready"
            data-testid="import-analyze"
            @click="analyze"
          />
          <q-btn
            flat
            no-caps
            :label="$t('common.actions.cancel')"
            to="/repertoire"
          />
        </div>
        <div class="text-caption cm-muted">
          {{ $t('repertoire.importPage.checkHint') }}
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
              ? $t('repertoire.importPage.analyzing')
              : $t('repertoire.importPage.applying')
          }}
        </div>
        <q-linear-progress
          :value="(imp.current.value?.progress ?? 0) / 100"
          size="12px"
          rounded
          color="primary"
        />
        <div class="text-caption cm-muted q-mt-sm">
          {{ $t('repertoire.importPage.bigFile') }}
        </div>
      </div>

      <!-- 3. The preview -->
      <template v-else-if="imp.phase.value === 'preview' && imp.current.value">
        <div class="text-body2 cm-muted">
          {{ sourceLabel
          }}<template v-if="imp.current.value.source !== 'openbook'">{{
            $t('repertoire.importPage.games', imp.current.value.games)
          }}</template>
        </div>

        <q-card flat bordered class="q-pa-md">
          <div class="text-subtitle1 q-mb-sm">{{
            $t('repertoire.importPage.destination')
          }}</div>
          <q-option-group
            v-model="imp.destination.value.mode"
            inline
            :options="[
              {
                label: $t('repertoire.importPage.newRepertoire'),
                value: 'new'
              },
              {
                label: $t('repertoire.importPage.existing'),
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
              :label="$t('repertoire.new.name')"
              maxlength="80"
              :rules="[v => !!v?.trim() || $t('repertoire.new.nameRequired')]"
              data-testid="import-name"
            />
            <div class="col-12 col-sm-4">
              <q-btn-toggle
                v-model="imp.destination.value.color"
                no-caps
                unelevated
                toggle-color="primary"
                :options="[
                  { label: $t('repertoire.list.white'), value: 'white' },
                  { label: $t('repertoire.list.black'), value: 'black' }
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
            :label="$t('repertoire.importPage.repertoire')"
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
            :label="$t('repertoire.importPage.apply')"
            :loading="imp.busy.value"
            :disable="!canApply"
            data-testid="import-apply"
            @click="imp.apply()"
          />
          <q-btn
            flat
            no-caps
            :label="$t('repertoire.importPage.restart')"
            @click="restart"
          />
          <div class="text-caption cm-muted">
            {{ $t('repertoire.importPage.undoHint') }}
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
        <q-btn
          color="primary"
          no-caps
          :label="$t('repertoire.importPage.restart')"
          @click="restart"
        />
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
import { MAX_IMPORT_BYTES, failureText } from '@/utils/repertoireImport'
import { apiErrorMessage } from '@/utils/apiError'
import { t } from '@/i18n'

definePage({
  meta: {
    auth: 'required',
    header: {
      title: 'repertoire.importPage.title',
      subtitle: 'layout.nav.repertoires'
    }
  }
})

/** Where the OAuth callback page sends the user back after a grant (sessionStorage). */
const RETURN_KEY = 'dontstayrooky.oauthReturn'

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
  if (current.source === 'study')
    return t('repertoire.importPage.studyLabel', { label: current.label ?? '' })
  if (current.source === 'openbook')
    return current.label
      ? t('repertoire.importPage.openbookNamed', { label: current.label })
      : t('repertoire.importPage.openbook')
  return current.label ?? t('repertoire.importPage.pasted')
})
const targetOptions = computed(() =>
  store.repertoires.map(r => ({
    label: t('repertoire.importPage.target', {
      name: r.name,
      color: t(
        r.color === 'white' ? 'repertoire.list.white' : 'repertoire.list.black'
      )
    }),
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
        message: failureText('too_large')
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
    $q.notify({ type: 'positive', message: t('repertoire.importPage.done') })
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
