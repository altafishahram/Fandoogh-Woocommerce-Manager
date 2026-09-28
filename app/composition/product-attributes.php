<?php

namespace Fandoogh_Manager;

use Fandoogh_Manager\Catalog\ProductAttributeList;
use Fandoogh_Manager\Infrastructure\WooCommerceAttributeRepository;
use Fandoogh_Manager\Infrastructure\WordPressAttributeTextSanitizer;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/../src/Catalog/AttributeRepository.php';
require_once __DIR__ . '/../src/Catalog/AttributeTextSanitizer.php';
require_once __DIR__ . '/../src/Catalog/ProductAttributeList.php';
require_once __DIR__ . '/../src/Infrastructure/WooCommerceAttributeRepository.php';
require_once __DIR__ . '/../src/Infrastructure/WordPressAttributeTextSanitizer.php';

/**
 * Composition root: wire dependencies outside the classes, without a service
 * locator, singleton, cached data, or a new Composer/runtime requirement.
 */
function compose_product_attribute_list(): ProductAttributeList {
	return new ProductAttributeList(
		new WooCommerceAttributeRepository(),
		new WordPressAttributeTextSanitizer()
	);
}
