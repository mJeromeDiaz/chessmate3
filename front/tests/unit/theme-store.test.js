import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const dark = vi.hoisted(() => ({ set: vi.fn() }))
const api = vi.hoisted(() => ({ setTheme: vi.fn() }))

vi.mock('quasar', () => ({ Dark: dark }))
vi.mock('@/services/api', () => ({ authApi: {}, profileApi: api }))

const { useThemeStore, THEME_KEY } = await import('@/stores/theme')
const { useAuthStore } = await import('@/stores/auth')

describe('theme store', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.clearAllMocks()
    setActivePinia(createPinia())
  })

  it('follows the OS until a choice is made', () => {
    const store = useThemeStore()
    store.apply()

    expect(store.theme).toBe('auto')
    expect(dark.set).toHaveBeenCalledWith('auto')
  })

  it('applies and remembers a choice in this browser', async () => {
    await useThemeStore().choose('dark')

    expect(dark.set).toHaveBeenLastCalledWith(true)
    expect(localStorage.getItem(THEME_KEY)).toBe('dark')
    // Signed out: nothing sent.
    expect(api.setTheme).not.toHaveBeenCalled()

    setActivePinia(createPinia())
    const again = useThemeStore()
    expect(again.theme).toBe('dark')
    again.apply()
    expect(dark.set).toHaveBeenLastCalledWith(true)
  })

  it('ignores a corrupt stored value and an unknown theme', async () => {
    localStorage.setItem(THEME_KEY, 'sepia')
    const store = useThemeStore()
    expect(store.theme).toBe('auto')

    await store.choose(/** @type {any} */ ('sepia'))
    expect(store.theme).toBe('auto')
    expect(localStorage.getItem(THEME_KEY)).toBe('sepia')
  })

  it('saves the choice on the account when signed in', async () => {
    useAuthStore().accessToken = 'token'
    api.setTheme.mockResolvedValue({ theme: 'light' })

    await useThemeStore().choose('light')

    expect(api.setTheme).toHaveBeenCalledWith('light')
    expect(useAuthStore().profile).toEqual({ theme: 'light' })
    expect(dark.set).toHaveBeenLastCalledWith(false)
  })

  it('keeps the choice locally when the account save fails', async () => {
    useAuthStore().accessToken = 'token'
    api.setTheme.mockRejectedValue(new Error('offline'))

    await useThemeStore().choose('dark')

    expect(localStorage.getItem(THEME_KEY)).toBe('dark')
  })

  it("takes the account's theme when the profile loads", () => {
    localStorage.setItem(THEME_KEY, 'light')
    const store = useThemeStore()

    store.adopt({ theme: 'dark' })

    expect(store.theme).toBe('dark')
    expect(localStorage.getItem(THEME_KEY)).toBe('dark')
    expect(dark.set).toHaveBeenLastCalledWith(true)
    expect(api.setTheme).not.toHaveBeenCalled()
  })

  it("gives an account without a theme this browser's choice, never the default", async () => {
    api.setTheme.mockResolvedValue({ theme: 'light' })
    useThemeStore().adopt({ theme: null })
    expect(api.setTheme).not.toHaveBeenCalled()

    localStorage.setItem(THEME_KEY, 'light')
    setActivePinia(createPinia())
    useThemeStore().adopt({ theme: null })
    expect(api.setTheme).toHaveBeenCalledWith('light')
  })
})
