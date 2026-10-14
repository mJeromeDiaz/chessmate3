<template>
  <section
    v-if="slides.length"
    class="cm-card spotlight"
    data-testid="dashboard-shop"
  >
    <div class="spotlight__head">
      <h2 class="cm-card__title">En ce moment à la boutique</h2>
      <router-link to="/boutique" class="spotlight__link"
        >Voir la boutique →</router-link
      >
    </div>
    <ShopCarousel :slides="slides" @open="open" />
  </section>
</template>

<script setup>
/**
 * "En ce moment à la boutique" (docs/DASHBOARD.md, docs/SHOP.md): the store's carousel on the
 * dashboard; a slide opens its offer in the store. Hidden without slides or when the store does
 * not answer.
 */
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import ShopCarousel from '@/components/shop/ShopCarousel.vue'
import { useShopStore } from '@/stores/shop'
import { profImage } from '@/utils/prof/images'
import { carouselSlides } from '@/utils/shop'

const router = useRouter()
const shop = useShopStore()

const slides = computed(() => carouselSlides(shop.state, profImage))

/** @param {import('@/utils/shop').ShopOffer} offer */
function open(offer) {
  router.push({ path: '/boutique', query: { offer: offer.slug } })
}

onMounted(() => {
  shop.loadOnce().catch(() => {})
})
</script>

<style scoped lang="scss">
.spotlight {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.spotlight__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}

.spotlight__link {
  font-size: 13px;
  font-weight: 700;
  text-decoration: none;
  white-space: nowrap;
}
</style>
