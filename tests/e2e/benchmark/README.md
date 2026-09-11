# URL rewriting in the performance report

Every PR runs these cases in addition to the pipeline stages selected by
`focused-stages.txt`. Each case has its own PR time, trunk time and percentage
change in the existing performance comment. JSON artifacts retain all five
samples and the path of the implementation loaded from each build.

Changes above 5% in either direction stay visible. Changes of 5% or less go in
a collapsed second table. Each row's detailed metrics are also collapsed.
Failed or unavailable comparisons stay visible. This is a display threshold,
not a CI failure threshold.

| Case | What it measures |
| --- | --- |
| `html` | HTML links with distinct URLs. |
| `style-elements` | CSS URLs inside STYLE elements. |
| `blocks-literal-urls` | Plain URLs inside nested Divi attributes. |
| `blocks-nested-html` | HTML in `module.content.value`, with distinct URLs. Ordinary imports keep the raw-JSON fast path; selected-site imports parse the nested strings. |
| `blocks-repeated-urls` | The same HTML shape with one repeated URL and distinct surrounding text. |
| `blocks-escaped-quotes` | Nested HTML with JSON `\u0022` quotes. This already needed parsing before #795. |
| `blocks-encoded-shortcodes` | A nested WPBakery shortcode whose base64 body hides HTML links. |
| `blocks-no-source-urls` | Distinct block values that need no URL changes. |
| `serialized-options` | PHP-serialized options, including string-length updates. |

Each corpus runs twice. `url-rewrite-*` uses ordinary import settings.
`url-rewrite-selected-site-*` selects a multisite migration with no child paths,
so the report shows the extra format parsing separately. Both modes receive the
same inputs and must produce the same target content.

Until selected-site rewriting lands on trunk, the trunk side of those extra rows
uses its ordinary rewrite path as a reference. It does not claim that trunk can
keep child-site links remote. The URL correctness tests cover those links.

The three `selected-site-child-paths-*` cases also supply a child site at
`/news/`. Their content points to `/article/...`, so each parsed URL must pass
the child-path lookup before it can move. Trunk ignores the child-path argument;
these particular outputs remain comparable because no input points to `/news/`.
The URL tests separately check matched child links, relative links and dot segments.
These cases measure lookup misses, not the cost of loading a large site directory.

## Reading the numbers

Each case contains 128 distinct values with 32 entries each. One PHP process
runs five samples; the report uses the median. Each sample starts a new rewriter
and reuses it across the values, as SQL apply does. This includes building the
URL mapping. PHP startup, fixture creation and output validation are not timed.

Every result must match the expected target content. Blocks are compared after
JSON decoding so harmless JSON formatting changes are allowed. Missing blocks,
duplicate blocks, changed labels and skipped rewrites fail the benchmark. Failed
results get no speed delta. These checks do not replace the URL correctness suite.

The inputs use one source-to-target mapping. They do not measure child-path list
construction, large mapping sets, database I/O or full imports. Peak memory is for
the whole case process, including fixtures and validation. Timings use native PHP;
they do not establish PHP.wasm speed. Compare individual rows and their five
samples, not just the combined total. There is no hard slowdown threshold: runner
noise still needs review.

## Run without WordPress or MySQL

From the repository root, with dependencies installed:

```sh
node tests/e2e/benchmark/bench-url-rewrite.mjs /absolute/path/to/reprint.phar > urls.json
php tests/e2e/benchmark/bench-url-rewrite.php /absolute/path/to/checkout blocks-nested-html
php tests/e2e/benchmark/bench-url-rewrite.php /absolute/path/to/checkout selected-site-blocks-nested-html
```

To compare two builds, run the same harness against each PHAR. Do not copy the
benchmark from each build: that could compare different inputs. The CI pipeline
uses `IMPORTER_PATH` to select the actual build; it must not silently benchmark the
checked-out source for both PR and trunk.

Save the two runs as `bench-pr.json` and `bench-trunk.json`, then run
`node tests/e2e/benchmark/render-diff.mjs` to produce the comparison table.
