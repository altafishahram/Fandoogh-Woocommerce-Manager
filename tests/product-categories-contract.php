<?php
/**
 * Standalone product category contract checks with in-memory WP/Woo doubles.
 * Run: php tests/product-categories-contract.php
 * These checks do not boot WordPress or replace live WooCommerce integration.
 */

namespace {
    if ( 'cli' !== PHP_SAPI ) {
        exit;
    }
    define( 'ABSPATH', __DIR__ . '/' );

    class WP_Error {}

    function absint( $value ) { return abs( (int) $value ); }
    function is_wp_error( $value ) { return $value instanceof WP_Error; }
    function sanitize_text_field( $value ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $value ) ) ); }
    function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
    function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
    function sanitize_title( $value ) { return sanitize_key( str_replace( ' ', '-', $value ) ); }
    function wp_strip_all_tags( $value ) { return strip_tags( $value ); }

    function get_term( $id, $taxonomy ) {
        $GLOBALS['category_test_reads'][] = array( $id, $taxonomy );
        if ( 'product_cat' !== $taxonomy ) {
            throw new \RuntimeException( 'Category traversal must request product_cat explicitly.' );
        }
        // Return fixtures verbatim, including broken/filtered taxonomy responses.
        return $GLOBALS['category_test_terms'][ $id ] ?? null;
    }

    class Category_Test_Product {
        private $category_ids;
        public function __construct( $category_ids ) { $this->category_ids = $category_ids; }
        public function get_category_ids() { return $this->category_ids; }
        public function __call( $method, $args ) {
            $values = array(
                'get_id' => 7, 'get_name' => 'Test product', 'get_slug' => 'test-product',
                'get_permalink' => '', 'get_type' => 'simple', 'get_status' => 'publish',
                'get_image_id' => 0, 'get_gallery_image_ids' => array(),
                'get_price' => '10', 'get_regular_price' => '10', 'get_sale_price' => '',
                'get_sku' => 'SKU-7', 'get_stock_status' => 'instock',
                'get_stock_quantity' => null, 'get_manage_stock' => false,
                'get_short_description' => '', 'get_description' => '',
                'get_date_created' => null, 'get_date_modified' => null,
            );
            if ( ! array_key_exists( $method, $values ) ) {
                throw new \BadMethodCallException( $method );
            }
            return $values[ $method ];
        }
    }
}

namespace Fandoogh_Manager {
    function public_asset_url( $url ) { return $url; }
}

namespace {
    use function Fandoogh_Manager\product_root_category;
    use function Fandoogh_Manager\serialize_product;

    require __DIR__ . '/../app/products.php';

    $category_test_count = 0;
    function expect_same( $expected, $actual, $message ) {
        if ( $expected !== $actual ) {
            throw new \RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
        }
    }
    function category_term( $id, $parent = 0, $taxonomy = 'product_cat' ) {
        return (object) array(
            'term_id' => $id, 'parent' => $parent, 'taxonomy' => $taxonomy,
            'name' => 'Category ' . $id, 'slug' => 'category-' . $id,
        );
    }
    function root_data( $id ) {
        return array( 'id' => $id, 'name' => 'Category ' . $id, 'slug' => 'category-' . $id );
    }
    function fixture( $terms ) {
        $GLOBALS['category_test_terms'] = $terms;
        $GLOBALS['category_test_reads'] = array();
    }
    function categories( $ids, $detail = false ) {
        return serialize_product( new Category_Test_Product( $ids ), $detail )['categories'];
    }
    function test_case( $name, $callback ) {
        try {
            $callback();
            ++$GLOBALS['category_test_count'];
            echo 'PASS ' . $name . PHP_EOL;
        } catch ( \Throwable $error ) {
            fwrite( STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL );
            exit( 1 );
        }
    }

    test_case( 'top-level category resolves to itself and preserves existing fields', function () {
        fixture( array( 1 => category_term( 1 ) ) );
        expect_same( array( array(
            'id' => 1, 'name' => 'Category 1', 'slug' => 'category-1',
            'parent' => 0, 'parent_name' => '', 'parent_slug' => '',
            'root_category' => root_data( 1 ),
        ) ), categories( array( 1 ) ), 'Additive category contract' );
    } );

    test_case( 'deep child assigned alone resolves root in list and detail without changing assignments', function () {
        fixture( array( 1 => category_term( 1 ), 2 => category_term( 2, 1 ), 3 => category_term( 3, 2 ) ) );
        $before = serialize( $GLOBALS['category_test_terms'] );
        $expected = array( array(
            'id' => 3, 'name' => 'Category 3', 'slug' => 'category-3',
            'parent' => 2, 'parent_name' => 'Category 2', 'parent_slug' => 'category-2',
            'root_category' => root_data( 1 ),
        ) );
        expect_same( $expected, categories( array( 3 ) ), 'List resolves root and retains immediate parent' );
        expect_same( $expected, categories( array( 3 ), true ), 'Detail uses the same contract' );
        expect_same( $before, serialize( $GLOBALS['category_test_terms'] ), 'Term objects remain unchanged' );
        $product = new Category_Test_Product( array( 3 ) );
        serialize_product( $product );
        expect_same( array( 3 ), $product->get_category_ids(), 'Assigned IDs remain unchanged' );
    } );

    test_case( 'multiple assignments keep order and entries even with a shared root', function () {
        fixture( array( 1 => category_term( 1 ), 2 => category_term( 2, 1 ), 3 => category_term( 3, 1 ), 4 => category_term( 4 ) ) );
        $items = categories( array( 3, 2, 4 ) );
        expect_same( array( 3, 2, 4 ), array_column( $items, 'id' ), 'Assignment order preserved' );
        expect_same( array( root_data( 1 ), root_data( 1 ), root_data( 4 ) ), array_column( $items, 'root_category' ), 'Roots resolved per assignment' );
    } );

    test_case( 'missing ancestor yields null rather than mistaking a child for the root', function () {
        fixture( array( 3 => category_term( 3, 2 ), 2 => category_term( 2, 1 ) ) );
        $item = categories( array( 3 ) )[0];
        expect_same( null, $item['root_category'], 'Missing grandparent' );
        expect_same( 'Category 2', $item['parent_name'], 'Immediate parent metadata remains' );
        fixture( array( 3 => category_term( 3, 2 ) ) );
        expect_same( null, categories( array( 3 ) )[0]['root_category'], 'Missing immediate parent' );
    } );

    test_case( 'taxonomy API errors yield null without dropping the assigned category', function () {
        fixture( array( 3 => category_term( 3, 2 ), 2 => new WP_Error() ) );
        $items = categories( array( 3 ) );
        expect_same( array( 3 ), array_column( $items, 'id' ), 'Assigned child retained' );
        expect_same( null, $items[0]['root_category'], 'Failed ancestor lookup' );
    } );

    test_case( 'self-cycle and multi-node ancestor cycle terminate with null', function () {
        fixture( array( 1 => category_term( 1, 1 ) ) );
        expect_same( null, categories( array( 1 ) )[0]['root_category'], 'Self-cycle' );
        fixture( array( 1 => category_term( 1, 2 ), 2 => category_term( 2, 1 ), 3 => category_term( 3, 2 ) ) );
        expect_same( null, categories( array( 3 ) )[0]['root_category'], 'Ancestor cycle' );
        expect_same( true, count( $GLOBALS['category_test_reads'] ) <= 4, 'Cycle stops before revisiting ancestors' );
    } );

    test_case( 'foreign ancestor taxonomy cannot be emitted as a root category', function () {
        fixture( array( 2 => category_term( 2, 1 ), 1 => category_term( 1, 0, 'category' ) ) );
        expect_same( null, categories( array( 2 ) )[0]['root_category'], 'Foreign ancestor' );
    } );

    test_case( 'foreign assigned term has no product category root', function () {
        fixture( array( 1 => category_term( 1, 0, 'product_tag' ) ) );
        expect_same( null, categories( array( 1 ) )[0]['root_category'], 'Foreign assigned taxonomy' );
    } );

    test_case( 'empty and missing assignments preserve existing omission behavior', function () {
        fixture( array( 2 => new WP_Error() ) );
        expect_same( array(), categories( array() ), 'No assignments' );
        expect_same( array(), categories( array( 1, 2 ) ), 'Unavailable assigned terms omitted' );
    } );

    test_case( 'mismatched ancestor response cannot resolve to an unrelated root', function () {
        fixture( array( 2 => category_term( 2, 1 ), 1 => category_term( 99 ) ) );
        expect_same( null, categories( array( 2 ) )[0]['root_category'], 'Unexpected term ID from API' );
    } );

    test_case( 'invalid term objects and parent identifiers yield null', function () {
        foreach ( array( null, new WP_Error(), (object) array(), category_term( 0 ), category_term( 1, -2 ), category_term( 1, 'invalid' ) ) as $term ) {
            expect_same( null, product_root_category( $term ), 'Invalid term rejected' );
        }
    } );

    test_case( 'root display metadata is sanitized', function () {
        $root = category_term( 1 );
        $root->name = ' <b>Root</b> ';
        $root->slug = 'Root Category';
        fixture( array( 1 => $root, 2 => category_term( 2, 1 ) ) );
        expect_same( array( 'id' => 1, 'name' => 'Root', 'slug' => 'root-category' ), categories( array( 2 ) )[0]['root_category'], 'Sanitized root snapshot' );
    } );

    test_case( 'excessive hierarchy has bounded reads and no false root', function () {
        $terms = array();
        for ( $id = 1; $id <= 101; $id++ ) {
            $terms[ $id ] = category_term( $id, 101 === $id ? 0 : $id + 1 );
        }
        fixture( $terms );
        expect_same( null, product_root_category( $terms[1] ), 'Hierarchy exceeding safety bound' );
        expect_same( true, count( $GLOBALS['category_test_reads'] ) <= 100, 'Bounded taxonomy reads' );
    } );

    echo $category_test_count . ' product category contract checks passed (standalone stubs; not live WP integration).' . PHP_EOL;
}
