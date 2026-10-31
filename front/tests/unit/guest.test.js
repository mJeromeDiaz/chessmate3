import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useAuthStore } from '@/stores/auth'
import { useGuestStore } from '@/stores/guest'
import { addRunXp, runXp } from '@/utils/guest/runXp'

describe('runXp', () => {
  it('sums the XP each submission of a run announced', () => {
    addRunXp('run-a', 12)
    addRunXp('run-a', null)
    addRunXp('run-a', 0)
    addRunXp('run-a', 6)
    addRunXp('run-b', 4)

    expect(runXp('run-a')).toBe(18)
    expect(runXp('run-b')).toBe(4)
    expect(runXp('run-c')).toBe(0)
  })
})

describe('guest store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  /** @param {boolean} guest */
  function signedIn(guest) {
    const auth = useAuthStore()
    auth.accessToken = 'token'
    auth.profile = { isGuest: guest }
    return auth
  }

  it('tells a guest what an account would have kept, once per run', () => {
    const auth = signedIn(true)
    const guest = useGuestStore()

    expect([auth.isGuest, auth.isMember]).toEqual([true, false])
    guest.tell('run-1', 30, 60)
    expect(guest.missed).toEqual({ xp: 30, crystals: 60 })

    guest.dismiss()
    guest.tell('run-1', 30, 60)
    expect(guest.missed).toBeNull()
  })

  it('tells nothing to an account, nor when nothing was missed', () => {
    const auth = signedIn(false)
    const guest = useGuestStore()

    expect(auth.isMember).toBe(true)
    guest.tell('run-1', 30, 0)
    expect(guest.missed).toBeNull()

    auth.profile = { isGuest: true }
    guest.tell('run-2', 0, 0)
    expect(guest.missed).toBeNull()
  })
})
