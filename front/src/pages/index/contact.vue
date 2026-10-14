<template>
  <PlaceholderPage :title="$t('landing.footer.contact')" />
</template>

<script setup>
import PlaceholderPage from '@/components/landing/PlaceholderPage.vue'

definePage({ meta: { auth: 'public' } })
</script>
