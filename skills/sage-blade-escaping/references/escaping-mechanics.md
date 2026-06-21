# Escaping mechanics — the full picture

Deep-dive backing the rules in `SKILL.md`. Read this when you need to justify a
call, handle a context the rules table doesn't cover (JSON-in-attribute, inline
`<script>`, SVG), or explain *why* a fix is required.

## How `{{ }}` compiles

Blade compiles `{{ $x }}` to `<?php echo e($x); ?>`. Laravel's `e()` is:

```php
function e($value, $doubleEncode = true)
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', $doubleEncode);
}
```

Two facts that matter:

- **`ENT_QUOTES`** — both `"` and `'` are encoded, so `{{ }}` is safe inside
  either single- or double-quoted HTML attributes. You do **not** need
  `esc_attr()` on top of it.
- **`$doubleEncode = true`** (the default) — `htmlspecialchars` re-encodes the
  `&` in strings that are *already* entities. The Laravel 13 docs state this
  plainly: *"By default, Blade (and the Laravel `e` function) will double encode
  HTML entities."* That is the entire bug surface.

`{!! $x !!}` compiles to `<?php echo $x; ?>` — raw, no escaping. The official
docs warn: *"Be very careful when echoing content that is supplied by users…
You should typically use the escaped, double curly brace syntax."* So `{!! !!}`
is only safe when **something else already escaped the value** — which is
exactly what the WordPress `esc_*` / `wp_kses_*` helpers do.

## What each WordPress helper returns

| Helper | Encodes / does | Output is… | Emit with |
| --- | --- | --- | --- |
| `esc_url($url)` | Strips disallowed protocols (allowlist: `http`, `https`, `mailto`, `tel`…), encodes `&`→`&#038;`, `'`→`&#039;`, spaces | escaped, display-safe URL | `{!! esc_url($url) !!}` |
| `esc_url_raw($url)` | Same sanitizing, **no** entity encoding | raw URL for DB/redirects, **not** display | (don't echo to HTML) |
| `esc_attr($s)` | `htmlspecialchars` with `ENT_QUOTES` | escaped attribute value | (don't — Blade `{{ }}` already does this) |
| `esc_html($s)` | `htmlspecialchars` with `ENT_QUOTES` | escaped text | (don't — Blade `{{ }}` already does this) |
| `wp_kses_post($html)` | Strips tags/attrs outside the post allowlist, keeps safe markup | sanitized **HTML** | `{!! wp_kses_post($html) !!}` |
| `wc_price($n)` | Returns a `<span class="woocommerce-Price-amount">…</span>` | **HTML** | `{!! wc_price($n) !!}` |
| `$product->get_price_html()` | Returns price markup (`<del>`, `<ins>`, `<bdi>`) | **HTML** | `{!! $product->get_price_html() !!}` |
| `get_the_term_list()` | Returns linked term list (`<a>…</a>`) | **HTML** | `{!! get_the_term_list(...) !!}` |

The split is contextual:

- **Display URL helpers** (`esc_url`) entity-encode and protocol-sanitize → emit
  raw with `{!! esc_url($url) !!}`, or Blade will double-encode entities.
- **Attribute/text helpers** (`esc_attr`, `esc_html`) entity-encode scalar text.
  Emitting them raw would avoid double-encoding, but in Blade they are redundant;
  prefer the original plain value with `{{ $text }}`.
- **HTML helpers** (`wp_kses_post`, `wc_price`, `*_html`) return markup → emit
  raw, or `{{ }}` prints tags as literal text.

The only thing that takes bare `{{ }}` is a **plain PHP string you have not
passed through any `esc_*` helper**, or a `Js::from(...)` value whose object
intentionally renders safe script output.

## Why the bug is silent

`esc_url('/shop/widgets/')` returns `/shop/widgets/` unchanged — no `&`, `'`,
`"`, so double-encoding is a no-op and `{{ esc_url($url) }}` *looks* correct.
The damage only appears once the URL carries a query string or an entity:

```
esc_url('/shop/?category[]=a&category[]=b')
  → /shop/?category%5B%5D=a&#038;category%5B%5D=b
{{ … }} re-encodes the & in &#038;
  → /shop/?category%5B%5D=a&amp;#038;category%5B%5D=b   ❌ broken filter link
```

So "it renders fine locally" proves nothing — the clean-permalink path hides it.
This is why pattern-matching nearby Blade is unsafe and the audit script exists.

## Context cheat-sheet

| Output context | Preferred Blade form | Notes |
| --- | --- | --- |
| HTML text node | `{{ $text }}` | Plain scalar text; no `esc_html()` wrapper. |
| HTML attribute (`alt`, `aria-label`, `title`) | `{{ $text }}` | `e()` uses `ENT_QUOTES`, so quotes are escaped. |
| URL attribute (`href`, `src`, `action`, `data-href`) | `{!! esc_url($url) !!}` | Use `esc_url()` for display; do not echo `esc_url_raw()`. |
| Rich HTML (editor content, price markup, term lists) | `{!! wp_kses_post($html) !!}` or trusted helper HTML | Raw only after sanitization or a trusted WP/WC HTML helper. |
| JSON in inline `<script>` | `{{ \Illuminate\Support\Js::from($data) }}` | Laravel escapes for script inclusion and returns a safe JS expression. |
| JSON in an HTML attribute (`x-data`, `data-config`) | `x-data='@json($data)'` | Use single quotes around the attribute; JSON contains double quotes. |
| Inline JS string fragment | `'{!! esc_js($text) !!}'` | Last resort only, inside single-quoted JS strings; not for JSON data. |
| Inline `<style>` custom prop, scalar (`--w: {{ $px }}px`) | `{{ $px }}` | For untrusted non-scalar style values, sanitize before raw output. |

Notes:

- **`Js::from($data)`** is the Laravel-preferred form for structured data in
  `<script>` tags. Use `\Illuminate\Support\Js::from(...)` if the `Js` facade
  alias is not registered in Acorn.
- **`@json($data)`** compiles to `json_encode` with safe hex flags. It is useful
  in attributes when the HTML attribute itself is single-quoted:
  `x-data='@json($data)'`.
- **`esc_js()`** is context-specific. WordPress documents it for inline JS string
  fragments, especially strings inside single quotes. Prefer structured JSON
  over inline event-handler code.
- **URLs are the one place `{{ }}` is technically XSS-safe but still wrong**:
  `htmlspecialchars` blocks attribute breakout but does **not** strip a
  `javascript:` scheme. `esc_url()` adds that allowlist, so `{!! esc_url() !!}`
  is the defense-in-depth choice even for WP-core-generated URLs — and it's the
  established form across this theme.
- **Never** reach for `{!! !!}` around a bare variable to "fix rendering." If a
  value needs raw output it must first pass through `esc_url()` / `wp_kses_post()`
  or a documented escape-then-markup pattern.

## Canonical example

See `../examples/category-subcategory-cards.blade.php` — a complete, annotated
Blade template that applies the common URL, text, and HTML rules in context:

- `{!! esc_url($href) !!}` for an `add_query_arg(...)` URL (the query-string case)
- `{{ $var }}` for plain text and attribute text (no `esc_html`/`esc_attr`)
- `{!! nl2br(e($text)) !!}` for the escape-then-add-markup pattern
- the matching ❌ mistakes for contrast

Copy from it.
