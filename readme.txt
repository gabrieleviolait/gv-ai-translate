=== GV AI Translate ===
Contributors: gabrieleviola
Tags: translation, ai, groq, google translate, multilingual
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.0.10
License: GPLv2 or later

Traduttore AI per WordPress con selettore lingua, traduzione AJAX dei testi visibili, cache locale e fallback gratuito Google Translate.

== Funzioni ==
* Shortcode [gv_translate]
* Selettore dropdown o bottoni
* Selettore floating opzionale
* Bandierine SVG IT/EN
* Provider: Groq, OpenAI, Anthropic, Gemini
* Fallback finale google_free senza API key tramite endpoint web Google Translate
* Cache transients per evitare chiamate continue

== Uso consigliato ==
1. Installa e attiva il plugin.
2. Vai in Impostazioni > GV AI Translate.
3. Imposta lingue: it,en.
4. Ordine provider consigliato: groq,openai,anthropic,google,google_free.
5. Inserisci solo le API key che vuoi usare. Se falliscono o mancano, google_free resta come fallback.
6. Inserisci [gv_translate] dove vuoi il selettore oppure usa il floating.

== Nota ==
Il fallback google_free è comodo e gratuito, ma non è una API ufficiale con SLA. Per produzione seria conviene mantenere Groq/API come primo provider e usare google_free solo come rete di sicurezza.
