# Escaping mechanics — the full picture

Deep-dive backing the rules in `SKILL.md`. Read this when you need to justify a
call, handle a context the rules table doesn't cover (JSON-in-attribute, inline
`<script>`, SVG), or explain *why* a fix is required.

## How `{{ }}` compiles

With default compiler configuration, a regular `{{ $x }}` calls `e($x)`.
Illuminate 13.23.0's helper:

1. Resolves deferred display values.
2. Returns `Htmlable::toHtml()` output without escaping.
3. Converts a backed enum to its backing value.
4. Otherwise takes this string/scalar escaping path:

```php
htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', $doubleEncode);
```

`ENT_QUOTES` encodes both quote kinds for HTML text and ordinary quoted
attributes; `ENT_SUBSTITUTE` replaces invalid UTF-8. `$doubleEncode` defaults
to `true`, so existing entity ampersands are encoded again.

`Htmlable` is an intentional bypass, not a sanitizer. `HtmlString` returns its
input unchanged. Review construction, inputs, and insertion context before
trusting such output, just as for raw echoes.

Laravel documents `Blade::withoutDoubleEncoding()`, which changes regular
echoes to `e($x, false)`. It does not change standalone `e($x)` calls, sanitize
URL/JS/CSS semantics, or turn escaped tags into rendered HTML. It changes
entity-like text's visible meaning: input `R&amp;D` displays literally with
default escaping, but as `R&D` with entity preservation. Keep configuration
changes separate from callsite repairs.

Raw `{!! $x !!}` bypasses the default `e()` layer. This project's raw-string
convention requires output already appropriate for the **exact insertion
context**; it is not the only syntax supported by Laravel. Custom echo
configuration/handlers can also change compilation.

Sources: [Laravel Blade](https://laravel.com/docs/13.x/blade#html-entity-encoding),
[pinned helper](https://github.com/illuminate/support/blob/v13.23.0/helpers.php),
[compiler](https://github.com/illuminate/view/blob/v13.23.0/Compilers/BladeCompiler.php),
[echo handling](https://github.com/illuminate/view/blob/v13.23.0/Compilers/Concerns/CompilesEchos.php).

## What each WordPress helper returns

| Helper | Encodes / does | Output is… | Emit with |
| --- | --- | --- | --- |
| `esc_url($url)` | Strips disallowed protocols (allowlist: `http`, `https`, `mailto`, `tel`…), encodes `&`→`&#038;`, `'`→`&#039;`, spaces | escaped, display-safe URL | `{!! esc_url($url) !!}` |
| `esc_url_raw($url)` | URL/protocol sanitizing without display entity encoding | storage/redirect URL, not display escaping | Use `esc_url()` at HTML display sites; this is not itself a double encoder |
| `esc_attr($s)` | UTF-8 validation, entity preservation, site charset, attribute escaping filter | escaped ordinary attribute text | `{!! esc_attr($s) !!}` when WP semantics are intentional |
| `esc_html($s)` | UTF-8 validation, entity preservation, site charset, HTML-text escaping filter | escaped text | `{!! esc_html($s) !!}` when WP semantics are intentional |
| `wp_kses_post($html)` | Filters unslashed input to the post HTML allowlist | HTML fragment for that content policy | `{!! wp_kses_post($html) !!}` where the allowlist fits |
| `wc_price($n)` | Returns price markup | HTML; review producer inputs/filters | Raw only in the reviewed HTML-content context |
| `$product->get_price_html()` | Returns price markup (`<del>`, `<ins>`, `<bdi>`) | HTML; review producer inputs/filters | Raw only in the reviewed HTML-content context |
| `get_the_term_list()` | Builds linked terms with caller markup and filters | HTML, `WP_Error`, or `false` | Handle return types; review markup/filters before raw output |
| `the_content()` | Filters and directly prints post content | no returned HTML value | `@php(the_content())` for the intended content pipeline |

### WordPress and Blade text semantics

For new plain, unescaped text and ordinary quoted attributes, prefer `{{ }}`.
Explicit WordPress text/attribute escaping followed by raw output is also valid
in the matching context. It is not identical to default Blade escaping:

- `_wp_specialchars()` preserves existing entities and uses the site's charset.
- `wp_check_invalid_utf8()` normally returns an empty string for invalid UTF-8
  on a UTF-8 site; Laravel's scalar path replaces malformed sequences.
- `esc_html` / `attribute_escape` filters run after encoding; their trusted
  callbacks are part of the output contract.

For input `R&amp;D`, default `e()` produces `R&amp;amp;D`, while `esc_html()`
produces `R&amp;D`. Before removing a helper from an escaped Blade echo,
preserve intended text and required WP behavior: choose plain input plus
Blade escaping, or one context-appropriate WP escape plus raw output.

Neither a WP/WC origin, an `esc_*` prefix, nor an `_html` suffix establishes
HTML safety. Returned fragments need producer/input/filter and return-type
review. `esc_sql()` targets SQL, not HTML.

Sources: [WP escaping](https://developer.wordpress.org/apis/security/escaping/),
[WP 7.0.2 formatting](https://github.com/WordPress/wordpress-develop/blob/7.0.2/src/wp-includes/formatting.php),
[term-list contract](https://developer.wordpress.org/reference/functions/get_the_term_list/).

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
| HTML text node | `{{ $text }}` | Plain, unescaped text; preserve intentional WP semantics as described above. |
| Ordinary quoted attribute (`alt`, `aria-label`, `title`) | `{{ $text }}` | Scalar path encodes both quote kinds; no URL/JS/CSS guarantees. |
| Single HTML URL (`href`, `src`, `action`, URL-valued `data-href`) | `{!! esc_url($url) !!}` | Project display convention; `data-*` is not inherently a URL. |
| Returned rich HTML | `{!! wp_kses_post($html) !!}` or reviewed producer output | Match sanitization/trust to the intended HTML-content context. |
| Directly printed post content | `@php(the_content())` | An echo wrapper cannot sanitize bytes already printed. |
| Data expression in inline `<script>` | `{{ \Illuminate\Support\Js::from($data) }}` | Complete JavaScript expression, not necessarily literal JSON. |
| JSON in an HTML attribute (`x-data`, `data-config`) | `x-data='@json($data)'` | Use single quotes around the attribute; JSON contains double quotes. |
| Inline JS string fragment | `'{!! esc_js($text) !!}'` | Last resort only, inside single-quoted JS strings; not for JSON data. |
| Inline CSS custom prop (`--w: {{ $px }}px`) | `{{ $px }}` with static units/structure | Accept only validated finite numbers in the property's allowed range; other CSS values require an explicit per-property allowlist. |

Notes:

- **CSS values need validation, not just HTML escaping.** For a pixel width,
  validate a finite number within the permitted range before passing it to the
  view. For other CSS values, select from a fixed per-property allowlist.
  `is_scalar()` is insufficient: the string `0; color: red; --ignored: 0`
  injects declarations in `style="--w: {{ $px }}px"`. Neither Blade escaping nor
  `esc_attr()` prevents this; reject invalid values before rendering.

- **`Js::from($data)`** is Laravel's documented inline-script form and intentionally
  implements `Htmlable`. Use the fully qualified class if no alias is registered.
  Its expression is not a substitute for literal JSON required by another consumer;
  an arbitrary `Htmlable` / `HtmlString` does not inherit its serialization guarantees.
- **`@json($data)`** compiles to `json_encode` with safe hex flags. It is useful
  in attributes when the HTML attribute itself is single-quoted:
  `x-data='@json($data)'`.
- **`esc_js()`** is context-specific. WordPress documents it for inline JS string
  fragments, especially strings inside single quotes. Prefer structured JSON
  over inline event-handler code.
- **Bare `{{ $url }}` is not generally XSS-safe.** `htmlspecialchars` prevents
  quoted-attribute breakout but preserves a `javascript:` scheme. Use
  `{!! esc_url($url) !!}` for protocol allowlisting without double-encoding,
  including WordPress-generated URLs that filters may change.
- **Direct printing is not returned HTML.** `the_content()` echoes after running
  its content filters; `{{ the_content() }}` escapes only its return value.
  Even `wp_kses_post(the_content())` cannot sanitize already printed output.
  Use the intended trusted content pipeline, not a raw-echo “fix.”
  `get_the_content()` does not apply the `the_content` filter and is not a fully
  rendered replacement. [WP content contracts](https://developer.wordpress.org/reference/functions/the_content/),
  [returning content](https://developer.wordpress.org/reference/functions/get_the_content/).
- **Context matters.** Single-URL HTML escaping does not cover `srcset` lists or
  URLs nested in JS/CSS. HTML-safe fragments are not safe attribute/JS/CSS values.
  Trace raw-variable provenance before approval.

## Canonical example

See `../examples/category-subcategory-cards.blade.php` — a complete, annotated
Blade template that applies the common URL, text, and HTML rules in context:

- `{!! esc_url($href) !!}` for an `add_query_arg(...)` URL (the query-string case)
- `{{ $var }}` for plain, unescaped text and ordinary quoted attributes
- `{!! nl2br(e($text)) !!}` for the escape-then-add-markup pattern
- the matching ❌ mistakes for contrast

Copy from it.
