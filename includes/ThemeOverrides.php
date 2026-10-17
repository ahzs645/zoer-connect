<?php
namespace ZoerConnect;

/**
 * Read-only summary of a block theme's Site Editor copies (GET /theme-overrides, 0.7.0).
 * Saved templates, template parts and styles are database records that win over the theme's
 * files, so a code-only theme update can look like it did nothing. Names and dates only:
 * no content, IDs of other records or user data.
 */
final class ThemeOverrides {
    public const LIMIT = 200;

    public static function theme($value, string $active): string {
        $theme = $value === null || $value === '' ? $active : $value;
        if (!is_string($theme) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,79}$/D', $theme)) throw new \InvalidArgumentException('Choose a theme folder.');
        return $theme;
    }

    /** @param object $db wpdb */
    public static function summary($db, string $theme, string $active, ?string $themeDir, array $folders = []): array {
        $folders += ['wp_template' => 'templates', 'wp_template_part' => 'parts'];
        $join = "FROM {$db->posts} p JOIN {$db->term_relationships} tr ON tr.object_id=p.ID JOIN {$db->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='wp_theme' JOIN {$db->terms} t ON t.term_id=tt.term_id";
        $rows = $db->get_results($db->prepare("SELECT p.ID,p.post_type,p.post_name,p.post_title,p.post_modified_gmt $join WHERE t.name=%s AND p.post_type IN ('wp_template','wp_template_part') AND p.post_status IN ('publish','draft','pending','private','future') ORDER BY p.ID LIMIT " . (self::LIMIT + 1), $theme), ARRAY_A) ?: [];
        $items = [];
        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $type = $row['post_type'] === 'wp_template' ? 'template' : 'part';
            $slug = (string) $row['post_name'];
            if (!preg_match('/^[A-Za-z0-9._-]{1,200}$/D', $slug)) continue;
            $path = $folders[$row['post_type']] . '/' . $slug . '.html';
            $items[] = ['type' => $type, 'slug' => $slug, 'title' => (string) $row['post_title'], 'modified' => (string) $row['post_modified_gmt'], 'path' => $path, 'hasThemeFile' => $themeDir !== null && is_file($themeDir . '/' . $path)];
        }
        $styles = $db->get_row($db->prepare("SELECT p.post_content,p.post_modified_gmt $join WHERE t.name=%s AND p.post_type='wp_global_styles' AND p.post_status='publish' ORDER BY p.post_date DESC, p.ID DESC LIMIT 1", $theme), ARRAY_A);
        $user = is_array($styles) ? json_decode((string) $styles['post_content'], true) : null;
        $customized = is_array($user) && !empty($user['isGlobalStylesUserThemeJSON']) && (!empty($user['settings']) || !empty($user['styles']));
        if ($customized) $items[] = ['type' => 'styles', 'slug' => 'theme.json', 'title' => 'Styles', 'modified' => (string) $styles['post_modified_gmt'], 'path' => 'theme.json', 'hasThemeFile' => $themeDir !== null && is_file($themeDir . '/theme.json')];
        return ['theme' => $theme, 'active' => $theme === $active, 'installed' => $themeDir !== null && is_dir($themeDir), 'items' => $items, 'truncated' => count($rows) > self::LIMIT];
    }
}
