<template>
  <q-page class="placeholder-page">
    <div class="placeholder-page__inner cm-card">
      <h1 class="placeholder-page__title cm-heading">{{ title }}</h1>
      <p class="cm-muted q-mb-none">Contenu à venir.</p>
      <router-link to="/" class="placeholder-page__back"
        >← Retour à l’accueil</router-link
      >
    </div>
  </q-page>
</template>

<script setup>
/** A site page linked from the landing footer whose content is still to be written. */
defineProps({
  title: { type: String, required: true }
})
</script>

<style scoped lang="scss">
.placeholder-page {
  padding: 24px 16px 48px;
}

.placeholder-page__inner {
  max-width: 720px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.placeholder-page__title {
  margin: 0;
  font-size: 28px;
  line-height: 1.1;
}

.placeholder-page__back {
  align-self: flex-start;
  font-weight: 700;
  text-decoration: none;
}
</style>
