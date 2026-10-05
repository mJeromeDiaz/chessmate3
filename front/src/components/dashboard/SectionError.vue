<template>
  <section class="cm-card" data-testid="section-error">
    <h2 class="cm-card__title">{{ title }}</h2>
    <p class="cm-muted q-mt-sm q-mb-sm">{{ message }}</p>
    <q-btn
      flat
      dense
      no-caps
      color="primary"
      label="Réessayer"
      @click="emit('retry')"
    />
  </section>
</template>

<script setup>
/** A block of the statistics page that could not be loaded, with a retry. */
defineProps({
  title: { type: String, required: true },
  message: { type: String, required: true }
})

const emit = defineEmits({ retry: null })
</script>
