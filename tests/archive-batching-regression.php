<?php
/** php tests/archive-batching-regression.php: archive batches avoid total-row counts. */
define('ABSPATH',dirname(__DIR__).'/theme/zen/');define('HOUR_IN_SECONDS',3600);
$cache=[];$batch_sizes=[];$batch_posts=[500,500,201];
function add_action($hook,$callback,...$args){}
function get_header(){}function get_footer(){}function the_title(){echo 'Archives';}
function get_locale(){return 'en_US';}
function get_transient($key){return $GLOBALS['cache'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['cache'][$key]=$value;}
function get_option($key,$default=false){return $default;}
function get_post_type($id){return 'post';}
function get_the_title($post){return 'Post '.$post->ID;}
function get_permalink($post){return '/post/'.$post->ID;}
function get_the_date($format,$post){return '2026';}
function wp_reset_postdata(){}function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES);}
function esc_url($value){return esc_html($value);}
class WP_Query {
    public $posts=[];
    function __construct($args){
        $i=count($GLOBALS['batch_sizes']);$GLOBALS['batch_sizes'][]=$args;
        $count=$GLOBALS['batch_posts'][$i]??0;
        for($n=0;$n<$count;$n++)$this->posts[]=(object)['ID'=>$i*500+$n+1];
        $this->max_num_pages=3;
    }
}
ob_start();include ABSPATH.'page-archives.php';$html=ob_get_clean();
$failed=0;
$checks=[
    [count($batch_sizes)===3, 'archive fetches all pages until a short batch'],
    [count(array_filter($batch_sizes,fn($args)=>true===$args['no_found_rows']))===3, 'archive batches disable total-row counting'],
    [count(array_filter($batch_sizes,fn($args)=>500===$args['posts_per_page']))===3, 'archive batch size remains bounded'],
    [substr_count($html,'class="zen-archive-row')===1201, 'all fetched posts remain rendered'],
];
foreach($checks as [$ok,$label]){echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;if(!$ok)$failed++;}
exit($failed?1:0);
