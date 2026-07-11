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

        $model    = isset($options['model']) ? $options['model'] : get_config('local_xlate', 'openai_model');
        $endpoint = get_config('local_xlate', 'openai_endpoint');
        $apikey   = get_config('local_xlate', 'openai_api_key');

        // Fail fast if endpoint or api key not configured to avoid confusing provider errors.
        if (empty($endpoint) || empty($apikey)) {
            return ['ok' => false, 'errors' => ['missing_api_config']];
        }

        // Minimal validation.
        if (empty($requestid) || empty($sourcelang) || empty($targetlang) || empty($items) || !is_array($items)) {
            return ['ok' => false, 'errors' => ['invalid_arguments']];
        }

        $built = self::build_payload($requestid, $sourcelang, $targetlang, $items, $glossary, $options);
        if (!empty($built['error'])) {
            return ['ok' => false, 'errors' => [$built['error']]];
        }
        $payload = $built['payload'];

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
                // Do not include the response body — it may contain provider-specific
                // account details. The full body is already captured in debugging() above.
                return ['ok' => false, 'errors' => ['http_error' => $httpcode]];
            }

            // At this point we have $result and $httpcode in successful range.
            $response = json_decode($result, true);
            if (!$response) {
                return ['ok' => false, 'errors' => ['invalid_json_response']];
            }

            // Attempt to extract function_call.arguments (preferred) or message content.
            $choice = $response['choices'][0] ?? null;

            // A truncated completion (hit max_tokens / model output cap) yields
            // malformed JSON whose brace-extraction "rescue" below can silently
            // drop the tail of the batch. Fail cleanly instead so the caller
            // retries rather than persisting a partial result.
            if (!empty($choice['finish_reason']) && $choice['finish_reason'] === 'length') {
                return ['ok' => false, 'errors' => ['truncated_response']];
            }

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
                        'functions' => $built['functions'],
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
                // Extract cached token counts from the API response, which differs per provider.
                $provider = self::detect_provider($endpoint);
                if ($provider === 'anthropic') {
                    // Anthropic response fields: cache_read_input_tokens, cache_creation_input_tokens.
                    $cachedtokens  = (int)($response['usage']['cache_read_input_tokens'] ?? 0);
                    $inputtokens   = (int)($response['usage']['input_tokens'] ?? 0);
                    $outputtokens  = (int)($response['usage']['output_tokens'] ?? 0);
                    $totaltokens   = $inputtokens + $cachedtokens
                                   + (int)($response['usage']['cache_creation_input_tokens'] ?? 0)
                                   + $outputtokens;
                } else {
                    // OpenAI response field: prompt_tokens_details.cached_tokens.
                    $promptdetails = $response['usage']['prompt_tokens_details'] ?? [];
                    $cachedtokens  = (int)($promptdetails['cached_tokens'] ?? 0);
                    $inputtokens   = isset($usage['prompt']) ? (int)$usage['prompt'] : 0;
                    $outputtokens  = isset($usage['completion']) ? (int)$usage['completion'] : 0;
                    $totaltokens   = (int)($usage['total'] ?? ($inputtokens + $cachedtokens + $outputtokens));
                }

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
            // Log the full detail for server-side diagnosis only. Do not include
            // $e->getMessage() in the returned array — it could contain the
            // endpoint URL, file paths, or other internal configuration details
            // that would be forwarded to the browser via the web service layer.
            debugging('[local_xlate] translate_batch exception: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return ['ok' => false, 'errors' => ['exception']];
        }
    }

    /**
     * Return per-language Tier 2 appendix text for the system prompt lang block.
     *
     * Appendices target real LLM failure modes for morphologically complex languages.
     * Only languages with known systematic issues have an entry; all others return ''.
     * Language matching strips the region suffix so 'ar_sa' and 'ar' both match 'ar'.
     *
     * @param string $lang Target language code (e.g. 'uk', 'pl', 'ar_sa').
     * @return string Appendix text (starts with "\n\n") or empty string.
     */
    private static function get_lang_appendix($lang) {
        $base = strtolower(explode('_', $lang)[0]);
        switch ($base) {
            case 'ar':
                return "\n\n"
                    . "Arabic-specific rules:\n"
                    . "- Use Modern Standard Arabic (MSA / الفصحى) throughout. Do not use dialect forms (Egyptian, Levantine, Gulf, etc.) even if they sound more natural to a regional audience.\n"
                    . "- Apply full tashkeel (diacritical marks) only when it is present in the source material. For unvocalised source text, produce unvocalised output — do not add diacritics.\n"
                    . "- Dual number: use the dual suffix (-ان/-ين) when the source explicitly refers to exactly two items. Do not use the dual as a stylistic choice for plural strings.\n"
                    . "- Broken plural (جمع التكسير): always use the grammatically correct broken plural for the noun in question rather than defaulting to the sound plural suffix (-ون/-ات), which is incorrect for many common nouns.\n"
                    . "- Numerals 3–10 govern the opposite grammatical gender of the noun they count (rule of gender polarity). Apply this correctly — do not default the numeral gender to masculine.\n"
                    . "- Right-to-left punctuation: place punctuation at the logical end of the RTL string. Do not mirror Latin punctuation placement.\n"
                    . "- Register default: formal (رسمي). Use formal verb forms and avoid colloquial contractions.";
            case 'bg':
                return "\n\n"
                    . "Bulgarian-specific rules:\n"
                    . "- Bulgarian has no grammatical cases for nouns (except the vocative and a residual dative in pronouns). Do not invent case endings.\n"
                    . "- Definiteness is marked by a postfixed definite article, not a separate word. Apply the correct article form: -ът/-ят (m. subject), -а/-я (m. object/other contexts), -та (f.), -то (n.), -те (pl.). Choose the subject vs. object form based on the syntactic role of the noun in the translation.\n"
                    . "- Verb aspect (svyrshen / nesyvrshen): choose the perfective or imperfective aspect appropriate to the context. Button labels and one-off actions typically use perfective; ongoing or habitual actions use imperfective.\n"
                    . "- Register default: neutral-formal (неутрално-официален). Use the formal second-person plural (Вие) for direct address in UI strings rather than informal ти.";
            case 'cs':
                return "\n\n"
                    . "Czech-specific rules:\n"
                    . "- Czech has seven grammatical cases. Assign the correct case to every noun, pronoun, and adjective based on its syntactic function. Do not default to the nominative for non-subject positions.\n"
                    . "- Aspect pairs: prefer the perfective aspect for completed, one-off actions (button labels, success messages) and the imperfective for ongoing states or settings descriptions.\n"
                    . "- Plural count agreement: Czech has three count classes — singular (1), paucal (2–4), and plural (5+). For strings with a numeric placeholder, supply the form for 5+ unless context makes a smaller count unambiguous, since that form is most general.\n"
                    . "- Genitive of negation: use the genitive case (not accusative) after negated verbs when the negation scopes over the object.\n"
                    . "- Register default: neutral-formal (neutrální-formální). Use the formal second-person plural (Vy / vykání) in UI strings that address the user directly.";
            case 'hu':
                return "\n\n"
                    . "Hungarian-specific rules:\n"
                    . "- Vowel harmony: all suffixes must harmonise with the vowel inventory of the stem (back vowels: a/á/o/ó/u/ú → back-vowel suffix; front vowels: e/é/i/í/ö/ő/ü/ű → front-vowel suffix). This applies to case suffixes, possessive suffixes, verbal conjugation endings, and derivational suffixes.\n"
                    . "- Hungarian marks the object with the accusative suffix (-t/-ot/-at/-et/-öt depending on the stem). Do not omit the accusative on definite direct objects.\n"
                    . "- Definite vs. indefinite conjugation: use the definite conjugation when the verb has a definite object (including third-person object pronouns and noun phrases with a definite article), and the indefinite conjugation otherwise.\n"
                    . "- Postpositions: Hungarian uses postpositions, not prepositions. Ensure the postposition follows its noun phrase.\n"
                    . "- Compound words are written as single words in Hungarian when the components form a stable lexical unit. Do not insert spaces between the parts of an established compound.\n"
                    . "- Register default: formal (magázó). Use the third-person singular polite pronoun (Ön / Önnek) for direct address in UI strings rather than the informal te.";
            case 'pl':
                return "\n\n"
                    . "Polish-specific rules:\n"
                    . "- Polish has seven grammatical cases. Assign the correct case to every noun, pronoun, and adjective. Do not default to the nominative for non-subject positions.\n"
                    . "- Plural count agreement: Polish has four count classes — singular (1), paucal (2–4), plural (5–21, 22–24 etc. require the paucal again for the last digit 2–4, and plural for 5–9 and 0 and 11–19). For strings with a numeric placeholder, supply the genitive plural form (most general and correct for large numbers) unless context makes a specific count unambiguous.\n"
                    . "- Verbal aspect: use the perfective aspect for one-off completed actions (button labels) and the imperfective for ongoing descriptions or settings.\n"
                    . "- Animate vs. inanimate masculine: in the accusative singular, animate masculine nouns take the genitive form. Apply this rule correctly.\n"
                    . "- Register default: formal (formalny). Use the third-person plural polite address (Pan/Pani/Państwo + third-person verb) for direct address in UI strings rather than the informal second person.";
            case 'ro':
                return "\n\n"
                    . "Romanian-specific rules:\n"
                    . "- Comma-below letters: always use the correct Unicode characters — ș (U+0219, s with comma below) and ț (U+021B, t with comma below). Never substitute the visually similar cedilla variants ş (U+015F) or ţ (U+0163); they are orthographically incorrect in modern Romanian.\n"
                    . "- Romanian has three genders (masculine, feminine, neuter). Neuter nouns behave like masculine in the singular and feminine in the plural. Ensure adjective agreement follows the correct pattern for neuter nouns.\n"
                    . "- Definiteness is expressed by a postfixed definite article. Apply the correct form for gender, number, and case (e.g. -ul/-le for m.sg., -a for f.sg., -i for m.pl., -le for f./n.pl.).\n"
                    . "- Romanian has five cases; nominative/accusative share one form, and genitive/dative share another. Ensure the genitive/dative form (with -ului/-ei/-lor) is used correctly for possession and indirect objects.\n"
                    . "- Register default: formal (formal). Use the polite second-person pronoun dumneavoastră (+ second-person plural verb agreement) for direct address in UI strings rather than informal tu.";
            case 'tr':
                return "\n\n"
                    . "Turkish-specific rules:\n"
                    . "- I/ı dotted/dotless distinction: Turkish has four high vowels — İ (uppercase dotted i), i (lowercase dotted i), I (uppercase dotless i), ı (lowercase dotless i). Never conflate them. Uppercasing 'i' must produce 'İ', not 'I'; uppercasing 'ı' must produce 'I', not 'İ'. Verify that your output respects this in any mixed-case words.\n"
                    . "- Vowel harmony: all suffixes must harmonise with the last vowel of the stem. The two harmony axes are: back/front (a, ı, o, u vs. e, i, ö, ü) and rounded/unrounded. Apply four-way harmony (-ı/-i/-u/-ü) and two-way harmony (-a/-e) correctly.\n"
                    . "- Consonant mutation (ünsüz değişimi): when a suffix beginning with a voiced consonant is attached to a stem ending in a voiceless consonant, apply the appropriate assimilation (e.g. t→d, ç→c, k→ğ in certain positions).\n"
                    . "- Turkish is agglutinative; build complex forms by chaining the correct suffixes rather than using periphrastic constructions where a single suffixed form is the norm.\n"
                    . "- Register default: formal-polite (resmi-nazik). Use second-person plural (siz + plural verb ending) for direct address in UI strings rather than informal sen.";
            case 'uk':
                return "\n\n"
                    . "Ukrainian-specific rules:\n"
                    . "- Ukrainian has seven grammatical cases. Assign the correct case to every noun, pronoun, and adjective based on its syntactic function. Do not default to the nominative for non-subject positions.\n"
                    . "- The Ukrainian letter І (и з крапкою, U+0406) is distinct from the Cyrillic И (U+0418) and the Latin I. Use І/і (U+0406/U+0456) in Ukrainian words, not the Cyrillic И or Latin I.\n"
                    . "- The apostrophe (') is used in Ukrainian before я, ю, є, ї after labial consonants (б, п, в, м, ф), р, and the prefix. Use the right single quotation mark (U+2019) or the straight apostrophe (U+0027) — never the Cyrillic ъ or other substitutes.\n"
                    . "- Plural count agreement: Ukrainian has three count classes — singular (1, 21, 31…), paucal (2–4, 22–24…), and plural (5–20, 25–30…). For strings with a numeric placeholder, supply the genitive plural form unless context makes a smaller count unambiguous.\n"
                    . "- Verbal aspect: use perfective for completed one-off actions (button labels, confirmations) and imperfective for descriptions of ongoing states or settings.\n"
                    . "- Register default: formal (офіційний). Use the formal second-person plural (Ви + plural verb) for direct address in UI strings rather than informal ти.";
            default:
                return '';
        }
    }

    /**
     * Build the OpenAI-compatible request payload for a translation batch.
     *
     * Extracted from translate_batch() so CLI/debug tooling can inspect the
     * outgoing request without making an HTTP call (e.g. replay_job --dryrun).
     *
     * @param string $requestid Stable request identifier.
     * @param string $sourcelang Source language code.
     * @param string $targetlang Target language code.
     * @param array<int,array> $items Items to translate.
     * @param array<int,array> $glossary Glossary constraints.
     * @param array<string,mixed> $options Provider options (model, temperature, max_tokens, etc.).
     * @return array{payload?:array,functions?:array,endpoint?:string,model?:string,error?:string}
     */
    public static function build_payload($requestid, $sourcelang, $targetlang, $items, $glossary = [], $options = []) {
        $model    = isset($options['model']) ? $options['model'] : get_config('local_xlate', 'openai_model');
        $endpoint = get_config('local_xlate', 'openai_endpoint');

        // Load the function-calling schema from the spec file.
        $specpath = __DIR__ . '/../../spec/translate_batch_function.json';
        $specjson = @file_get_contents($specpath);
        if ($specjson === false || trim($specjson) === '') {
            return ['error' => 'missing_function_spec'];
        }
        $fnspec = json_decode($specjson, true);
        if (!is_array($fnspec)) {
            return ['error' => 'missing_function_spec'];
        }
        $functions = [$fnspec];

        // Normalize items: strip internal DB fields the AI does not need.
        $fnitems = [];
        foreach ($items as $it) {
            $fnitem = [
                'id'          => (string)($it['id'] ?? $it['key'] ?? ''),
                'source_text' => (string)($it['source_text'] ?? ''),
            ];
            if (!empty($it['context'])) {
                $fnitem['context'] = (string)$it['context'];
            }
            if (!empty($it['placeholders']) && is_array($it['placeholders'])) {
                $fnitem['placeholders'] = $it['placeholders'];
            }
            if (!empty($it['component'])) {
                $fnitem['component'] = (string)$it['component'];
            }
            $fnitems[] = $fnitem;
        }

        // Hardcoded core prompt — always present regardless of admin settings.
        // Covers the technical rules that must never be omitted: role, HTML/placeholder
        // preservation, natural fluency, grammar, register, cultural adaptation, and
        // control character sanitisation. Appendix references in rules 5 and 6 point
        // to the per-language Tier 2 appendices added to the lang block.
        $coreprompt = 'You are a professional translator for a learning management system. '
            . 'Translate UI strings accurately and naturally into the target language.' . "\n\n"
            . 'Rules you must always follow:' . "\n"
            . '1. Preserve all HTML tags and attributes exactly — translate only the visible text content between tags.' . "\n"
            . '2. Preserve all placeholder variables exactly as they appear (e.g. {$a}, %s, %d). Never translate, modify, or omit them. If a string contains only placeholders or HTML with no translatable text, return it unchanged.' . "\n"
            . '3. Translate the meaning faithfully — do not omit, add, or distort any meaning present in the source string, even when rephrasing for naturalness. When faithfulness and naturalness conflict, prefer naturalness provided no meaning is lost or distorted.' . "\n"
            . '4. Translate naturally and idiomatically, preserving the tone, style, and rhetorical intent of the source. Restructuring sentence syntax is acceptable and often necessary — prioritise natural word order and phrasing in the target language over the structure of the source. Avoid calques and word-for-word renderings. Apply economy of expression: prefer the shortest natural equivalent that communicates the meaning unambiguously. Never add explanatory text, footnotes, or commentary.' . "\n"
            . '5. Apply grammatically correct inflection — including verb conjugation, gender agreement, adjective agreement, case endings, and plural forms. Do not default to uninflected or base forms when context requires otherwise. For numeric placeholders (e.g. %d, {$a}), use the plural form most appropriate for the target language; if uncertain, prefer the most general form that works across counts. See per-language appendices for languages with complex plural or number systems.' . "\n"
            . '6. Use a consistent register throughout. Map the register of the source (formal, informal, neutral) to the closest equivalent in the target language. Where the source register is ambiguous, default to the register conventional for software UI in that language. See per-language appendices for specific register defaults.' . "\n"
            . '7. Be consistent within a batch: translate the same term the same way in every string unless a grammatical variation is required.' . "\n"
            . '8. UI strings (buttons, labels, headings) should be concise and use the imperative or noun form conventional for interfaces in the target language.' . "\n"
            . '9. Adapt cultural references appropriately — including punctuation conventions, date and number formats, and culturally loaded expressions — to match the norms of the target language and region.' . "\n"
            . '10. Do not translate proper nouns, brand names, or product names unless a widely accepted localised equivalent exists. Retain technical terms and acronyms from the source unless the target language has a standard equivalent.' . "\n"
            . '11. If a source string is ambiguous or poorly written, make the most conservative interpretation and preserve the ambiguity in the translation rather than resolving it silently.' . "\n"
            . '12. Translated strings must contain no NUL bytes or ASCII control characters (U+0000–U+001F except tab/newline). For languages using non-Latin scripts, always use the native script — do not romanise or transliterate unless the term has an established romanised form in that language community.';

        // Domain-specific additional instructions from admin settings (e.g. theological guidance).
        // The setting label is "Additional translation instructions" — it is appended after the core.
        $additionalprompt = (string)get_config('local_xlate', 'openai_prompt');

        // Glossary instruction: always include the full glossary so the system prompt
        // remains stable per language pair across batches, enabling prompt caching.
        // Per-batch filtering was previously used to save tokens but prevented caching —
        // the cache economics favour a stable full-glossary prompt.
        $glossaryinstruction = '';
        if (!empty($glossary)) {
            $glossarypairs = [];
            foreach ($glossary as $g) {
                if (empty($g['term']) || !array_key_exists('replacement', $g)) {
                    continue;
                }
                $pair = $g['term'] . ' => ' . $g['replacement'];
                if (!empty($g['notes'])) {
                    $pair .= ' [Note: ' . trim($g['notes']) . ']';
                }
                $glossarypairs[] = $pair;
            }
            if (!empty($glossarypairs)) {
                $glossaryinstruction = "\n\nGlossary — always translate these terms as specified:\n" . implode("\n", $glossarypairs);
            }
        }

        // Batch coherence instruction.
        $batchinstruction = "\n\nYou will receive a JSON object with a 'request_id' and an 'items' array. "
            . "Call the translate_batch function with a 'results' array that has exactly one entry per input item "
            . "in the same order. Each entry needs 'id' (copied from input) and 'translated' (the translated string). "
            . "Optionally include 'applied_glossary_terms' (array of {term, replacement}) and 'warnings' (array of strings).";

        // Split the system prompt into three logical blocks with different stability profiles:
        //   Static block   — Tier 1 rules + admin prompt + batch instruction; globally stable.
        //   Lang block     — "Translate from X to Y." + Tier 2 per-language appendix; per-lang stable.
        //   Glossary block — full glossary; per-(lang+glossary) stable, omitted when empty.
        // This split maximises prompt-cache hits on both OpenAI (automatic prefix caching)
        // and Anthropic (explicit cache_control breakpoints). For Anthropic a glossary edit
        // only evicts the third block; for OpenAI the stable prefix still benefits from caching.
        $staticblock = $coreprompt;
        if (!empty($additionalprompt)) {
            $staticblock .= "\n\n" . $additionalprompt;
        }
        $staticblock .= $batchinstruction;

        $langblock = "\n\nTranslate from {$sourcelang} to {$targetlang}.";
        $langappendix = self::get_lang_appendix($targetlang);
        if (!empty($langappendix)) {
            $langblock .= $langappendix;
        }

        // Build messages array; Anthropic requires explicit cache_control markers while
        // OpenAI caches automatically from a stable prompt prefix.
        // Three-block split for Anthropic:
        //   Block 1 (static)  — Tier 1 rules + admin prompt + batch instruction; global cache.
        //   Block 2 (per lang) — "Translate from X to Y." + Tier 2 appendix; per-lang cache.
        //   Block 3 (glossary) — glossary terms; per-(lang+glossary) cache, omitted when empty.
        // This means a glossary edit only evicts block 3; blocks 1 and 2 remain cached.
        $provider = self::detect_provider($endpoint);
        if ($provider === 'anthropic') {
            $contentblocks = [
                [
                    'type'          => 'text',
                    'text'          => $staticblock,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
                [
                    'type'          => 'text',
                    'text'          => $langblock,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ];
            if (!empty($glossaryinstruction)) {
                $contentblocks[] = [
                    'type'          => 'text',
                    'text'          => $glossaryinstruction,
                    'cache_control' => ['type' => 'ephemeral'],
                ];
            }
            $systemmessage = [
                'role'    => 'system',
                'content' => $contentblocks,
            ];
        } else {
            // OpenAI / Azure: single string, automatic prefix caching.
            $systemmessage = ['role' => 'system', 'content' => $staticblock . $langblock . $glossaryinstruction];
        }

        // User message: the batch to translate.
        $userdata = [
            'request_id'  => $requestid,
            'source_lang' => $sourcelang,
            'target_lang' => $targetlang,
            'items'       => $fnitems,
        ];
        $usercontent = json_encode($userdata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Final payload.
        $payload = [
            'model'         => $model,
            'messages'      => [
                $systemmessage,
                ['role' => 'user', 'content' => $usercontent],
            ],
            'functions'     => $functions,
            'function_call' => ['name' => 'translate_batch'],
        ];
        if (!empty($options['max_tokens'])) {
            $payload['max_tokens'] = (int)$options['max_tokens'];
        }
        // Low temperature for translation: we want faithful, deterministic output,
        // not creative variation. Provider default (typically 1.0) measurably
        // increases terminology drift between batches. Overridable via options.
        $payload['temperature'] = isset($options['temperature'])
            ? (float)$options['temperature'] : 0.2;

        return [
            'payload'   => $payload,
            'functions' => $functions,
            'endpoint'  => $endpoint,
            'model'     => $model,
        ];
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
            // Unicode-aware word boundaries. PCRE \b is ASCII-based even with /u,
            // so \bПривет\b NEVER matches at the start of a Cyrillic word —
            // glossary detection silently failed for non-Latin target languages
            // (ru, uk, bg, ar...). Use letter/number lookarounds instead.
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/ui';
            $repattern = '/(?<![\p{L}\p{N}])' . preg_quote($replacement, '/') . '(?![\p{L}\p{N}])/ui';

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

    /**
     * Detect the AI provider from the configured endpoint URL.
     *
     * Used to apply provider-specific prompt-caching strategies and to parse
     * cached token counts from the correct response fields.
     *
     * @param string $endpoint Configured OpenAI-compatible endpoint URL.
     * @return string 'anthropic', 'azure', or 'openai'.
     */
    private static function detect_provider($endpoint) {
        if (stripos($endpoint, 'api.anthropic.com') !== false) {
            return 'anthropic';
        }
        if (stripos($endpoint, 'openai.azure.com') !== false || stripos($endpoint, 'azure') !== false) {
            return 'azure';
        }
        return 'openai';
    }
}
