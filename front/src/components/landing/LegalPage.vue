<template>
  <q-page class="legal-page">
    <article class="legal-page__inner cm-card" :data-testid="`legal-${page}`">
      <h1 class="legal-page__title cm-heading">{{
        $t(`legal.${page}.title`)
      }}</h1>
      <p class="legal-page__updated">{{ $t('legal.updated') }}</p>
      <p class="legal-page__draft">{{ $t('legal.draft') }}</p>
      <section
        v-for="section in sections"
        :key="section.key"
        class="legal-page__section"
      >
        <h2 class="legal-page__h2">{{ section.title }}</h2>
        <p v-for="(text, i) in section.paragraphs" :key="i">{{ text }}</p>
      </section>
      <router-link to="/" class="legal-page__back">{{
        $t('legal.home')
      }}</router-link>
    </article>
  </q-page>
</template>

<script setup>
import { computed } from 'vue'
import { legalSections } from '@/utils/legal'

/** A legal page of the site (terms of use, privacy), its texts in `legal.<page>`. */
const props = defineProps({
  /** @type {import('vue').PropType<'terms'|'privacy'>} */
  page: { type: String, required: true }
})

// Read again when the language changes (t() follows the current locale).
const sections = computed(() => legalSections(props.page))
</script>

<style scoped lang="scss">
.legal-page {
  padding: 24px 16px 48px;
}

.legal-page__inner {
  max-width: 760px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 12px;
  line-height: 1.6;

  p {
    margin: 0;
  }
}

.legal-page__title {
  margin: 0;
  font-size: 30px;
  line-height: 1.1;
}

.legal-page__updated {
  color: var(--cm-muted);
  font-size: 14px;
}

.legal-page__draft {
  padding: 10px 14px;
  border-radius: 12px;
  background: var(--cm-orange-soft);
  color: var(--cm-orange-ink);
  font-size: 14px;
  font-weight: 600;
}

.legal-page__section {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 8px;
}

.legal-page__h2 {
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 19px;
}

.legal-page__back {
  align-self: flex-start;
  margin-top: 8px;
  font-weight: 700;
  text-decoration: none;
}
</style>
