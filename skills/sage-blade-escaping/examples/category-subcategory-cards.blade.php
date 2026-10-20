{{--
  CORRECT escaping showcase — the worked scenario from SKILL.md.

  A "shop by category" card grid. Every dynamic output below is annotated with
  WHICH rule applies and WHY. This is the ✅ side: copy these patterns. The
  matching ❌ mistakes are listed at the bottom for contrast.
--}}

<section class="border-base-content/10 border-t py-16">
  <div class="site-shell">
    {{-- Section heading --}}
    <div class="mb-10 space-y-2">
      {{-- ✅ Plain, unescaped string → {{ }}. The default scalar e() path
           encodes both quote kinds and substitutes invalid UTF-8. --}}
      <p class="text-primary text-xs font-semibold uppercase tracking-wider">
        {{ $sectionLabel }}
      </p>
      <h2 class="text-base-content font-sans text-3xl font-bold md:text-4xl">
        {{ $sectionHeading }}
      </h2>
    </div>

    {{-- Subcategory card grid --}}
    <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
      @foreach ($subcategories as $cat)
        @php
          // add_query_arg() yields a query string (?category%5B%5D=…). With a
          // second param it contains '&' — the exact char that double-encodes.
          $href = $cat['slug'] !== '#' ? add_query_arg('category[]', $cat['slug'], $shopBaseUrl) : '#';
        @endphp

        <div class="card border-base-200 group relative flex h-full flex-col overflow-hidden border">
          <figure class="absolute inset-0 z-0 h-full w-full overflow-hidden">
            {{-- ✅ Single HTML display URL → {!! esc_url() !!}. This project form
                 checks the scheme and avoids default Blade double encoding. --}}
            {{-- ✅ Plain, unescaped alt text → {{ }} in a quoted attribute.
                 Keep WP escaping only when its distinct semantics are intentional. --}}
            <img src="{!! esc_url($cat['image']) !!}" alt="{{ $cat['alt'] }}" class="h-full w-full object-cover" />
            <div class="bg-neutral/60 absolute inset-0"></div>
          </figure>

          <div class="card-body relative z-10 flex h-full flex-col justify-end p-6">
            <div class="space-y-3">
              {{-- ✅ Plain text label → bare {{ }}. --}}
              <p class="text-primary text-xs font-semibold uppercase tracking-wider">
                {{ $cat['label'] }}
              </p>

              {{-- ✅ Escape-THEN-markup. We want user-controlled text but with
                   newlines rendered as <br>. Escape first with e(), then add the
                   <br> tags, then emit raw. NEVER {!! $cat['heading'] !!} alone —
                   that would be an XSS hole; the value must pass through e() first. --}}
              <h2 class="font-sans text-4xl font-bold uppercase leading-tight text-white">
                {!! nl2br(e($cat['heading'])) !!}
              </h2>

              <div class="pt-4">
                {{-- ✅ esc_url() encodes the '&'; raw output avoids default
                     double encoding into …&amp;#038;orderby=price. --}}
                <a href="{!! esc_url($href) !!}" class="btn btn-primary">
                  {{-- ✅ Translated plain text → bare {{ }}. --}}
                  {{ __('Shop Now', 'sage') }}
                </a>
              </div>
            </div>
          </div>
        </div>
      @endforeach
    </div>
  </div>
</section>

{{--
  ❌ WHAT NOT TO WRITE — each line maps to a ✅ above.
  (Do not paste Blade comment delimiter examples inside this block: comments do
  not nest, and a close delimiter would end this whole block early.)

  <a href="{{ esc_url($href) }}">           -- double-encodes & in query strings
  <img src="{{ esc_url($cat['image']) }}">  -- same double-encode bug
  <p>{{ esc_html($sectionLabel) }}</p>      -- duplicate escaping; preserve intended WP semantics
  <img alt="{{ esc_attr($cat['alt']) }}">   -- choose one context-appropriate escaping step
  <h2>{!! $cat['heading'] !!}</h2>          -- raw user input -> XSS; must e() first

  Run scripts/audit-escaping.sh to catch the first four automatically.
--}}
