<?php
/** Native transient API with a two-request L1/persistent cache boundary double. */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1])) exit(2);
$complete = false;
register_shutdown_function(function () use (&$complete) { if (!$complete) { fwrite(STDERR, "NATIVE_COUNTER_CACHE_INCOMPLETE\n"); exit(1); } });
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/';
require $argv[1];
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1' || get_template() !== 'zen') throw new RuntimeException('Isolated local Zen required');
if (hash_file('sha256', get_template_directory() . '/inc/options.php') !== hash_file('sha256', dirname(__DIR__) . '/theme/zen/inc/options.php')) throw new RuntimeException('Source mismatch');
/** Only the transient cache boundary is doubled; Core and count/options SQL remain native. */
final class Zen_Transient_L1_Cache {
    private $local = array();
    public function __construct(private object $fallback, private object $persistent) {}
    public function get($key, $group = '', $force = false, &$found = null) {
        if ($group !== 'transient') return $this->fallback->get($key, $group, $force, $found);
        if ($force || !array_key_exists($key, $this->local)) $this->local[$key] = $this->persistent->values[$key] ?? false;
        $found = $this->local[$key] !== false;
        return $this->local[$key];
    }
    public function set($key, $value, $group = '', $ttl = 0) {
        if ($group !== 'transient') return $this->fallback->set($key, $value, $group, $ttl);
        $this->persistent->values[$key] = $this->local[$key] = $value;
        $this->persistent->ttls[$key] = $ttl;
        return true;
    }
    public function delete($key, $group = '', $deprecated = false) {
        if ($group !== 'transient') return $this->fallback->delete($key, $group, $deprecated);
        unset($this->local[$key], $this->persistent->values[$key]); return true;
    }
    public function __call($name, $args) { return $this->fallback->$name(...$args); }
}
$old = get_option('zen_show_reading_count', null); $old_user = get_current_user_id();
$old_cache = $wp_object_cache; $old_ext = wp_using_ext_object_cache();
$id = 0; $hook = null; $failures = 0;
try {
    update_option('zen_show_reading_count', 1); wp_set_current_user(0);
    $id = wp_insert_post(array('post_type'=>'post','post_status'=>'publish','post_title'=>'Counter cache fixture'), true);
    if (!$id || is_wp_error($id)) throw new RuntimeException('Fixture insert failed');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.245'; $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
    $key = 'zen_viewed_' . $id . '_' . md5($_SERVER['REMOTE_ADDR']);
    $backend = (object) array('values'=>array(), 'ttls'=>array());
    $first_cache = new Zen_Transient_L1_Cache($old_cache, $backend);
    $second_cache = new Zen_Transient_L1_Cache($old_cache, $backend);
    $wp_object_cache = $first_cache; wp_using_ext_object_cache(true);
    $second = null;
    // A's initial native lookup misses; B then completes against a separate L1.
    $hook = function ($value) use (&$hook, $key, $id, $first_cache, $second_cache, &$second) {
        remove_filter('transient_' . $key, $hook);
        $GLOBALS['wp_object_cache'] = $second_cache;
        try { $second = zen_track_post_view($id); }
        finally { $GLOBALS['wp_object_cache'] = $first_cache; }
        return $value;
    };
    add_filter('transient_' . $key, $hook);
    $first = zen_track_post_view($id);
    $stored = (int) get_post_meta($id, 'zen_view_count', true);
    $ok = $first === 1 && $second === 1 && $stored === 1 && ($backend->values[$key] ?? false) === 1 && ($backend->ttls[$key] ?? 0) === 300;
    $failures += !$ok;
    echo ($ok ? 'PASS ' : 'FAIL ') . json_encode(array('first'=>$first,'second'=>$second,'stored'=>$stored,'bucket'=>$backend->values[$key] ?? false,'ttl'=>$backend->ttls[$key] ?? null)) . "\n";
} finally {
    if ($hook && isset($key)) remove_filter('transient_' . $key, $hook);
    $wp_object_cache = $old_cache; wp_using_ext_object_cache($old_ext);
    if ($id && !is_wp_error($id)) { delete_transient('zen_viewed_' . $id . '_' . md5('192.0.2.245')); delete_option('zen_view_lock_' . $id); wp_delete_post($id, true); }
    if ($old === null) delete_option('zen_show_reading_count'); else update_option('zen_show_reading_count', $old);
    wp_set_current_user($old_user);
}
$complete = true;
printf("NATIVE_COUNTER_CACHE_COMPLETE failures=%d\n", $failures);
exit($failures ? 1 : 0);
