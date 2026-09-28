<template>
  <q-page padding class="row justify-center">
    <div class="col-12 col-md-8 q-gutter-y-lg">
      <div class="text-h5">Mon profil</div>

      <q-banner v-if="linkedNotice" class="bg-green-1" rounded>{{
        linkedNotice
      }}</q-banner>

      <q-card v-if="profile" flat bordered>
        <q-card-section>
          <div class="text-subtitle1">Compte</div>
          <div>Email : {{ profile.email ?? 'aucun' }}</div>
          <div v-if="profile.pendingEmail">
            En attente de confirmation : {{ profile.pendingEmail }} (ouvrez le
            lien reçu par email)
          </div>
          <div>Membre depuis le {{ formatDate(profile.createdAt) }}</div>
        </q-card-section>
      </q-card>

      <LinkedAccounts v-if="profile" :profile="profile" />
      <PasswordSection v-if="profile" :profile="profile" />
      <TrustedDevices v-if="profile?.hasPassword" />
    </div>
  </q-page>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { formatDate, providerLabel } from '@/utils/format'
import LinkedAccounts from '@/components/profile/LinkedAccounts.vue'
import PasswordSection from '@/components/profile/PasswordSection.vue'
import TrustedDevices from '@/components/profile/TrustedDevices.vue'

definePage({ meta: { auth: 'required' } })

const auth = useAuthStore()
const route = useRoute()

const profile = computed(() => auth.profile)
const linkedNotice = computed(() =>
  typeof route.query.linked === 'string'
    ? `Compte ${providerLabel(route.query.linked)} lié.`
    : ''
)
</script>
