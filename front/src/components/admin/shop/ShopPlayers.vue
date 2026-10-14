<template>
  <section class="cm-card shop-players" data-testid="shop-players">
    <div class="admin__filters">
      <q-input
        v-model="search"
        outlined
        dense
        clearable
        debounce="300"
        label="Email, pseudo ou nom"
        class="admin__filter--grow"
        data-testid="shop-player-search"
        @update:model-value="load"
      />
    </div>
    <q-banner v-if="error" class="cm-banner--danger q-mb-md" rounded>{{
      error
    }}</q-banner>

    <div class="shop-players__grid">
      <q-markup-table flat dense class="admin__table">
        <thead>
          <tr>
            <th class="text-left">Joueur</th>
            <th class="text-right">Solde</th>
            <th class="text-right">Dépensé</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && players.length === 0">
            <td colspan="3" class="cm-muted">{{
              search
                ? 'Aucun joueur trouvé.'
                : 'Aucun joueur n’a encore de couronnes.'
            }}</td>
          </tr>
          <tr
            v-for="p in players"
            :key="p.id"
            class="shop-players__row"
            :class="{ 'shop-players__row--on': detail?.player.id === p.id }"
            data-testid="shop-player-row"
            @click="open(p.id)"
          >
            <td>
              <div class="shop-players__name">{{
                p.displayName || p.handle || p.email || p.id
              }}</div>
              <div v-if="p.email" class="cm-muted">{{ p.email }}</div>
            </td>
            <td class="text-right">{{ crowns(p.balance) }}</td>
            <td class="text-right">{{ crowns(p.spent) }}</td>
          </tr>
        </tbody>
      </q-markup-table>

      <div v-if="detail" class="shop-players__detail" data-testid="shop-player">
        <div class="shop-players__head">
          <div>
            <div class="shop-players__name">{{
              detail.player.displayName ||
              detail.player.email ||
              detail.player.id
            }}</div>
            <div class="cm-muted">{{ detail.player.email }}</div>
          </div>
          <div class="shop-players__balance" data-testid="shop-player-balance">
            <CrownCoin :size="22" />{{ crowns(detail.player.balance) }}
          </div>
        </div>

        <form class="shop-players__adjust" @submit.prevent="adjust">
          <q-input
            v-model.number="delta"
            type="number"
            outlined
            dense
            label="Couronnes (+ ou −)"
            class="shop-players__delta"
            data-testid="shop-adjust-delta"
          />
          <q-input
            v-model="reason"
            outlined
            dense
            label="Motif *"
            maxlength="255"
            class="admin__filter--grow"
            data-testid="shop-adjust-reason"
          />
          <q-btn
            type="submit"
            unelevated
            no-caps
            color="primary"
            :label="(delta ?? 0) < 0 ? 'Retirer' : 'Ajouter'"
            :disable="!delta || !reason.trim()"
            :loading="saving"
            data-testid="shop-adjust"
          />
        </form>
        <div v-if="adjustError" class="shop-players__error">{{
          adjustError
        }}</div>

        <div class="shop-players__label">Inventaire</div>
        <div class="shop-players__chips">
          <span
            v-for="(qty, item) in detail.inventory"
            :key="item"
            class="admin__status"
            >{{ ITEMS[item]?.label ?? item }} × {{ qty }}</span
          >
          <span
            v-if="Object.keys(detail.inventory).length === 0"
            class="cm-muted"
            >Rien.</span
          >
        </div>
        <div v-if="detail.freezes.length" class="cm-muted q-mt-xs">
          Gels :
          {{
            detail.freezes.map(f => `${f.daysLeft}/${f.days} j`).join(' · ')
          }}
        </div>

        <div class="shop-players__label">Mouvements</div>
        <q-markup-table flat dense class="admin__table">
          <tbody>
            <tr v-for="(l, i) in detail.ledger" :key="i" data-testid="shop-ledger-row">
              <td class="no-wrap">{{ when(l.createdAt) }}</td>
              <td>
                <span
                  :class="[
                    'admin__status',
                    l.kind === 'grant'
                      ? 'admin__status--used'
                      : l.kind === 'revoke'
                        ? 'admin__status--danger'
                        : ''
                  ]"
                  >{{ LEDGER_KINDS[l.kind] ?? l.kind }}</span
                >
              </td>
              <td>
                {{
                  l.offerName
                    ? `${l.quantity > 1 ? `${l.quantity} × ` : ''}${l.offerName}`
                    : l.reason
                }}
                <div v-if="l.admin" class="cm-muted">par {{ l.admin }}</div>
              </td>
              <td class="text-right no-wrap">
                {{ l.delta > 0 ? '+' : '' }}{{ crowns(l.delta) }}
              </td>
              <td class="text-right cm-muted">{{ crowns(l.balanceAfter) }}</td>
            </tr>
            <tr v-if="detail.ledger.length === 0">
              <td class="cm-muted">Aucun mouvement.</td>
            </tr>
          </tbody>
        </q-markup-table>
      </div>
    </div>
  </section>
</template>

<script setup>
/**
 * Players' crowns for the admin (docs/SHOP.md): a search (the richest without one), then a
 * player's balance, crowns given or taken back with a reason, inventory, freezes and ledger.
 */
import { onMounted, ref } from 'vue'
import CrownCoin from '@/components/shop/CrownCoin.vue'
import { adminApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { LEDGER_KINDS } from '@/utils/admin/shop'
import { ITEMS, crowns } from '@/utils/shop'

const search = ref('')
/** @type {import('vue').Ref<any[]>} */
const players = ref([])
/** @type {import('vue').Ref<any|null>} */
const detail = ref(null)
const loading = ref(false)
const error = ref('')
/** @type {import('vue').Ref<number|null>} */
const delta = ref(null)
const reason = ref('')
const saving = ref(false)
const adjustError = ref('')

const dateTime = new Intl.DateTimeFormat('fr-FR', {
  dateStyle: 'short',
  timeStyle: 'short'
})

/** @param {string} iso */
function when(iso) {
  return dateTime.format(new Date(iso))
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    players.value = (await adminApi.shopPlayers(search.value ?? '')).players
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

/** @param {string} id */
async function open(id) {
  adjustError.value = ''
  try {
    detail.value = await adminApi.shopPlayer(id)
  } catch (e) {
    error.value = apiErrorMessage(e, { 404: 'Ce compte n’existe plus.' })
  }
}

async function adjust() {
  if (!detail.value || !delta.value || !reason.value.trim()) return
  saving.value = true
  adjustError.value = ''
  try {
    detail.value = await adminApi.adjustCrowns(
      detail.value.player.id,
      Number(delta.value),
      reason.value.trim()
    )
    delta.value = null
    reason.value = ''
    load()
  } catch (e) {
    adjustError.value = apiErrorMessage(e, {
      409: 'Le solde ne peut pas passer sous zéro.'
    })
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<style scoped lang="scss">
.shop-players__grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: minmax(0, 2fr) minmax(0, 3fr);
    align-items: start;
  }
}

.shop-players__row {
  cursor: pointer;

  &--on td {
    background: var(--cm-brand-soft);
  }
}

.shop-players__name {
  font-weight: 700;
}

.shop-players__detail {
  padding: 14px;
  border-radius: 18px;
  background: var(--cm-page);
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.shop-players__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}

.shop-players__balance {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 800;
  font-size: 20px;
}

.shop-players__adjust {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 8px;
}

.shop-players__delta {
  width: 160px;
}

.shop-players__error {
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-danger);
}

.shop-players__label {
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-ink-soft);
}

.shop-players__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
</style>
