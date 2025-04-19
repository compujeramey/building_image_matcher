<?php
/**
 * Plugin Name: Building Featured Image Importer
 * Description: Automatically assigns featured images to Building posts using NextGEN Gallery tags. Logs actions. Allows review, undo, and manual selection.
 * Version: 2.4
 * Author: Jeramey Jannene
 */

if (!defined('ABSPATH')) exit;

class Building_Featured_Image_Importer {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function add_admin_menu() {
        add_menu_page(
            'Building Image Importer',
            'Building Image Importer',
            'manage_options',
            'building-image-importer',
            [$this, 'importer_page'],
            'dashicons-format-image'
        );

        add_submenu_page(
            'building-image-importer',
            'Assignment Log',
            'Assignment Log',
            'manage_options',
            'building-image-log',
            [$this, 'log_page']
        );
    }

    public function enqueue_scripts($hook) {
        if (isset($_GET['page']) && $_GET['page'] === 'building-image-importer') {
            wp_enqueue_script(
                'building-importer-auto',
                plugin_dir_url(__FILE__) . 'autopager.js',
                [],
                null,
                true
            );
            wp_localize_script('building-importer-auto', 'BuildingImporter', [
                'nextPageUrl' => $this->get_next_url(),
                'ajaxUrl'     => admin_url('admin-ajax.php')
            ]);
        }
    }

    public function importer_page() {
        $paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
        $batch = isset($_GET['batch']) ? intval($_GET['batch']) : 1;
        $per_page = 200;

        echo "<div class='wrap'><h1>Building Image Importer</h1>";
        echo "<p><strong>Batch:</strong> {$batch} | <strong>Page:</strong> {$paged}</p>";

        $this->process_batch($paged, $per_page, $batch);
        echo "</div>";
    }

    private function find_existing_media_by_hash($filename, $source_path) {
        if (!file_exists($source_path)) return false;

        $source_hash = sha1_file($source_path);
        if (!$source_hash) return false;

        $args = [
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'meta_query'  => [
                [
                    'key'     => '_wp_attached_file',
                    'value'   => $filename,
                    'compare' => 'LIKE'
                ]
            ]
        ];

        $query = new WP_Query($args);
        if (!$query->have_posts()) return false;

        foreach ($query->posts as $attachment) {
            $existing_path = get_attached_file($attachment->ID);
            if (file_exists($existing_path) && sha1_file($existing_path) === $source_hash) {
                return $attachment->ID;
            }
        }

        return false;
    }


    public function log_page() {
        echo "<div class='wrap'><h1>Assignment Log</h1>";
        global $wpdb;

        // Handle manual image assignment
        if (isset($_GET['set_image']) && isset($_GET['post'])) {
            $pid = intval($_GET['post']);
            $image_pid = intval($_GET['set_image']);

            $ng_image = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ngg_pictures WHERE pid = %d", $image_pid));
            $gallery = $wpdb->get_row($wpdb->prepare("SELECT path FROM {$wpdb->prefix}ngg_gallery WHERE gid = %d", $ng_image->galleryid));

            if ($ng_image && $gallery) {
                $path = trailingslashit($gallery->path);
                $filename = basename($ng_image->filename);
                $original_path = ABSPATH . $path . $filename;
                $backup_path = $original_path . '_backup';
                $source_path = file_exists($backup_path) ? $backup_path : (file_exists($original_path) ? $original_path : '');

                if ($source_path && file_exists($source_path)) {
                    $upload_dir = wp_upload_dir();
                    $dest_path = $upload_dir['path'] . '/' . $filename;

                    $existing_id = $this->find_existing_media_by_hash($filename, $source_path);
                    if ($existing_id) {
                        set_post_thumbnail($pid, $existing_id);
                        update_post_meta($pid, 'assigned_featured_image_log', [
                            'attachment_id' => $existing_id,
                            'filename'      => $filename,
                            'tag'           => $ng_image->alttext,
                            'time'          => current_time('mysql')
                        ]);
                        echo "<div class='updated'><p>🔁 Reused existing image for Building ID {$pid}.</p></div>";
                    } else {
                        if (copy($source_path, $dest_path)) {
                            $caption = $ng_image->description;
                            if (stripos($caption, 'Photo') === 0) {
                                $caption .= ' — ' . $ng_image->alttext;
                            }

                            $attachment = [
                                'post_mime_type' => mime_content_type($dest_path),
                                'post_title'     => $caption,
                                'post_content'   => '',
                                'post_status'    => 'inherit'
                            ];

                            $attach_id = wp_insert_attachment($attachment, $dest_path, $pid);
                            require_once ABSPATH . 'wp-admin/includes/image.php';
                            $attach_data = wp_generate_attachment_metadata($attach_id, $dest_path);
                            wp_update_attachment_metadata($attach_id, $attach_data);

                            update_post_meta($attach_id, '_wp_attachment_image_alt', $caption);
                            wp_update_post([
                                'ID'           => $attach_id,
                                'post_excerpt' => $caption,
                                'post_content' => $caption
                            ]);

                            set_post_thumbnail($pid, $attach_id);
                            update_post_meta($pid, 'assigned_featured_image_log', [
                                'attachment_id' => $attach_id,
                                'filename'      => $filename,
                                'tag'           => $ng_image->alttext,
                                'time'          => current_time('mysql')
                            ]);

                            echo "<div class='updated'><p>✅ Image manually assigned for Building ID {$pid}.</p></div>";
                        }
                    }
                }
            }
        }

        if (isset($_GET['select'])) {
            $pid = intval($_GET['select']);
            $post = get_post($pid);
            $tag = function_exists('get_nggtag') ? get_nggtag($pid, $post->post_title) : '';

            echo "<h2>Select Image for <em>{$post->post_title}</em></h2>";

            $images = $wpdb->get_results($wpdb->prepare("
                SELECT p.*, g.path
                FROM {$wpdb->prefix}ngg_pictures p
                INNER JOIN {$wpdb->prefix}ngg_gallery g ON p.galleryid = g.gid
                INNER JOIN {$wpdb->prefix}term_relationships tr ON p.pid = tr.object_id
                INNER JOIN {$wpdb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->prefix}terms t ON tt.term_id = t.term_id
                WHERE tt.taxonomy = 'ngg_tag' AND t.slug = %s
            ", sanitize_title($tag)));

            if ($images) {
                echo "<div style='display: flex; flex-wrap: wrap; gap: 12px;'>";

                foreach ($images as $img) {
                    $rel_path = preg_replace('#^wp-content/#', '', $img->path);
                    $src = content_url(trailingslashit($rel_path) . $img->filename);
                    $link = admin_url("admin.php?page=building-image-log&set_image={$img->pid}&post={$pid}");

                    echo "<div style='text-align:center;'>
                            <img src='" . esc_url($src) . "' style='max-height:120px; display:block; margin-bottom:5px;'>
                            <a href='" . esc_url($link) . "' class='button'>Use This Image</a>
                          </div>";
                }

                echo "</div>";
            } else {
                echo "<p>No images found with tag <code>{$tag}</code>.</p>";
            }

            echo "<p><a href='" . admin_url('admin.php?page=building-image-log') . "' class='button'>← Back to Log</a></p>";
            echo "</div>";
            return;
        }

        // Confirm
        if (!empty($_GET['confirm_log'])) {
            delete_post_meta((int) $_GET['confirm_log'], 'assigned_featured_image_log');
            echo "<div class='updated'><p>Confirmed and cleared log.</p></div>";
        }

        // Undo
        if (!empty($_GET['undo_log'])) {
            $pid = (int) $_GET['undo_log'];
            $log = get_post_meta($pid, 'assigned_featured_image_log', true);
            if (!empty($log['attachment_id'])) {
                $used_elsewhere = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d AND post_id != %d",
                    $log['attachment_id'], $pid
                ));
                if ($used_elsewhere === 0) {
                    wp_delete_attachment($log['attachment_id'], true);
                }
            }
            delete_post_thumbnail($pid);
            delete_post_meta($pid, 'assigned_featured_image_log');
            echo "<div class='updated'><p>Undone and image removed if unused elsewhere.</p></div>";
        }

        // Assignment log table
        $args = [
            'post_type'      => 'building',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_key'       => 'assigned_featured_image_log'
        ];
        $query = new WP_Query($args);

        if ($query->have_posts()) {
            echo "<table class='widefat striped'><thead><tr><th>Building</th><th>Image</th><th>Tag</th><th>Time</th><th>Actions</th></tr></thead><tbody>";

            foreach ($query->posts as $post) {
                $log = get_post_meta($post->ID, 'assigned_featured_image_log', true);
                $image_id = $log['attachment_id'] ?? null;
                if ($image_id) {
                    $thumb = wp_get_attachment_image($image_id, 'thumbnail');
                    $image_title = esc_html(get_the_title($image_id));
                    $image = "{$thumb}<br><small>{$image_title}</small>";
                } else {
                    $image = '—';
                }
                $tag = $log['tag'] ?? '—';
                $time = $log['time'] ?? '—';
                $confirm_url = admin_url("admin.php?page=building-image-log&confirm_log={$post->ID}");
                $undo_url = admin_url("admin.php?page=building-image-log&undo_log={$post->ID}");
                $manual_url = admin_url("admin.php?page=building-image-log&select={$post->ID}");

                echo "<tr>
                        <td>
                            <a href='" . get_edit_post_link($post->ID) . "'>" . esc_html($post->post_title) . "</a>
                            <br><a href='" . get_permalink($post->ID) . "' target='_blank'>(view)</a>
                        </td>

                        <td>{$image}</td>
                        <td><code>{$tag}</code></td>
                        <td>{$time}</td>
                        <td>
                            <a href='" . esc_url($confirm_url) . "' class='button'>Confirm</a>
                            <a href='" . esc_url($undo_url) . "' class='button button-secondary'>Undo</a>
                            <a href='" . esc_url($manual_url) . "' class='button button-secondary'>Select Image Manually</a>
                        </td>
                      </tr>";
            }

            echo "</tbody></table>";
        } else {
            echo "<p>No logged assignments found.</p>";
        }

        wp_reset_postdata();
        echo "</div>";
    }


    private function get_next_url() {
        $paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
        $batch = isset($_GET['batch']) ? intval($_GET['batch']) : 1;
        return admin_url("admin.php?page=building-image-importer&batch={$batch}&paged=" . ($paged + 1));
    }

    private function process_batch($paged, $per_page, $batch) {
        global $wpdb;
        $batch_offset = ($batch - 1) * 200;
        $post_offset = $batch_offset + (($paged - 1) * $per_page);

        $args = [
            'post_type'      => 'building',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'offset'         => $post_offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_query'     => [
                ['key' => '_thumbnail_id', 'compare' => 'NOT EXISTS']
            ]
        ];

        $query = new WP_Query($args);
        if (!$query->have_posts()) {
            echo "<p><strong>✅ Done with this batch.</strong></p>";
            echo "<script>window.stopAutoPagination = true;</script>";
            return;
        }

        echo "<table class='widefat striped'><thead><tr><th>Building</th><th>Status</th></tr></thead><tbody>";

        foreach ($query->posts as $post) {
            $building_title = $post->post_title;
            $tag = function_exists('get_nggtag') ? get_nggtag($post->ID, $building_title) : '';

            $ng_image = $wpdb->get_row($wpdb->prepare("
                SELECT p.* FROM {$wpdb->prefix}ngg_pictures p
                INNER JOIN {$wpdb->prefix}term_relationships tr ON p.pid = tr.object_id
                INNER JOIN {$wpdb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->prefix}terms t ON tt.term_id = t.term_id
                WHERE tt.taxonomy = 'ngg_tag' AND t.slug = %s
                LIMIT 1
            ", sanitize_title($tag)));

            echo "<tr><td><strong>{$building_title}</strong></td>";

            if ($ng_image) {
                $gallery = $wpdb->get_row($wpdb->prepare("SELECT path FROM {$wpdb->prefix}ngg_gallery WHERE gid = %d", $ng_image->galleryid));
                if ($gallery) {
                    $path = trailingslashit($gallery->path);
                    $filename = basename($ng_image->filename);
                    $original_path = ABSPATH . $path . $filename;
                    $backup_path = $original_path . '_backup';
                    $source_path = file_exists($backup_path) ? $backup_path : (file_exists($original_path) ? $original_path : '');

                    if (!$source_path || !file_exists($source_path)) {
                        echo "<td>❌ File not found</td></tr>";
                        continue;
                    }

                    $upload_dir = wp_upload_dir();
                    $dest_path = $upload_dir['path'] . '/' . $filename;

                    $existing_id = $this->find_existing_media_by_hash($filename, $source_path);
                    if ($existing_id) {
                        set_post_thumbnail($post->ID, $existing_id);
                        update_post_meta($post->ID, 'assigned_featured_image_log', [
                            'attachment_id' => $existing_id,
                            'filename'      => $filename,
                            'tag'           => $tag,
                            'time'          => current_time('mysql')
                        ]);
                        echo "<td>🔁 Re-used existing {$filename}</td></tr>";
                        continue;
                    }

                    if (!copy($source_path, $dest_path)) {
                        echo "<td>❌ Failed to copy</td></tr>";
                        continue;
                    }

                    $caption = $ng_image->description;
                    if (stripos($caption, 'Photo') === 0) {
                        $caption = $ng_image->alttext . ". " . $caption;
                    }

                    $attachment = [
                        'post_mime_type' => mime_content_type($dest_path),
                        'post_title'     => $caption,
                        'post_content'   => '',
                        'post_status'    => 'inherit'
                    ];

                    $attach_id = wp_insert_attachment($attachment, $dest_path, $post->ID);
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                    $attach_data = wp_generate_attachment_metadata($attach_id, $dest_path);
                    wp_update_attachment_metadata($attach_id, $attach_data);

                    update_post_meta($attach_id, '_wp_attachment_image_alt', $caption);
                    wp_update_post([
                        'ID'           => $attach_id,
                        'post_excerpt' => $caption,
                        'post_content' => $caption
                    ]);

                    set_post_thumbnail($post->ID, $attach_id);
                    update_post_meta($post->ID, '_imported_featured_image_id', $attach_id);
                    update_post_meta($post->ID, 'assigned_featured_image_log', [
                        'attachment_id' => $attach_id,
                        'filename'      => $filename,
                        'tag'           => $tag,
                        'time'          => current_time('mysql')
                    ]);

                    echo "<td>✅ Assigned {$filename}</td></tr>";
                } else {
                    echo "<td>❌ Gallery missing</td></tr>";
                }
            } else {
                echo "<td>❌ No image for tag: <code>{$tag}</code></td></tr>";
            }
        }

        echo "</tbody></table>";
        wp_reset_postdata();
    }

}

new Building_Featured_Image_Importer();
