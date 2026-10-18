<template>
  <q-page padding>
    <div class="repertoire-page q-gutter-y-md">
      <div class="cm-page-head">
        <div class="text-h5">{{ $t('repertoire.list.title') }}</div>
        <q-space />
        <q-btn
          outline
          color="primary"
          no-caps
          icon="insights"
          :label="$t('repertoire.list.stats')"
          to="/repertoire/stats"
          data-testid="repertoire-stats"
        />
        <q-btn
          outline
          color="primary"
          no-caps
          icon="upload_file"
          :label="$t('repertoire.list.import')"
          to="/repertoire/import"
          data-testid="repertoire-import"
        />
        <q-btn
          unelevated
          no-caps
          class="cm-btn-generate"
          icon="auto_awesome"
          :label="$t('repertoire.list.auto')"
          to="/repertoire/auto"
          data-testid="repertoire-auto"
        />
        <q-btn
          unelevated
          no-caps
          class="cm-btn-create"
          icon="add"
          :label="$t('repertoire.list.new')"
          to="/repertoire/new"
          data-testid="repertoire-new"
        />
      </div>
      <p class="text-caption text-grey">{{ $t('repertoire.list.lead') }}</p>

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
          <span class="auto-entry__title">{{
            $t('repertoire.list.autoTitle')
          }}</span>
          <span class="auto-entry__sub">{{
            $t('repertoire.list.autoSub')
          }}</span>
        </span>
        <q-icon name="arrow_forward" size="22px" class="auto-entry__arrow" />
      </router-link>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>

      <q-banner
        v-if="overview && overview.cards.total > 0"
        rounded
        class="repertoire-due"
        data-testid="repertoire-due"
      >
        <template #avatar><q-icon name="timer" color="primary" /></template>
        <span class="text-weight-medium">{{
          $t('repertoire.list.due', overview.cards.due)
        }}</span
        >{{
          $t('repertoire.list.dueNew', {
            new: $t('repertoire.list.new_', overview.cards.new),
            total: $t('repertoire.list.prepared', overview.cards.total)
          })
        }}
        <template #action>
          <q-btn
            color="primary"
            unelevated
            no-caps
            icon="play_arrow"
            :label="$t('repertoire.list.testAll')"
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
        <i18n-t
          keypath="repertoire.list.empty"
          tag="div"
          scope="global"
          class="q-mt-sm"
        >
          <template #auto>
            <router-link to="/repertoire/auto">{{
              $t('repertoire.list.emptyAuto')
            }}</router-link>
          </template>
          <template #import>
            <router-link to="/repertoire/import">{{
              $t('repertoire.list.emptyImport')
            }}</router-link>
          </template>
        </i18n-t>
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
              {{
                r.color === 'white'
                  ? $t('repertoire.list.white')
                  : $t('repertoire.list.black')
              }}
              · {{ $t('repertoire.list.positions', r.positionCount - 1) }} ·
              {{ $t('repertoire.list.segments', r.segmentCount) }}
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
                    <q-item-section>{{
                      $t('repertoire.list.test')
                    }}</q-item-section>
                  </q-item>
                  <q-item
                    v-close-popup
                    clickable
                    :to="`/repertoire/${r.id}/stats`"
                  >
                    <q-item-section>{{
                      $t('repertoire.list.stats')
                    }}</q-item-section>
                  </q-item>
                  <q-item v-close-popup clickable @click="rename(r)">
                    <q-item-section>{{
                      $t('repertoire.list.rename')
                    }}</q-item-section>
                  </q-item>
                  <q-item v-close-popup clickable @click="exportFile(r, 'pgn')">
                    <q-item-section>{{
                      $t('repertoire.list.exportPgn')
                    }}</q-item-section>
                  </q-item>
                  <q-item
                    v-close-popup
                    clickable
                    @click="exportFile(r, 'openbook')"
                  >
                    <q-item-section>{{
                      $t('repertoire.list.exportOpenBook')
                    }}</q-item-section>
                  </q-item>
                  <q-item
                    v-if="r.origin?.source === 'lichess_games'"
                    v-close-popup
                    clickable
                    :to="`/repertoire/${r.id}/pending`"
                    data-testid="repertoire-pending"
                  >
                    <q-item-section>{{
                      $t('repertoire.list.pending')
                    }}</q-item-section>
                  </q-item>
                  <q-item
                    v-close-popup
                    clickable
                    :to="`/repertoire/${r.id}/trash`"
                  >
                    <q-item-section>{{
                      $t('repertoire.list.trash')
                    }}</q-item-section>
                  </q-item>
                  <q-item
                    v-close-popup
                    clickable
                    class="text-negative"
                    data-testid="repertoire-delete"
                    @click="confirmDelete(r)"
                  >
                    <q-item-section>{{
                      $t('repertoire.list.delete')
                    }}</q-item-section>
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
import { t } from '@/i18n'

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
        title: t('repertoire.list.testOne'),
        caption: r.name,
        config: { repertoireIds: [r.id] }
      }
    : {
        title: t('repertoire.list.testAll'),
        caption: t('repertoire.list.testAllCaption'),
        config: { repertoireIds: store.repertoires.map(x => x.id) }
      }
  testOpen.value = true
}

/** @param {import('@/stores/repertoire').RepertoireSummary} r */
function rename(r) {
  $q.dialog({
    title: t('repertoire.list.renameTitle'),
    prompt: {
      model: r.name,
      type: 'text',
      maxlength: NAME_MAX,
      isValid: v => !!v?.trim()
    },
    cancel: { flat: true, noCaps: true, label: t('common.actions.cancel') },
    ok: { noCaps: true, label: t('repertoire.list.rename') }
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
    title: t('repertoire.list.deleteTitle', { name: r.name }),
    message: t('repertoire.list.deleteMessage'),
    cancel: { flat: true, noCaps: true, label: t('common.actions.cancel') },
    ok: {
      color: 'negative',
      noCaps: true,
      label: t('repertoire.list.deleteOk')
    }
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
/* The positions due: a card with a brand edge, the count in bold. */
.repertoire-due {
  background: var(--cm-surface);
  color: var(--cm-ink);
  border-radius: 18px;
  border-left: 5px solid var(--cm-brand);
  box-shadow: var(--cm-card-shadow);
  font-size: 15px;
}

.auto-entry {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 10px 18px 0 10px;
  border-radius: 22px;
  background: var(--cm-brand-soft);
  /* A brand ring: it stands out on the tinted page as on the dark one. */
  box-shadow:
    inset 0 0 0 2px rgba(106, 76, 255, 0.28),
    var(--cm-card-shadow);
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
