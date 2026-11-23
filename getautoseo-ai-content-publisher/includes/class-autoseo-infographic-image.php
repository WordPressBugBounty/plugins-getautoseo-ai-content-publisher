<?php
/**
 * Infographic image markup.
 *
 * WordPress replaces an upload whose longest side is over 2560px with a
 * "-scaled" file and then treats that copy as the "full" size. Tall
 * infographics become 600–1100px wide. Themes stretch that copy, so the
 * graphic looks soft. The original file stays on disk.
 *
 * This helper is WordPress-free except for the attachment lookup, so unit
 * tests can load it. Hero images and featured images are not handled here.
 */

if (class_exists('AutoSEO_Infographic_Image')) {
    return;
}

class AutoSEO_Infographic_Image {

    /**
     * Layout width of the infographic design, in CSS pixels.
     * A 2x capture is about twice this wide in the file. The img width
     * attribute must stay at this layout size.
     */
    const LAYOUT_WIDTH = 1200;

    /**
     * Run a sideload while WordPress is not allowed to shrink the file.
     * The filter is removed even when the callback throws.
     *
     * @param callable $callback
     * @return mixed
     */
    public static function without_big_image_scaling($callback) {
        if (!function_exists('add_filter')) {
            return $callback();
        }

        $filter = static function () {
            return false;
        };
        add_filter('big_image_size_threshold', $filter, 9999);
        try {
            return $callback();
        } finally {
            remove_filter('big_image_size_threshold', $filter, 9999);
        }
    }

    /**
     * URL, pixel width, and pixel height of the file to show.
     * Prefers the pre-scaled original when WordPress kept one and the file exists.
     *
     * @param int $attachment_id
     * @return array{url: string, width: int, height: int}|null
     */
    public static function attachment_source($attachment_id) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0 || !function_exists('wp_get_attachment_metadata')) {
            return null;
        }

        $url = '';
        $width = 0;
        $height = 0;
        $meta = wp_get_attachment_metadata($attachment_id);

        if (is_array($meta) && !empty($meta['original_image']) && function_exists('wp_get_original_image_url')) {
            $original_url = wp_get_original_image_url($attachment_id);
            $original_path = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($attachment_id) : '';
            if (is_string($original_url) && $original_url !== '' && is_string($original_path) && $original_path !== '' && is_file($original_path)) {
                $url = $original_url;
                $size = function_exists('wp_getimagesize') ? @wp_getimagesize($original_path) : @getimagesize($original_path);
                if (is_array($size)) {
                    $width = (int) $size[0];
                    $height = (int) $size[1];
                }
            }
        }

        if ($url === '' && function_exists('wp_get_attachment_image_src')) {
            $src = wp_get_attachment_image_src($attachment_id, 'full');
            if (is_array($src) && !empty($src[0])) {
                $url = (string) $src[0];
                $width = (int) ($src[1] ?? 0);
                $height = (int) ($src[2] ?? 0);
            }
        }

        if ($url === '') {
            return null;
        }

        $url = self::prefer_original_upload_url($url);

        return array(
            'url' => $url,
            'width' => $width,
            'height' => $height,
        );
    }

    /**
     * @param int $attachment_id
     * @param string $alt
     * @return string Empty when the attachment has no image URL.
     */
    public static function image_tag_for_attachment($attachment_id, $alt) {
        $source = self::attachment_source($attachment_id);
        if ($source === null) {
            return '';
        }

        return self::image_tag($source['url'], $source['width'], $source['height'], $alt);
    }

    /**
     * Img tag for one infographic. No srcset: a srcset that stops at the
     * scaled file makes the browser ignore the full original.
     *
     * @param string $url
     * @param int $pixel_width
     * @param int $pixel_height
     * @param string $alt
     * @return string
     */
    public static function image_tag($url, $pixel_width, $pixel_height, $alt) {
        $display = self::display_dimensions($pixel_width, $pixel_height);
        $html = '<img src="' . self::esc_url_value($url) . '"';
        if ($display['width'] > 0 && $display['height'] > 0) {
            $html .= ' width="' . (int) $display['width'] . '" height="' . (int) $display['height'] . '"';
        }
        $html .= ' class="autoseo-infographic-image skip-lazy no-lazy"';
        $html .= ' alt="' . self::esc_attr_value($alt) . '"';
        $html .= ' loading="eager" decoding="async"';
        $html .= ' data-no-lazy="1" data-skip-lazy="1"';
        $html .= ' style="max-width:100%;height:auto;" />';

        return $html;
    }

    /**
     * CSS layout size. Files wider than the 1200px design are 2x captures.
     * Half of the pixel size is the layout size, and it never goes above 1200.
     *
     * @param int $pixel_width
     * @param int $pixel_height
     * @return array{width: int, height: int}
     */
    public static function display_dimensions($pixel_width, $pixel_height) {
        $pixel_width = (int) $pixel_width;
        $pixel_height = (int) $pixel_height;
        if ($pixel_width <= 0 || $pixel_height <= 0) {
            return array('width' => 0, 'height' => 0);
        }
        if ($pixel_width <= self::LAYOUT_WIDTH) {
            return array('width' => $pixel_width, 'height' => $pixel_height);
        }

        $display_width = (int) round($pixel_width / 2);
        if ($display_width > self::LAYOUT_WIDTH) {
            $display_width = self::LAYOUT_WIDTH;
        }
        if ($display_width < 1) {
            $display_width = 1;
        }
        $display_height = (int) max(1, round($pixel_height * ($display_width / $pixel_width)));

        return array('width' => $display_width, 'height' => $display_height);
    }

    /**
     * Put the full-resolution infographic into stored or rendered content.
     * Replaces the first infographic image when a new tag is given, then
     * rewrites any remaining "-scaled" infographic src.
     *
     * @param string $content
     * @param string $image_tag
     * @return string
     */
    public static function prepare_content($content, $image_tag = '') {
        if (!is_string($content)) {
            return $content;
        }

        $content = self::replace_first_infographic_image($content, $image_tag);

        return self::swap_scaled_infographic_sources($content);
    }

    /**
     * @param string $content
     * @param string $image_tag
     * @return string
     */
    public static function replace_first_infographic_image($content, $image_tag) {
        if (!is_string($content) || !is_string($image_tag) || $image_tag === '') {
            return $content;
        }

        $replacement = '<div class="autoseo-infographic-container">' . $image_tag . '</div>';
        $updated = preg_replace_callback(
            self::container_img_pattern(true),
            static function () use ($replacement) {
                return $replacement;
            },
            $content,
            1,
            $count
        );

        if (!is_string($updated) || $count === 0) {
            return $content;
        }

        return $updated;
    }

    /**
     * Point an infographic img at the sibling original and drop srcset.
     * Images outside the infographic container are left alone.
     *
     * @param string $content
     * @return string
     */
    public static function swap_scaled_infographic_sources($content) {
        if (!is_string($content) || $content === '' || stripos($content, 'autoseo-infographic-container') === false) {
            return $content;
        }

        $updated = preg_replace_callback(
            self::container_img_pattern(false),
            static function ($matches) {
                $img_pos = strripos($matches[0], '<img');
                if ($img_pos === false) {
                    return $matches[0];
                }

                return substr($matches[0], 0, $img_pos) . self::retarget_img_tag(substr($matches[0], $img_pos));
            },
            $content
        );

        return is_string($updated) ? $updated : $content;
    }

    /**
     * Remove the WordPress "-scaled" suffix that sits immediately before the extension.
     *
     * @param string $url
     * @return string
     */
    public static function unscaled_upload_url($url) {
        if (!is_string($url) || $url === '') {
            return $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['path'])) {
            return $url;
        }

        $count = 0;
        $path = preg_replace('/-scaled(\.[A-Za-z0-9]+)$/', '$1', $parts['path'], 1, $count);
        if (!$count || !is_string($path)) {
            return $url;
        }

        $rebuilt = '';
        if (!empty($parts['scheme'])) {
            $rebuilt .= $parts['scheme'] . '://';
        }
        if (!empty($parts['host'])) {
            $rebuilt .= $parts['host'];
        }
        if (!empty($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $path;
        if (isset($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }

    /**
     * Use the original upload when that file is on disk.
     * When WordPress is not loaded, the caller cannot check the disk, so the
     * unscaled URL is returned. When the original file is missing, the scaled URL stays.
     *
     * @param string $url
     * @return string
     */
    public static function prefer_original_upload_url($url) {
        $original = self::unscaled_upload_url($url);
        if ($original === $url) {
            return $url;
        }
        if (!function_exists('wp_upload_dir')) {
            return $original;
        }

        $path = self::upload_url_to_path($original);
        if ($path === '' || !is_file($path)) {
            return $url;
        }

        return $original;
    }

    /**
     * @param string $img_tag
     * @return string
     */
    public static function retarget_img_tag($img_tag) {
        if (!is_string($img_tag) || $img_tag === '') {
            return $img_tag;
        }

        $img_tag = preg_replace('/\s(?:srcset|sizes)\s*=\s*(["\']).*?\1/is', '', $img_tag);
        if (!is_string($img_tag)) {
            return $img_tag;
        }

        $retargeted = preg_replace_callback(
            '/\ssrc\s*=\s*(["\'])(.*?)\1/is',
            static function ($matches) {
                $url = html_entity_decode($matches[2], ENT_QUOTES, 'UTF-8');
                $url = self::prefer_original_upload_url($url);

                return ' src=' . $matches[1] . self::esc_attr_value($url) . $matches[1];
            },
            $img_tag,
            1
        );

        return is_string($retargeted) ? $retargeted : $img_tag;
    }

    /**
     * @param bool $include_closing_div
     * @return string
     */
    private static function container_img_pattern($include_closing_div) {
        $pattern = '/<div\b[^>]*\bclass=(["\'])([^"\']*\s)?autoseo-infographic-container(\s[^"\']*)?\1[^>]*>\s*<img\b[^>]*>/is';
        if ($include_closing_div) {
            $pattern = '/<div\b[^>]*\bclass=(["\'])([^"\']*\s)?autoseo-infographic-container(\s[^"\']*)?\1[^>]*>\s*<img\b[^>]*>\s*<\/div>/is';
        }

        return $pattern;
    }

    /**
     * @param string $url
     * @return string Absolute path, or an empty string when the URL is not in the uploads folder.
     */
    private static function upload_url_to_path($url) {
        if (!function_exists('wp_upload_dir')) {
            return '';
        }

        $uploads = wp_upload_dir();
        $baseurl = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';
        $basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        if ($baseurl === '' || $basedir === '' || !is_string($url) || strpos($url, $baseurl) !== 0) {
            return '';
        }

        $relative = substr($url, strlen($baseurl));
        $relative = strtok($relative, '?');
        $relative = strtok($relative, '#');
        if (!is_string($relative) || $relative === '' || strpos($relative, '..') !== false) {
            return '';
        }

        return $basedir . $relative;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function esc_attr_value($value) {
        $value = (string) $value;
        if (function_exists('esc_attr')) {
            return esc_attr($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param string $value
     * @return string
     */
    private static function esc_url_value($value) {
        $value = (string) $value;
        if (function_exists('esc_url')) {
            return esc_url($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
