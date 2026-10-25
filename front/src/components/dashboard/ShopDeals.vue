<template>
  <section
    v-if="deals.length"
    class="cm-card shop-deals"
    data-testid="dashboard-shop"
  >
    <div class="shop-deals__head">
      <h2 class="cm-card__title">{{ $t('dashboard.shop.title') }}</h2>
      <router-link to="/boutique" class="shop-deals__all">{{
        $t('dashboard.shop.link')
      }}</router-link>
    </div>
    <div class="shop-deals__list">
      <router-link
        v-for="deal in deals"
        :key="deal.id"
        :to="{ path: '/boutique', query: { offer: deal.slug } }"
        class="shop-deals__deal"
        :style="{ background: deal.look.tile, color: deal.look.tileInk }"
        data-testid="dashboard-deal"
      >
        <span class="shop-deals__main">
          <span class="shop-deals__top">
            <span class="shop-deals__kicker">{{ deal.look.kicker }}</span>
            <span v-if="deal.saving" class="shop-deals__saving">{{
              deal.saving
            }}</span>
          </span>
          <span class="shop-deals__name">{{ deal.name }}</span>
          <span class="shop-deals__contents">{{ deal.contents }}</span>
          <span v-if="deal.ends" class="shop-deals__ends">{{ deal.ends }}</span>
        </span>
        <span class="shop-deals__prices">
          <span v-if="deal.wasPrice" class="shop-deals__was">{{
            crowns(deal.wasPrice)
          }}</span>
          <span class="shop-deals__price"
            ><CrystalGem :size="20" />{{ crowns(deal.price) }}</span
          >
        </span>
      </router-link>
    </div>
  </section>
</template>

<script setup>
/**
 * « En ce moment à la boutique » (design « Dashboard v2 », chosen 2026-10-25): up to three real
 * offers on promotion (a struck price or an end date), the soonest to end first, what they hold
 * read from their items; one opens its offer in the store. Hidden without a deal or when the store
 * does not answer.
 */
import { computed, onMounted } from 'vue'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import { useShopStore } from '@/stores/shop'
import { dealContents, dealEnds, dealsOf } from '@/utils/dashboard/home'
import { crowns, lookOf, savingOf } from '@/utils/shop'

const shop = useShopStore()

const deals = computed(() =>
  dealsOf(shop.state?.offers ?? []).map(offer => ({
    ...offer,
    look: lookOf(offer),
    saving: savingOf(offer),
    contents: dealContents(offer),
    ends: dealEnds(offer.endsAt)
  }))
)

onMounted(() => {
  shop.load().catch(() => {})
})
</script>

<style scoped lang="scss">
.shop-deals {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.shop-deals__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}

.shop-deals__all {
  color: var(--cm-brand);
  font-size: 13px;
  font-weight: 800;
  text-decoration: none;
}

// Phone: cards side by side, scrolled; from md, a list in the side column.
.shop-deals__list {
  display: flex;
  gap: 10px;
  margin: 0 -4px;
  padding: 0 4px 4px;
  overflow-x: auto;
  scroll-snap-type: x mandatory;

  @media (min-width: $breakpoint-md-min) {
    flex-direction: column;
    overflow: visible;
  }
}

.shop-deals__deal {
  flex: none;
  width: 280px;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 18px;
  text-decoration: none;
  scroll-snap-align: start;

  @media (min-width: $breakpoint-md-min) {
    width: auto;
  }
}

.shop-deals__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.shop-deals__top {
  display: flex;
  align-items: center;
  gap: 6px;
}

.shop-deals__kicker {
  font-size: 10.5px;
  font-weight: 800;
  letter-spacing: 0.06em;
  opacity: 0.85;
}

.shop-deals__saving {
  display: flex;
  align-items: center;
  height: 20px;
  padding: 0 6px;
  border-radius: 6px;
  background: var(--cm-lime);
  color: #1b1530;
  font-size: 11px;
  font-weight: 800;
}

.shop-deals__name {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 16px;
  line-height: 1.15;
}

.shop-deals__contents {
  font-size: 12px;
  line-height: 1.3;
  opacity: 0.85;
}

.shop-deals__ends {
  font-size: 11px;
  font-weight: 700;
  opacity: 0.75;
}

.shop-deals__prices {
  flex: none;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 3px;
}

.shop-deals__was {
  font-size: 12px;
  text-decoration: line-through;
  opacity: 0.6;
}

.shop-deals__price {
  display: flex;
  align-items: center;
  gap: 5px;
  height: 32px;
  padding: 0 10px 0 4px;
  border-radius: 11px;
  background: #fff;
  color: #1b1530;
  font-weight: 800;
  font-size: 14px;
}
</style>
