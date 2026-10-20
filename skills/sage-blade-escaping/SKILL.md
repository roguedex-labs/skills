---
name: sage-blade-escaping
description: Enforce correct output escaping in Sage 11 / Acorn Blade templates and prevent double-encoding bugs. MUST be invoked before writing or editing any `.blade.php` output that emits a URL, attribute, JavaScript, JSON, or HTML — especially anything wrapping a WordPress escaping/sanitizing helper. Use whenever you add `{{ }}` or `{!! !!}` around `esc_url`, `esc_url_raw`, `esc_attr`, `esc_html`, `esc_js`, `wp_kses_post`, `wc_price`, `get_*_html`, or attribute values like `href`, `src`, `action`, `style`, `alt`.
---

# Sage Blade Escaping

These are strict **project conventions** for Sage/Acorn Blade output. Under
Blade's default double encoding, an escaped echo around an already HTML-escaped
string can encode its entities again. Apply the context-specific rules below
while preserving the value's intended text and required WordPress behavior.

## When to use this skill

**Invoke it before** you write or edit any `.blade.php` line that emits:

- a URL into `href`, `src`, `action`, `data-*`, or `content`
- an attribute value of any kind (`alt`, `title`, `aria-*`, `style`, `value`)
- HTML produced by WordPress/WooCommerce (`wp_kses_post`, `wc_price`,
  `get_price_html`, `get_the_term_list`, `the_content`)
- anything wrapped in `esc_url`, `esc_url_raw`, `esc_attr`, `esc_html`, `esc_js`

That covers nearly every Blade edit that prints dynamic data. When in doubt,
invoke it — the cost is one table lookup; the cost of skipping is a latent
broken-link or XSS bug that only shows up once a query string appears.

**Decision in one line:** Plain, unescaped text/ordinary quoted attributes →
`{{ }}`. Single HTML display URLs → `{!! esc_url(...) !!}`. Reviewed HTML
fragments → `{!! !!}`. Inline script data → `Js::from`; attribute JSON →
single-quoted `@json`. Direct printers → PHP statements, not echo wrappers.

**Skip it only when** the edit touches no dynamic output — pure markup/classes,
`@if`/`@foreach` control flow, comments, or `@include`/component composition
with no new `{{ }}`/`{!! !!}` echoes.

## The core problem

With the default compiler configuration, `{{ $x }}` calls Laravel's `e($x)`.
For ordinary strings/scalars, its final escaping path is:

```php
htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', $doubleEncode)
```

`$doubleEncode` defaults to `true`. Laravel documents this explicitly:
_"By default, Blade (and the Laravel `e` function) will double encode HTML
entities."_ `Htmlable` values are an exception: `e()` returns their `toHtml()`
without escaping. Review those producers as escaping boundaries; `HtmlString`
does not sanitize its input.

`Blade::withoutDoubleEncoding()` changes regular Blade echoes to pass `false`;
it does not change direct `e()` calls or validate URL/JS/CSS semantics. Keep the
project configuration: changing how entity-like text renders is a separate
decision, not an automatic repair. See the [mechanics reference](references/escaping-mechanics.md)
for value handling and configuration details.

WordPress's `esc_url()`, `esc_attr()`, and `esc_html()` already encode entities.
Putting their string output through a default escaped echo encodes those
entities again:

```
esc_url('https://x.test/?a=1&b=2')  →  https://x.test/?a=1&#038;b=2
{{ ... }} re-encodes the & in &#038;  →  https://x.test/?a=1&amp;#038;b=2   ❌ broken link
```

An entity-free pretty permalink can hide the problem. A query-string ampersand
exposes it; verify that case rather than relying on a clean permalink.


## Quick reference: `{{ }}` vs `{!! !!}`

Prefer `{{ }}` for plain, unescaped text and ordinary quoted attribute text:

```blade
{{ $title }}
alt="{{ $image_alt }}"
aria-label="{{ $label }}"
value="{{ old('email') }}"
data-count="{{ $count }}"
```

Use `{!! esc_url(...) !!}` for a single dynamic HTML display URL:

```blade
href="{!! esc_url($url) !!}"
src="{!! esc_url($image_url) !!}"
action="{!! esc_url($form_action) !!}"
data-full-image="{!! esc_url($preview_url) !!}"
```

Use `{!! !!}` for returned HTML only after reviewing its producer, inputs, and
filters for the intended HTML-content context:

```blade
{!! wp_kses_post($html) !!}
{!! wc_price($price) !!}
{!! $product->get_price_html() !!}
{!! get_the_password_form() !!}
```

Never use raw output just to "make HTML work":

```blade
{!! $userInput !!} {{-- XSS risk unless the source was already sanitized --}}
```

## The rules

| You are emitting… | Project form | Avoid / review |
| --- | --- | --- |
| Plain, unescaped text / ordinary quoted attribute text | `{{ $var }}` | Duplicate escaping; preserve intentional WordPress semantics below |
| A single HTML display URL (`href`, `src`, `action`, URL-valued `data-*`) | `{!! esc_url($url) !!}` | Default `{{ esc_url($url) }}`; non-display `esc_url_raw()` |
| Returned HTML intended as markup | `{!! $html !!}` after context review, or `{!! wp_kses_post($html) !!}` when its allowlist fits | Unreviewed raw output; `{{ $html }}` when markup is intended |
| Directly printed post content | `@php(the_content())` | Treating an enclosing echo as sanitization |
| PHP array/object as a complete inline `<script>` expression | `{{ \Illuminate\Support\Js::from($data) }}` | Raw `json_encode()` without context review |
| JSON in an HTML attribute (`x-data`, `data-config`) | `x-data='@json($data)'` with default encoding flags | `x-data="@json($data)"` |
| Unavoidable inline JS string fragment | `'{!! esc_js($text) !!}'` inside single-quoted JS, after context review | `{{ esc_js($text) }}`; using string escaping for JSON |
| Inline CSS custom props | `{{ }}` only for validated finite numbers or explicitly allowlisted CSS values | Unvalidated strings; `esc_attr()` as CSS sanitization |

Validate CSS values before rendering: constrain numbers to the property's allowed
range, or select from a fixed per-property allowlist. Keep units and declaration
structure static. Strings are scalars too; neither Blade escaping nor
`esc_attr()` prevents a string from injecting additional CSS declarations.

### Plain text and WordPress semantics

For new plain text, prefer Blade escaping. Explicit `{!! esc_html($text) !!}`
in HTML text and `title="{!! esc_attr($title) !!}"` in an ordinary quoted
attribute are also valid when WordPress behavior is intentional. WordPress
preserves existing entities, uses the site's charset/UTF-8 handling, and runs
escaping filters; these differ from default `e()`.

Before removing a helper from `{{ esc_html(...) }}` / `{{ esc_attr(...) }}`,
trace the input and preserve intended text and required filters. Either use
plain data with Blade escaping or keep one WordPress escaping step with raw
output in its matching context. Do not mechanically delete the helper.

### Returned HTML versus direct printing

Trust returned HTML through its actual construction and filters, not a
WordPress/WooCommerce origin or `_html` suffix. Check return types too:
`get_the_term_list()` can return `WP_Error` or `false`.

`the_content()` prints after running its content filters; it does not return
sanitized HTML. Use `@php(the_content())` for that intended pipeline. Neither
`{{ the_content() }}` nor `wp_kses_post(the_content())` sanitizes bytes already
printed. `get_the_content()` is not a fully rendered substitute: it does not
apply the `the_content` filter.

### Why `{!! esc_url() !!}` and not bare `{{ $url }}`?

For ordinary string URLs, Blade HTML escaping prevents quoted-attribute
breakout but does **not** validate the URL scheme. `esc_url()` adds protocol
allowlisting and display entity encoding. Keep `{!! esc_url($url) !!}` as this
project's HTML display convention, including WordPress-generated URLs that
filters may change.

`esc_url_raw()` / `sanitize_url()` are storage/non-display APIs; they do not
perform display entity encoding. `{{ esc_url_raw($url) }}` is not inherently a
double-encoding bug, but use the documented display API here.

A `data-*` attribute is not inherently a URL. Structured lists such as `srcset`
and URLs nested in CSS or JS need their own context handling. An `esc_*` prefix
alone does not imply HTML-safe output: `esc_sql()`, for example, targets SQL.

### JavaScript and JSON contexts

Do not treat `esc_js()` as a generic "make JavaScript safe" helper. WordPress
documents it for inline JS string fragments, especially strings inside single
quotes. Prefer structured JSON instead:

```blade
<script>
  window.app = {{ \Illuminate\Support\Js::from($data) }};
</script>

<div x-data='@json($data)'></div>

{{-- Last resort: inline JS string fragment, not JSON --}}
<button onclick="alert('{!! esc_js($message) !!}')">…</button>
```

The attribute JSON form deliberately uses single quotes around the HTML
attribute. Do not use `x-data="@json($data)"`; JSON contains double quotes.

`Js::from()` intentionally implements `Htmlable`; its maintained serializer
produces a complete JavaScript expression for the documented inline-script
context. Do not add JS quotes around it or use its expression where a consumer
requires literal JSON. An arbitrary `Htmlable` / `HtmlString` has no equivalent
sanitization guarantee.

## Right vs wrong

```blade
{{-- ✅ URL: escape in esc_url, emit raw --}}
<a href="{!! esc_url($product->get_permalink()) !!}">…</a>
<img src="{!! esc_url($image) !!}" alt="{{ $title }}" />

{{-- ✅ Attribute text: let Blade escape, no WP helper --}}
<button aria-label="{{ $label }}">…</button>

{{-- ✅ Reviewed price HTML, sanitized for this HTML-content context --}}
<span>{!! wp_kses_post($product->get_price_html()) !!}</span>

{{-- ❌ Default double encoding corrupts entities in query strings --}}
<a href="{{ esc_url($url) }}">…</a>
{{-- ❌ Duplicate escaping; preserve WP semantics before choosing one escaper --}}
<span alt="{{ esc_attr($title) }}">
{{-- ❌ Prints &lt;span&gt; as literal text --}}
<span>{{ $product->get_price_html() }}</span>
```

## Worked scenario

**Task:** add a "shop by category" card grid where each card links to the shop,
pre-filtered to that category. (The full annotated template is in
[`examples/category-subcategory-cards.blade.php`](examples/category-subcategory-cards.blade.php).)

You build the link with `add_query_arg`, which produces a query string:

```blade
@php
    $href = $cat['slug'] !== '#'
        ? add_query_arg('category[]', $cat['slug'], $shopBaseUrl)
        : '#';
@endphp
```

For a single card that resolves to e.g. `/shop/?category%5B%5D=pumps`. The
tempting first draft mirrors a lot of WordPress tutorials:

```blade
{{-- ❌ first instinct — and it passes review locally --}}
<a href="{{ esc_url($href) }}">{{ $cat['name'] }}</a>
```

It works on the demo page, so it ships. Then a customer lands on a filtered URL
that already carries two params — `/shop/?category[]=pumps&orderby=price`.
`esc_url()` encodes the `&` to `&#038;`, then `{{ }}` re-encodes that `&` to
`&amp;`, yielding `…&amp;#038;orderby=price` in the HTML source. After HTML
entity decoding, the URL contains `…&#038;orderby=price`: the `#` starts a
fragment beginning `#038;orderby=price`. The category remains in the query,
but sorting disappears because `orderby` is now in the fragment.

**The fix — escape once in the helper, emit raw:**

```blade
{{-- ✅ correct: escape in the helper, emit raw --}}
<a href="{!! esc_url($href) !!}">{{ $cat['name'] }}</a>
```

Note the card's `name` stays in `{{ }}` (plain text — Blade escapes it) while
only the URL moves to `{!! esc_url() !!}`. Two values, two rules, same line.

The full, fully-annotated correct version of this template — every output
labelled with the rule that applies, plus the ❌ mistakes for contrast — lives in
[`examples/category-subcategory-cards.blade.php`](examples/category-subcategory-cards.blade.php).
Copy from it.

## Auditing code

The guard requires PHP CLI and no project dependencies. Point it at any directory
of `.blade.php` files; it exits `0` for a clean scan, `1` for findings, and `2`
for scan errors (including unreadable files or directories):

```bash
scripts/audit-escaping.sh [DIR]    # DIR defaults to the current directory
```

It flags selected project-policy patterns inside escaped echoes:

- `esc_url()`: display entities can be double encoded under Blade defaults.
- `esc_url_raw()`: a non-display URL API is used at a display site.
- `esc_html()` / `esc_attr()`: review duplicate escaping while preserving
  intentional WordPress semantics.
- `esc_js()`: review the JS context instead of adding HTML encoding.
- Returned HTML helpers: review provenance and context before choosing raw output.
- `the_content()`: a direct printer is wrapped in an echo that cannot sanitize it.

These are review findings, not unconditional fixes. Follow the context rules
above; do not blindly remove helpers or switch returned values to raw output.

It checks complete escaped echo expressions, including multiline, tab-separated,
and nested helper calls. Blade comments, `@verbatim` blocks, and escaped
`@{{ ... }}` echoes are excluded; HTML comments are still scanned because Blade
executes inside them.

Run it after Blade edits and resolve every finding against these project
conventions. For common URL/text/HTML cases, see
[`examples/category-subcategory-cards.blade.php`](examples/category-subcategory-cards.blade.php).

### What the audit does not prove

`scripts/audit-escaping.sh` is a selected-pattern policy guard, not a full XSS
scanner. A clean result does not prove raw echoes or `Htmlable` producers safe.

Review raw output for its exact insertion context, including producer inputs,
return types, filters, and later transformations. Appropriate sources include
display-escaped URLs, HTML filtered to a suitable allowlist, or reviewed
escape-then-markup construction such as `{!! nl2br(e($text)) !!}`. A recognized
helper name alone is not proof. Trace any `{!! $variable !!}` before approval.

## Raw output is a convention, not a sanitizer

In this project, raw echoes are the chosen syntax for strings already escaped
or sanitized for their **exact insertion context**. This is not the only form
Laravel supports. Raw syntax adds no protection, and HTML, URL, JS, CSS, and
attribute safety are not interchangeable. The producer's contract—not the
braces or its name—must establish safety.

## After editing

Run your Blade formatter (e.g. `blade-formatter`, 2-space indent) on any touched
`.blade.php`, then `scripts/audit-escaping.sh <your-views-dir>` to confirm no
known double-encoding anti-patterns slipped in. Manually review any raw echoes.

## References

- `examples/category-subcategory-cards.blade.php` — the worked scenario as a
  complete, annotated Blade file showing common URL, text, and HTML cases.
- `references/escaping-mechanics.md` — value/configuration exceptions, WordPress
  entity/filter semantics, helper contracts, and the context cheat-sheet.
- Laravel 13 Blade — Displaying Data / HTML Entity Encoding: https://laravel.com/docs/13.x/blade
- Roots Sage — Blade templates: https://roots.io/sage/docs/blade-templates/
- WordPress escaping: https://developer.wordpress.org/apis/security/escaping/
- WordPress URL contracts: https://developer.wordpress.org/reference/functions/esc_url/ and https://developer.wordpress.org/reference/functions/esc_url_raw/
- WordPress content contracts: https://developer.wordpress.org/reference/functions/the_content/ and https://developer.wordpress.org/reference/functions/get_the_content/
- Illuminate 13.23.0 `e()` implementation: https://github.com/illuminate/support/blob/v13.23.0/helpers.php
