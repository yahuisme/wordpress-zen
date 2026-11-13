<?php
/** Minimal sequential option-row boundary double. Native concurrency is tested separately. */
function wp_generate_uuid4() { return 'fixture-owner-' . ++$GLOBALS['zen_storage_token']; }
function wp_cache_delete($key, $group = '') { return true; }
function wp_using_ext_object_cache() { return false; }
$GLOBALS['zen_storage_token'] = 0;
$GLOBALS['wpdb'] = new class {
    public $options = 'wp_options';
    private $rows = array();
    public function prepare($sql, ...$args) { return array($sql, $args); }
    public function query($query) {
        list($sql, $args) = $query;
        if (str_starts_with($sql, 'INSERT IGNORE INTO wp_options')) {
            list($key, $value, $autoload) = $args;
            if ($autoload !== 'no') throw new RuntimeException('Counter lease must not autoload');
            if (isset($this->rows[$key])) return 0;
            $this->rows[$key] = $value; return 1;
        }
        if (str_starts_with($sql, 'UPDATE wp_options SET option_value')) {
            list($value, $key, $previous) = $args;
            if (($this->rows[$key] ?? null) !== $previous) return 0;
            $this->rows[$key] = $value; return 1;
        }
        if (str_starts_with($sql, 'DELETE FROM wp_options')) {
            list($key, $value) = $args;
            if (($this->rows[$key] ?? null) !== $value) return 0;
            unset($this->rows[$key]); return 1;
        }
        throw new RuntimeException('Unexpected counter SQL: ' . $sql);
    }
    public function get_var($query) {
        list($sql, $args) = $query;
        if (!str_starts_with($sql, 'SELECT option_value FROM wp_options')) throw new RuntimeException('Unexpected lease read');
        return $this->rows[$args[0]] ?? null;
    }
};
