[CmdletBinding()]
param(
    [switch]$VerboseOutput
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path -LiteralPath (Join-Path -Path $PSScriptRoot -ChildPath '..')).Path
$failures = [System.Collections.Generic.List[string]]::new()

$requiredFiles = @(
    'README.md',
    'docs/vertical-slice.md',
    'docs/runtime-staging-checklist.md',
    'tests/acceptance-criteria.md',
    'tests/verify-baseline.ps1',
    'tests/verify-release.ps1',
    'fandoogh-manager/fandoogh-manager.php',
    'fandoogh-manager/app/security.php',
    'fandoogh-manager/app/products.php',
    'fandoogh-manager/app/attributes.php',
    'fandoogh-manager/app/taxonomy.php',
    'fandoogh-manager/app/variations.php',
    'fandoogh-manager/app/customers.php',
    'fandoogh-manager/app/orders.php',
    'fandoogh-manager/app/shipping.php',
    'fandoogh-manager/app/media.php',
    'fandoogh-manager/app/fonts.php',
    'fandoogh-manager/app/analytics.php',
    'fandoogh-manager/app/coupons.php',
    'fandoogh-manager/app/reviews.php',
    'fandoogh-manager/app/inventory.php',
    'fandoogh-manager/assets/css/admin.css',
    'fandoogh-manager/manifest.webmanifest',
    'fandoogh-manager/app/index.html',
    'fandoogh-manager/app/app.js',
    'fandoogh-manager/app/styles.css',
    'fandoogh-manager/app/sw.js'
)

$sourceFiles = @(
    'fandoogh-manager/fandoogh-manager.php',
    'fandoogh-manager/app/security.php',
    'fandoogh-manager/app/products.php',
    'fandoogh-manager/app/attributes.php',
    'fandoogh-manager/app/taxonomy.php',
    'fandoogh-manager/app/variations.php',
    'fandoogh-manager/app/customers.php',
    'fandoogh-manager/app/orders.php',
    'fandoogh-manager/app/shipping.php',
    'fandoogh-manager/app/media.php',
    'fandoogh-manager/app/fonts.php',
    'fandoogh-manager/app/analytics.php',
    'fandoogh-manager/app/coupons.php',
    'fandoogh-manager/app/reviews.php',
    'fandoogh-manager/app/inventory.php',
    'fandoogh-manager/assets/css/admin.css',
    'fandoogh-manager/manifest.webmanifest',
    'fandoogh-manager/app/index.html',
    'fandoogh-manager/app/app.js',
    'fandoogh-manager/app/styles.css',
    'fandoogh-manager/app/sw.js'
)

foreach ($relativePath in $requiredFiles) {
    $absolutePath = Join-Path -Path $repoRoot -ChildPath ($relativePath -replace '/', '\')
    if (-not (Test-Path -LiteralPath $absolutePath -PathType Leaf)) {
        $failures.Add("Required file missing: $relativePath")
    }
}

foreach ($relativePath in $sourceFiles) {
    $absolutePath = Join-Path -Path $repoRoot -ChildPath ($relativePath -replace '/', '\')
    if (-not (Test-Path -LiteralPath $absolutePath -PathType Leaf)) {
        $failures.Add("Source file missing: $relativePath")
    }
}

function Get-SourceText([string]$relativePath) {
    $absolutePath = Join-Path -Path $repoRoot -ChildPath ($relativePath -replace '/', '\')
    if (-not (Test-Path -LiteralPath $absolutePath -PathType Leaf)) { return '' }
    return Get-Content -LiteralPath $absolutePath -Raw -Encoding UTF8
}

$pluginText = Get-SourceText 'fandoogh-manager/fandoogh-manager.php'
$securityText = Get-SourceText 'fandoogh-manager/app/security.php'
$productsText = Get-SourceText 'fandoogh-manager/app/products.php'
$attributesText = Get-SourceText 'fandoogh-manager/app/attributes.php'
$taxonomyText = Get-SourceText 'fandoogh-manager/app/taxonomy.php'
$variationsText = Get-SourceText 'fandoogh-manager/app/variations.php'
$customersText = Get-SourceText 'fandoogh-manager/app/customers.php'
$ordersText = Get-SourceText 'fandoogh-manager/app/orders.php'
$shippingText = Get-SourceText 'fandoogh-manager/app/shipping.php'
$mediaText = Get-SourceText 'fandoogh-manager/app/media.php'
$fontsText = Get-SourceText 'fandoogh-manager/app/fonts.php'
$analyticsText = Get-SourceText 'fandoogh-manager/app/analytics.php'
$couponsText = Get-SourceText 'fandoogh-manager/app/coupons.php'
$reviewsText = Get-SourceText 'fandoogh-manager/app/reviews.php'
$inventoryText = Get-SourceText 'fandoogh-manager/app/inventory.php'
$adminCssText = Get-SourceText 'fandoogh-manager/assets/css/admin.css'
$appText = Get-SourceText 'fandoogh-manager/app/app.js'
$serviceWorkerText = Get-SourceText 'fandoogh-manager/app/sw.js'
$indexText = Get-SourceText 'fandoogh-manager/app/index.html'
$acceptanceText = Get-SourceText 'tests/acceptance-criteria.md'
$tomanLabel = ([char]0x62a) + ([char]0x648) + ([char]0x645) + ([char]0x627) + ([char]0x646)

$requiredContractFragments = @(
    @{ Name = 'REST route registration'; Text = 'register_rest_route' ; Source = $pluginText },
    @{ Name = 'health route'; Text = "'/health'" ; Source = $pluginText },
    @{ Name = 'config route'; Text = "'/config'" ; Source = $pluginText },
    @{ Name = 'manager app rewrite'; Text = 'fandoogh_manager_app'; Source = $pluginText },
    @{ Name = 'asset rewrite'; Text = 'fandoogh_manager_asset'; Source = $pluginText },
    @{ Name = 'same-origin config endpoint'; Text = 'fandoogh-config-url'; Source = $pluginText },
    @{ Name = 'service worker registration'; Text = 'serviceWorker.register'; Source = $appText },
    @{ Name = 'API cache exclusion'; Text = 'wp-json'; Source = $serviceWorkerText },
    @{ Name = 'pair route'; Text = "'/auth/pair'"; Source = $securityText },
    @{ Name = 'csrf route'; Text = "'/auth/csrf'"; Source = $securityText },
    @{ Name = 'logout route'; Text = "'/auth/logout'"; Source = $securityText },
    @{ Name = 'device route'; Text = "'/auth/devices'"; Source = $securityText },
    @{ Name = 'device revoke'; Text = 'revoke_manager_device'; Source = $securityText },
    @{ Name = 'audit route'; Text = "'/auth/audit'"; Source = $securityText },
    @{ Name = 'audit recorder'; Text = 'record_audit_event'; Source = $securityText },
    @{ Name = 'audit schema'; Text = "SECURITY_SCHEMA_VERSION = '2'"; Source = $securityText },
    @{ Name = 'pairing target selector'; Text = 'fandoogh_manager_pairing_user_id'; Source = $securityText },
    @{ Name = 'pairing target capability boundary'; Text = 'pairing_target_user'; Source = $securityText },
    @{ Name = 'pairing scope intersection'; Text = 'intersect_pairing_scopes'; Source = $securityText },
    @{ Name = 'pairing issuance audit'; Text = 'pairing_issued'; Source = $securityText },
    @{ Name = 'products route'; Text = "'/products'"; Source = $productsText },
    @{ Name = 'products write permission'; Text = 'products_write_permission'; Source = $productsText },
    @{ Name = 'product attributes route'; Text = "'/product-attributes'"; Source = $attributesText },
    @{ Name = 'variable product CRUD'; Text = 'WC_Product_Variable'; Source = $productsText },
    @{ Name = 'product attribute allowlist'; Text = 'product_write_attributes'; Source = $productsText },
    @{ Name = 'categories route'; Text = "'/product-categories'"; Source = $taxonomyText },
    @{ Name = 'categories write permission'; Text = 'product_categories_write_permission'; Source = $taxonomyText },
    @{ Name = 'variation route'; Text = '/variations'; Source = $variationsText },
    @{ Name = 'variation CRUD'; Text = 'WC_Product_Variation'; Source = $variationsText },
    @{ Name = 'customers route'; Text = "'/customers'"; Source = $customersText },
    @{ Name = 'customers read permission'; Text = 'customers_read_permission'; Source = $customersText },
    @{ Name = 'customer data store'; Text = 'WC_Customer_Data_Store'; Source = $customersText },
    @{ Name = 'orders route'; Text = "'/orders'"; Source = $ordersText },
    @{ Name = 'orders read permission'; Text = 'orders_read_permission'; Source = $ordersText },
    @{ Name = 'order status mutation'; Text = 'update_order_status'; Source = $ordersText },
    @{ Name = 'order status verification'; Text = 'orders_status_matches'; Source = $ordersText },
    @{ Name = 'order shipping lines'; Text = 'serialize_order_shipping_lines'; Source = $ordersText },
    @{ Name = 'shipment route'; Text = "'/orders/(?P<id>\\d+)/shipment'"; Source = $shippingText },
    @{ Name = 'shipment read permission'; Text = 'shipment_read_permission'; Source = $shippingText },
    @{ Name = 'shipment write permission'; Text = 'shipment_write_permission'; Source = $shippingText },
    @{ Name = 'shipment allowlist'; Text = 'shipping_allowed_statuses'; Source = $shippingText },
    @{ Name = 'shipment audit'; Text = 'shipment_updated'; Source = $shippingText },
    @{ Name = 'session cookie'; Text = 'SESSION_COOKIE_NAME'; Source = $securityText },
    @{ Name = 'same-origin credentials'; Text = 'credentials: "same-origin"'; Source = $appText },
    @{ Name = 'order mutation message'; Text = 'ordersMutationMessage'; Source = $appText },
    @{ Name = 'order status confirmation'; Text = 'confirmOrderStatus'; Source = $appText },
    @{ Name = 'order request guard'; Text = 'loadRequestId'; Source = $appText },
    @{ Name = 'Persian digit formatter'; Text = 'function toPersianDigits'; Source = $appText },
    @{ Name = 'Jalali date locale'; Text = 'PERSIAN_DATE_LOCALE'; Source = $appText },
    @{ Name = 'Media upload timeout'; Text = 'MEDIA_UPLOAD_TIMEOUT_MS'; Source = $appText },
    @{ Name = 'media route'; Text = "'/media'"; Source = $mediaText },
    @{ Name = 'webp conversion'; Text = 'convert_media_to_webp'; Source = $mediaText },
    @{ Name = 'Media filename fallback'; Text = 'fandoogh-image.'; Source = $mediaText },
    @{ Name = 'WebP save diagnostic'; Text = 'fandoogh_webp_save'; Source = $mediaText },
    @{ Name = 'local font signature check'; Text = 'fandoogh_font_signature'; Source = $fontsText },
    @{ Name = 'analytics module include'; Text = "app/analytics.php"; Source = $pluginText },
    @{ Name = 'analytics route'; Text = "'/analytics/summary'"; Source = $analyticsText },
    @{ Name = 'analytics permission'; Text = 'analytics_read_permission'; Source = $analyticsText },
    @{ Name = 'analytics scope'; Text = "'analytics.read'"; Source = $analyticsText },
    @{ Name = 'analytics setting'; Text = "'analytics_enabled' => false"; Source = $pluginText },
    @{ Name = 'coupon module'; Text = 'register_coupon_routes'; Source = $pluginText },
    @{ Name = 'coupon REST route'; Text = "'/coupons'"; Source = $couponsText },
    @{ Name = 'review module'; Text = 'register_review_routes'; Source = $pluginText },
    @{ Name = 'review moderation'; Text = 'wp_set_comment_status'; Source = $reviewsText },
    @{ Name = 'inventory module'; Text = 'register_inventory_routes'; Source = $pluginText },
    @{ Name = 'inventory REST route'; Text = "'/inventory'"; Source = $inventoryText },
    @{ Name = 'order refund API'; Text = 'wc_create_refund'; Source = $ordersText },
    @{ Name = 'order note API'; Text = 'add_order_note'; Source = $ordersText },
    @{ Name = 'partial shipment items'; Text = 'SHIPPING_ITEMS_LIMIT'; Source = $shippingText },
    @{ Name = 'IRT display mapping'; Text = '''IRT'' === $code'; Source = $pluginText },
    @{ Name = 'Toman display label'; Text = $tomanLabel; Source = $pluginText },
    @{ Name = 'admin design card'; Text = '.fandoogh-admin-card'; Source = $adminCssText }
)

foreach ($fragment in $requiredContractFragments) {
    if ([string]::IsNullOrWhiteSpace($fragment.Source) -or $fragment.Source.IndexOf($fragment.Text, [System.StringComparison]::Ordinal) -lt 0) {
        $failures.Add("Contract fragment missing ($($fragment.Name)): $($fragment.Text)")
    }
}

$frontendStoragePattern = '(?i)\b(localStorage|sessionStorage)\b'
if ([regex]::IsMatch($appText, $frontendStoragePattern) -or [regex]::IsMatch($serviceWorkerText, $frontendStoragePattern)) {
    $failures.Add('Frontend must not use localStorage or sessionStorage.')
}

$frontendSecretPattern = '(?i)(access[_-]?token|refresh[_-]?token|bearer\s+|client[_-]?secret|document\.cookie\s*=)'
if ([regex]::IsMatch($appText, $frontendSecretPattern)) {
    $failures.Add('Frontend contains a token/secret/password pattern.')
}

$textExtensions = @(
    '.css', '.html', '.js', '.jsx', '.json', '.md', '.php', '.ps1',
    '.scss', '.svg', '.ts', '.tsx', '.txt', '.xml', '.yml', '.yaml'
)

$excludedDirectories = @('.git', '.agents', 'node_modules', 'tests', 'vendor')
$candidateFiles = @(
    Get-ChildItem -LiteralPath $repoRoot -Recurse -File -Force -ErrorAction SilentlyContinue |
        Where-Object {
            $relativePath = $_.FullName.Substring($repoRoot.Length).TrimStart('\', '/')
            $pathParts = $relativePath -split '[\\/]'
            $hasExcludedDirectory = @($pathParts | Where-Object { $excludedDirectories -contains $_ }).Count -gt 0
            (-not $hasExcludedDirectory) -and ($textExtensions -contains $_.Extension.ToLowerInvariant())
        }
)

$externalUrlPattern = '(?i)\bhttps?://(?!(?:localhost|127\.0\.0\.1|\[::1\])(?::\d+)?(?:[/?#]|$))[^\s''"<>]+'
$urlFindings = [System.Collections.Generic.List[string]]::new()

foreach ($file in $candidateFiles) {
    $lineNumber = 0
    foreach ($line in (Get-Content -LiteralPath $file.FullName -Encoding UTF8)) {
        $lineNumber++
        foreach ($match in [regex]::Matches($line, $externalUrlPattern)) {
            $relativePath = $file.FullName.Substring($repoRoot.Length).TrimStart('\', '/')
            $urlFindings.Add("External URL in ${relativePath}:$lineNumber - $($match.Value)")
        }
    }
}

foreach ($finding in $urlFindings) {
    $failures.Add($finding)
}

if ($VerboseOutput) {
    Write-Host "Repository root: $repoRoot"
    Write-Host "Text files scanned: $($candidateFiles.Count)"
}

if ($candidateFiles.Count -eq 0) {
    Write-Host 'INFO: No PHP/frontend source files were found for URL scanning.'
}

if ($failures.Count -gt 0) {
    Write-Host 'FAIL: Baseline verification failed.' -ForegroundColor Red
    foreach ($failure in $failures) {
        Write-Host "- $failure"
    }
    exit 1
}

Write-Host 'PASS: Baseline documents exist and no detectable external URL was found.' -ForegroundColor Green
exit 0
