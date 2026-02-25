<?php
// Check what error appears when captcha is wrong

$cookieFile = sys_get_temp_dir() . '/eda_cookies_' . uniqid() . '.txt';
$baseUrl = 'http://eservices.edaegypt.gov.eg/EDASearch/SearchRegDrugs.aspx';

// Step 1: GET page
$ch = curl_init($baseUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$html = curl_exec($ch);
curl_close($ch);

preg_match('/id="__VIEWSTATE"\s*value="([^"]*)"/', $html, $vs);
preg_match('/id="__VIEWSTATEGENERATOR"\s*value="([^"]*)"/', $html, $vsg);
preg_match('/id="__EVENTVALIDATION"\s*value="([^"]*)"/', $html, $ev);

// POST with wrong captcha
$postData = http_build_query([
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
    'ctl00$ContentPlaceHolder1$txtimgcode' => 'WRONG',
    'ctl00$ContentPlaceHolder1$ui_btnSearch' => 'Search',
]);

$ch = curl_init($baseUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
    'Referer: ' . $baseUrl,
]);
$resultHtml = curl_exec($ch);
curl_close($ch);

// Search for any label/span that contains error/message text
preg_match_all('/<span[^>]*id="[^"]*(?:lbl|Label|msg|error|Error|Msg)[^"]*"[^>]*>([^<]*)<\/span>/i', $resultHtml, $spans);
echo "=== SPAN MESSAGES ===\n";
foreach($spans[0] as $i => $full) {
    if (trim($spans[1][$i]) !== '') {
        echo "  " . $spans[1][$i] . "\n";
    }
}

// Search for script alerts
preg_match_all('/alert\(([^)]+)\)/', $resultHtml, $alerts);
echo "\n=== SCRIPT ALERTS ===\n";
foreach($alerts[1] as $a) echo "  $a\n";

// Check if grid exists in result (would mean search worked)
echo "\n=== GRID CHECK ===\n";
if (preg_match('/ui_gvDrug|gvResult|GridView/', $resultHtml)) {
    echo "Grid/Table found in response\n";
} else {
    echo "No grid/table in response\n";
}

// Look for any div with error class
preg_match_all('/<div[^>]*class="[^"]*error[^"]*"[^>]*>(.*?)<\/div>/si', $resultHtml, $errorDivs);
echo "\n=== ERROR DIVS ===\n";
foreach($errorDivs[1] as $d) echo "  " . strip_tags(trim($d)) . "\n";

// Check for difference: does the response have more content (table)?
$hasTable = preg_match('/<table[^>]*id="[^"]*gv/', $resultHtml);
echo "\nHas result table: " . ($hasTable ? 'YES' : 'NO') . "\n";

// Get portion around "imgcode" or captcha area in response
if (preg_match('/txtimgcode.{0,300}/s', $resultHtml, $captchaArea)) {
    echo "\n=== AREA AROUND CAPTCHA FIELD ===\n";
    echo strip_tags($captchaArea[0]) . "\n";
}

@unlink($cookieFile);
