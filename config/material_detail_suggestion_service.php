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

function material_detail_suggestion_valid_name(string $name): bool
{
    $name = preg_replace('/\s+/', ' ', trim($name)) ?? '';
    return $name !== ''
        && preg_match('/^[\p{L}\p{N}\s\-\/\.\(\)&]+$/u', $name) === 1
        && preg_match('/\p{L}/u', $name) === 1;
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
    if ($provider !== 'openai' || $apiKey === '' || $model === '' || $endpoint === '' || !function_exists('curl_init')) {
        return null;
    }

    $categories = material_detail_suggestion_categories();
    $units = material_detail_suggestion_units();
    $prompt = 'Classify this possible consumable inventory material: ' . json_encode($materialName) . ".\n"
        . 'Use web search only as help. Return only JSON with keys kind, category, unit, description. '
        . 'kind must be material or asset. Use asset for reusable tools, equipment, measuring devices, or computers. '
        . 'For material, category must be one of: ' . implode('; ', $categories) . '. '
        . 'Unit must be one of: ' . implode('; ', $units) . '. '
        . 'Description must be short, practical, uncertain details omitted, and 255 characters or less.';

    $payload = [
        'model' => $model,
        'store' => false,
        'tools' => [['type' => 'web_search']],
        'input' => $prompt,
    ];
    $curl = curl_init($endpoint);
    if ($curl === false) {
        return null;
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
    ]);
    $rawResponse = curl_exec($curl);
    $statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($rawResponse) || $statusCode < 200 || $statusCode >= 300) {
        return null;
    }

    $response = json_decode($rawResponse, true);
    $details = is_array($response)
        ? material_detail_suggestion_parse_json(material_detail_suggestion_response_text($response))
        : null;
    if (!is_array($details) || !in_array($details['kind'] ?? '', ['material', 'asset'], true)) {
        return null;
    }
    if (($details['kind'] ?? '') === 'asset') {
        return ['asset_like' => true];
    }

    $category = trim((string)($details['category'] ?? ''));
    $unit = strtolower(trim((string)($details['unit'] ?? '')));
    $description = preg_replace('/\s+/', ' ', trim((string)($details['description'] ?? ''))) ?? '';
    if (!in_array($category, $categories, true) || !in_array($unit, $units, true) || $description === '') {
        return null;
    }
    if ((function_exists('mb_strlen') ? mb_strlen($description) : strlen($description)) > 255) {
        return null;
    }

    return [
        'asset_like' => false,
        'category' => $category,
        'unit' => $unit,
        'description' => $description,
    ];
}
