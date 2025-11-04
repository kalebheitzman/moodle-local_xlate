<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Translation backend integration for Local Xlate.
 *
 * Implements the request/response flow for AI-assisted translation, including
 * glossary enforcement, placeholder validation, and error handling.
 *
 * @package    local_xlate
 * @category   translation
 * @copyright  2025 Kaleb Heitzman <kalebheitzman@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_xlate\translation;

defined('MOODLE_INTERNAL') || die();

/**
 * Backend wrapper skeleton for AI translation provider integration.
 *
 * The backend coordinates request preparation, glossary prompt injection,
 * transport, and response validation for the translate_batch flow. Provider
 * specific HTTP concerns can be swapped in at the marked extension points,
 * but the overall contract stays: accept Moodle-side batch requests and return
 * structured translation data ready for post-processing.
 *
 * @package local_xlate\translation
 */
class backend {
    /**
     * Translate a batch of strings via the configured LLM provider.
     *
     * Builds an OpenAI-compatible function-calling payload, dispatches it using
     * Moodle's curl client, validates the JSON arguments returned by the model,
     * and raises structured errors for any failure along the way. Glossary
     * entries are woven into the system prompt and later checked against the
     * translations during post-processing to surface warnings.
     *
     * @param string $requestid Stable identifier for correlating a translate_batch request.
     * @param string $sourcelang ISO language code for the source text.
     * @param string $targetlang ISO language code for the desired translation.
     * @param array<int,array{id?:string,key?:string|null,source_text:string,context?:string,placeholders?:array<int,string>}> $items Items to translate as provided by the caller.
     * @param array<int,array{term:string,replacement:string}> $glossary Optional glossary constraints supplied by the caller.
     * @param array<string,mixed> $options Provider-specific tuning options such as temperature, max_tokens, or model name.
    * @return array{ok:bool,results?:array<int,array{id:string,translated:string,applied_glossary_terms:array<int,array{term:string,replacement:string}>,warnings:array<int,string>,confidence?:float,model_tokens?:array<string,int|float>}>,meta?:array<string,mixed>,errors?:array,raw?:array} Structured response compatible with the external API layer.
     * @throws \coding_exception If Moodle configuration is missing during runtime checks.
     */
    public static function translate_batch($requestid, $sourcelang, $targetlang, $items, $glossary = [], $options = []) {
        global $CFG;

        // Quick entry trace to ensure worker processes reach this method.
        try {
            $entry = json_encode(['requestid' => $requestid, 'sourcelang' => $sourcelang, 'targetlang' => $targetlang, 'items_count' => is_array($items) ? count($items) : 0], JSON_PARTIAL_OUTPUT_ON_ERROR);
            debugging('[local_xlate] translate_batch entered: ' . $entry, DEBUG_DEVELOPER);
        } catch (\Throwable $e) {
            debugging('[local_xlate] translate_batch entered (failed to json_encode): ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        $model = isset($options['model']) ? $options['model'] : get_config('local_xlate', 'openai_model');
        $endpoint = get_config('local_xlate', 'openai_endpoint');
        $apikey = get_config('local_xlate', 'openai_api_key');

        // Fail fast if endpoint or api key not configured to avoid confusing provider errors.
        if (empty($endpoint) || empty($apikey)) {
            return ['ok' => false, 'errors' => ['missing_api_config']];
        }

        // Minimal validation.
        if (empty($requestid) || empty($sourcelang) || empty($targetlang) || empty($items) || !is_array($items)) {
            return ['ok' => false, 'errors' => ['invalid_arguments']];
        }

        // Build function arguments according to spec.
        // Build function arguments. Only include model options if explicitly provided
        // to avoid sending unsupported defaults (some models reject temperature=0).
        $modeloptions = [];
        if (array_key_exists('temperature', $options)) {
            $modeloptions['temperature'] = (float)$options['temperature'];
        }
        if (array_key_exists('max_tokens', $options)) {
            $modeloptions['max_tokens'] = (int)$options['max_tokens'];
        }

        // Normalize items for the function payload: the model expects a compact
        // item id (the stable hash/key). Frontend sometimes sends items with
        // `id` in the form "component:key"; prefer `key` when available so
        // the function receives only the short id the model will return.
        $fnitems = [];
        if (is_array($items)) {
            foreach ($items as $it) {
                $shortid = '';
                if (!empty($it['key'])) {
                    $shortid = (string)$it['key'];
                } else if (!empty($it['id'])) {
                    // If id looks like "component:key", try to extract the part
                    // after the last ':'; otherwise use as-is.
                    $parts = explode(':', (string)$it['id']);
                    $shortid = end($parts);
                }

                $fnitems[] = [
                    'id' => $shortid,
                    'source_text' => $it['source_text'] ?? '',
                    'context' => $it['context'] ?? '',
                    'placeholders' => $it['placeholders'] ?? []
                ];
            }
        }

        $fnargs = [
            'request_id' => $requestid,
            'source_lang' => $sourcelang,
            'target_lang' => $targetlang,
            'items' => $fnitems,
            'glossary' => $glossary,
            'model_options' => $modeloptions,
        ];

    // Read system prompt from settings (fallback to a sensible default).
    // Require the model to return valid text only: valid UTF-8, no NUL or control
    // characters. Ask the model to replace any control characters with a single
    // space if they would otherwise appear.
    $defaultprompt = "You are a helpful translation assistant. Return structured JSON following the provided function schema. " .
        "Important: all returned translation strings MUST be valid UTF-8 text and MUST NOT contain NUL (\u0000) or other control characters. " .
        "If a control character would otherwise appear in the translation, replace it with a single space. Do NOT include any non-printable control characters in your output. " .
        "Preserve HTML tags, placeholders and variables. Return only the JSON matching the function schema when called via function-calling.";
    $systemprompt = get_config('local_xlate', 'openai_prompt') ?: $defaultprompt;

        // Load function definition (spec file shipped with the plugin). This is required
        // to use function-calling reliably. Fail early if missing.
        $specfn = __DIR__ . '/../../spec/translate_batch_function.json';
        if (!file_exists($specfn)) {
            return ['ok' => false, 'errors' => ['missing_function_spec']];
        }
        $fcontent = file_get_contents($specfn);
        $fjson = json_decode($fcontent, true);
        if (!$fjson) {
            return ['ok' => false, 'errors' => ['invalid_function_spec']];
        }
        $functions = [$fjson];

        // Build a short human-readable glossary instruction to include in the prompt so the model
        // uses glossary terms as translation constraints and inflects them appropriately.
        $glossaryinstruction = '';
        if (!empty($glossary) && is_array($glossary)) {
            $glossarypairs = [];
            $count = 0;
            foreach ($glossary as $g) {
                if (empty($g['term']) || !array_key_exists('replacement', $g)) {
                    continue;
                }
                $glossarypairs[] = $g['term'] . ' => ' . $g['replacement'];
                $count++;
                if ($count >= 40) { // avoid giant prompts; include up to 40 pairs
                    break;
                }
            }
            if (!empty($glossarypairs)) {
                $glossaryinstruction = "Glossary (use these terms when translating; you may inflect them to match grammar/tense):\n" . implode("\n", $glossarypairs) . "\n";
                $glossaryinstruction .= "When you use or inflect a glossary term, include an entry in applied_glossary_terms with keys 'term' (original) and 'applied' (the exact string used in the translation).";
            }
        }

        // Build messages for function-calling. Include the glossary instruction in the system prompt
        // so the model treats it as authoritative guidance while still producing natural translations.
        $messages = [
            ['role' => 'system', 'content' => $systemprompt . "\n\n" . $glossaryinstruction],
            ['role' => 'user', 'content' => json_encode(['request_id' => $requestid, 'note' => 'Translate the provided items using the translate_batch function.'])],
        ];

        // Build request payload for OpenAI-like chat completion with function-calling.
        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];

        // Only attach top-level temperature if explicitly provided in options.
        if (array_key_exists('temperature', $options)) {
            $payload['temperature'] = (float)$options['temperature'];
        }

        // Attach function definitions and force the model to call the named function
        // so we reliably receive structured function_call.arguments in the response.
        $payload['functions'] = $functions;
        $payload['function_call'] = ['name' => 'translate_batch'];

        // Also include the full function arguments as a user message (models sometimes
        // prefer a concrete example). We still force the function call above.
        $payload['messages'][] = ['role' => 'user', 'content' => json_encode($fnargs)];

        // Use Moodle's curl library to POST JSON.
    try {
            // Ensure Moodle's curl wrapper is available when running in CLI/worker
            // contexts where it may not have been auto-included.
            if (!class_exists('\curl')) {
                require_once($CFG->libdir . '/filelib.php');
            }
            $curl = new \curl();
            // If the configured endpoint already includes the completions path, use it as-is.
            if (preg_match('#/chat/completions/?$#', $endpoint)) {
                $url = $endpoint;
            } else {
                $url = rtrim($endpoint, '/') . '/chat/completions';
            }
            // Detect Azure-hosted OpenAI endpoints and use the api-key header instead
            // of Authorization: Bearer. This covers common Azure deployment URLs
            // like *.openai.azure.com where the provider expects `api-key` header.
            $isazure = (stripos($endpoint, 'openai.azure.com') !== false) || (stripos($endpoint, 'azure') !== false);
            if ($isazure) {
                $headers = [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'api-key: ' . $apikey,
                ];
                debugging('[local_xlate] Using Azure api-key header for endpoint: ' . $endpoint, DEBUG_DEVELOPER);
            } else {
                $headers = [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $apikey,
                ];
            }

            $postdata = json_encode($payload);

            // Log outgoing payload and endpoint for debugging. Do NOT log the API key.
            $short = (strlen($postdata) > 10000) ? substr($postdata, 0, 10000) . '...[truncated]' : $postdata;
            debugging('[local_xlate] Outgoing ' . $url . ' payload: ' . $short, DEBUG_DEVELOPER);

            // Set headers and options on curl instance.
            $curl->setHeader($headers);
            // Set reasonable timeouts and retry on transient network errors/timeouts.
            $curl->setopt([
                'CURLOPT_RETURNTRANSFER' => 1,
                'CURLOPT_CONNECTTIMEOUT' => 10,
                // Increase overall timeout to 300 seconds to allow slower provider responses.
                'CURLOPT_TIMEOUT' => 300,
            ]);

            $maxattempts = 2;
            $attempt = 0;
            $response = null;
            $result = null;
            $httpcode = 0;
            while ($attempt < $maxattempts) {
                $attempt++;
                try {
                    $result = $curl->post($url, $postdata);
                    $httpcode = $curl->info['http_code'] ?? 0;
                } catch (\Exception $e) {
                    // Curl wrapper may throw for low-level errors; capture and log then retry if possible.
                    debugging('[local_xlate] translate_batch curl exception on attempt ' . $attempt . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                    $result = false;
                    $httpcode = 0;
                }

                // Log provider response for debugging (or error state)
                $resshort = is_string($result) ? ((strlen($result) > 10000) ? substr($result, 0, 10000) . '...[truncated]' : $result) : '[no body]';
                debugging('[local_xlate] Response attempt=' . $attempt . ' httpcode=' . $httpcode . ' body: ' . $resshort, DEBUG_DEVELOPER);

                // If we got a successful HTTP response, break and process it.
                if ($httpcode >= 200 && $httpcode < 300) {
                    break;
                }

                // If we received a 429 (rate limit), do not retry here; surface rate-limited error.
                if ($httpcode === 429) {
                    return ['ok' => false, 'errors' => ['rate_limited']];
                }

                // For other transient-like failures (httpcode 0 or 5xx), retry if attempts remain.
                if ($attempt < $maxattempts) {
                    // Exponential-ish backoff: 2, then 4 seconds.
                    $backoff = pow(2, $attempt);
                    try {
                        sleep($backoff);
                    } catch (\Exception $ex) {
                        // ignore
                    }
                    continue;
                }

                // No more attempts; return an http_error-ish response.
                return ['ok' => false, 'errors' => ['http_error' => $httpcode, 'body' => is_string($result) ? substr($result, 0, 200) : '']];
            }

            // At this point we have $result and $httpcode in successful range.
            $response = json_decode($result, true);
            if (!$response) {
                return ['ok' => false, 'errors' => ['invalid_json_response']];
            }

            // Attempt to extract function_call.arguments (preferred) or message content.
            $choice = $response['choices'][0] ?? null;
            $functionargs = null;
            if (!empty($choice['message']['function_call']['arguments'])) {
                $functionargs = $choice['message']['function_call']['arguments'];
            } else if (!empty($choice['message']['content'])) {
                $functionargs = $choice['message']['content'];
            } else if (!empty($choice['text'])) {
                $functionargs = $choice['text'];
            }

            if ($functionargs === null) {
                return ['ok' => false, 'errors' => ['no_function_arguments']];
            }

            // The functionargs may be a JSON string; attempt decode.
            $decoded = json_decode($functionargs, true);
            if ($decoded === null) {
                // Try to extract JSON substring if the model wrapped it.
                $start = strpos($functionargs, '{');
                $end = strrpos($functionargs, '}');
                if ($start !== false && $end !== false && $end > $start) {
                    $maybe = substr($functionargs, $start, $end - $start + 1);
                    $decoded = json_decode($maybe, true);
                }
            }

            if (!is_array($decoded) || empty($decoded['results']) || !is_array($decoded['results'])) {
                return ['ok' => false, 'errors' => ['invalid_function_response']];
            }

            // If the model returned results containing control characters (NUL or
            // other C0/C1 controls), attempt a single repair call asking the model
            // to clean the translated strings. This helps ensure client-facing
            // APIs receive valid text.
            $hascontrol = false;
            foreach ($decoded['results'] as $rchk) {
                $txt = isset($rchk['translated']) ? (string)$rchk['translated'] : '';
                if (preg_match('/[\x00\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $txt)) {
                    $hascontrol = true;
                    break;
                }
            }

            if ($hascontrol) {
                try {
                    // Build a follow-up prompt asking the model to return cleaned
                    // translations only. We include the original results to guide
                    // the cleaning.
                    $repairmessages = [
                        ['role' => 'system', 'content' => "You are a translation cleaner. Receive JSON with id+translated values and return a JSON object {results:[{id,translated}, ...]} where each 'translated' string contains no NUL or control characters. Replace control chars with a single space. Preserve other text unchanged."],
                        ['role' => 'user', 'content' => json_encode(['results' => $decoded['results']])]
                    ];

                    $repairpayload = [
                        'model' => $model,
                        'messages' => $repairmessages,
                        'functions' => $functions,
                        'function_call' => ['name' => 'translate_batch']
                    ];

                    $repairpost = json_encode($repairpayload);
                    $curl->setopt(['CURLOPT_TIMEOUT' => 300]);
                    $repairresult = $curl->post($url, $repairpost);
                    $repairhttp = $curl->info['http_code'] ?? 0;
                    if ($repairhttp >= 200 && $repairhttp < 300) {
                        $repairresp = json_decode($repairresult, true);
                        $rchoice = $repairresp['choices'][0] ?? null;
                        $repairargs = null;
                        if (!empty($rchoice['message']['function_call']['arguments'])) {
                            $repairargs = $rchoice['message']['function_call']['arguments'];
                        } else if (!empty($rchoice['message']['content'])) {
                            $repairargs = $rchoice['message']['content'];
                        }
                        $repairdecoded = null;
                        if ($repairargs !== null) {
                            $repairdecoded = json_decode($repairargs, true);
                        }
                        if (is_array($repairdecoded) && !empty($repairdecoded['results'])) {
                            $decoded['results'] = $repairdecoded['results'];
                        }
                    }
                } catch (\Exception $e) {
                    // If repair fails, we'll fall back to server-side sanitization later.
                    debugging('[local_xlate] repair attempt failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }

            // Basic schema checks: request_id should match and each result must contain id+translated.
            if (isset($decoded['request_id']) && $decoded['request_id'] !== $requestid) {
                return ['ok' => false, 'errors' => ['request_id_mismatch']];
            }

            $resultslist = $decoded['results'];
            if (count($resultslist) < 1) {
                return ['ok' => false, 'errors' => ['empty_results']];
            }
            foreach ($resultslist as $ri => $r) {
                if (!isset($r['id']) || !isset($r['translated'])) {
                    return ['ok' => false, 'errors' => ['malformed_result_item', 'index' => $ri]];
                }
            }

            // Post-process each item: enforce glossary and validate placeholders.
            $results = [];
            foreach ($decoded['results'] as $r) {
                if (empty($r['id']) || !isset($r['translated'])) {
                    // Skip invalid item but record error.
                    continue;
                }
                // Find original item to extract placeholders if provided.
                $orig = null;
                foreach ($items as $it) {
                    if ((string)$it['id'] === (string)$r['id']) {
                        $orig = $it;
                        break;
                    }
                }

                $postin = ['id' => $r['id'], 'source_text' => $orig['source_text'] ?? '', 'translated' => $r['translated'], 'placeholders' => $orig['placeholders'] ?? []];
                // Postprocess is advisory-only: detect applied glossary terms and warnings but do not mutate translation.
                $post = self::postprocess_item($postin, $glossary);

                $results[] = array_merge([
                    'id' => (string)$r['id'],
                    'translated' => $post['translated'],
                    'applied_glossary_terms' => $post['applied_glossary_terms'],
                    'warnings' => $post['warnings'],
                ],
                // include optional fields if present
                array_intersect_key($r, array_flip(['confidence', 'model_tokens'])));
            }

            // Build meta from response usage if available.
            $meta = ['model' => $model, 'system_prompt_hash' => '', 'elapsed_ms' => 0, 'usage_tokens' => null, 'errors' => []];
            if (!empty($response['usage']) && is_array($response['usage'])) {
                $meta['usage_tokens'] = [
                    'prompt' => $response['usage']['prompt_tokens'] ?? 0,
                    'completion' => $response['usage']['completion_tokens'] ?? 0,
                    'total' => $response['usage']['total_tokens'] ?? 0,
                ];
            }

            // Log batch-level token usage to local_xlate_token_batch.
            global $DB;
            $usage = $meta['usage_tokens'] ?? null;
            if (is_array($usage) && (!empty($usage['prompt']) || !empty($usage['completion']) || !empty($usage['total']))) {
                $inputtokens = isset($usage['prompt']) ? (int)$usage['prompt'] : 0;
                $cachedtokens = isset($options['cached_input_tokens']) ? (int)$options['cached_input_tokens'] : 0;
                $outputtokens = isset($usage['completion']) ? (int)$usage['completion'] : 0;
                $totaltokens = (int)($usage['total'] ?? ($inputtokens + $cachedtokens + $outputtokens));

                $inputrate = (float)get_config('local_xlate', 'pricing_input_per_million');
                $cachedrate = (float)get_config('local_xlate', 'pricing_cached_input_per_million');
                $outputrate = (float)get_config('local_xlate', 'pricing_output_per_million');

                $inputcost = $inputtokens > 0 ? ($inputtokens / 1000000) * $inputrate : 0.0;
                $cachedcost = $cachedtokens > 0 ? ($cachedtokens / 1000000) * $cachedrate : 0.0;
                $outputcost = $outputtokens > 0 ? ($outputtokens / 1000000) * $outputrate : 0.0;

                // If a caller provided explicit cost breakdown, prefer it.
                if (!empty($options['input_cost'])) {
                    $inputcost = (float)$options['input_cost'];
                }
                if (!empty($options['cached_input_cost'])) {
                    $cachedcost = (float)$options['cached_input_cost'];
                }
                if (!empty($options['output_cost'])) {
                    $outputcost = (float)$options['output_cost'];
                }

                $totalcost = $inputcost + $cachedcost + $outputcost;

                $modelstr = $meta['model'] ?? '';
                $elapsed = $meta['elapsed_ms'] ?? 0;
                $langvalue = is_array($targetlang) ? implode(',', $targetlang) : (string)$targetlang;
                $batchsize = is_array($results) ? count($results) : 0;

                $usagejobid = null;
                if (is_array($options)) {
                    if (array_key_exists('usage_jobid', $options)) {
                        $usagejobid = (int)$options['usage_jobid'];
                    } elseif (array_key_exists('jobid', $options)) {
                        $usagejobid = (int)$options['jobid'];
                    }
                }

                $rec = [
                    'timecreated' => time(),
                    'lang' => $langvalue,
                    'batchsize' => $batchsize,
                    'model' => $modelstr,
                    'input_tokens' => $inputtokens,
                    'cached_input_tokens' => $cachedtokens,
                    'output_tokens' => $outputtokens,
                    'input_cost' => $inputcost,
                    'cached_input_cost' => $cachedcost,
                    'output_cost' => $outputcost,
                    'total_cost' => $totalcost,
                    'response_ms' => $elapsed,
                    'jobid' => $usagejobid,
                    'total_tokens' => $totaltokens
                ];

                try {
                    $DB->insert_record('local_xlate_token_batch', $rec, false);
                } catch (\Exception $e) {
                    debugging('[local_xlate] Failed to log batch token usage: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
            return ['ok' => true, 'results' => $results, 'meta' => $meta, 'raw' => $response];

        } catch (\Exception $e) {
            // Make sure exceptions are visible in worker logs for debugging.
            debugging('[local_xlate] translate_batch exception: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return ['ok' => false, 'errors' => ['exception' => $e->getMessage()]];
        }
    }

    /**
     * Post-process a single translated entry for reporting quality signals.
     *
     * Applies soft glossary detection (records which replacements are already
     * present) and verifies that each placeholder found in the original string
     * still appears in the translated text. Results are advisory only; the
     * translated value is not modified.
     *
     * @param array{id:string,source_text?:string,translated:string,placeholders?:array<int,string>} $item Item produced by the LLM provider for a single translation.
     * @param array<int,array{term:string,replacement:string}> $glossary List of glossary constraints applied to the batch.
     * @return array{translated:string,applied_glossary_terms:array<int,array{term:string,replacement:string}>,warnings:array<int,string>} Advisory output used by the web service response.
     */
    public static function postprocess_item($item, $glossary) {
        // $item is ['id','source_text','translated','placeholders']
        $translated = isset($item['translated']) ? $item['translated'] : '';
        $applied = [];
        $warnings = [];

        // Glossary handling:
        // - If $enforce is true, deterministically replace terms in the translated text.
        // - If false, treat glossary as context: detect which replacements the model already used
        //   (by checking for replacement strings) and report applied terms; warn when neither
        //   term nor replacement appear but source contained the term.
        foreach ($glossary as $g) {
            if (empty($g['term']) || !array_key_exists('replacement', $g)) {
                continue;
            }
            $term = $g['term'];
            $replacement = $g['replacement'];
            $pattern = '/\b' . preg_quote($term, '/') . '\b/ui';
            $repattern = '/\b' . preg_quote($replacement, '/') . '\b/ui';

            // Advisory mode: if the translation contains the replacement string, mark applied.
            if (preg_match($repattern, $translated)) {
                $applied[] = ['term' => $term, 'replacement' => $replacement];
            } else {
                // If source contained the term but translation neither contains the original term nor the replacement, warn.
                if (!empty($item['source_text']) && preg_match($pattern, $item['source_text'])) {
                    $warnings[] = 'glossary_not_applied:' . $term;
                }
            }
        }

        // Validate placeholders: ensure each placeholder from source exists in translated.
        if (!empty($item['placeholders']) && is_array($item['placeholders'])) {
            foreach ($item['placeholders'] as $ph) {
                if (strpos($translated, $ph) === false) {
                    $warnings[] = 'placeholder_missing:' . $ph;
                }
            }
        }

        return ['translated' => $translated, 'applied_glossary_terms' => $applied, 'warnings' => $warnings];
    }
}
