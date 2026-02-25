<?php
// Test EDA search by:
// 1. GET page → get ViewState + cookies + CAPTCHA image
// 2. POST with form data + CAPTCHA

$cookieFile = sys_get_temp_dir() . '/eda_cookies_' . uniqid() . '.txt';
$baseUrl = 'http://eservices.edaegypt.gov.eg/EDASearch/SearchRegDrugs.aspx';

// Step 1: GET page
$ch = curl_init($baseUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$html = curl_exec($ch);
curl_close($ch);

// Extract form tokens
preg_match('/id="__VIEWSTATE"\s*value="([^"]*)"/', $html, $vs);
preg_match('/id="__VIEWSTATEGENERATOR"\s*value="([^"]*)"/', $html, $vsg);
preg_match('/id="__EVENTVALIDATION"\s*value="([^"]*)"/', $html, $ev);

echo "Got ViewState: " . strlen($vs[1] ?? '') . " chars\n";
echo "Got EventValidation: " . strlen($ev[1] ?? '') . " chars\n";

// Step 2: GET CAPTCHA image (same session)  
$ch = curl_init('http://eservices.edaegypt.gov.eg/EDASearch/CImage.aspx');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$captchaImage = curl_exec($ch);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

echo "Captcha image size: " . strlen($captchaImage) . " bytes\n";
echo "Captcha content type: $contentType\n";
echo "Captcha base64 (first 100): " . substr(base64_encode($captchaImage), 0, 100) . "...\n";

// Step 3: Try search WITHOUT captcha to see what happens
$postData = [
    '__EVENTTARGET' => '',
    '__EVENTARGUMENT' => '',
    '__LASTFOCUS' => '',
    '__VIEWSTATE' => $vs[1] ?? '',
    '__VIEWSTATEGENERATOR' => $vsg[1] ?? '',
    '__VIEWSTATEENCRYPTED' => '',
    '__EVENTVALIDATION' => $ev[1] ?? '',
    'ctl00$ContentPlaceHolder1$ui_rdDrugType' => '1',
    'ctl00$ContentPlaceHolder1$ui_txtTradeName' => 'panadol',
    'ctl00$ContentPlaceHolder1$ui_txtRegNo' => '',
    'ctl00$ContentPlaceHolder1$ui_txtApplicant' => '',
    'ctl00$ContentPlaceHolder1$ui_txtGeneric' => '',
    'ctl00$ContentPlaceHolder1$txtimgcode' => 'TEST123',
    'ctl00$ContentPlaceHolder1$ui_btnSearch' => 'Search',
];

$ch = curl_init($baseUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
    'Referer: ' . $baseUrl,
    'Origin: http://eservices.edaegypt.gov.eg',
]);
$resultHtml = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nSearch result HTTP: $httpCode\n";
echo "Result HTML length: " . strlen($resultHtml) . "\n";

// Check for error message about captcha
if (preg_match('/lblError[^>]*>([^<]+)</', $resultHtml, $error)) {
    echo "Error: " . $error[1] . "\n";
}
if (preg_match('/lblmsg[^>]*>([^<]+)</', $resultHtml, $msg)) {
    echo "Message: " . $msg[1] . "\n";
}

// Check if there's a grid/table with results
if (preg_match('/ui_gv(Drug|Result)/', $resultHtml, $grid)) {
    echo "Grid found: " . $grid[0] . "\n";
}
if (preg_match_all('/<tr[^>]*class="[^"]*Row[^"]*"[^>]*>/', $resultHtml, $rows)) {
    echo "Data rows: " . count($rows[0]) . "\n";
}

// Check for validation error or captcha error
if (stripos($resultHtml, 'invalid') !== false || stripos($resultHtml, 'خطأ') !== false) {
    echo "Contains 'invalid' or error text\n";
}
if (stripos($resultHtml, 'captcha') !== false || stripos($resultHtml, 'code') !== false) {
    echo "Contains captcha-related text\n";
}

// Look for any alert/script indicating error
preg_match_all('/alert\(["\']([^"\']+)["\']\)/', $resultHtml, $alerts);
if (!empty($alerts[1])) {
    echo "Alerts: " . implode(', ', $alerts[1]) . "\n";
}

// Clean up
@unlink($cookieFile);
