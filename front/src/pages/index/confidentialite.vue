<template>
  <PlaceholderPage :title="$t('landing.footer.privacy')" />
</template>

<script setup>
import PlaceholderPage from '@/components/landing/PlaceholderPage.vue'

definePage({ meta: { auth: 'public' } })
</script>
