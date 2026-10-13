<template>
  <q-page padding>
    <div class="repertoire-page q-gutter-y-md">
      <div class="row items-center">
        <div class="text-h5">Répertoires</div>
        <q-space />
        <q-btn
          flat
          no-caps
          icon="insights"
          label="Statistiques"
          to="/repertoire/stats"
          class="q-mr-sm"
          data-testid="repertoire-stats"
        />
        <q-btn
          flat
          no-caps
          icon="upload_file"
          label="Importer"
          to="/repertoire/import"
          class="q-mr-sm"
          data-testid="repertoire-import"
        />
        <q-btn
          flat
          no-caps
          icon="auto_awesome"
          label="Depuis mes parties"
          to="/repertoire/auto"
          class="q-mr-sm"
          data-testid="repertoire-auto"
        />
        <q-btn
          color="primary"
          no-caps
          icon="add"
          label="Nouveau répertoire"
          to="/repertoire/new"
          data-testid="repertoire-new"
        />
      </div>
      <p class="text-caption text-grey">
        Construisez vos ouvertures coup par coup sur l’échiquier, avec vos
        réponses aux coups de l’adversaire.
      </p>

      <router-link
        to="/repertoire/auto"
        class="auto-entry"
        data-testid="repertoire-auto-card"
      >
        <img
          :src="profImage('albert-v2-pointer')"
          alt=""
          class="auto-entry__albert"
        />
        <span class="auto-entry__text">
          <span class="auto-entry__title"
            >Ton répertoire, tiré de tes vraies parties</span
          >
          <span class="auto-entry__sub"
            >Albert lit tes parties Lichess et construit un répertoire par
            ouverture, avec ce qui te coûte des points.</span
          >
        </span>
        <q-icon name="arrow_forward" size="22px" class="auto-entry__arrow" />
      </router-link>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>

      <q-banner
        v-if="overview && overview.cards.total > 0"
        rounded
        class="cm-banner--info"
        data-testid="repertoire-due"
      >
        <template #avatar><q-icon name="timer" color="primary" /></template>
        <span class="text-weight-medium">{{
          plural(overview.cards.due, 'position due', 'positions dues')
        }}</span>
        · {{ plural(overview.cards.new, 'nouvelle') }} sur
        {{ plural(overview.cards.total, 'coup préparé', 'coups préparés') }}
        <template #action>
          <q-btn
            color="primary"
            no-caps
            icon="play_arrow"
            label="Tester mes répertoires"
            data-testid="repertoire-test-all"
            @click="openTest(null)"
          />
        </template>
      </q-banner>

      <RepertoireTestDialog
        v-if="testScope"
        v-model="testOpen"
        :title="testScope.title"
        :caption="testScope.caption"
        :config="testScope.config"
      />

      <q-list v-if="loading" bordered separator>
        <q-item v-for="n in 3" :key="n">
          <q-item-section avatar
            ><q-skeleton type="QAvatar" size="32px"
          /></q-item-section>
          <q-item-section>
            <q-skeleton type="text" width="40%" />
            <q-skeleton type="text" width="25%" />
          </q-item-section>
        </q-item>
      </q-list>

      <div
        v-else-if="store.repertoires.length === 0 && !error"
        class="text-center q-pa-xl cm-muted"
        data-testid="repertoire-empty"
      >
        <q-icon name="menu_book" size="48px" color="grey-5" />
        <div class="q-mt-sm">
          Aucun répertoire. Créez votre premier répertoire,
          <router-link to="/repertoire/auto"
            >construisez-le depuis vos parties Lichess</router-link
          >, ou
          <router-link to="/repertoire/import">importez un PGN</router-link>.
        </div>
      </div>

      <q-list v-else bordered separator>
        <q-item
          v-for="r in store.repertoires"
          :key="r.id"
          clickable
          :to="`/repertoire/${r.id}`"
          data-testid="repertoire-item"
        >
          <q-item-section avatar>
            <q-avatar
              size="32px"
              :color="r.color === 'white' ? 'grey-2' : 'grey-9'"
              :text-color="r.color === 'white' ? 'grey-9' : 'white'"
              icon="menu_book"
            />
          </q-item-section>
          <q-item-section>
            <q-item-label>{{ r.name }}</q-item-label>
            <q-item-label caption>
              {{ r.color === 'white' ? 'Blancs' : 'Noirs' }} ·
              {{ plural(r.positionCount - 1, 'position') }} ·
              {{ plural(r.segmentCount, 'tronçon') }}
            </q-item-label>
          </q-item-section>
          <q-item-section side>
            <q-btn flat round dense icon="more_vert" @click.prevent.stop>
              <q-menu>
                <q-list dense>
                  <q-item
                    v-close-popup
                    clickable
                    data-testid="repertoire-test"
                    @click="openTest(r)"
                  >
                    <q-item-section>Tester</q-item-section>
                  </q-item>
                  <q-item
                    v-close-popup
                    clickable
                    :to="`/repertoire/${r.id}/stats`"
                  >
                    <q-item-section>Statistiques</q-item-section>
                  </q-item>
                  <q-item v-close-popup clickable @click="rename(r)">
                    <q-item-section>Renommer</q-item-section>
                  </q-item>
                  <q-item v-close-popup clickable @click="exportFile(r, 'pgn')">
                    <q-item-section>Exporter (PGN)</q-item-section>
                  </q-item>
                  <q-item
                    v-close-popup
                    clickable
                    @click="exportFile(r, 'openbook')"
                  >
                    <q-item-section
                      >Exporter pour OpenBook (JSON)</q-item-section
                    >
                  </q-item>
                  <q-item
                    v-close-popup
                    clickable
                    :to="`/repertoire/${r.id}/trash`"
                  >
                    <q-item-section>Corbeille</q-item-section>
                  </q-item>
                  <q-item
                    v-close-popup
                    clickable
                    class="text-negative"
                    data-testid="repertoire-delete"
                    @click="confirmDelete(r)"
                  >
                    <q-item-section>Supprimer</q-item-section>
                  </q-item>
                </q-list>
              </q-menu>
            </q-btn>
          </q-item-section>
        </q-item>
      </q-list>
    </div>
  </q-page>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import RepertoireTestDialog from '@/components/repertoire/RepertoireTestDialog.vue'
import { useRepertoireStore } from '@/stores/repertoire'
import { apiErrorMessage } from '@/utils/apiError'
import { repertoireApi } from '@/services/api'
import { downloadText } from '@/utils/download'
import { profImage } from '@/utils/prof/images'

definePage({ meta: { auth: 'required' } })

/** Same limit as the API (App\Repertoire\Limits). */
const NAME_MAX = 80

const $q = useQuasar()
const store = useRepertoireStore()
const loading = ref(true)
const error = ref('')
/** Cards due and new, all repertoires (GET /repertoires/stats). */
const overview = ref(null)
const testOpen = ref(false)
/** @type {import('vue').Ref<{title: string, caption: string, config: Record<string, any>}|null>} */
const testScope = ref(null)

/**
 * Opens the test launcher on one repertoire, or on all of them.
 *
 * @param {import('@/stores/repertoire').RepertoireSummary|null} r
 */
function openTest(r) {
  testScope.value = r
    ? {
        title: 'Tester le répertoire',
        caption: r.name,
        config: { repertoireIds: [r.id] }
      }
    : {
        title: 'Tester mes répertoires',
        caption:
          'Les positions dues d’abord, puis les tronçons ratés et les nouveaux.',
        config: { repertoireIds: store.repertoires.map(x => x.id) }
      }
  testOpen.value = true
}

/**
 * @param {number} count
 * @param {string} word
 * @param {string} [many] the plural, when not word + "s"
 */
const plural = (count, word, many = `${word}s`) =>
  `${count} ${count > 1 ? many : word}`

/** @param {import('@/stores/repertoire').RepertoireSummary} r */
function rename(r) {
  $q.dialog({
    title: 'Renommer le répertoire',
    prompt: {
      model: r.name,
      type: 'text',
      maxlength: NAME_MAX,
      isValid: v => !!v?.trim()
    },
    cancel: { flat: true, noCaps: true, label: 'Annuler' },
    ok: { noCaps: true, label: 'Renommer' }
  }).onOk(async name => {
    try {
      await store.rename(r.id, name.trim())
    } catch (e) {
      $q.notify({ type: 'negative', message: apiErrorMessage(e) })
    }
  })
}

/**
 * @param {import('@/stores/repertoire').RepertoireSummary} r
 * @param {'pgn'|'openbook'} format
 */
async function exportFile(r, format) {
  try {
    const { text, fileName } = await repertoireApi.exportFile(r.id, format)
    downloadText(
      text,
      fileName,
      format === 'pgn' ? undefined : 'application/json'
    )
  } catch (e) {
    $q.notify({ type: 'negative', message: apiErrorMessage(e) })
  }
}

/** @param {import('@/stores/repertoire').RepertoireSummary} r */
function confirmDelete(r) {
  $q.dialog({
    title: `Supprimer « ${r.name} » ?`,
    message:
      'Le répertoire et toutes ses statistiques seront supprimés définitivement.',
    cancel: { flat: true, noCaps: true, label: 'Annuler' },
    ok: { color: 'negative', noCaps: true, label: 'Supprimer définitivement' }
  }).onOk(async () => {
    try {
      await store.remove(r.id)
    } catch (e) {
      $q.notify({ type: 'negative', message: apiErrorMessage(e) })
    }
  })
}

onMounted(async () => {
  repertoireApi
    .overview()
    .then(data => (overview.value = data))
    .catch(() => {})
  try {
    await store.fetchList()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.repertoire-page {
  max-width: 800px;
  margin: 0 auto;
}

/* The way into "repertoire from my games" (design "Repertoire auto"). */
.auto-entry {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 10px 18px 0 10px;
  border-radius: 22px;
  background: var(--cm-brand-soft);
  color: var(--cm-ink);
  text-decoration: none;
  overflow: hidden;
  transition: transform 0.15s;
}

.auto-entry:hover {
  transform: translateY(-2px);
}

.auto-entry__albert {
  width: 84px;
  height: 92px;
  object-fit: contain;
  object-position: center bottom;
  flex: none;
  align-self: flex-end;
}

.auto-entry__text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding-bottom: 10px;
}

.auto-entry__title {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 18px;
  line-height: 1.15;
}

.auto-entry__sub {
  font-size: 13px;
  line-height: 1.4;
  color: var(--cm-muted);
}

.auto-entry__arrow {
  flex: none;
  color: var(--cm-brand);
  padding-bottom: 10px;
}
</style>
