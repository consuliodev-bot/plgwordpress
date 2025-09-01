<?php
if (!defined('ABSPATH')) exit;

class AlfaAI_Admin_Sites {

    const OPTION = 'alfaai_pro_external_site_urls';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'handle_post']);
    }

    public static function menu() {
        // Lo mettiamo in "Impostazioni" per evitare conflitti con altri menu.
        add_options_page(
            'AlfaAI – External Sites',
            'AlfaAI External Sites',
            'manage_options',
            'alfaai-external-sites',
            [__CLASS__, 'render_page']
        );
    }

    public static function handle_post() {
        if (!isset($_POST['alfaai_sites_save'])) return;
        if (!current_user_can('manage_options')) return;
        check_admin_referer('alfaai_sites_save_nonce', 'alfaai_sites_nonce');

        $map = [];
        if (!empty($_POST['site_url']) && is_array($_POST['site_url'])) {
            foreach ($_POST['site_url'] as $key => $url) {
                $key = sanitize_text_field($key);
                $url = trim((string)$url);
                if ($url === '') continue;
                // normalizza URL
                if (!preg_match('#^https?://#i', $url)) {
                    $url = 'https://' . ltrim($url, '/');
                }
                $map[$key] = esc_url_raw($url);
            }
        }
        update_option(self::OPTION, $map);
        add_settings_error('alfaai_sites', 'saved', 'Impostazioni salvate.', 'updated');
    }

    private static function db_key($db) {
        $name = isset($db->name) ? trim((string)$db->name) : '';
        if ($name !== '') return $name;
        $host = isset($db->host) ? trim((string)$db->host) : '';
        $database = isset($db->database) ? trim((string)$db->database) : '';
        return strtolower($host . '|' . $database);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return;

        // Prendi i DB esterni dal plugin
        $dbs = [];
        if (class_exists('AlfaAI_Database') && method_exists('AlfaAI_Database', 'get_external_databases')) {
            $dbs = AlfaAI_Database::get_external_databases();
        }
        if (!is_array($dbs)) $dbs = [];

        $saved = get_option(self::OPTION, []);
        settings_errors('alfaai_sites');
        ?>
        <div class="wrap">
            <h1>AlfaAI – External Sites (link per ogni DB)</h1>
            <p>Qui puoi associare il <strong>sito web</strong> (es. <code>https://www.alfassa.org</code>) a ciascun database esterno.  
               L’AI userà questi link quando deve mostrare gli <strong>articoli</strong> o rimandare l’utente al sito corretto.</p>

            <form method="post">
                <?php wp_nonce_field('alfaai_sites_save_nonce', 'alfaai_sites_nonce'); ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:18%">Chiave DB</th>
                            <th style="width:16%">Nome</th>
                            <th style="width:18%">Host</th>
                            <th style="width:16%">Database</th>
                            <th>Site URL (https://...)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($dbs)): ?>
                        <tr><td colspan="5">Nessun database esterno configurato.</td></tr>
                    <?php else: ?>
                        <?php foreach ($dbs as $row): $db = (object)$row;
                            $key = self::db_key($db);
                            $val = $saved[$key] ?? ($db->site_url ?? '');
                        ?>
                        <tr>
                            <td><code><?php echo esc_html($key); ?></code></td>
                            <td><?php echo esc_html($db->name ?? ''); ?></td>
                            <td><?php echo esc_html($db->host ?? ''); ?></td>
                            <td><?php echo esc_html($db->database ?? ''); ?></td>
                            <td>
                                <input type="text" class="regular-text" name="site_url[<?php echo esc_attr($key); ?>]"
                                       value="<?php echo esc_attr($val); ?>" placeholder="https://www.alfassa.org">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" name="alfaai_sites_save" class="button button-primary">Salva</button>
                </p>
            </form>

            <h2>Note</h2>
            <ul style="list-style: disc; padding-left: 20px;">
                <li>I siti ufficiali Alfassa sono <strong>https://www.alfassa.net</strong> e <strong>https://www.alfassa.org</strong>.</li>
                <li>La chiave DB è il <em>nome</em> del DB esterno; se assente, viene generata come <code>host|database</code>.</li>
                <li>Se non imposti nulla, l’AI proverà a dedurre l’URL da <code>wp_options.siteurl</code> del sito remoto.</li>
            </ul>
        </div>
        <?php
    }
}
