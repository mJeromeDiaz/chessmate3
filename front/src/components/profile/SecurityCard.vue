<template>
  <section class="cm-card profile-card" data-testid="profile-security">
    <h2 class="cm-card__title">{{ $t('profile.security.title') }}</h2>
    <div class="profile-block" data-testid="profile-email">
      <div class="profile-row__title">{{ $t('profile.security.email') }}</div>
      <div>{{ profile.email ?? $t('profile.security.noEmail') }}</div>
      <div v-if="profile.pendingEmail" class="profile-row__sub">
        {{
          $t('profile.security.pendingEmail', { email: profile.pendingEmail })
        }}
      </div>
    </div>
    <PasswordSection :profile="profile" />
    <TrustedDevices v-if="profile.hasPassword" />
  </section>
</template>

<script setup>
/** Profile: email, password and trusted devices (not in the design, kept in its style). */
import PasswordSection from '@/components/profile/PasswordSection.vue'
import TrustedDevices from '@/components/profile/TrustedDevices.vue'

defineProps({
  /** The /api/profile payload. */
  profile: { type: Object, required: true }
})
</script>
