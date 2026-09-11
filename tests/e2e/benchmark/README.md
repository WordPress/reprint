# URL rewriting in the performance report

Every PR runs these cases in addition to the pipeline stages selected by
`focused-stages.txt`. Each case has its own PR time, trunk time and percentage
change in the existing performance comment. JSON artifacts retain all five
samples and the path of the implementation loaded from each build.

| Case | What it measures |
| --- | --- |
| `html` | HTML links with distinct URLs. |
| `style-elements` | CSS URLs inside STYLE elements. |
| `blocks-literal-urls` | Plain URLs inside nested Divi attributes. |
| `blocks-nested-html` | HTML in `module.content.value`, with distinct URLs. This exercised the raw-JSON fast path removed in #795. |
| `blocks-repeated-urls` | The same HTML shape with one repeated URL and distinct surrounding text. |
| `blocks-escaped-quotes` | Nested HTML with JSON `\u0022` quotes. This already needed parsing before #795. |
| `blocks-encoded-shortcodes` | A nested WPBakery shortcode whose base64 body hides HTML links. |
| `blocks-no-source-urls` | Distinct block values that need no URL changes. |
| `serialized-options` | PHP-serialized options, including string-length updates. |

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
```

To compare two builds, run the same harness against each PHAR. Do not copy the
benchmark from each build: that could compare different inputs. The CI pipeline
uses `IMPORTER_PATH` to select the actual build; it must not silently benchmark the
checked-out source for both PR and trunk.

Save the two runs as `bench-pr.json` and `bench-trunk.json`, then run
`node tests/e2e/benchmark/render-diff.mjs` to produce the comparison table.
