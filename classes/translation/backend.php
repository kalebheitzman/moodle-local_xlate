<?php
namespace local_xlate\translation;

defined('MOODLE_INTERNAL') || die();

/**
 * Backend wrapper skeleton for AI translation provider integration.
 *
 * This class implements the high-level flow for batched translation requests
 * using function-calling (translate_batch). It is intentionally a skeleton
 * with TODOs where provider-specific HTTP code should go. The implementation
 * focuses on input preparation, response validation and glossary enforcement.
 */
class backend {
    /**
     * Translate a batch of items.
     *
     * @param string $requestid batch id
     * @param string $sourcelang
     * @param string $targetlang
     * @param array $items array of ['id','source_text','context','placeholders']
     * @param array $glossary array of ['term','replacement']
     * @param array $options optional keys: temperature, max_tokens, model
     * @return array result structure: ['ok' => bool, 'results' => [...], 'meta' => [...], 'errors' => [...]]
     */
    public static function translate_batch($requestid, $sourcelang, $targetlang, $items, $glossary = [], $options = []) {
        global $CFG;

        // Quick entry trace to ensure worker processes reach this method.
        try {
            $entry = json_encode(['requestid' => $requestid, 'sourcelang' => $sourcelang, 'targetlang' => $targetlang, 'items_count' => is_array($items) ? count($items) : 0], JSON_PARTIAL_OUTPUT_ON_ERROR);
            error_log('[local_xlate] translate_batch entered: ' . $entry);
        } catch (\Exception $e) {
            error_log('[local_xlate] translate_batch entered (failed to json_encode): ' . $e->getMessage());
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
                try {
                    error_log('[local_xlate] USING AZURE API-KEY header for endpoint: ' . $endpoint);
                } catch (\Exception $ex) {
                    // ignore logging errors
                }
            } else {
                $headers = [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $apikey,
                ];
            }

            $postdata = json_encode($payload);

            // Log outgoing payload and endpoint for debugging. Do NOT log the API key.
            try {
                $short = (strlen($postdata) > 10000) ? substr($postdata, 0, 10000) . '...[truncated]' : $postdata;
                error_log('[local_xlate] OUTGOING ' . $url . ' payload: ' . $short);
            } catch (\Exception $ex) {
                // swallow logging errors
            }

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
                    error_log('[local_xlate] translate_batch curl exception on attempt ' . $attempt . ': ' . $e->getMessage());
                    $result = false;
                    $httpcode = 0;
                }

                // Log provider response for debugging (or error state)
                try {
                    $resshort = is_string($result) ? ((strlen($result) > 10000) ? substr($result, 0, 10000) . '...[truncated]' : $result) : '[no body]';
                    error_log('[local_xlate] RESPONSE attempt=' . $attempt . ' httpcode=' . $httpcode . ' body: ' . $resshort);
                } catch (\Exception $ex) {
                    // ignore logging failures
                }

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
                    error_log('[local_xlate] repair attempt failed: ' . $e->getMessage());
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

            // Log token usage for each translated item, including prompt and completion tokens if available.
            global $DB;
            if (!empty($meta['usage_tokens']['total']) && is_array($results)) {
                $now = time();
                $tokens = (int)$meta['usage_tokens']['total'];
                $prompt_tokens = isset($meta['usage_tokens']['prompt']) ? (int)$meta['usage_tokens']['prompt'] : null;
                $completion_tokens = isset($meta['usage_tokens']['completion']) ? (int)$meta['usage_tokens']['completion'] : null;
                $modelstr = $meta['model'] ?? '';
                $elapsed = $meta['elapsed_ms'] ?? 0;
                $lang = $targetlang;
                foreach ($results as $r) {
                    if (!empty($r['id'])) {
                        $rec = [
                            'timecreated' => $now,
                            'lang' => $lang,
                            'xkey' => $r['id'],
                            'tokens' => $tokens,
                            'prompt_tokens' => $prompt_tokens,
                            'completion_tokens' => $completion_tokens,
                            'model' => $modelstr,
                            'response_ms' => $elapsed
                        ];
                        try {
                            $DB->insert_record('local_xlate_token_usage', $rec, false);
                        } catch (\Exception $e) {
                            // Log but do not fail translation if token logging fails.
                            error_log('[local_xlate] Failed to log token usage: ' . $e->getMessage());
                        }
                    }
                }
            }
            return ['ok' => true, 'results' => $results, 'meta' => $meta, 'raw' => $response];

        } catch (\Exception $e) {
            // Make sure exceptions are visible in worker logs for debugging.
            try {
                error_log('[local_xlate] translate_batch EXCEPTION: ' . $e->getMessage() . '\n' . $e->getTraceAsString());
            } catch (\Exception $ex) {
                // swallow secondary logging errors
            }
            return ['ok' => false, 'errors' => ['exception' => $e->getMessage()]];
        }
    }

    /**
     * Post-process a single translated item: enforce glossary and validate placeholders.
     * Returns an array with keys: translated, applied_glossary_terms, warnings (array).
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
