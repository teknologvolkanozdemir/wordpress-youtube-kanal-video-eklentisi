<?php
/**
 * Plugin Name: WordPress YouTube Kanal Video Eklentisi
 * Description: YouTube kanal videolarını seçilen kategorilerde WordPress yazısı olarak yayımlar.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: WordPress YouTube Kanal Video Eklentisi
 * Text Domain: wordpress-youtube-kanal-video-eklentisi
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WordPress_YouTube_Channel_Video_Importer
{
    private const OPTION_NAME = 'wpykvi_channel_sources';
    private const CRON_HOOK = 'wpykvi_import_channel_videos';
    private const VIDEO_META_KEY = '_wpykvi_youtube_video_id';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_post_wpykvi_add_source', [$this, 'add_source']);
        add_action('admin_post_wpykvi_remove_source', [$this, 'remove_source']);
        add_action('admin_post_wpykvi_sync_sources', [$this, 'sync_now']);
        add_action(self::CRON_HOOK, [$this, 'sync_sources']);
    }

    public static function activate(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function add_admin_page(): void
    {
        add_menu_page(
            'YouTube Kanal Videoları',
            'YouTube Kanalları',
            'manage_options',
            'wpykvi-channels',
            [$this, 'render_admin_page'],
            'dashicons-video-alt3'
        );
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $sources = $this->get_sources();
        $categories = get_categories(['hide_empty' => false]);
        ?>
        <div class="wrap">
            <h1>YouTube Kanal Videoları</h1>
            <?php $this->render_notice(); ?>

            <h2>Yeni kanal kaynağı ekle</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wpykvi_add_source">
                <?php wp_nonce_field('wpykvi_add_source'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wpykvi-channel-url">YouTube kanal bağlantısı</label></th>
                        <td>
                            <input id="wpykvi-channel-url" name="channel_url" type="url" class="regular-text" required
                                placeholder="https://www.youtube.com/@kanal">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpykvi-category">WordPress kategorisi</label></th>
                        <td>
                            <select id="wpykvi-category" name="category_id" required>
                                <option value="">Kategori seçin</option>
                                <?php foreach ($categories as $category) : ?>
                                    <option value="<?php echo esc_attr((string) $category->term_id); ?>">
                                        <?php echo esc_html($category->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Kanalı ekle'); ?>
            </form>

            <hr>
            <h2>Eklenen kanallar</h2>
            <?php if (!$sources) : ?>
                <p>Henüz kanal eklenmedi.</p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Kanal bağlantısı</th>
                            <th>Kategori</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sources as $source) : ?>
                            <tr>
                                <td><a href="<?php echo esc_url($source['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($source['url']); ?></a></td>
                                <td><?php echo esc_html(get_cat_name((int) $source['category_id'])); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="wpykvi_remove_source">
                                        <input type="hidden" name="source_id" value="<?php echo esc_attr($source['id']); ?>">
                                        <?php wp_nonce_field('wpykvi_remove_source'); ?>
                                        <?php submit_button('Kaldır', 'delete small', 'submit', false); ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wpykvi_sync_sources">
                    <?php wp_nonce_field('wpykvi_sync_sources'); ?>
                    <?php submit_button('Kanalları şimdi kontrol et'); ?>
                </form>
            <?php endif; ?>
            <p>Eklenen kanallar saatte bir otomatik kontrol edilir. Yeni videolar seçilen kategoriyle yazı olarak yayımlanır.</p>
        </div>
        <?php
    }

    public function add_source(): void
    {
        $this->authorize_action('wpykvi_add_source');

        $url = isset($_POST['channel_url']) ? esc_url_raw(wp_unslash($_POST['channel_url'])) : '';
        $category_id = isset($_POST['category_id']) ? absint($_POST['category_id']) : 0;
        $category = get_term($category_id, 'category');

        if (!$url || !$category_id || is_wp_error($category) || !$category) {
            $this->redirect_with_status('invalid');
        }

        $channel_id = $this->resolve_channel_id($url);
        if (is_wp_error($channel_id)) {
            $this->redirect_with_status('invalid');
        }

        $sources = $this->get_sources();
        foreach ($sources as $source) {
            if ($source['channel_id'] === $channel_id) {
                $this->redirect_with_status('duplicate');
            }
        }

        $sources[] = [
            'id' => wp_generate_uuid4(),
            'url' => $url,
            'channel_id' => $channel_id,
            'category_id' => $category_id,
        ];
        update_option(self::OPTION_NAME, $sources, false);
        $this->redirect_with_status('added');
    }

    public function remove_source(): void
    {
        $this->authorize_action('wpykvi_remove_source');

        $source_id = isset($_POST['source_id']) ? sanitize_text_field(wp_unslash($_POST['source_id'])) : '';
        $sources = array_values(array_filter(
            $this->get_sources(),
            static function ($source) use ($source_id) {
                return $source['id'] !== $source_id;
            }
        ));
        update_option(self::OPTION_NAME, $sources, false);
        $this->redirect_with_status('removed');
    }

    public function sync_now(): void
    {
        $this->authorize_action('wpykvi_sync_sources');
        $imported = $this->sync_sources();
        $this->redirect_with_status('synced', ['imported' => $imported]);
    }

    public function sync_sources(): int
    {
        $imported = 0;
        foreach ($this->get_sources() as $source) {
            $imported += $this->import_channel($source);
        }

        return $imported;
    }

    private function import_channel(array $source): int
    {
        if (!function_exists('fetch_feed')) {
            require_once ABSPATH . WPINC . '/feed.php';
        }

        $feed_url = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode($source['channel_id']);
        $feed = fetch_feed($feed_url);
        if (is_wp_error($feed)) {
            return 0;
        }

        $imported = 0;
        $items = $feed->get_items(0, 15);
        if (!is_array($items)) {
            return 0;
        }

        foreach ($items as $item) {
            $video_url = (string) $item->get_link();
            $video_id = $this->get_video_id($video_url);
            if (!$video_id || $this->video_exists($video_id)) {
                continue;
            }

            $embed = wp_oembed_get('https://www.youtube.com/watch?v=' . rawurlencode($video_id));
            $description = trim(wp_strip_all_tags((string) $item->get_content()));
            $content = $embed ? $embed : 'https://www.youtube.com/watch?v=' . $video_id;
            if ($description !== '') {
                $content .= "\n\n" . esc_html($description);
            }

            $post_id = wp_insert_post([
                'post_title' => sanitize_text_field((string) $item->get_title()),
                'post_content' => $content,
                'post_status' => 'publish',
                'post_type' => 'post',
                'post_category' => [(int) $source['category_id']],
                'post_author' => (int) get_option('default_user', 1),
            ], true);
            if (is_wp_error($post_id)) {
                continue;
            }

            update_post_meta($post_id, self::VIDEO_META_KEY, $video_id);
            $imported++;
        }

        return $imported;
    }

    private function resolve_channel_id(string $url)
    {
        $parts = wp_parse_url($url);
        $allowed_hosts = ['youtube.com', 'www.youtube.com', 'm.youtube.com'];
        if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['host']), $allowed_hosts, true)) {
            return new WP_Error('invalid_channel_url');
        }

        if (!empty($parts['path']) && preg_match('~^/channel/(UC[A-Za-z0-9_-]{20,})/?$~', $parts['path'], $matches)) {
            return $matches[1];
        }

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            if (!empty($query['channel_id']) && preg_match('/^UC[A-Za-z0-9_-]{20,}$/', $query['channel_id'])) {
                return $query['channel_id'];
            }
        }

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'redirection' => 5,
            'user-agent' => 'WordPress YouTube Channel Video Importer',
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('channel_not_found');
        }

        $body = wp_remote_retrieve_body($response);
        if (preg_match('/"(?:channelId|externalChannelId)"\s*:\s*"(UC[A-Za-z0-9_-]{20,})"/', $body, $matches)) {
            return $matches[1];
        }
        if (preg_match('~youtube\.com/channel/(UC[A-Za-z0-9_-]{20,})~', $body, $matches)) {
            return $matches[1];
        }

        return new WP_Error('channel_not_found');
    }

    private function get_video_id(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['host']), ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            return '';
        }

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            if (!empty($query['v']) && preg_match('/^[A-Za-z0-9_-]{11}$/', $query['v'])) {
                return $query['v'];
            }
        }

        return '';
    }

    private function video_exists(string $video_id): bool
    {
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => self::VIDEO_META_KEY,
            'meta_value' => $video_id,
        ]);

        return !empty($posts);
    }

    private function get_sources(): array
    {
        $sources = get_option(self::OPTION_NAME, []);
        if (!is_array($sources)) {
            return [];
        }

        return array_values(array_filter($sources, static function ($source) {
            return is_array($source)
                && !empty($source['id'])
                && !empty($source['url'])
                && !empty($source['channel_id'])
                && !empty($source['category_id']);
        }));
    }

    private function authorize_action(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Bu işlem için yetkiniz yok.', 'wordpress-youtube-kanal-video-eklentisi'));
        }
        check_admin_referer($action);
    }

    private function redirect_with_status(string $status, array $extra = []): void
    {
        wp_safe_redirect(add_query_arg(
            array_merge(['page' => 'wpykvi-channels', 'wpykvi_status' => $status], $extra),
            admin_url('admin.php')
        ));
        exit;
    }

    private function render_notice(): void
    {
        $status = isset($_GET['wpykvi_status']) ? sanitize_key(wp_unslash($_GET['wpykvi_status'])) : '';
        $messages = [
            'added' => 'Kanal kaynağı eklendi.',
            'removed' => 'Kanal kaynağı kaldırıldı.',
            'duplicate' => 'Bu kanal zaten eklenmiş.',
            'invalid' => 'Kanal bağlantısı veya kategori geçersiz. YouTube kanal bağlantısı kullanın.',
        ];

        if ($status === 'synced') {
            $count = isset($_GET['imported']) ? absint($_GET['imported']) : 0;
            $message = sprintf('%d yeni video yazısı yayımlandı.', $count);
        } elseif (isset($messages[$status])) {
            $message = $messages[$status];
        } else {
            return;
        }
        ?>
        <?php $notice_type = in_array($status, ['invalid', 'duplicate'], true) ? 'error' : 'success'; ?>
        <div class="notice notice-<?php echo esc_attr($notice_type); ?> is-dismissible"><p><?php echo esc_html($message); ?></p></div>
        <?php
    }
}

register_activation_hook(__FILE__, [WordPress_YouTube_Channel_Video_Importer::class, 'activate']);
register_deactivation_hook(__FILE__, [WordPress_YouTube_Channel_Video_Importer::class, 'deactivate']);
new WordPress_YouTube_Channel_Video_Importer();
