import { afterEach, describe, expect, it } from 'vitest'
import {
  browserLocale,
  currentLocale,
  frenchPlural,
  i18n,
  intlLocale,
  isLocale,
  t
} from '@/i18n'

describe('languages (docs/I18N.md)', () => {
  afterEach(() => {
    i18n.global.locale.value = 'fr'
  })

  it('takes the first language of the browser it speaks, English otherwise', () => {
    expect(browserLocale(['fr-FR', 'en'])).toBe('fr')
    expect(browserLocale(['fr-CA'])).toBe('fr')
    expect(browserLocale(['de-DE', 'fr-FR'])).toBe('fr')
    expect(browserLocale(['en-US', 'fr'])).toBe('en')
    expect(browserLocale(['de-DE', 'it'])).toBe('en')
    expect(browserLocale([])).toBe('en')
  })

  it('knows its languages and their Intl tags', () => {
    expect([
      isLocale('fr'),
      isLocale('en'),
      isLocale('de'),
      isLocale('fr-FR')
    ]).toEqual([true, true, false, false])
    expect([currentLocale(), intlLocale()]).toEqual(['fr', 'fr-FR'])
    i18n.global.locale.value = 'en'
    expect(intlLocale()).toBe('en-GB')
  })

  it('shows the French text while a key is not translated', () => {
    expect(t('common.locale.title')).toBe('Langue')
    i18n.global.locale.value = 'en'
    expect(t('common.locale.title')).toBe('Langue')
    expect(t('common.locale.menu', { name: 'English' })).toBe(
      'Langue : English'
    )
  })

  it('keeps 0 and 1 singular in French', () => {
    expect([0, 1, 2, 5].map(n => frenchPlural(n, 2))).toEqual([0, 0, 1, 1])
    expect([0, 1, 2, 5].map(n => frenchPlural(n, 3))).toEqual([0, 1, 2, 2])
    expect(t('session.builder.modules', 0)).toBe('0 module')
    expect(t('session.builder.modules', 1)).toBe('1 module')
    expect(t('session.builder.modules', 3)).toBe('3 modules')
  })
})
