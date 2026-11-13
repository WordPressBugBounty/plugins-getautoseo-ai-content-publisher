<?php
/**
 * Choose which API key the plugin sends.
 *
 * WordPress-free so Laravel unit tests can load it.
 * The Settings "Test API Connection" button must check the key in the
 * box. The saved option is the previous key until the user clicks Save.
 */

if (class_exists('AutoSEO_Api_Key')) {
    return;
}

class AutoSEO_Api_Key {

    /**
     * @param mixed $key
     * @return string
     */
    public static function normalize($key) {
        if (!is_string($key)) {
            return '';
        }

        return trim($key);
    }

    /**
     * Key to send on Test. A typed key wins. An empty box uses the saved key.
     *
     * @param mixed $posted_key
     * @param mixed $saved_key
     * @return string
     */
    public static function key_for_connection_test($posted_key, $saved_key) {
        $posted = self::normalize($posted_key);
        if ($posted !== '') {
            return $posted;
        }

        return self::normalize($saved_key);
    }

    /**
     * True when a successful test should store a different key.
     *
     * @param mixed $new_key
     * @param mixed $saved_key
     * @return bool
     */
    public static function replaces_saved_key($new_key, $saved_key) {
        $new = self::normalize($new_key);
        $saved = self::normalize($saved_key);

        if ($new === '') {
            return false;
        }

        if (strlen($new) !== strlen($saved)) {
            return true;
        }

        return !hash_equals($saved, $new);
    }
}
