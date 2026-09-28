<template>
  <q-card flat bordered>
    <q-card-section>
      <div class="text-subtitle1">Appareils de confiance</div>
      <p class="text-grey-7"
        >Sur ces appareils, la connexion par mot de passe ne demande pas de code
        pendant 30 jours.</p
      >

      <q-spinner v-if="loading" />
      <p v-else-if="devices.length === 0">Aucun appareil de confiance.</p>

      <q-list v-else separator>
        <q-item v-for="device in devices" :key="device.id">
          <q-item-section>
            <q-item-label>{{ device.label }}</q-item-label>
            <q-item-label caption>
              Ajouté le {{ formatDate(device.createdAt) }} · dernière
              utilisation {{ formatDate(device.lastUsedAt) }} · expire le
              {{ formatDate(device.expiresAt) }}
            </q-item-label>
          </q-item-section>
          <q-item-section side>
            <q-btn
              flat
              no-caps
              color="negative"
              label="Révoquer"
              :loading="busy === device.id"
              @click="revoke(device)"
            />
          </q-item-section>
        </q-item>
      </q-list>

      <q-banner v-if="error" class="bg-red-1 q-mt-md" rounded>{{
        error
      }}</q-banner>
    </q-card-section>
  </q-card>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { profileApi } from '@/services/api'
import { formatDate } from '@/utils/format'
import { apiErrorMessage } from '@/utils/apiError'

const devices = ref([])
const loading = ref(true)
const busy = ref(null)
const error = ref('')

async function load() {
  loading.value = true
  try {
    devices.value = await profileApi.trustedDevices()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

async function revoke(device) {
  busy.value = device.id
  error.value = ''
  try {
    await profileApi.revokeTrustedDevice(device.id)
    devices.value = devices.value.filter(d => d.id !== device.id)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = null
  }
}

onMounted(load)
</script>
