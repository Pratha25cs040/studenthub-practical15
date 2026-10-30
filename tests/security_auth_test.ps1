# Security Authorization & Access Control Verification
$ErrorActionPreference = 'Stop'

Write-Host "--- 1. Testing Unauthenticated Request to Admin API Endpoint ---"
try {
    $guestReq = Invoke-WebRequest -Uri "http://localhost/StudentHub_Practical15/api/search_students.php" -UseBasicParsing
    Write-Host "[FAIL] Guest was unexpectedly allowed access to admin API (Status $($guestReq.StatusCode))"
    exit 1
} catch {
    $status = $_.Exception.Response.StatusCode.value__
    if ($status -eq 401) {
        Write-Host "[PASS] Guest access strictly rejected with HTTP 401 Unauthorized!"
    } else {
        Write-Host "[FAIL] Unexpected status code: $status"
        exit 1
    }
}

Write-Host "`n--- 2. Testing Student Account Attempting to Access Admin API ---"
# Log in as student 'aarav'
$studentSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$loginPage = Invoke-WebRequest -Uri "http://localhost/StudentHub_Practical15/index.php?page=login" -WebSession $studentSession -UseBasicParsing
if ($loginPage.Content -match 'name="csrf"\s+value="([^"]+)"') {
    $csrf = $matches[1]
}

$body = @{
    action = 'login'
    identity = 'aarav'
    password = 'password'
    csrf = $csrf
}
$null = Invoke-WebRequest -Uri "http://localhost/StudentHub_Practical15/index.php?page=login" -Method Post -Body $body -WebSession $studentSession -UseBasicParsing -MaximumRedirection 5

try {
    # Attempt to call admin API with student session
    $headers = @{ 'X-Requested-With' = 'XMLHttpRequest' }
    $studentAttempt = Invoke-WebRequest -Uri "http://localhost/StudentHub_Practical15/api/search_students.php" -WebSession $studentSession -Headers $headers -UseBasicParsing
    Write-Host "[FAIL] Student was unexpectedly allowed access to Admin API!"
    exit 1
} catch {
    $status = $_.Exception.Response.StatusCode.value__
    if ($status -eq 403) {
        Write-Host "[PASS] Student access strictly rejected with HTTP 403 Forbidden!"
    } else {
        Write-Host "[FAIL] Unexpected status code: $status"
        exit 1
    }
}

Write-Host "`n--- 3. Testing Student Account Attempting to Access Admin Web Page ---"
try {
    $pageAttempt = Invoke-WebRequest -Uri "http://localhost/StudentHub_Practical15/index.php?page=students" -WebSession $studentSession -UseBasicParsing
    Write-Host "[FAIL] Student was allowed access to Admin Student Page!"
    exit 1
} catch {
    $status = $_.Exception.Response.StatusCode.value__
    if ($status -eq 403) {
        Write-Host "[PASS] Student access to admin web page returned HTTP 403 Forbidden!"
    } else {
        Write-Host "[FAIL] Unexpected status code: $status"
        exit 1
    }
}

Write-Host "`n========================================================"
Write-Host " ALL SECURITY AUTHORIZATION TESTS PASSED SUCCESSFULLY! "
Write-Host "========================================================"
