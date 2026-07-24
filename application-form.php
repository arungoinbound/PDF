<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Mpdf\Mpdf;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();


// Check if data is posted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid request method. Please submit form data via POST.');
}

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

function fetchAssociationId(string $objectType, string $objectId, string $associationType): ?string
{
    $url = 'https://api.hubapi.com/crm/v3/objects/' . rawurlencode($objectType) . '/' . rawurlencode($objectId) . '/associations/' . rawurlencode($associationType);
    $result = hubspotApiGet($url, ['limit' => 1]);

    return $result['results'][0]['id'] ?? null;
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

function formatFieldValue(string $fieldName, string $value): string
{
    $checkboxFields = [
        'booth_type',
        'premium_marketing_package',
        'if_different_from_the_name_on_step_1__main_company__contact_information',
    ];

    if (!in_array($fieldName, $checkboxFields, true)) {
        return nl2br(htmlspecialchars($value));
    }

    if ($value === '') {
        return '<div class="field-placeholder">Not selected</div>';
    }

    $values = preg_split('/[;,\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (count($values) === 1) {
        $normalized = strtolower(trim($values[0]));
        if (in_array($normalized, ['true', 'yes', 'y', '1'], true)) {
            return '<ul class="checkbox-list"><li>Yes</li></ul>';
        }
        if (in_array($normalized, ['false', 'no', 'n', '0'], true)) {
            return '<ul class="checkbox-list"><li>No</li></ul>';
        }
    }

    $html = '<ul class="checkbox-list">';
    foreach ($values as $item) {
        $labelText = htmlspecialchars(trim($item));
        if ($labelText === '') {
            continue;
        }
        $html .= '<li>' . $labelText . '</li>';
    }
    $html .= '</ul>';

    return $html;
}

$postedData = getRequestData();
$objectId = trim($postedData['objectId'] ?? $postedData['object_id'] ?? '');
$objectType = trim($postedData['objectType'] ?? $postedData['object_type'] ?? '');

$orderedFields = [
    ['key' => '0-1/type', 'label' => 'Type', 'source' => 'contact', 'property' => 'type'],
    ['key' => '0-1/firstname', 'label' => 'First Name', 'source' => 'contact', 'property' => 'firstname'],
    ['key' => '0-1/lastname', 'label' => 'Last Name', 'source' => 'contact', 'property' => 'lastname'],
    ['key' => '0-1/email', 'label' => 'Email', 'source' => 'contact', 'property' => 'email'],
    ['key' => '0-1/job_title', 'label' => 'Job Title', 'source' => 'contact', 'property' => 'job_title'],
    ['key' => '0-1/phone', 'label' => 'Phone', 'source' => 'contact', 'property' => 'phone'],
    ['key' => '0-2/name', 'label' => 'Company Name', 'source' => 'company', 'property' => 'name'],
    ['key' => '0-2/website', 'label' => 'Company Website', 'source' => 'company', 'property' => 'website'],
    ['key' => '0-2/address', 'label' => 'Company Address', 'source' => 'company', 'property' => 'address'],
    ['key' => '0-2/city', 'label' => 'Company City', 'source' => 'company', 'property' => 'city'],
    ['key' => '0-2/country_dropdown', 'label' => 'Company Country', 'source' => 'company', 'property' => 'country_dropdown'],
    ['key' => '0-1/booth_type', 'label' => 'Booth Type', 'source' => 'contact', 'property' => 'booth_type'],
    ['key' => '0-1/number_of_units_9m_each', 'label' => 'Number of Units (9m each)', 'source' => 'contact', 'property' => 'number_of_units_9m_each'],
    ['key' => '0-1/total_square_meters', 'label' => 'Total Square Meters', 'source' => 'contact', 'property' => 'total_square_meters'],
    ['key' => '0-1/rate_applied', 'label' => 'Rate Applied', 'source' => 'contact', 'property' => 'rate_applied'],
    ['key' => '0-1/premium_marketing_package', 'label' => 'Premium Marketing Package', 'source' => 'contact', 'property' => 'premium_marketing_package'],
    ['key' => '0-1/additional_exhibitor_badges', 'label' => 'Additional Exhibitor Badges', 'source' => 'contact', 'property' => 'additional_exhibitor_badges'],
    ['key' => '0-2/if_different_from_the_name_on_step_1__main_company__contact_information', 'label' => 'if different from the name on Step 1 ( Main Company & Contact Information )', 'source' => 'contact', 'property' => 'if_different_from_the_name_on_step_1__main_company__contact_information'],
    ['key' => '0-2/official_listing_name', 'label' => 'Official Listing Name', 'source' => 'company', 'property' => 'official_listing_name'],
    ['key' => '0-2/country_for_listing_dropdown', 'label' => 'Country for Listing', 'source' => 'company', 'property' => 'country_for_listing_dropdown'],
    ['key' => '0-2/looking_for_agents_in', 'label' => 'Looking for Agents In Text', 'source' => 'company', 'property' => 'looking_for_agents_in'],
    ['key' => 'LEGAL_CONSENT.subscription_type_536049909', 'label' => 'Subscription Type Consent', 'source' => 'contact', 'property' => 'LEGAL_CONSENT.subscription_type_536049909'],
    ['key' => 'LEGAL_CONSENT.processing', 'label' => 'Processing Consent', 'source' => 'contact', 'property' => 'LEGAL_CONSENT.processing'],
    ['key' => '0-1/authorized_signature', 'label' => 'Authorized Signature Name', 'source' => 'contact', 'property' => 'authorized_signature'],
    ['key' => '0-1/date_of_submission', 'label' => 'Date of Submission', 'source' => 'contact', 'property' => 'date_of_submission']
];

$propertyNames = array_unique(array_map(function ($field) {
    return $field['property'];
}, $orderedFields));

$contactProperties = [];
$companyProperties = [];
$debugInfo = [
    'object_id' => $objectId,
    'object_type' => $objectType,
    'posted_fields' => array_keys($postedData),
];

if (!empty($objectId) && !empty($objectType)) {
    $contactId = fetchAssociationId($objectType, $objectId, 'contacts');
    $debugInfo['associated_contact_id'] = $contactId;
    if (!empty($contactId)) {
        $contactProperties = fetchObjectProperties('contacts', $contactId, $propertyNames);
        $debugInfo['contact_properties_count'] = count($contactProperties);
        $debugInfo['contact_properties'] = $contactProperties;
    }

    $companyId = fetchAssociationId($objectType, $objectId, 'companies');
    $debugInfo['associated_company_id'] = $companyId;
    if (!empty($companyId)) {
        $companyProperties = fetchObjectProperties('companies', $companyId, $propertyNames);
        $debugInfo['company_properties_count'] = count($companyProperties);
        $debugInfo['company_properties'] = $companyProperties;
    }
} else {
    $debugInfo['warning'] = 'No objectId or objectType provided. Only posted data will be used.';
}

$html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SIGGRAPH Asia 2026</title>
    <style>
    * {
    box-sizing: border-box;
}
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            color: #333;
        }
        h1 {
            color: #0056b3;
            border-bottom: 2px solid #0056b3;
            padding-bottom: 10px;
        }
       
    .review-section {
    border: 1px solid #ECEDEF;
    border-radius: 10px;
    padding: 18px 20px;
    margin-bottom: 12px;
    background: #FFFFFF;
}
    .review-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
    .review-grid {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 8px 18px;
    font-size: 13px;
}
    .review-grid dt {
    color: #8C8F93;
    font-size: 11.5px;
    text-transform: uppercase;
    letter-spacing: .04em;
    font-weight: 600;
    padding-top: 1px;
    align-content: center;
}
    .review-grid dd {
    margin: 0;
    color: #1F2123;
    font-weight: 500;
    align-content: center;
}
        .checkbox-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .checkbox-list li {
            position: relative;
            margin-bottom: 6px;
            padding-left: 18px;
            font-size: 13px;
            line-height: 1.45;
        }
        .checkbox-list li::before {
            content: "•";
            position: absolute;
            left: 0;
            top: 0;
            color: #0056b3;
            font-weight: bold;
        }
        .field-placeholder {
            color: #666;
            font-style: italic;
        }
        .section {
            padding: 16px;
            border: 1px solid #e1e4e8;
            border-radius: 8px;
            background: #f8f9fb;
            margin-bottom: 20px;
        }
        .no-data {
            text-align: center;
            color: #666;
            font-style: italic;
            margin-top: 50px;
        }
    </style>
</head>
<body>
    <h1>SIGGRAPH Asia 2026 - Application Form</h1>';

$hasData = false;
$html .= '<div class="review-section"> <div class="review-head"><h4>Applicant Information</h4></div>';
foreach ($orderedFields as $field) {
    $value = '';
    if ($field['source'] === 'contact') {
        $value = $contactProperties[$field['property']] ?? '';
    } elseif ($field['source'] === 'company') {
        $value = $companyProperties[$field['property']] ?? '';
    }

    if ($value !== '') {
        $hasData = true;
    }
    $html .= '<dl class="review-grid">';
    $html .= '<dt class="field-label">' . htmlspecialchars($field['label']) . '</dt>';
if ($field['property'] === 'authorized_signature' && !empty($value)) {
    // Render image instead of text
    $html .= '<dd class="field-value">
                <img src="' . htmlspecialchars($value) . '" style="max-height:150px;">
              </dd>';
} else {
    $html .= '<dd class="field-value">' . formatFieldValue($field['property'], $value) . '</dd>';
}

    
    $html .= '</dl>';
}
$html .= '</div>';

if (!$hasData) {
    $html .= '<div class="no-data">No associated contact or company data available for this object.</div>';
}

$html .= '</body></html>';

$mpdf = new Mpdf([
    'tempDir' => __DIR__ . '/tmp'
]);
$mpdf->WriteHTML($html);
$filename = 'hubspot_form_' . date('Y-m-d_H-i-s') . '.pdf';
$mpdf->Output($filename, 'D');
?>
