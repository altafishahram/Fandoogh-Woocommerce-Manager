<?php
/** No network: expose a test-only transport envelope for independent Node verification. */
if ( 'cli' !== PHP_SAPI ) { exit; }
define('ABSPATH', __DIR__ . '/');
function get_option($key,$default=false) { return $GLOBALS['options'][$key] ?? $default; }
function add_option($key,$value,$deprecated='',$autoload=true) { if(isset($GLOBALS['options'][$key])) return false; $GLOBALS['options'][$key]=$value; return true; }
function wp_parse_url($url) { return parse_url($url); }
function wp_json_encode($value) { return json_encode($value); }
function home_url($path) { return 'https://shop.example.test'.$path; }
function is_wp_error($value) { return false; }
function wp_safe_remote_post($url,$options) { $GLOBALS['sent']=array('url'=>$url,'headers'=>$options['headers'],'body'=>base64_encode($options['body']),'redirects'=>$options['redirection']); return array('code'=>201); }
function wp_remote_retrieve_response_code($value) { return $value['code']; }
require __DIR__.'/../app/push.php';
if (!function_exists('openssl_pkey_new')) { fwrite(STDERR,"OpenSSL extension is required for crypto verification.\n"); exit(2); }
$subscription=json_decode(stream_get_contents(STDIN),true);
$result=Fandoogh_Manager\push_deliver($subscription,'low_stock');
if($result!=='') { fwrite(STDERR,"Encryption failed: ".$result."\n"); exit(1); }
echo json_encode($GLOBALS['sent']);
