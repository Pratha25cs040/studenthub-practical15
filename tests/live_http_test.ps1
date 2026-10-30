# Live HTTP Session & API Integration Test
$ErrorActionPreference = 'Stop'

Write-Host "--- 1. Testing GET /index.php?page=login ---"
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$loginPage = Invoke-WebRequest -Uri "http://localhost/StudentHub_Practical15/index.php?page=login" -WebSession $session -UseBasicParsing

if ($loginPage.Content -match 'name="csrf"\s+value="([^"]+)"') {
    $csrf = $matches[1]
    Write-Host "[PASS] CSRF Token Extracted: $csrf"
} else {
    Write-Host "[FAIL] Could not find CSRF token"
    exit 1
}

Write-Host "`n--- 2. Submitting POST Login credentials (admin / password) ---"
$body = @{
    action = 'login'
    identity = 'admin'
    password = 'password'
    csrf = $csrf
}

$loginResponse = Invoke-WebRequest -Uri "http://localhost/StudentHub_Practical15/index.php?page=login" `
    -Method Post `
    -Body $body `
    -WebSession $session `
    -UseBasicParsing `
    -MaximumRedirection 5

Write-Host "[PASS] Authentication submitted. Status: $($loginResponse.StatusCode)"

Write-Host "`n--- 3. Verifying Authenticated Session on /index.php?page=dashboard ---"
$dash = Invoke-WebRequest -Uri "http://localhost/StudentHub_Practical15/index.php?page=dashboard" -WebSession $session -UseBasicParsing
if ($dash.Content -like "*Welcome back, admin.*") {
    Write-Host "[PASS] Dashboard recognized logged-in administrator successfully!"
} else {
    Write-Host "[FAIL] Dashboard did not contain user greeting"
    exit 1
}

Write-Host "`n--- 4. Accessing Protected Admin Students Page ---"
$studentsPage = Invoke-WebRequest -Uri "http://localhost/StudentHub_Practical15/index.php?page=students" -WebSession $session -UseBasicParsing
if ($studentsPage.Content -like "*Student Management Directory*") {
    Write-Host "[PASS] Admin permissions verified: Students page accessible."
} else {
    Write-Host "[FAIL] Students page not accessible"
    exit 1
}

Write-Host "`n--- 5. Testing AJAX API Endpoint /api/search_students.php?q=Aarav ---"
$apiSearch = Invoke-WebRequest -Uri "http://localhost/StudentHub_Practical15/api/search_students.php?q=Aarav" -WebSession $session -UseBasicParsing
Write-Host "Response Body: $($apiSearch.Content)"
$json = $apiSearch.Content | ConvertFrom-Json
if ($json.ok -eq $true -and $json.count -ge 1) {
    Write-Host "[PASS] AJAX search returned valid JSON with student: $($json.data[0].full_name)"
} else {
    Write-Host "[FAIL] AJAX search failed"
    exit 1
}

Write-Host "`n--- 6. Testing AJAX API Endpoint /api/event_attendees.php?event_id=1 ---"
$apiRoster = Invoke-WebRequest -Uri "http://localhost/StudentHub_Practical15/api/event_attendees.php?event_id=1" -WebSession $session -UseBasicParsing
Write-Host "Response Body: $($apiRoster.Content)"
$rosterJson = $apiRoster.Content | ConvertFrom-Json
if ($rosterJson.ok -eq $true -and $rosterJson.count -ge 1) {
    Write-Host "[PASS] Event attendees roster returned valid JSON with count: $($rosterJson.count)"
} else {
    Write-Host "[FAIL] Event attendees failed"
    exit 1
}

Write-Host "`n========================================================"
Write-Host " ALL LIVE HTTP & API SESSION TESTS PASSED SUCCESSFULLY! "
Write-Host "========================================================"
