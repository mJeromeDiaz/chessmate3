import { defineStore } from 'pinia'
import { ref } from 'vue'
import { announcementApi } from '@/services/api'

/**
 * The team's announcements the player has still to see (docs/ANNOUNCEMENTS.md, § 3), the oldest
 * first: the modal shows the first one.
 */
export const useAnnouncementStore = defineStore('announcement', () => {
  /** @type {import('vue').Ref<import('@/utils/announcement').CurrentAnnouncement[]>} */
  const items = ref([])

  /** Asks the server again; a failure keeps what was there. */
  async function load() {
    try {
      items.value = (await announcementApi.current()).announcements
    } catch {
      // Next visit, tab return or poll.
    }
  }

  /**
   * The player closed it: gone at once, never shown again; never rejects (a failure brings it
   * back with the next reading).
   *
   * @param {string} id
   */
  async function markRead(id) {
    items.value = items.value.filter(a => a.id !== id)
    await announcementApi.markRead(id).catch(() => {})
  }

  return { items, load, markRead }
})
