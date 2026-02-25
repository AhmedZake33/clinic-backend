<?php
$ch = curl_init('http://eservices.edaegypt.gov.eg/EDASearch/SearchRegDrugs.aspx');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
curl_setopt($ch, CURLOPT_COOKIEJAR, sys_get_temp_dir() . '/eda_cookies.txt');
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "HTML Length: " . strlen($html) . "\n\n";

// Extract all input fields with their attributes
preg_match_all('/<input[^>]+>/i', $html, $allInputs);
echo "=== ALL INPUT TAGS ===\n";
foreach($allInputs[0] as $input) {
    preg_match('/name="([^"]+)"/', $input, $name);
    preg_match('/type="([^"]+)"/', $input, $type);
    preg_match('/value="([^"]*)"/', $input, $value);
    preg_match('/id="([^"]+)"/', $input, $id);
    if (isset($name[1])) {
        $n = $name[1];
        $t = $type[1] ?? 'text';
        $v = isset($value[1]) ? substr($value[1], 0, 50) : '';
        $i = $id[1] ?? '';
        echo "  name=$n | type=$t | id=$i | value=$v\n";
    }
}

// Extract ViewState
if (preg_match('/id="__VIEWSTATE"\s*value="([^"]+)"/', $html, $vs)) {
    echo "\n__VIEWSTATE length: " . strlen($vs[1]) . "\n";
}
if (preg_match('/id="__VIEWSTATEGENERATOR"\s*value="([^"]+)"/', $html, $vsg)) {
    echo "__VIEWSTATEGENERATOR: " . $vsg[1] . "\n";
}
if (preg_match('/id="__EVENTVALIDATION"\s*value="([^"]+)"/', $html, $ev)) {
    echo "__EVENTVALIDATION length: " . strlen($ev[1]) . "\n";
}

// Extract select fields
preg_match_all('/<select[^>]*name="([^"]+)"[^>]*>/', $html, $selects);
echo "\n=== SELECT FIELDS ===\n";
foreach($selects[1] as $s) echo "  $s\n";

// Check for radio buttons
preg_match_all('/<input[^>]*type="radio"[^>]*>/', $html, $radios);
echo "\n=== RADIO BUTTONS (" . count($radios[0]) . ") ===\n";
foreach($radios[0] as $r) {
    preg_match('/name="([^"]+)"/', $r, $name);
    preg_match('/value="([^"]+)"/', $r, $value);
    $checked = strpos($r, 'checked') !== false ? ' (checked)' : '';
    echo "  name=" . ($name[1] ?? '') . " value=" . ($value[1] ?? '') . $checked . "\n";
}

// Check for CAPTCHA
echo "\n=== CAPTCHA ===\n";
if (preg_match('/CImage/', $html)) echo "CAPTCHA image found\n";
preg_match('/CImage\.aspx[^"]*/', $html, $captchaUrl);
echo "CAPTCHA URL: " . ($captchaUrl[0] ?? 'not found') . "\n";

// Look for the text input for captcha
if (preg_match('/captcha|Captcha|CAPTCHA|txtCaptcha|CaptchaValue/', $html, $capField)) {
    echo "Captcha field ref: " . $capField[0] . "\n";
}
