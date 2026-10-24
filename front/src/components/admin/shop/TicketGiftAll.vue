<template>
  <section class="cm-card ticket-gift" data-testid="ticket-gift-all">
    <h2 class="cm-card__title">{{ $t('admin.ticketGiftAll.title') }}</h2>
    <p class="cm-muted ticket-gift__lead">{{
      $t('admin.ticketGiftAll.lead')
    }}</p>
    <q-banner v-if="error" class="cm-banner--danger q-mb-md" rounded>{{
      error
    }}</q-banner>

    <form class="ticket-gift__form" @submit.prevent="give">
      <q-input
        v-model.number="quantity"
        type="number"
        min="1"
        :max="MAX_TICKETS"
        outlined
        dense
        :label="$t('admin.ticketGiftAll.quantity')"
        class="ticket-gift__quantity"
        :error="!!violationOf(violations, 'quantity')"
        :error-message="violationOf(violations, 'quantity')"
        data-testid="ticket-gift-all-quantity"
      />
      <q-input
        v-model="message"
        outlined
        dense
        :label="$t('admin.ticketGiftAll.message')"
        :hint="$t('admin.ticketGiftAll.messageHint')"
        maxlength="255"
        class="admin__filter--grow"
        :error="!!violationOf(violations, 'message')"
        :error-message="violationOf(violations, 'message')"
        data-testid="ticket-gift-all-message"
      />
      <q-btn
        type="submit"
        unelevated
        no-caps
        color="primary"
        :label="$t('admin.ticketGiftAll.give')"
        :disable="!valid"
        :loading="saving"
        data-testid="ticket-gift-all-give"
      />
    </form>
    <div
      v-if="done"
      class="ticket-gift__done"
      data-testid="ticket-gift-all-done"
    >
      {{ done }}
    </div>

    <div class="ticket-gift__label">{{
      $t('admin.ticketGiftAll.journal')
    }}</div>
    <q-markup-table flat dense class="admin__table">
      <thead>
        <tr>
          <th class="text-left">{{
            $t('admin.ticketGiftAll.columns.date')
          }}</th>
          <th class="text-right">{{
            $t('admin.ticketGiftAll.columns.quantity')
          }}</th>
          <th class="text-left">{{
            $t('admin.ticketGiftAll.columns.message')
          }}</th>
          <th class="text-right">{{
            $t('admin.ticketGiftAll.columns.recipients')
          }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="b in batches" :key="b.id" data-testid="ticket-gift-batch">
          <td class="no-wrap">{{ when(b.createdAt) }}</td>
          <td class="text-right">{{ b.quantity }}</td>
          <td>
            {{ b.message }}
            <div v-if="b.admin" class="cm-muted">{{
              $t('admin.shopPlayers.by', { who: b.admin })
            }}</div>
          </td>
          <td class="text-right">{{ b.recipients }}</td>
        </tr>
        <tr v-if="batches.length === 0">
          <td colspan="4" class="cm-muted">{{
            $t('admin.ticketGiftAll.none')
          }}</td>
        </tr>
      </tbody>
    </q-markup-table>
  </section>
</template>

<script setup>
/**
 * Gift wheel tickets given to every active account (docs/SHOP.md, § 4 quater): how many, the
 * message every player sees at the next visit, a confirmation naming the accounts served, then the
 * journal of these gifts.
 */
import { computed, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { adminApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import {
  MAX_TICKETS,
  ticketGiftValid,
  violationOf,
  violationsOf
} from '@/utils/admin/shop'
import { intlLocale, t } from '@/i18n'

const $q = useQuasar()
/** @type {import('vue').Ref<number|null>} */
const quantity = ref(1)
const message = ref('')
const saving = ref(false)
const error = ref('')
const done = ref('')
/** @type {import('vue').Ref<{propertyPath: string, title: string}[]>} */
const violations = ref([])
/** @type {import('vue').Ref<any[]>} */
const batches = ref([])

const valid = computed(() => ticketGiftValid(quantity.value, message.value))

/** @param {string} iso */
function when(iso) {
  return new Intl.DateTimeFormat(intlLocale(), {
    dateStyle: 'short',
    timeStyle: 'short'
  }).format(new Date(iso))
}

async function loadBatches() {
  try {
    batches.value = (await adminApi.grantBatches()).batches
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function give() {
  if (!valid.value) return
  error.value = ''
  done.value = ''
  violations.value = []
  let count
  try {
    count = (await adminApi.ticketRecipients()).count
  } catch (e) {
    error.value = apiErrorMessage(e)
    return
  }
  const n = Number(quantity.value)
  const text = message.value.trim()
  $q.dialog({
    title: t('admin.ticketGiftAll.confirmTitle'),
    message: t(
      'admin.ticketGiftAll.confirm',
      { n, accounts: count, message: text },
      n
    ),
    cancel: { label: t('common.actions.cancel'), flat: true, noCaps: true },
    ok: {
      label: t('admin.ticketGiftAll.confirmOk'),
      unelevated: true,
      noCaps: true,
      color: 'primary'
    },
    persistent: true
  }).onOk(() => send(n, text))
}

/**
 * @param {number} n
 * @param {string} text
 */
async function send(n, text) {
  saving.value = true
  try {
    const batch = await adminApi.giveTicketsToEveryone(n, text)
    done.value = t(
      'admin.ticketGiftAll.done',
      { n: batch.quantity, accounts: batch.recipients },
      batch.quantity
    )
    quantity.value = 1
    message.value = ''
    await loadBatches()
  } catch (e) {
    violations.value = violationsOf(e)
    if (!violations.value.length) error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

onMounted(loadBatches)
</script>

<style scoped lang="scss">
.ticket-gift__lead {
  margin: 0 0 12px;
}

.ticket-gift__form {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 8px;
}

.ticket-gift__quantity {
  width: 120px;
}

.ticket-gift__done {
  margin-top: 4px;
  font-weight: 700;
  color: var(--cm-success);
}

.ticket-gift__label {
  margin: 16px 0 6px;
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-ink-soft);
}
</style>
