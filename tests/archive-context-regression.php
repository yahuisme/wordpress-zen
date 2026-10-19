<?php
/** php tests/archive-context-regression.php: real template, explicit WordPress boundaries. */
define('ABSPATH',dirname(__DIR__).'/');define('HOUR_IN_SECONDS',3600);
$cache=[];$language='zh';$locale='zh_CN';$queries=0;$hooks=[];
function add_action($hook,$callback,...$args){$GLOBALS['hooks'][$hook][]=$callback;}
function get_header(){}function get_footer(){}function the_title(){echo 'Archives';}
function get_locale(){return $GLOBALS['locale'];}
function pll_current_language(){return $GLOBALS['language'];}
function get_transient($key){return $GLOBALS['cache'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['cache'][$key]=$value;}
function delete_transient($key){unset($GLOBALS['cache'][$key]);}
function get_option($key,$default=false){return $key==='permalink_structure'?'/%postname%/':$default;}
function get_post_type($id){return 'post';}
function get_the_title($post){return $GLOBALS['language'].' '.$GLOBALS['locale'].' title';}
function get_permalink($post){return '/'.$GLOBALS['language'].'/post/'.$post->ID;}
function get_the_date($format,$post){return $format==='Y'?'2026':'2026年10月19日';}
function wp_reset_postdata(){}function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES);}
function esc_url($value){return esc_html($value);}
class WP_Query{public $posts;public $max_num_pages=1;function __construct($args){$GLOBALS['queries']++;$this->posts=[(object)['ID'=>7]];}}
require ABSPATH.'inc/template-tags.php';
function render_archive(){ob_start();include ABSPATH.'page-archives.php';return ob_get_clean();}
function check($ok,$label){echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;if(!$ok)$GLOBALS['failures']=($GLOBALS['failures']??0)+1;}
$zh=render_archive();$language='en';$locale='en_US';$en=render_archive();
check(str_contains($en,'en en_US title')&&!str_contains($en,'zh zh_CN title'),'archive language does not reuse another language');
$language='zh';$locale='zh_CN';$again=render_archive();check($again===$zh&&$queries===2,'each language reuses its own warm cache');
$locale='zh_TW';$translated=render_archive();check(str_contains($translated,'zh zh_TW title'),'locale-specific filtered titles have independent cache');
zen_clear_archives_cache_on_category_relationship(7,[1],'language');check(get_transient('zen_archives_public_posts_v2')===false,'language reassignment invalidates every cached archive context');
foreach(['timezone_string','gmt_offset'] as $key){$cache['zen_site_uptime']=['days'=>1];foreach($hooks['update_option_'.$key]??[] as $fn)$fn();check(!isset($cache['zen_site_uptime']),$key.' invalidates uptime');}
exit(empty($GLOBALS['failures'])?0:1);
