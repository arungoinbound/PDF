<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

// Add these lines here:
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

use Mpdf\Mpdf;

function getHubspotAuthHeader(): array
{
    $envPath = __DIR__ . '/.env';
    $accessToken = '';

    // Manually parse the file into an array
    if (file_exists($envPath)) {
        $env = parse_ini_file($envPath);
        $accessToken = $env['HUBSPOT_ACCESS_TOKEN'] ?? '';
    }

    // Fallback: Check getenv if file parsing failed
    if (empty($accessToken)) {
        $accessToken = getenv('HUBSPOT_ACCESS_TOKEN');
    }

    if (!empty($accessToken)) {
        // trim removes any accidental quotes or spaces
        return ['Authorization: Bearer ' . trim($accessToken, " \t\n\r\0\x0B\"'")];
    }

    die('HubSpot API credentials not found in ' . $envPath . ' or system environment.');
}

function hubspotApiGet(string $url, array $query = []): array
{
    $apiKey = getenv('HUBSPOT_API_KEY');
    $headers = getHubspotAuthHeader();

    if (!empty($apiKey)) {
        $query['hapikey'] = $apiKey;
    }

    $queryString = http_build_query($query);
    if (!empty($queryString)) {
        $url .= '?' . $queryString;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        die('HubSpot request failed: ' . $error);
    }

    $data = json_decode($response, true);
    if ($status >= 400 || json_last_error() !== JSON_ERROR_NONE) {
        die('HubSpot API error: HTTP ' . $status . ' - ' . $response);
    }

    return $data;
}

function fetchObjectProperties(string $objectType, string $objectId, array $properties): array
{
    $url = 'https://api.hubapi.com/crm/v3/objects/' . rawurlencode($objectType) . '/' . rawurlencode($objectId);
    $query = ['properties' => implode(',', $properties)];
    $result = hubspotApiGet($url, $query);
    return $result['properties'] ?? [];
}

function getRequestData(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $rawBody = file_get_contents('php://input');
        $decoded = json_decode($rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }

    return $_POST;
}

$postedData = getRequestData();
$objectId = trim($postedData['objectId'] ?? $postedData['object_id'] ?? '');
$objectType = trim($postedData['objectType'] ?? $postedData['object_type'] ?? '');

if (empty($objectId) || empty($objectType)) {
    die('Please provide objectId and objectType via POST or JSON body.');
}

$properties = ['first_name', 'last_name', 'name', 'number_of_units_9m_each', 'booth_type', 'coexhibitor_name'];
$objectProperties = fetchObjectProperties($objectType, $objectId, $properties);

$template = file_get_contents(__DIR__ . '/confirmation_letter_template.html');
$replacements = [
    '{{first_name}}' => $objectProperties['first_name'] ?? '',
    '{{last_name}}' => $objectProperties['last_name'] ?? '',
    '{{name}}' => $objectProperties['name'] ?? '',
    '{{number_of_units_9m_each}}' => $objectProperties['number_of_units_9m_each'] ?? '',
    '{{booth_type}}' => $objectProperties['booth_type'] ?? '',
];

$html = str_replace(array_keys($replacements), array_values($replacements), $template);

$mpdf = new Mpdf([
    'tempDir' => '/tmp/mpdf',
    'mode' => 'utf-8',
    'format' => 'A4',
]);

$mpdf->WriteHTML($html);
$filename = 'siggraph_confirmation_letter.pdf';
$mpdf->Output($filename, 'D');
