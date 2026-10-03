[CmdletBinding()]
param(
    [switch]$VerboseOutput
)

# Independent release-candidate check. This script is read-only and performs
# no network requests. Run with:
# powershell -ExecutionPolicy Bypass -File .\tests\verify-release.ps1

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path -LiteralPath (Join-Path -Path $PSScriptRoot -ChildPath '..')).Path
$failures = [System.Collections.Generic.List[string]]::new()
$warnings = [System.Collections.Generic.List[string]]::new()

function Add-Failure {
    param([Parameter(Mandatory = $true)][string]$Message)

    [void]$failures.Add($Message)
}

function Add-WarningMessage {
    param([Parameter(Mandatory = $true)][string]$Message)

    [void]$warnings.Add($Message)
}

function Resolve-RepoPath {
    param([Parameter(Mandatory = $true)][string]$RelativePath)

    return Join-Path -Path $repoRoot -ChildPath ($RelativePath -replace '/', '\')
}

function Get-SourceText {
    param([Parameter(Mandatory = $true)][string]$RelativePath)

    $absolutePath = Resolve-RepoPath $RelativePath
    if (-not (Test-Path -LiteralPath $absolutePath -PathType Leaf)) {
        return ''
    }

    return [string](Get-Content -LiteralPath $absolutePath -Raw -Encoding UTF8)
}

function Assert-Contains {
    param(
        [Parameter(Mandatory = $true)][string]$Label,
        [AllowEmptyString()][string]$Source,
        [Parameter(Mandatory = $true)][string]$Expected
    )

    if ([string]::IsNullOrEmpty($Source) -or $Source.IndexOf($Expected, [System.StringComparison]::Ordinal) -lt 0) {
        Add-Failure "$Label is missing: $Expected"
    }
}

function Assert-NotContains {
    param(
        [Parameter(Mandatory = $true)][string]$Label,
        [AllowEmptyString()][string]$Source,
        [Parameter(Mandatory = $true)][string]$Unexpected
    )

    if (-not [string]::IsNullOrEmpty($Source) -and $Source.IndexOf($Unexpected, [System.StringComparison]::Ordinal) -ge 0) {
        Add-Failure "$Label is unexpectedly present: $Unexpected"
    }
}

function Assert-Matches {
    param(
        [Parameter(Mandatory = $true)][string]$Label,
        [AllowEmptyString()][string]$Source,
        [Parameter(Mandatory = $true)][string]$Pattern
    )

    if ([string]::IsNullOrEmpty($Source) -or -not [regex]::IsMatch($Source, $Pattern)) {
        Add-Failure "$Label does not match: $Pattern"
    }
}

try {
    $requiredFiles = @(
        'fandoogh-manager/fandoogh-manager.php',
        'fandoogh-manager/manifest.webmanifest',
        'fandoogh-manager/app/index.html',
        'fandoogh-manager/app/app.js',
        'fandoogh-manager/app/styles.css',
        'fandoogh-manager/app/sw.js',
        'fandoogh-manager/app/security.php',
        'fandoogh-manager/app/customers.php',
        'fandoogh-manager/app/orders.php',
        'fandoogh-manager/app/shipping.php',
        'fandoogh-manager/app/products.php',
        'fandoogh-manager/app/bulk-pricing.php',
        'fandoogh-manager/app/attributes.php',
        'fandoogh-manager/app/media.php',
        'fandoogh-manager/app/fonts.php',
        'fandoogh-manager/app/analytics.php',
        'fandoogh-manager/app/coupons.php',
        'fandoogh-manager/app/reviews.php',
        'fandoogh-manager/app/inventory.php',
        'fandoogh-manager/assets/css/admin.css'
    )

    foreach ($relativePath in $requiredFiles) {
        $absolutePath = Resolve-RepoPath $relativePath
        if (-not (Test-Path -LiteralPath $absolutePath -PathType Leaf)) {
            Add-Failure "Required release file is missing: $relativePath"
        }
    }

    $pluginText = Get-SourceText 'fandoogh-manager/fandoogh-manager.php'
    $securityText = Get-SourceText 'fandoogh-manager/app/security.php'
    $ordersText = Get-SourceText 'fandoogh-manager/app/orders.php'
    $customersText = Get-SourceText 'fandoogh-manager/app/customers.php'
    $shippingText = Get-SourceText 'fandoogh-manager/app/shipping.php'
    $productsText = Get-SourceText 'fandoogh-manager/app/products.php'
    $bulkPricingText = Get-SourceText 'fandoogh-manager/app/bulk-pricing.php'
    $attributesText = Get-SourceText 'fandoogh-manager/app/attributes.php'
    $mediaText = Get-SourceText 'fandoogh-manager/app/media.php'
    $analyticsText = Get-SourceText 'fandoogh-manager/app/analytics.php'
    $couponsText = Get-SourceText 'fandoogh-manager/app/coupons.php'
    $reviewsText = Get-SourceText 'fandoogh-manager/app/reviews.php'
    $inventoryText = Get-SourceText 'fandoogh-manager/app/inventory.php'
    $adminCssText = Get-SourceText 'fandoogh-manager/assets/css/admin.css'
    $serviceWorkerText = Get-SourceText 'fandoogh-manager/app/sw.js'
    $appText = Get-SourceText 'fandoogh-manager/app/app.js'
    $indexText = Get-SourceText 'fandoogh-manager/app/index.html'
    $stylesText = Get-SourceText 'fandoogh-manager/app/styles.css'
    $tomanLabel = ([char]0x62a) + ([char]0x648) + ([char]0x645) + ([char]0x627) + ([char]0x646)
    $orderReportsLabel = ([char]0x6af) + ([char]0x632) + ([char]0x627) + ([char]0x631) + ([char]0x634) + ([char]0x627) + ([char]0x62a) + ' ' + ([char]0x633) + ([char]0x641) + ([char]0x627) + ([char]0x631) + ([char]0x634)
    $pendingOrderAnnouncement = ([char]0x633) + ([char]0x641) + ([char]0x627) + ([char]0x631) + ([char]0x634) + ' ' + ([char]0x62c) + ([char]0x62f) + ([char]0x6cc) + ([char]0x62f) + ' ' + ([char]0x628) + ([char]0x62f) + ([char]0x648) + ([char]0x646) + ' ' + ([char]0x627) + ([char]0x642) + ([char]0x62f) + ([char]0x627) + ([char]0x645)
    $dashboardTitleLabel = ([char]0x67e) + ([char]0x646) + ([char]0x644) + ' ' + ([char]0x645) + ([char]0x62f) + ([char]0x6cc) + ([char]0x631) + ([char]0x6cc) + ([char]0x62a) + ' ' + ([char]0x641) + ([char]0x631) + ([char]0x648) + ([char]0x634) + ([char]0x6af) + ([char]0x627) + ([char]0x647)

    Assert-Matches 'Plugin header version' $pluginText '(?m)^\s*\*\s*Version:\s*1\.21\.0\s*$'
    Assert-Contains 'Plugin version constant' $pluginText "const VERSION = '1.21.0';"

    Assert-Matches 'Service worker cache version' $serviceWorkerText "(?m)\bCACHE_NAME\s*=\s*[\""']fandoogh-manager-shell-v26[\""']"
    Assert-Contains 'PWA install prompt handling' $appText 'beforeinstallprompt'
    Assert-Contains 'PWA install guide' $appText 'Add to Home Screen'
    Assert-Contains 'PWA update handoff' $appText 'SKIP_WAITING'
    Assert-Contains 'Offline mutation guard' $appText 'offlineError'
    Assert-Contains 'Service worker waiting update' $serviceWorkerText 'self.skipWaiting()'

    Assert-Contains 'Shipment REST route' $shippingText "'/orders/(?P<id>\\d+)/shipment'"
    Assert-Contains 'Shipment route registration' $shippingText 'register_rest_route'
    Assert-Contains 'Shipment read callback' $shippingText 'get_order_shipment'
    Assert-Contains 'Shipment write callback' $shippingText 'update_order_shipment'
    Assert-Contains 'Main plugin shipment module include' $pluginText "app/shipping.php"
    Assert-Contains 'Main plugin shipment route registration' $pluginText 'register_shipping_routes();'
    Assert-Contains 'Main plugin analytics module include' $pluginText "app/analytics.php"
    Assert-Contains 'Main plugin analytics route registration' $pluginText 'register_analytics_routes();'
    Assert-Contains 'Analytics REST route' $analyticsText "'/analytics/summary'"
    Assert-Contains 'Analytics permission callback' $analyticsText 'analytics_read_permission'
    Assert-Contains 'Analytics scope' $analyticsText "'analytics.read'"
    Assert-Contains 'Analytics setting' $pluginText "'analytics_enabled' => false"
    Assert-Contains 'Currency IRT mapping' $pluginText "'IRT' ==="
    Assert-Contains 'Currency Toman label' $pluginText $tomanLabel
    Assert-Contains 'Admin design card tokens' $adminCssText '.fandoogh-admin-card'

    Assert-Contains 'Shipment write scope in session check' $shippingText "'orders.update_shipment'"
    Assert-Contains 'Product attributes REST route' $attributesText "'/product-attributes'"
    Assert-Contains 'Variable product CRUD' $productsText 'WC_Product_Variable'
    Assert-Contains 'Product attribute validation' $productsText 'product_write_attributes'
    Assert-Contains 'Bulk pricing module include' $pluginText "app/bulk-pricing.php"
    Assert-Contains 'Bulk pricing route registration' $pluginText 'register_bulk_pricing_routes();'
    Assert-Contains 'Bulk pricing REST route' $bulkPricingText "'/products/bulk-price'"
    Assert-Contains 'Bulk pricing CSRF and write scope boundary' $bulkPricingText 'products_write_permission'
    Assert-Contains 'Bulk pricing preview token' $bulkPricingText 'bulk_price_confirmation_token'
    Assert-Contains 'Bulk pricing one-time token consume' $bulkPricingText 'delete_transient'
    Assert-Contains 'Bulk pricing category descendants' $bulkPricingText 'get_term_children'
    Assert-Contains 'Bulk pricing WooCommerce query layer' $bulkPricingText 'wc_get_products'
    Assert-Contains 'Bulk pricing audit event' $securityText "'product_bulk_price_updated'"
    Assert-Contains 'Bulk pricing preview UI' $appText 'renderBulkPricePreview'
    Assert-Contains 'Bulk pricing explicit confirmation UI' $appText 'window.confirm(confirmation)'
    Assert-Contains 'Product attribute API config' $pluginText "'product_attributes'"
    Assert-Contains 'Shipment scope in default scope map' $securityText "'update_shipment' => true"
    Assert-Contains 'Shipment partial item contract' $shippingText 'SHIPPING_ITEMS_LIMIT'
    Assert-Contains 'Order note route' $ordersText "'/orders/(?P<id>\\d+)/notes'"
    Assert-Contains 'Order note scope' $securityText "'add_note'"
    Assert-Contains 'Order note callback' $ordersText 'add_order_note'
    Assert-Contains 'Order refund route' $ordersText "'/orders/(?P<id>\\d+)/refund'"
    Assert-Contains 'Order refund scope' $securityText "'refund'"
    Assert-Contains 'WooCommerce refund API' $ordersText 'wc_create_refund'
    Assert-Contains 'Order refund projection' $ordersText 'serialize_order_refunds'
    Assert-Contains 'Coupon module include' $pluginText "app/coupons.php"
    Assert-Contains 'Coupon route registration' $pluginText 'register_coupon_routes();'
    Assert-Contains 'Coupon REST route' $couponsText "'/coupons'"
    Assert-Contains 'Coupon CRUD API' $couponsText 'WC_Coupon'
    Assert-Contains 'Coupon audit event' $securityText "'coupon_created'"
    Assert-Contains 'Review module include' $pluginText "app/reviews.php"
    Assert-Contains 'Review route registration' $pluginText 'register_review_routes();'
    Assert-Contains 'Review REST route' $reviewsText "'/reviews'"
    Assert-Contains 'Review moderation API' $reviewsText 'wp_set_comment_status'
    Assert-Contains 'Review audit event' $securityText "'review_moderated'"
    Assert-Contains 'Inventory module include' $pluginText "app/inventory.php"
    Assert-Contains 'Inventory route registration' $pluginText 'register_inventory_routes();'
    Assert-Contains 'Inventory REST route' $inventoryText "'/inventory'"
    Assert-Contains 'Inventory WooCommerce CRUD' $inventoryText 'wc_get_products'
    Assert-Contains 'Inventory audit event' $securityText "'inventory_updated'"
    Assert-Contains 'Advanced product backorders' $productsText 'set_backorders'
    Assert-Contains 'Advanced product dimensions' $productsText "'weight', 'length', 'width', 'height'"
    Assert-Contains 'Advanced product UI' $appText 'productDownloadable'
    Assert-Contains 'Inventory UI' $appText 'loadInventory'
    Assert-Contains 'Coupon UI' $appText 'loadCoupons'
    Assert-Contains 'Review UI' $appText 'loadReviews'
    Assert-Contains 'Shipment permission callback' $shippingText 'shipment_write_permission'
    Assert-Contains 'Admin pairing target selector' $securityText 'fandoogh_manager_pairing_user_id'
    Assert-Contains 'Pairing target capability boundary' $securityText 'pairing_target_user'
    Assert-Contains 'Pairing target enumeration' $securityText 'function pairing_target_users'
    Assert-Contains 'Administrator pairing gate' $securityText "current_user_can( 'manage_options' )"
    Assert-Contains 'Pairing target identity binding' $securityText 'absint( $user->ID ) !== absint( $pairing->user_id )'
    Assert-Contains 'Pairing scope intersection' $securityText 'intersect_pairing_scopes'
    Assert-Contains 'Pairing issuance audit' $securityText 'pairing_issued'
    Assert-Contains 'Per-user access policy meta' $securityText 'ACCESS_POLICY_META_KEY'
    Assert-Contains 'Per-user access policy sanitizer' $securityText 'update_user_access_policy'
    Assert-Contains 'Cross-user session projection' $securityText 'admin_security_session_rows'
    Assert-Contains 'Administrator session revoke' $securityText 'admin_revoke_session'
    Assert-Contains 'Administrator user access removal' $securityText 'admin_set_user_access'
    Assert-Contains 'Major changes scope' $securityText "'major_changes' => true"
    Assert-Contains 'Product create scope gate' $productsText "'products.create'"
    Assert-Contains 'Product update scope gate' $productsText "'products.update'"
    Assert-Contains 'Major changes policy helper' $securityText 'session_has_major_changes_access'
    Assert-Contains 'Order major changes gate' $ordersText 'session_has_major_changes_access'
    Assert-Contains 'Admin access management UI' $pluginText 'fandoogh-access-management'
    Assert-Contains 'Order reports label' $appText $orderReportsLabel
    Assert-Contains 'Order filters' $ordersText 'date_created'
    Assert-Contains 'Order payment projection' $ordersText 'serialize_order_payment'
    Assert-Contains 'Order notes projection' $ordersText 'serialize_order_notes'
    Assert-Contains 'Customer order history route' $customersText "'/customers/(?P<id>\\d+)/orders'"
    Assert-Contains 'Customer order history permission' $customersText 'customer_orders_read_permission'
    Assert-Contains 'Customer order history callback' $customersText 'list_customer_orders'
    Assert-Contains 'Order filters UI' $appText 'applyOrderFilters'
    Assert-Contains 'Order pagination UI' $appText 'ordersPagination'
    Assert-Contains 'Customer order history UI' $appText 'customerOrderHistory'
    Assert-Contains 'Orders mutation status message' $appText 'ordersMutationMessage'
    Assert-Contains 'Orders status confirmation retry' $appText 'confirmOrderStatus'
    Assert-Contains 'Orders stale request guard' $appText 'loadRequestId'
    Assert-Contains 'Orders server status verification' $ordersText 'orders_status_matches'
    Assert-Contains 'Persian digit formatter' $appText 'function toPersianDigits'
    Assert-Contains 'Jalali date formatter' $appText 'PERSIAN_DATE_LOCALE'
    Assert-Contains 'Jalali conversion formatter' $appText 'function gregorianToJalali'
    Assert-Contains 'Jalali conversion parser' $appText 'function jalaliToGregorian'
    Assert-Contains 'Persian date picker setup' $appText 'function setupPersianDateInput'
    Assert-Contains 'Persian date picker initialization' $appText 'initializePersianDatePickers();'
    Assert-Contains 'Persian date picker API boundary' $appText 'function getPersianDateInputValue'
    Assert-Contains 'Dynamic shipment date picker binding' $appText 'setupPersianDateInput(control);'
    Assert-Contains 'Persian date picker markup' $indexText 'data-persian-date-type="date"'
    Assert-Contains 'Persian date picker styles' $stylesText '.persian-calendar-popover'
    Assert-Contains 'Media upload timeout override' $appText 'MEDIA_UPLOAD_TIMEOUT_MS'
    Assert-Contains 'Media filename fallback' $mediaText 'fandoogh-image.'
    Assert-Contains 'WebP save diagnostic' $mediaText 'fandoogh_webp_save'

    $noStoreValue = 'no-store, no-cache, must-revalidate, max-age=0'
    Assert-Contains 'Shipment no-store cache header' $shippingText $noStoreValue
    Assert-Contains 'Shipment no-cache pragma' $shippingText "'Pragma'                 => 'no-cache'"
    Assert-Contains 'Shipment nosniff header' $shippingText "'X-Content-Type-Options' => 'nosniff'"
    Assert-Contains 'Frontend no-store fetch mode' $appText 'cache: "no-store"'
    Assert-Contains 'Session boot view' $appText 'appBootView'
    Assert-Contains 'Session restoration' $appText 'function restoreSession'
    Assert-Contains 'Dashboard store title' $indexText ('id="dashboardTitle">' + $dashboardTitleLabel)
    Assert-Contains 'Dashboard store subtitle element' $indexText 'class="page-description">'
    Assert-NotContains 'Removed overview card' $indexText 'نمای کلی'
    Assert-NotContains 'Removed next-step card' $indexText 'گام بعدی'
    Assert-Contains 'Quick products action' $indexText 'quick-action--products'
    Assert-NotContains 'Removed quick sales report action' $indexText 'گزارش فروش'
    Assert-Contains 'Dashboard analytics order' $stylesText '.dashboard-overview-grid .analytics-panel'
    Assert-Contains 'Dashboard sales access gate' $appText 'dashboardSalesPanel'
    Assert-Contains 'Analytics scope gate' $appText 'hasAnalyticsAccess'
    Assert-Contains 'Pending order count query' $appText 'status: "pending"'
    Assert-Contains 'Pending order contextual announcement' $appText $pendingOrderAnnouncement
    Assert-Contains 'Quick order badge' $indexText 'id="quickOrdersBadge"'
    Assert-Contains 'Sidebar order badge' $indexText 'id="sidebarOrdersBadge"'
    Assert-Contains 'Mobile order badge' $indexText 'id="mobileOrdersBadge"'
    Assert-Contains 'Pending order live region' $indexText 'id="pendingOrdersAnnouncement"'
    Assert-Contains 'Product editor overlay markup' $indexText 'id="productEditorOverlay"'
    Assert-Contains 'Product editor dialog semantics' $indexText 'aria-modal="true"'
    Assert-Contains 'Product editor wizard setup' $appText 'function setupProductEditorWizard'
    Assert-Contains 'Product editor wizard step transition' $appText 'function setProductEditorStep'
    Assert-Contains 'Product editor focus trap' $appText 'editorFocusable'
    Assert-Contains 'Product editor responsive styles' $stylesText '.product-editor-overlay'
    Assert-Contains 'Product editor mobile full-height flow' $stylesText 'height: 100dvh'
    Assert-Contains 'Bulk price control field styling' $stylesText '.bulk-price-control-field'
    Assert-Contains 'Order detail step definitions' $appText 'ORDER_DETAIL_STEP_DEFINITIONS'
    Assert-Contains 'Order detail flow builder' $appText 'function buildOrderDetailFlow'
    Assert-Contains 'Order detail focus trap' $appText 'orderFocusable'
    Assert-Contains 'Order detail stepper markup' $indexText 'id="orderDetailStepper"'
    Assert-Contains 'Order detail mobile dialog styles' $stylesText '.order-detail-overlay'
    Assert-Contains 'Order mobile card styles' $stylesText '.order-card-details'
    Assert-Contains 'Dynamic app shell robots meta' $pluginText '<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">'
    Assert-Contains 'Dynamic app shell robots header' $pluginText 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet'
    Assert-Contains 'Responsive manager sidebar' $appText 'appSidebar'
    Assert-Contains 'Service worker REST cache exclusion' $serviceWorkerText 'indexOf("/wp-json/") === 0'

    $zipPath = Join-Path -Path $repoRoot -ChildPath 'fandoogh-manager-1.21.0.zip'
    if (Test-Path -LiteralPath $zipPath -PathType Leaf) {
        $zip = $null
        try {
            Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction Stop
            $zip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
            $entryNames = @(
                $zip.Entries |
                    ForEach-Object { $_.FullName.Replace('\', '/') }
            )

            $requiredZipEntries = @(
                'fandoogh-manager/fandoogh-manager.php',
                'fandoogh-manager/manifest.webmanifest',
                'fandoogh-manager/app/index.html',
                'fandoogh-manager/app/app.js',
                'fandoogh-manager/app/styles.css',
                'fandoogh-manager/app/sw.js',
                'fandoogh-manager/app/security.php',
                'fandoogh-manager/app/shipping.php',
                'fandoogh-manager/app/orders.php',
                'fandoogh-manager/app/customers.php',
                'fandoogh-manager/app/products.php',
                'fandoogh-manager/app/bulk-pricing.php',
                'fandoogh-manager/app/attributes.php',
                'fandoogh-manager/app/analytics.php',
                'fandoogh-manager/app/coupons.php',
                'fandoogh-manager/app/reviews.php',
                'fandoogh-manager/app/inventory.php',
                'fandoogh-manager/assets/css/admin.css'
            )

            foreach ($entryName in $requiredZipEntries) {
                if ($entryNames -notcontains $entryName) {
                    Add-Failure "Release ZIP entry is missing: $entryName"
                }
            }
        }
        catch {
            Add-Failure "Release ZIP could not be inspected: $($_.Exception.Message)"
        }
        finally {
            if ($null -ne $zip) {
                $zip.Dispose()
            }
        }
    }
    else {
        Add-WarningMessage 'Optional release ZIP not found: fandoogh-manager-1.21.0.zip'
    }

    if ($VerboseOutput) {
        Write-Host "Repository root: $repoRoot"
        Write-Host "Required files checked: $($requiredFiles.Count)"
        Write-Host "Optional ZIP checked: $(Test-Path -LiteralPath $zipPath -PathType Leaf)"
    }
}
catch {
    Add-Failure "Unexpected release verification error: $($_.Exception.Message)"
}

foreach ($warning in $warnings) {
    Write-Host "WARN: $warning" -ForegroundColor Yellow
}

if ($failures.Count -gt 0) {
    Write-Host 'FAIL: Release candidate verification failed.' -ForegroundColor Red
    foreach ($failure in $failures) {
        Write-Host "- $failure"
    }
    exit 1
}

Write-Host 'PASS: Fandoogh Manager 1.21.0 release candidate checks passed.' -ForegroundColor Green
exit 0
