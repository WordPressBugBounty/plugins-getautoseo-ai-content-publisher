<?php
/**
 * Custom update checks for the unbranded (zip-installed) plugin.
 *
 * The official GetAutoSEO plugin updates from wordpress.org. Visibly and
 * white-label sites install AI Content Publisher by zip, so WordPress.org
 * never offers an update. This class asks getautoseo.com for the latest
 * zip and feeds it into the normal WordPress update UI.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoSEO_Plugin_Updater {

    const SLUG = 'ai-content-publisher';
    const UPDATE_PATH = '/wordpress-plugin/update';
    const CACHE_KEY = 'autoseo_unbranded_update';
    const CACHE_TTL = 43200;

    /**
     * @var object|false|null
     */
    private static $remote = null;

    public static function init() {
        if (!self::is_unbranded()) {
            return;
        }

        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'inject_update'));
        add_filter('plugins_api', array(__CLASS__, 'plugin_info'), 10, 3);
        add_filter('update_plugins_getautoseo.com', array(__CLASS__, 'update_from_uri'), 10, 4);
    }

    /**
     * True for the unbranded zip (Visibly / white-label), not the .org plugin.
     */
    public static function is_unbranded() {
        if (defined('AUTOSEO_UNBRANDED') && AUTOSEO_UNBRANDED) {
            return true;
        }

        return dirname(AUTOSEO_PLUGIN_BASENAME) === self::SLUG;
    }

    /**
     * @param object $transient
     * @return object
     */
    public static function inject_update($transient) {
        if (!is_object($transient)) {
            return $transient;
        }

        $remote = self::remote();
        if (!$remote || empty($remote->new_version)) {
            return $transient;
        }

        if (version_compare(AUTOSEO_VERSION, $remote->new_version, '>=')) {
            return $transient;
        }

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = array();
        }

        $transient->response[AUTOSEO_PLUGIN_BASENAME] = self::as_update($remote);

        return $transient;
    }

    /**
     * WordPress 5.8+ Update URI hostname hook.
     *
     * @param object|false $update
     * @param array        $plugin_data
     * @param string       $plugin_file
     * @param string[]     $locales
     * @return object|false
     */
    public static function update_from_uri($update, $plugin_data, $plugin_file, $locales) {
        unset($plugin_data, $locales);

        if ($plugin_file !== AUTOSEO_PLUGIN_BASENAME) {
            return $update;
        }

        $remote = self::remote();
        if (!$remote || empty($remote->new_version)) {
            return $update;
        }

        if (version_compare(AUTOSEO_VERSION, $remote->new_version, '>=')) {
            return $update;
        }

        return self::as_update($remote);
    }

    /**
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object|array
     */
    public static function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== self::SLUG) {
            return $result;
        }

        $remote = self::remote();
        if (!$remote) {
            return $result;
        }

        return (object) array(
            'name' => isset($remote->name) ? $remote->name : 'AI Content Publisher',
            'slug' => self::SLUG,
            'version' => $remote->new_version,
            'author' => '',
            'homepage' => $remote->url,
            'download_link' => $remote->package,
            'requires' => $remote->requires,
            'tested' => $remote->tested,
            'requires_php' => $remote->requires_php,
            'sections' => array(
                'description' => 'Updates come from your dashboard, not WordPress.org.',
            ),
        );
    }

    /**
     * @param object $remote
     * @return object
     */
    private static function as_update($remote) {
        return (object) array(
            'slug' => self::SLUG,
            'plugin' => AUTOSEO_PLUGIN_BASENAME,
            'new_version' => $remote->new_version,
            'url' => $remote->url,
            'package' => $remote->package,
            'tested' => $remote->tested,
            'requires' => $remote->requires,
            'requires_php' => $remote->requires_php,
        );
    }

    /**
     * @return object|false
     */
    private static function remote() {
        if (self::$remote !== null) {
            return self::$remote;
        }

        $cached = get_transient(self::CACHE_KEY);
        if (is_object($cached)) {
            self::$remote = $cached;
            return self::$remote;
        }

        $url = rtrim(AUTOSEO_API_BASE_URL, '/') . self::UPDATE_PATH;
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            self::$remote = false;
            return self::$remote;
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        if (!is_object($body) || empty($body->new_version) || empty($body->package)) {
            self::$remote = false;
            return self::$remote;
        }

        $remote = (object) array(
            'name' => isset($body->name) ? (string) $body->name : 'AI Content Publisher',
            'new_version' => (string) $body->new_version,
            'url' => isset($body->url) ? (string) $body->url : (string) $body->package,
            'package' => (string) $body->package,
            'requires' => isset($body->requires) ? (string) $body->requires : '5.0',
            'tested' => isset($body->tested) ? (string) $body->tested : '6.9',
            'requires_php' => isset($body->requires_php) ? (string) $body->requires_php : '7.4',
        );

        set_transient(self::CACHE_KEY, $remote, self::CACHE_TTL);
        self::$remote = $remote;

        return self::$remote;
    }
}
