<?php

require_once __DIR__ . '/Config.php';

function material_detail_suggestion_categories(): array
{
    return [
        'Cable & Wire',
        'Connectors & Terminals',
        'Conduit & Raceway',
        'Fasteners & Hardware',
        'Electrical Components',
        'Network Components',
        'Automation / Control Components',
        'Other',
    ];
}

function material_detail_suggestion_units(): array
{
    return ['pcs', 'meter', 'roll', 'box', 'pack', 'set', 'kg', 'liter', 'bundle', 'sheet', 'pair', 'tube'];
}

function material_detail_normalize_name(string $name): string
{
    return preg_replace('/\s+/u', ' ', trim($name)) ?? '';
}

function material_detail_name_identity(string $name): string
{
    $name = material_detail_normalize_name($name);
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

function material_detail_suggestion_valid_name(string $name): bool
{
    $name = material_detail_normalize_name($name);
    return $name !== ''
        && preg_match('/[\p{L}\p{N}]/u', $name) === 1
        && preg_match('/\p{C}/u', $name) === 0;
}

function material_detail_suggestion_response_text(array $response): string
{
    if (!empty($response['output_text']) && is_string($response['output_text'])) {
        return trim($response['output_text']);
    }

    foreach ((array)($response['output'] ?? []) as $item) {
        foreach ((array)($item['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) {
                return trim($content['text']);
            }
        }
    }

    return '';
}

function material_detail_gemini_response_text(array $response): string
{
    foreach ((array)($response['candidates'] ?? []) as $candidate) {
        foreach ((array)($candidate['content']['parts'] ?? []) as $part) {
            if (is_string($part['text'] ?? null)) {
                return trim($part['text']);
            }
        }
    }

    return '';
}

function material_detail_suggestion_parse_json(string $text): ?array
{
    $text = trim($text);
    if (str_starts_with($text, '```')) {
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? '';
    }

    $decoded = json_decode(trim($text), true);
    return is_array($decoded) ? $decoded : null;
}

function material_detail_suggest_from_ai(string $materialName): ?array
{
    $materialName = preg_replace('/\s+/', ' ', trim($materialName)) ?? '';
    if (!material_detail_suggestion_valid_name($materialName)) {
        return null;
    }

    $config = Config::getInstance();
    $provider = strtolower(trim((string)$config->get('ASSET_CATEGORY_SUGGESTION_PROVIDER')));
    $apiKey = trim((string)$config->get('ASSET_CATEGORY_SUGGESTION_API_KEY'));
    $model = trim((string)$config->get('ASSET_CATEGORY_SUGGESTION_MODEL'));
    $endpoint = trim((string)$config->get('ASSET_CATEGORY_SUGGESTION_ENDPOINT'));
    if (!in_array($provider, ['openai', 'gemini'], true)
        || $apiKey === ''
        || $model === ''
        || $endpoint === ''
        || !function_exists('curl_init')) {
        return null;
    }

    $categories = material_detail_suggestion_categories();
    $units = material_detail_suggestion_units();
    $prompt = 'Classify this possible consumable inventory material: ' . json_encode($materialName) . ".\n"
        . 'Return only JSON with keys kind, category, specific_category, unit, description. '
        . 'kind must be material or asset. Use asset for reusable tools, equipment, measuring devices, or computers. '
        . 'For material, choose the closest category from: ' . implode('; ', $categories) . '. '
        . 'Use Other only when no listed category fits. When category is Other, specific_category must be a short clear category name; otherwise it must be an empty string. '
        . 'Unit must be one of: ' . implode('; ', $units) . '. '
        . 'Description must be short, practical, uncertain details omitted, and 255 characters or less.';

    if ($provider === 'gemini') {
        $requestUrl = rtrim($endpoint, '/') . '/' . rawurlencode($model) . ':generateContent';
        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.1,
            ],
        ];
        $headers = [
            'x-goog-api-key: ' . $apiKey,
            'Content-Type: application/json',
        ];
    } else {
        $requestUrl = $endpoint;
        $payload = [
            'model' => $model,
            'store' => false,
            'input' => $prompt,
        ];
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ];
    }

    $curl = curl_init($requestUrl);
    if ($curl === false) {
        return null;
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $rawResponse = curl_exec($curl);
    $statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($rawResponse) || $statusCode < 200 || $statusCode >= 300) {
        return null;
    }

    $response = json_decode($rawResponse, true);
    $responseText = is_array($response)
        ? ($provider === 'gemini'
            ? material_detail_gemini_response_text($response)
            : material_detail_suggestion_response_text($response))
        : '';
    $details = material_detail_suggestion_parse_json($responseText);
    if (!is_array($details) || !in_array($details['kind'] ?? '', ['material', 'asset'], true)) {
        return null;
    }
    if (($details['kind'] ?? '') === 'asset') {
        return ['asset_like' => true];
    }

    $category = trim((string)($details['category'] ?? ''));
    $specificCategory = material_detail_normalize_name((string)($details['specific_category'] ?? ''));
    $unit = strtolower(trim((string)($details['unit'] ?? '')));
    $description = preg_replace('/\s+/', ' ', trim((string)($details['description'] ?? ''))) ?? '';
    if (!in_array($category, $categories, true)
        || !in_array($unit, $units, true)
        || $description === ''
        || ($category === 'Other' && ($specificCategory === '' || !material_detail_suggestion_valid_name($specificCategory)))) {
        return null;
    }
    if ($category !== 'Other') {
        $specificCategory = '';
    }
    if ((function_exists('mb_strlen') ? mb_strlen($specificCategory) : strlen($specificCategory)) > 80) {
        return null;
    }
    if ((function_exists('mb_strlen') ? mb_strlen($description) : strlen($description)) > 255) {
        return null;
    }

    return [
        'asset_like' => false,
        'category' => $category,
        'specific_category' => $specificCategory,
        'unit' => $unit,
        'description' => $description,
    ];
}
