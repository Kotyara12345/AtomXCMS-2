#!/usr/bin/env python3
"""Build sys/js/emoji/emoji-data-{en,ru}.json from Unicode 17.0 + CLDR 48.

Inputs:
  - https://www.unicode.org/Public/17.0.0/emoji/emoji-test.txt
  - https://raw.githubusercontent.com/unicode-org/cldr-json/48.0.0/cldr-annotations-full/annotations/{en,ru}/annotations.json

Outputs: emoji-data-en.json, emoji-data-ru.json
shortcut: regenerate only for Unicode 17.0 / CLDR 48.0, not for newer releases.
"""
import json
import os
import re
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(os.path.dirname(HERE)))
TMP = os.path.join(REPO, '.tmp-emoji')
OUT = HERE

GROUP_CAT = {
    'Smileys & Emotion': 'smileys',
    'People & Body': 'people',
    'Animals & Nature': 'animals',
    'Food & Drink': 'food',
    'Travel & Places': 'travel',
    'Activities': 'activities',
    'Objects': 'objects',
    'Symbols': 'symbols',
    'Flags': 'flags',
}
CATS = ['smileys', 'people', 'animals', 'food', 'travel', 'activities', 'objects', 'symbols', 'flags']
TONE_BASE = 0x1F3FB
REGIONAL_A = 0x1F1E6
TAG_BASE = 0xE0061
TAG_END = 0xE007F

# CLDR annotations have no flag entries; country names come from territories.
SUBDIVISIONS = {
    'gbeng': {'en': 'England', 'ru': 'Англия'},
    'gbsct': {'en': 'Scotland', 'ru': 'Шотландия'},
    'gbwls': {'en': 'Wales', 'ru': 'Уэльс'},
}
FLAG_WORD = {'en': 'flag', 'ru': 'флаг'}
KEYCAP_WORD = {'en': 'keycap', 'ru': 'клавиша'}


def parse_test(path):
    group = None
    entries = []
    with open(path, encoding='utf-8') as f:
        for line in f:
            line = line.rstrip('\n')
            if line.startswith('# group:'):
                group = line.split(':', 1)[1].strip()
                continue
            if not line or line.startswith('#'):
                continue
            head, _, tail = line.partition('#')
            parts = head.split(';')
            if len(parts) != 2 or parts[1].strip() != 'fully-qualified':
                continue
            cps = tuple(int(x, 16) for x in parts[0].split())
            m = re.match(r'^\S+\s+E[\d.]+\s+(.*)$', tail.strip())
            entries.append((cps, group, m.group(1) if m else tail.strip()))
    return entries


def find_annotation(annot, seq, cps):
    cands = [seq]
    no_vs = ''.join(chr(c) for c in cps if c != 0xFE0F)
    if no_vs != seq:
        cands.append(no_vs)
    for c in cands:
        if c in annot:
            return annot[c]
    return None


def finish_words(label, words):
    words = [label] + words
    seen, out = set(), []
    for w in words:
        w = w.strip().lower()
        if w and w not in seen:
            seen.add(w)
            out.append(w)
    pruned = [w for w in out if not any(w != o and w in o for o in out)]
    pruned.sort(key=len, reverse=True)
    return pruned[0], pruned[:10]


def fmt_words(annot, fallback_name):
    if not annot:
        return finish_words(fallback_name, [])
    words = list(annot.get('default') or [])
    tts = annot.get('tts') or []
    label = tts[0] if tts else (words[0] if words else fallback_name)
    return finish_words(label, words + tts)


def flag_region(cps):
    """Decode a flag sequence into ('territory', 'RU') or ('subdivision', 'gbeng')."""
    if len(cps) == 2 and all(REGIONAL_A <= c <= REGIONAL_A + 25 for c in cps):
        return 'territory', ''.join(chr(c - REGIONAL_A + ord('A')) for c in cps)
    if cps and cps[0] == 0x1F3F4 and cps[-1] == TAG_END:
        letters = ''.join(chr(c - TAG_BASE + ord('a')) for c in cps[1:-1] if TAG_BASE <= c < TAG_BASE + 26)
        if letters:
            return 'subdivision', letters
    return None


def flag_words(cps, territories, lang):
    region = flag_region(cps)
    if not region:
        return None
    kind, code = region
    if kind == 'territory':
        name = territories.get(code)
    else:
        name = SUBDIVISIONS.get(code, {}).get(lang)
    if not name:
        return None
    word = FLAG_WORD[lang]
    return finish_words(word + ': ' + name, [word, name])


def fallback_words(cps, name, annot, territories, lang):
    """Localize what CLDR annotations do not cover: flags and ZWJ sequences."""
    words = flag_words(cps, territories, lang)
    if words:
        return words
    if 0x20E3 in cps:
        base = ''.join(chr(c) for c in cps if c not in (0xFE0F, 0x20E3))
        word = KEYCAP_WORD[lang]
        return finish_words(word + ': ' + base, [word])
    if 0x200D in cps:
        labels, keywords = [], []
        chunk = []
        for c in list(cps) + [0x200D]:
            if c != 0x200D:
                chunk.append(c)
                continue
            part = tuple(c for c in chunk if c != 0xFE0F and not (TONE_BASE <= c <= TONE_BASE + 4))
            chunk = []
            if not part:
                continue
            a = find_annotation(annot, ''.join(chr(c) for c in part), part)
            if a is None:
                continue
            tts = a.get('tts') or []
            if tts:
                labels.append(tts[0])
                keywords.extend(tts)
            keywords.extend(a.get('default') or [])
        if labels:
            return finish_words(': '.join(labels), keywords)
    return fmt_words(None, name)


def download(name, url):
    path = os.path.join(TMP, name)
    if not os.path.exists(path):
        os.makedirs(TMP, exist_ok=True)
        urllib.request.urlretrieve(url, path)
    return path


def main():
    test = download('emoji-test.txt', 'https://www.unicode.org/Public/17.0.0/emoji/emoji-test.txt')
    entries = parse_test(test)
    annot = {}
    territories = {}
    for lang in ('en', 'ru'):
        path = download('annotations-%s.json' % lang,
                        'https://raw.githubusercontent.com/unicode-org/cldr-json/48.0.0/cldr-annotations-full/annotations/%s/annotations.json' % lang)
        with open(path, encoding='utf-8') as f:
            annot[lang] = json.load(f)['annotations']['annotations']
        path = download('territories-%s.json' % lang,
                        'https://raw.githubusercontent.com/unicode-org/cldr-json/48.0.0/cldr-json/cldr-localenames-full/main/%s/territories.json' % lang)
        with open(path, encoding='utf-8') as f:
            territories[lang] = json.load(f)['main'][lang]['localeDisplayNames']['territories']

    bases = []
    variants = {}
    base_by_cps = {}
    for cps, group, name in entries:
        if group == 'Component':
            continue
        mods = [c for c in cps if TONE_BASE <= c <= TONE_BASE + 4]
        if mods:
            if len(mods) == 1:
                base = tuple(c for c in cps if c != mods[0])
                if base in base_by_cps:
                    variants.setdefault(base, set()).add(mods[0] - TONE_BASE)
            continue
        if group not in GROUP_CAT or base_by_cps.get(cps) is not None:
            continue
        base_by_cps[cps] = len(bases)
        bases.append((cps, group, name))

    for lang in ('en', 'ru'):
        rows = []
        missing = 0
        for cps, group, name in bases:
            seq = ''.join(chr(c) for c in cps)
            a = find_annotation(annot[lang], seq, cps)
            if a is None:
                missing += 1
                label, words = fallback_words(cps, name, annot[lang], territories[lang], lang)
            else:
                label, words = fmt_words(a, name)
            tones = None
            idxs = variants.get(cps)
            if idxs:
                tones = [None] * 5
                for i in idxs:
                    tones[i] = seq + chr(TONE_BASE + i)
                if all(t is None for t in tones):
                    tones = None
            rows.append([seq, CATS.index(GROUP_CAT[group]), tones, label, words])
        out = {'v': '17.0', 'lang': lang, 'e': rows}
        with open(os.path.join(OUT, 'emoji-data-%s.json' % lang), 'w', encoding='utf-8', newline='\n') as f:
            json.dump(out, f, ensure_ascii=False, separators=(',', ':'))
        print(lang, 'rows=%d missing_annot=%d' % (len(rows), missing))


if __name__ == '__main__':
    main()
