<?php

/**
 * Send the new field value or image to the OpenAI API in order to update the linked field 
 *
 * @param int|array $resources          Resource ID or array of resource IDS
 * @param array     $target_field       Target metadata field array from get_resource_type_field()
 * @param array     $values             Array of strings from the field currently being processed
 * @param string    $file               Path to image file. If provided will use this file instead of metadata values
 * 
 * @return bool|array                    Array indicating success/failure 
 *                                      True if update successful, false if invalid field or no data returned
 * 
 */
function openai_gpt_update_field($resources,array $target_field,array $values, string $file="")
    {
    global $valid_ai_field_types, $FIXED_LIST_FIELD_TYPES,$language, $defaultlanguage, $openai_gpt_message_input_JSON, 
    $openai_gpt_message_output_json, $openai_gpt_message_text, $openai_gpt_processed, $openai_gpt_api_key,$openai_gpt_model,
    $openai_gpt_temperature,$openai_gpt_example_json_user,$openai_gpt_example_json_assistant,$openai_gpt_example_text_user,
    $openai_gpt_example_text_assistant,$openai_gpt_max_tokens, $openai_gpt_max_data_length, $openai_gpt_system_message,
    $openai_gpt_fallback_model, $openai_gpt_message_output_text, $openai_gpt_model_override, $lang, $language, $languages, $openai_gpt_language,
    $openai_gpt_token_limit, $openai_gpt_token_limit_days, $openai_gpt_endpoint, $ollama_endpoint, $ollama_hide_endpoint, $ollama_model, $ollama_model_override;

    // Don't update if not a valid field type
    if (!in_array($target_field["type"],$valid_ai_field_types)) {
        return false;
    }

    $provider = openai_gpt_get_provider();
    
    // Check usage limits if set before any processing starts
    if ($provider == "openai" && $openai_gpt_token_limit !== 0 && $openai_gpt_token_limit_days !== 0) {
        $tokens_used = openai_gpt_get_tokens_used($openai_gpt_token_limit_days);

        if ($tokens_used > $openai_gpt_token_limit) {
            debug("openai_gpt_update_field - token limit $openai_gpt_token_limit exceeded - used $tokens_used in last $openai_gpt_token_limit_days days");
            return false;
        }
    }

    if(!is_array($resources)) {
        $resources = [$resources];
    }

    set_processing_message(((count($resources) > 1) ? $lang["openai_gpt_processing_multiple_resources"] : str_replace("[resource]", $resources[0], $lang["openai_gpt_processing_resource"])) . ": " . str_replace("[field]",$target_field["name"],$lang["openai_gpt_processing_field"]));

    // Define a language instruction based on the language of the current user.
    $output_language = $openai_gpt_language;
    if ($output_language == "") {
         // Empty string = use the language of the current user.
        $output_language = $language;
    }

    $language_instruction = " The response should be in language: " . $languages[$output_language];

    $resources = array_filter($resources, "is_int_loose");

    $results = [];
    // Only get data for resources in resource types which have access to the target field.
    // No need to get openai_gpt data for resources that update_field() can't update.
    if ($target_field['global'] === 0 && isset($target_field['resource_types'])) {
        $valid_resource_types = explode(',', $target_field['resource_types']);

        if (count($valid_resource_types) > 0) {
            $filtered_resources = array();
            foreach ($resources as $resource_ref) {
                $resource_ref_resource_type = get_resource_data($resource_ref)['resource_type'];
                if (in_array($resource_ref_resource_type, $valid_resource_types)) {
                    $filtered_resources[] = $resource_ref;
                } else {
                    debug("openai_gpt - skipping resource $resource_ref - target field unavailable for resource type $resource_ref_resource_type");
                    $results[$resource_ref] = false;
                }
            }

            $resources = $filtered_resources;
            if (count($resources) === 0) {
                // All resources filtered out, nothing to process.
                return $results;
            }
        }
    }

    $valid_response = false;
    if (trim($file) != "") {
        // Shrink large stills before base64 — local vision models choke on multi-MB originals.
        $file_for_ai = openai_gpt_prepare_image_for_api($file);
        $file_data = file_get_contents($file_for_ai !== '' ? $file_for_ai : $file);
        $file_data_base64 = base64_encode($file_data);
                               
        
        $return_json = in_array($target_field["type"],$FIXED_LIST_FIELD_TYPES);
        $outtype = $return_json ? $openai_gpt_message_output_json : $openai_gpt_message_output_text;
        $system_message = str_replace(["%%IN_TYPE%%","%%OUT_TYPE%%"],["image",$outtype],$openai_gpt_system_message) . $language_instruction;
       
        $messages = [];
        $messages[] = ["role" => "system", "content" => $system_message];

        if ($provider == "ollama") {
            $messages[] = [
                "role" => "user",
                "content" => [
                    ["type" => "text", "text" => $target_field["openai_gpt_prompt"]],
                    ["type" => "image_url", "image_url" => "data:image/jpeg;base64," . $file_data_base64]
                ]
            ];
        } else {
            $messages[] = [
                "role" => "user",
                "content" => [
                    ["type" => "text", "text" => $target_field["openai_gpt_prompt"]],
                    ["type" => "image_url",
                        "image_url" => [
                        "url" => "data:image/jpeg;base64, " . $file_data_base64,
                        "detail" => "low"
                        ]
                    ]
                ]
            ];
        }

        debug("openai_gpt - sending request prompt for image");
    } else {
        // Get data to use
        // Remove any i18n variants and use default system language
        $prompt_values  = [];
        $saved_language = $language;
        $language       = $defaultlanguage;
        
        foreach ($values as $value) {
            if (substr($value, 0, 1) == "~") {
                $prompt_values[] = mb_strcut(i18n_get_translated($value), 0, $openai_gpt_max_data_length);
            } elseif (trim($value) != "") {
                $prompt_values[] = mb_strcut($value, 0, $openai_gpt_max_data_length);
            }
        }

        $language = $saved_language;
    
        // Generate prompt (only if there are any strings)
        if (count($prompt_values) == 0) {
            // No nodes present, fake a valid response to clear target field
            $newvalue = '';
            $valid_response = true;
            $messages = [];
        } else {
            $send_as_json = count($prompt_values) > 1;
            $return_json = in_array($target_field["type"], $FIXED_LIST_FIELD_TYPES);

            $intype = $send_as_json ? $openai_gpt_message_input_JSON : $openai_gpt_message_text; 
            $outtype = $return_json ? $openai_gpt_message_output_json : $openai_gpt_message_output_text;

            $system_message = str_replace(["%%IN_TYPE%%", "%%OUT_TYPE%%"], [$intype, $outtype], $openai_gpt_system_message) . $language_instruction;

            $messages = [];
            $messages[] = ["role" => "system", "content" => $system_message];

            // Give a sample 
            if (in_array($target_field["type"], $FIXED_LIST_FIELD_TYPES)) {
                $messages[] = ["role" => "user", "content" => $openai_gpt_example_json_user];
                $messages[] = ["role" => "assistant", "content" => $openai_gpt_example_json_assistant];
            } else {
                $messages[] = ["role" => "user", "content" => $openai_gpt_example_text_user];
                $messages[] = ["role" => "assistant", "content" => $openai_gpt_example_text_assistant];
            }

            $messages[] = ["role" => "user", "content" => $target_field["openai_gpt_prompt"] . ": " . ($send_as_json ? json_encode($prompt_values) : $prompt_values[0])];
        }
    }
   
    

    // Determine endpoint, api key and model to use
    if ($provider == "ollama") {
        $endpoint = isset($ollama_hide_endpoint) ? $ollama_hide_endpoint : $ollama_endpoint;
        // No API key required for Ollama at the moment
        $api_key = "";
        $model = isset($ollama_model_override) ? $ollama_model_override : $ollama_model;
    } else {
        // OpenAI
        $endpoint = $openai_gpt_endpoint;
        $api_key = $openai_gpt_api_key;

        if (trim($api_key) == "") {
            debug("openai_gpt error - missing API key for OpenAI");
        }

        // Can't use old model since move to chat API
        $model = trim($openai_gpt_model) == "text-davinci-003" ? $openai_gpt_fallback_model : $openai_gpt_model; 
        if (isset($openai_gpt_model_override)) {
            $model = $openai_gpt_model_override;
        }
    }

    debug("openai_gpt - sending $provider request prompt " . json_encode($messages));
    
    $response = openai_gpt_generate_completions($endpoint, $api_key, $model, $messages, $openai_gpt_temperature, $openai_gpt_max_tokens);

    if (trim($response) != "") {
        debug("response from openai_gpt_generate_completions() : " . $response);
        if (in_array($target_field["type"], $FIXED_LIST_FIELD_TYPES)) {
            // Clean up response
            if (substr($response, 0, 7) == "```json") {
                debug("openai_gpt - extracting JSON text");
                $response = substr(trim($response, " `\""), 4);
            } else {
                $response = trim($response, " \"");
            }

            $apivalues = json_decode(trim($response), true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($apivalues)) {
                debug("openai_gpt error - invalid JSON text response received from API: " . json_last_error_msg() . " " . trim($response));
                $apivalues = openai_gpt_parse_keyword_list($response);
            }
            // The returned array elements may be associative or contain sub arrays - convert to list of strings
            $newstrings = [];
            foreach ($apivalues as $attribute => &$value) {
                if (is_array($value)) {
                    $value = json_encode($value);
                }
                $newstrings[] = is_int_loose($attribute) ? $value : $attribute . " : " . $value;
            }
            $newstrings = array_values(array_filter(array_map('trim', $newstrings), function ($s) {
                return $s !== '' && strcasecmp($s, 'none') !== 0;
            }));
            // update_field() will separate on NODE_NAME_STRING_SEPARATOR
            $newvalue = implode(NODE_NAME_STRING_SEPARATOR, $newstrings);
            $valid_response = ($newvalue !== '');
        } else {
            $newvalue = trim($response, " \"");
            $valid_response = true;
        }

    } else {
        debug("openai_gpt error - empty response received from API: '" . trim($response) . "'");
    }

    foreach ($resources as $resource) {
        $valuepresent = false;
        if (!$GLOBALS["openai_gpt_overwrite_data"]) {
            $current_data = get_data_by_field($resource, $target_field["ref"]);
            if (trim((string) $current_data) !== '') {
                $valuepresent = true;
            }
        }
        if (isset($openai_gpt_processed[$resource . "_" . $target_field["ref"]]) || $valuepresent) {
            // This resource/field has already been processed, or already has data present
            continue;
        }
        if ($valid_response) {
            debug("openai_gpt_update_field() - resource # " . $resource . ", target field #" . $target_field["ref"]);
            // Set a flag to prevent any possibility of infinite recursion within update_field()
            $openai_gpt_processed[$resource . "_" . $target_field["ref"]] = true;
            $result = update_field($resource,$target_field["ref"],$newvalue);
            $results[$resource] = $result;
        } else {
            $results[$resource] = false;
        }
    }
    return $results;
    }

/**
 * Call OpenAI compatibile APIs
 *
 * Refer to https://beta.openai.com/docs/api-reference for detailed explanation
 * 
 * @param string    $endpoint           API endpoint
 * @param string    $api_key            API key 
 * @param string    $model              Model name e.g. "text-davinci-003"
 * @param array     $messages           Array of prompt messages to generate response from API.
 *                                      See https://platform.openai.com/docs/guides/chat/introduction for more information
 * @param float     $temperature        Value between 0 and 1 - higher values means model will take more risks. Default 0.
 * @param int       $max_tokens         The maximum number of completions to generate, default 2048
 * 
 * @return string   The first API response text output
 * 
 */
function openai_gpt_generate_completions($endpoint, $api_key, $model, $messages, $temperature = 0, $max_tokens = 2048)
{
    global $openai_response_cache, $userref, $ai_endpoint_connect_timeout, $ai_endpoint_timeout;

    $provider = openai_gpt_get_provider();

    debug("openai_gpt_generate_completions() \$provider=" . $provider . " \$model = '" . $model . "', \$prompt = '" . json_encode($messages) . "' \$temperature = '" . $temperature . "', \$max_tokens = " . $max_tokens);
    
    $messagestring = json_encode($messages);

    if (isset($openai_response_cache[md5($endpoint . $model . $messagestring)])) {
        return $openai_response_cache[md5($endpoint . $model . $messagestring)];
    }

    // $temperature must be between 0 and 1
    $temperature = floatval($temperature);
    if ($temperature > 1 || $temperature < 0) {
        debug("openai_gpt invalid temperature value set : '" . $temperature . "'");
        $temperature = 0;
    }

    if ($provider === "ollama") {
        // Native Ollama /api/chat — required for think:false on Qwen3.x.
        // The OpenAI-compatible /v1/chat/completions path often burns max_tokens on
        // reasoning and returns empty content.
        $endpoint = openai_gpt_ollama_native_endpoint($endpoint);
        $data = [
            "model" => $model,
            "messages" => openai_gpt_messages_to_ollama($messages),
            "stream" => false,
            "think" => false,
            "options" => [
                "temperature" => $temperature,
                "num_predict" => (int) $max_tokens,
            ],
        ];
        $headers = ["Content-Type: application/json"];
    } else {
        if ($api_key !== "") {
            debug("openai_gpt API key provided, setting headers");
            $headers = [
                "Content-Type: application/json",
                "Authorization: Bearer $api_key",
            ];
        } else {
            debug("openai_gpt no API key provided");
            $headers = [];
        }

        $data = [
            "model"       => $model,
            "messages"    => $messages,
            "temperature" => $temperature,
            "max_tokens"  => (int) $max_tokens,
        ];
    }

    // Initialize cURL
    $ch = curl_init($endpoint);
    
    // Set the options for the request
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => $ai_endpoint_connect_timeout,
        CURLOPT_TIMEOUT => $ai_endpoint_timeout,
    ]);

    // Send the request and get the response
    $response = curl_exec($ch);

    // Decode the response as JSON
    debug("openai_gpt_generate_completions original response : " . print_r($response, true));
    $response_data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        debug("openai_gpt error - invalid JSON response received from API: " . json_last_error_msg() . " " . trim($response));
        $openai_response_cache[md5($endpoint . $model . $messagestring)] = false;
        return false;
    }

    $error = $response_data["error"] ?? ($response_data["error"][0] ?? []);

    if (!empty($error)) {
        $error_type = is_array($error) ? ($error["type"] ?? "") : "";
        $error_message = is_array($error) ? ($error["message"] ?? json_encode($error)) : (string) $error;
        debug("openai_gpt_generate_completions API error - type:" . $error_type . ", message: " . $error_message);
        $openai_response_cache[md5($endpoint . $model . $messagestring)] = false;
        return false;
    }

    // Log the usage
    if (isset($response_data["usage"]["total_tokens"]) && is_numeric($response_data["usage"]["total_tokens"])) {
        if ($provider == "openai") {
            daily_stat("OpenAI Token Usage", $userref, $response_data["usage"]["total_tokens"]);
        } else {
            daily_stat("Ollama Usage", $userref, 1);
        }   
    } elseif ($provider === "ollama") {
        daily_stat("Ollama Usage", $userref, 1);
    }

    // OpenAI-compatible content path
    $return = $response_data["choices"][0]["message"]["content"] ?? null;
    // Native Ollama /api/chat content path
    if (($return === null || $return === "") && isset($response_data["message"]["content"])) {
        $return = $response_data["message"]["content"];
    }

    if ($return !== null && $return !== false) {
        // Strip accidental chain-of-thought wrappers some local models still emit.
        $return = preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', (string) $return) ?? (string) $return;
        $return = trim($return);
        if ($return === '') {
            $openai_response_cache[md5($endpoint . $model . $messagestring)] = false;
            return false;
        }
        $openai_response_cache[md5($endpoint . $model . $messagestring)] = $return;
        return $return;
    }

    return false;
}

/**
 * Map a configured Ollama endpoint to the native /api/chat URL.
 */
function openai_gpt_ollama_native_endpoint(string $endpoint): string
{
    $endpoint = trim($endpoint);
    if ($endpoint === '') {
        return 'http://127.0.0.1:11434/api/chat';
    }
    if (preg_match('#/api/chat/?$#', $endpoint)) {
        return $endpoint;
    }
    if (preg_match('#/v1/chat/completions/?$#', $endpoint)) {
        return preg_replace('#/v1/chat/completions/?$#', '/api/chat', $endpoint);
    }
    if (preg_match('#/v1/?$#', $endpoint)) {
        return preg_replace('#/v1/?$#', '/api/chat', $endpoint);
    }

    return rtrim($endpoint, '/') . '/api/chat';
}

/**
 * Convert OpenAI-style chat messages (including image_url parts) to Ollama /api/chat messages.
 *
 * @param list<array<string,mixed>> $messages
 * @return list<array<string,mixed>>
 */
function openai_gpt_messages_to_ollama(array $messages): array
{
    $converted = [];
    foreach ($messages as $message) {
        $role = (string) ($message['role'] ?? 'user');
        $content = $message['content'] ?? '';
        $images = [];
        $text_parts = [];

        if (is_array($content)) {
            foreach ($content as $part) {
                if (!is_array($part)) {
                    continue;
                }
                $type = $part['type'] ?? '';
                if ($type === 'text') {
                    $text_parts[] = (string) ($part['text'] ?? '');
                } elseif ($type === 'image_url') {
                    $image = $part['image_url'] ?? '';
                    if (is_array($image)) {
                        $image = (string) ($image['url'] ?? '');
                    }
                    $image = (string) $image;
                    if (preg_match('#^data:image/[^;]+;base64,(.+)$#s', $image, $match)) {
                        $images[] = $match[1];
                    }
                }
            }
            $text = trim(implode("\n", array_filter($text_parts, 'strlen')));
        } else {
            $text = (string) $content;
        }

        $entry = [
            'role' => $role,
            'content' => $text,
        ];
        if (count($images) > 0) {
            $entry['images'] = $images;
        }
        $converted[] = $entry;
    }

    return $converted;
}

/**
 * Return array of resource type field refs for a given openai_gpt_input_field value.
 * 
 * @param  int   $field   ID of GPT input field.
 */
function openai_gpt_get_dependent_fields(int $field): array
{
    return ps_array("SELECT ref AS `value` FROM resource_type_field WHERE openai_gpt_input_field = ?", ["i", $field]);
}

/**
 * Return an array of resource type field refs for all AI configured fields
 * 
 * @return array    Array field references for all AI configured fields 
 */
function openai_gpt_get_configured_fields(): array
{
    global $valid_ai_field_types;

    $results = [];

    $ai_fields = ps_array("SELECT ref AS `value` FROM resource_type_field WHERE openai_gpt_input_field IS NOT NULL AND openai_gpt_prompt IS NOT NULL ORDER BY ref ASC");

    foreach($ai_fields as $ai_field) {

        $ai_field_data = get_resource_type_field($ai_field);

        if(!in_array($ai_field_data["type"], $valid_ai_field_types)) {
            continue;
        } else {
            $results[] = $ai_field_data;
        }
    }

    return $results;

}

/**
 * Return a count of tokens used by GPT during the number of days passed as a parameter.
 * Defaults to using 30 days if 0 or less is passed.
 * 
 * @return int    Count of tokens used in $days days 
 */
function openai_gpt_get_tokens_used(int $days): int
{
    if ($days <= 0) {
        $days = 30;
    }
    
    clear_query_cache("gpt_token_check");

    return (int) ps_value("SELECT SUM(`count`) value 
                                FROM daily_stat 
                                WHERE activity_type = 'OpenAI Token Usage'
                                AND DATEDIFF(NOW(), CONCAT(`year`, '-', LPAD(`month`, 2, '0'), '-', LPAD(`day`, 2, '0'))) <= ?;", array("i", $days), 0, "gpt_token_check");
}

/**
 * Return the configured AI provider handles the remote override.
 * 
 * @return string    Currently configured AI provider
 */
function openai_gpt_get_provider(): string
{
    global $openai_gpt_provider_override, $openai_gpt_provider;

    return (isset($openai_gpt_provider_override) && $openai_gpt_provider_override) ? $openai_gpt_provider_override : $openai_gpt_provider;

}

/**
 * Resolve the image file path to send to the AI provider for a resource.
 *
 * Plugins (e.g. image_sequence) may override via hook openai_gpt_image_path
 * to supply a better source than the standard preview, such as a representative still.
 *
 * @return string Absolute path, or empty string if no usable image exists
 */
function openai_gpt_resolve_image_path(int $ref): string
{
    $hooked = hook('openai_gpt_image_path', '', [$ref]);
    if (is_string($hooked) && $hooked !== '' && file_exists($hooked)) {
        return $hooked;
    }

    $file = get_resource_path($ref, true, 'pre');
    if (is_string($file) && $file !== '' && file_exists($file)) {
        return $file;
    }

    return '';
}

/**
 * Turn a free-text model response into a list of short keyword strings.
 * Handles JSON failures, comma lists, and verbose Moondream-style prose.
 *
 * @return list<string>
 */
function openai_gpt_parse_keyword_list(string $response): array
{
    $response = trim($response);
    if ($response === '' || strcasecmp($response, 'none') === 0) {
        return [];
    }

    // Prefer comma / semicolon / newline separated tokens.
    $parts = preg_split('/[,;\n]+/', $response) ?: [];
    $keywords = [];
    foreach ($parts as $part) {
        $part = trim($part, " \t\n\r\0\x0B\"'`.");
        $part = preg_replace('/^\d+[\.\)]\s*/', '', $part) ?? $part;
        if ($part === '' || strcasecmp($part, 'none') === 0) {
            continue;
        }
        // Drop sentence-like chunks from verbose models.
        if (str_word_count($part) > 6 || preg_match('/\b(the|this|image|shows|features|appears)\b/i', $part)) {
            continue;
        }
        if (mb_strlen($part) > 60) {
            continue;
        }
        $keywords[] = $part;
    }

    if (count($keywords) > 0) {
        return array_values(array_unique($keywords));
    }

    // Last resort: keep a single short phrase if the whole reply is short.
    if (str_word_count($response) <= 6 && mb_strlen($response) <= 60) {
        return [$response];
    }

    return [];
}

/**
 * Create a JPEG suitable for vision APIs (max edge length), or return the original path.
 */
function openai_gpt_prepare_image_for_api(string $file, int $max_edge = 1024): string
{
    if ($file === '' || !file_exists($file)) {
        return '';
    }

    $info = @getimagesize($file);
    if (!is_array($info) || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
        return $file;
    }

    $width = (int) $info[0];
    $height = (int) $info[1];
    if ($width <= $max_edge && $height <= $max_edge && filesize($file) < 1_500_000) {
        return $file;
    }

    $mime = $info['mime'] ?? '';
    $src = null;
    if ($mime === 'image/jpeg' || preg_match('/\.jpe?g$/i', $file)) {
        $src = @imagecreatefromjpeg($file);
    } elseif ($mime === 'image/png' || preg_match('/\.png$/i', $file)) {
        $src = @imagecreatefrompng($file);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $src = @imagecreatefromwebp($file);
    } elseif ($mime === 'image/gif') {
        $src = @imagecreatefromgif($file);
    }

    if ($src === false || $src === null) {
        return $file;
    }

    $scale = min($max_edge / $width, $max_edge / $height, 1.0);
    $nw = max(1, (int) round($width * $scale));
    $nh = max(1, (int) round($height * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $width, $height);

    $tmp = get_temp_dir(false) . '/openai_gpt_' . md5($file . $max_edge . filesize($file)) . '.jpg';
    $ok = imagejpeg($dst, $tmp, 85);
    imagedestroy($src);
    imagedestroy($dst);

    return ($ok && file_exists($tmp)) ? $tmp : $file;
}

/**
 * Run all image-input AI fields for a resource (fills empty fields unless overwrite is enabled).
 *
 * @param bool $force_overwrite When true, temporarily overwrite existing AI field values
 *
 * @return array<int, bool|array> Per-field update results keyed by field ref
 */
function openai_gpt_process_image_fields(int $ref, bool $force_overwrite = false): array
{
    global $valid_ai_field_types, $openai_gpt_overwrite_data, $openai_gpt_processed;

    $file = openai_gpt_resolve_image_path($ref);
    if ($file === '') {
        debug("openai_gpt_process_image_fields - no image for resource {$ref}");
        return [];
    }

    $restore_overwrite = null;
    if ($force_overwrite) {
        $restore_overwrite = $openai_gpt_overwrite_data;
        $openai_gpt_overwrite_data = true;
        // Allow re-processing even if this request already touched the field.
        if (isset($openai_gpt_processed) && is_array($openai_gpt_processed)) {
            foreach (array_keys($openai_gpt_processed) as $key) {
                if (strpos((string) $key, $ref . '_') === 0) {
                    unset($openai_gpt_processed[$key]);
                }
            }
        }
    }

    $results = [];
    $ai_gpt_image_fields = openai_gpt_get_dependent_fields(-1);
    foreach ($ai_gpt_image_fields as $ai_gpt_image_field) {
        $field = get_resource_type_field($ai_gpt_image_field);
        if (!is_array($field) || !in_array($field['type'], $valid_ai_field_types)) {
            continue;
        }
        $updated = openai_gpt_update_field($ref, $field, [], $file);
        $results[(int) $field['ref']] = $updated[$ref] ?? $updated;
    }

    if ($restore_overwrite !== null) {
        $openai_gpt_overwrite_data = $restore_overwrite;
    }

    return $results;
}