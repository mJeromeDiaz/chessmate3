<template>
  <div class="profile-block" data-testid="calendar-section">
    <div class="profile-row__title">{{ $t('profile.calendar.title') }}</div>
    <p class="profile-row__sub q-mb-sm">{{ $t('profile.calendar.intro') }}</p>

    <q-spinner v-if="loading" />
    <template v-else-if="address.url">
      <q-input
        :model-value="address.url"
        readonly
        dense
        outlined
        :label="$t('profile.calendar.address')"
        data-testid="calendar-url"
        @focus="$event.target.select()"
      >
        <template #append>
          <q-btn
            flat
            round
            dense
            icon="content_copy"
            :aria-label="$t('profile.calendar.copy')"
            data-testid="calendar-copy"
            @click="copy"
          />
        </template>
      </q-input>
      <p class="profile-row__sub q-mt-xs q-mb-sm">
        {{ $t('profile.calendar.howTo') }}
      </p>
      <div class="row q-gutter-sm">
        <q-btn
          color="primary"
          unelevated
          no-caps
          icon="event"
          :label="$t('profile.calendar.subscribe')"
          :href="address.webcalUrl"
          data-testid="calendar-subscribe"
        />
        <q-btn
          flat
          no-caps
          :label="$t('profile.calendar.regenerate')"
          :loading="busy === 'regenerate'"
          data-testid="calendar-regenerate"
          @click="confirmRegenerate"
        />
        <q-btn
          flat
          no-caps
          color="negative"
          :label="$t('profile.calendar.revoke')"
          :loading="busy === 'revoke'"
          data-testid="calendar-revoke"
          @click="confirmRevoke"
        />
      </div>
    </template>
    <q-btn
      v-else
      color="primary"
      unelevated
      no-caps
      icon="event"
      :label="$t('profile.calendar.create')"
      :loading="busy === 'regenerate'"
      data-testid="calendar-create"
      @click="regenerate"
    />

    <q-banner v-if="error" class="profile-error q-mt-md" rounded>{{
      error
    }}</q-banner>
  </div>
</template>

<script setup>
/**
 * Profile: the private address of the user's calendar feed (docs/TRAINING.md, calendar), shown
 * again at each visit, regenerated (the previous one stops working) or revoked.
 */
import { onMounted, ref } from 'vue'
import { copyToClipboard, useQuasar } from 'quasar'
import { calendarApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { t } from '@/i18n'

const $q = useQuasar()
/** @type {import('vue').Ref<{url: string|null, webcalUrl: string|null}>} */
const address = ref({ url: null, webcalUrl: null })
const loading = ref(true)
/** @type {import('vue').Ref<null|'regenerate'|'revoke'>} */
const busy = ref(null)
const error = ref('')

async function regenerate() {
  busy.value = 'regenerate'
  error.value = ''
  try {
    address.value = await calendarApi.regenerate()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = null
  }
}

function confirmRegenerate() {
  $q.dialog({
    title: t('profile.calendar.regenerateTitle'),
    message: t('profile.calendar.regenerateMessage'),
    cancel: true
  }).onOk(regenerate)
}

function confirmRevoke() {
  $q.dialog({
    title: t('profile.calendar.revokeTitle'),
    message: t('profile.calendar.revokeMessage'),
    cancel: true
  }).onOk(async () => {
    busy.value = 'revoke'
    error.value = ''
    try {
      await calendarApi.revoke()
      address.value = { url: null, webcalUrl: null }
    } catch (e) {
      error.value = apiErrorMessage(e)
    } finally {
      busy.value = null
    }
  })
}

async function copy() {
  try {
    await copyToClipboard(address.value.url ?? '')
    $q.notify({ message: t('profile.calendar.copied'), timeout: 1500 })
  } catch {
    error.value = t('profile.calendar.copyFailed')
  }
}

onMounted(async () => {
  try {
    address.value = await calendarApi.address()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
})
</script>
