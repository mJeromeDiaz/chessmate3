<template>
  <AdminPage testid="admin-shop">
    <template #actions>
      <q-btn
        v-if="tab === 'offers'"
        unelevated
        no-caps
        color="primary"
        icon="add"
        :label="$t('admin.boutique.nouvelleOffre')"
        data-testid="offer-new"
        @click="editOffer(null)"
      />
      <q-btn
        v-else-if="tab === 'slides'"
        unelevated
        no-caps
        color="primary"
        icon="add"
        :label="$t('admin.boutique.nouveauSlide')"
        data-testid="slide-new"
        @click="editSlide(null)"
      />
    </template>

    <q-tabs
      v-model="tab"
      dense
      no-caps
      align="left"
      active-color="primary"
      indicator-color="primary"
      class="q-mb-md"
    >
      <q-tab
        name="offers"
        :label="$t('admin.boutique.offres')"
        data-testid="shop-admin-offers"
      />
      <q-tab
        name="slides"
        :label="$t('admin.boutique.carrousel')"
        data-testid="shop-admin-slides"
      />
      <q-tab
        name="rewards"
        :label="$t('admin.boutique.gains')"
        data-testid="shop-admin-rewards"
      />
      <q-tab
        name="players"
        :label="$t('admin.boutique.joueurs')"
        data-testid="shop-admin-players"
      />
      <q-tab
        name="stats"
        :label="$t('admin.boutique.statistiques')"
        data-testid="shop-admin-stats"
      />
    </q-tabs>

    <q-banner v-if="error" class="cm-banner--danger q-mb-md" rounded>{{
      error
    }}</q-banner>

    <section v-if="tab === 'offers'" class="cm-card">
      <q-markup-table flat dense class="admin__table" data-testid="offer-table">
        <thead>
          <tr>
            <th class="text-left">{{ $t('admin.boutique.offre') }}</th>
            <th class="text-left">{{ $t('admin.boutique.rayon') }}</th>
            <th class="text-left">{{ $t('admin.boutique.contenu') }}</th>
            <th class="text-right">{{ $t('admin.boutique.prix') }}</th>
            <th class="text-right">{{ $t('admin.boutique.ventes') }}</th>
            <th class="text-left">{{ $t('admin.boutique.statut') }}</th>
            <th class="text-right">{{ $t('admin.boutique.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="o in catalog?.offers ?? []"
            :key="o.id"
            data-testid="offer-row"
          >
            <td>
              <strong>{{ o.name }}</strong>
              <div class="cm-muted"
                ><code>{{ o.slug }}</code
                ><span v-if="o.endsAt">{{
                  $t('admin.boutique.until', { date: when(o.endsAt) })
                }}</span></div
              >
            </td>
            <td>{{ sectionLabel(o.section) }}</td>
            <td>{{ contents(o) }}</td>
            <td class="text-right no-wrap">
              <span v-if="o.wasPrice" class="shop-admin__was">{{
                crowns(o.wasPrice)
              }}</span>
              {{ o.comingSoon ? '—' : crowns(o.price) }}
            </td>
            <td class="text-right">
              {{ o.sales
              }}<div v-if="o.crowns" class="cm-muted">{{
                crowns(o.crowns)
              }}</div>
            </td>
            <td>
              <span
                :class="[
                  'admin__status',
                  offerStatus(o).tone
                    ? `admin__status--${offerStatus(o).tone}`
                    : ''
                ]"
                >{{ offerStatus(o).label }}</span
              >
            </td>
            <td class="text-right no-wrap">
              <q-btn
                flat
                dense
                no-caps
                :label="$t('admin.boutique.modifier')"
                data-testid="offer-edit"
                @click="editOffer(o)"
              />
              <q-btn
                flat
                dense
                no-caps
                :color="o.suspended ? 'primary' : 'negative'"
                :label="
                  o.suspended
                    ? $t('admin.boutique.reactivate')
                    : $t('admin.boutique.suspend')
                "
                :disable="busy === o.id"
                data-testid="offer-suspend"
                @click="toggleOffer(o)"
              />
            </td>
          </tr>
        </tbody>
      </q-markup-table>
    </section>

    <section v-else-if="tab === 'slides'" class="cm-card">
      <q-markup-table flat dense class="admin__table" data-testid="slide-table">
        <thead>
          <tr>
            <th class="text-left">{{ $t('admin.boutique.prof') }}</th>
            <th class="text-left">{{ $t('admin.boutique.accroche') }}</th>
            <th class="text-left">{{ $t('admin.boutique.offre') }}</th>
            <th class="text-right">{{ $t('admin.boutique.ordre') }}</th>
            <th class="text-left">{{ $t('admin.boutique.statut') }}</th>
            <th class="text-right">{{ $t('admin.boutique.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="(catalog?.slides ?? []).length === 0">
            <td colspan="6" class="cm-muted">{{
              $t('admin.boutique.aucunSlideLeCarrousel')
            }}</td>
          </tr>
          <tr
            v-for="s in catalog?.slides ?? []"
            :key="s.id"
            data-testid="slide-row"
          >
            <td
              ><img :src="profImage(s.image)" alt="" class="shop-admin__prof"
            /></td>
            <td>{{ s.tag }}</td>
            <td>{{ s.offerName }}</td>
            <td class="text-right">{{ s.position }}</td>
            <td>
              <span
                :class="['admin__status', s.shown ? 'admin__status--used' : '']"
                >{{
                  s.shown
                    ? $t('admin.boutique.shown')
                    : s.suspended
                      ? $t('admin.boutique.suspended')
                      : $t('admin.boutique.hidden')
                }}</span
              >
            </td>
            <td class="text-right no-wrap">
              <q-btn
                flat
                dense
                no-caps
                :label="$t('admin.boutique.modifier')"
                data-testid="slide-edit"
                @click="editSlide(s)"
              />
              <q-btn
                flat
                dense
                no-caps
                color="negative"
                :label="$t('admin.boutique.supprimer')"
                :disable="busy === s.id"
                data-testid="slide-delete"
                @click="removeSlide(s)"
              />
            </td>
          </tr>
        </tbody>
      </q-markup-table>
    </section>

    <RewardRuleForm v-else-if="tab === 'rewards'" />
    <ShopPlayers v-else-if="tab === 'players'" />
    <ShopStatsPanel v-else />

    <OfferForm
      :open="offerOpen"
      :offer="offer"
      @close="offerOpen = false"
      @saved="saved"
    />
    <SlideForm
      :open="slideOpen"
      :slide="slide"
      :offers="catalog?.offers ?? []"
      @close="slideOpen = false"
      @saved="saved"
    />
  </AdminPage>
</template>

<script setup>
/**
 * The store's administration (docs/SHOP.md): offers (create, change, suspend), the carousel,
 * what sessions earn (docs/SHOP.md, § 4 bis), players' crowns, figures.
 */
import { onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import AdminPage from '@/components/admin/AdminPage.vue'
import OfferForm from '@/components/admin/shop/OfferForm.vue'
import RewardRuleForm from '@/components/admin/shop/RewardRuleForm.vue'
import ShopPlayers from '@/components/admin/shop/ShopPlayers.vue'
import ShopStatsPanel from '@/components/admin/shop/ShopStatsPanel.vue'
import SlideForm from '@/components/admin/shop/SlideForm.vue'
import { adminApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import {
  SECTION_OPTIONS,
  offerForm,
  offerPayload,
  offerStatus
} from '@/utils/admin/shop'
import { profImage } from '@/utils/prof/images'
import { ITEMS, crowns } from '@/utils/shop'
import { intlLocale } from '@/i18n'
import { t } from '@/i18n'

definePage({ meta: { auth: 'admin' } })

const $q = useQuasar()
const tab = ref('offers')
/** @type {import('vue').Ref<{offers: import('@/utils/admin/shop').AdminOffer[], slides: import('@/utils/admin/shop').AdminSlide[]}|null>} */
const catalog = ref(null)
const error = ref('')
/** @type {import('vue').Ref<string|null>} */
const busy = ref(null)
const offerOpen = ref(false)
/** @type {import('vue').Ref<import('@/utils/admin/shop').AdminOffer|null>} */
const offer = ref(null)
const slideOpen = ref(false)
/** @type {import('vue').Ref<import('@/utils/admin/shop').AdminSlide|null>} */
const slide = ref(null)

const date = {
  format: d =>
    new Intl.DateTimeFormat(intlLocale(), { dateStyle: 'medium' }).format(d)
}

/** @param {string} iso */
function when(iso) {
  return date.format(new Date(iso))
}

/** @param {string} section */
function sectionLabel(section) {
  return SECTION_OPTIONS.find(s => s.value === section)?.label ?? section
}

/** @param {import('@/utils/admin/shop').AdminOffer} o */
function contents(o) {
  if (o.items.length === 0) return '—'
  return o.items
    .map(i =>
      i.quantity > 1
        ? t('admin.shopPlayers.item', {
            item: ITEMS[i.item]?.label ?? i.item,
            n: i.quantity
          })
        : (ITEMS[i.item]?.label ?? i.item)
    )
    .join(', ')
}

async function load() {
  error.value = ''
  try {
    catalog.value = await adminApi.shopCatalog()
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

/** @param {import('@/utils/admin/shop').AdminOffer|null} o */
function editOffer(o) {
  offer.value = o
  offerOpen.value = true
}

/** @param {import('@/utils/admin/shop').AdminSlide|null} s */
function editSlide(s) {
  slide.value = s
  slideOpen.value = true
}

/** @param {any} next the catalogue after the change */
function saved(next) {
  catalog.value = next
  offerOpen.value = false
  slideOpen.value = false
}

/** @param {import('@/utils/admin/shop').AdminOffer} o */
async function toggleOffer(o) {
  busy.value = o.id
  error.value = ''
  try {
    catalog.value = await adminApi.updateOffer(o.id, {
      ...offerPayload(offerForm(o)),
      suspended: !o.suspended
    })
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = null
  }
}

/** @param {import('@/utils/admin/shop').AdminSlide} s */
function removeSlide(s) {
  $q.dialog({
    title: t('admin.boutique.deleteSlideTitle'),
    message: t('admin.boutique.deleteSlideMessage', {
      tag: s.tag,
      offer: s.offerName
    }),
    cancel: { flat: true, noCaps: true, label: t('common.actions.cancel') },
    ok: { color: 'negative', noCaps: true, label: t('admin.boutique.delete') }
  }).onOk(async () => {
    busy.value = s.id
    try {
      catalog.value = await adminApi.deleteSlide(s.id)
    } catch (e) {
      error.value = apiErrorMessage(e)
    } finally {
      busy.value = null
    }
  })
}

onMounted(load)
</script>

<style scoped lang="scss">
.shop-admin__was {
  margin-right: 4px;
  color: var(--cm-muted);
  text-decoration: line-through;
}

.shop-admin__prof {
  width: 40px;
  height: 40px;
  object-fit: contain;
}
</style>
