<?php
/**
 * Plugin Name: Wetter Display
 * Plugin URI: https://matthesv.de
 * Description: Zeigt das aktuelle Wetter über einen Shortcode an - mit intelligenter Caching-Strategie
 * Version: 3.0.0
 * Author: Matthes Vogel
 * License: GPL v2 or later
 */

// Verhindere direkten Zugriff
if (!defined('ABSPATH')) {
    exit;
}

// Plugin-Konstanten definieren
define('WETTER_DISPLAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WETTER_DISPLAY_PLUGIN_URL', plugin_dir_url(__FILE__));

class WetterDisplay {
    
    private $option_name = 'wetter_display_options';
    private $cache_prefix = 'wetter_cache_';
    private $cache_group = 'wetter_display';
    private $cache_method;
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_shortcode('wetter', array($this, 'wetter_shortcode'));
        add_shortcode('wetter_icon', array($this, 'wetter_icon_shortcode'));
        
        // Cron Job für automatische Updates
        add_action('wp', array($this, 'schedule_weather_update'));
        add_action('wetter_update_hook', array($this, 'update_weather_data'));
        add_action('wetter_cache_cleanup_hook', array($this, 'cleanup_expired_cache'));
        
        // Plugin Deaktivierung
        register_deactivation_hook(__FILE__, array($this, 'deactivate_plugin'));
        
        // CSS und Icons laden
        add_action('wp_head', array($this, 'add_weather_styles'));
        
        // AJAX Handlers
        add_action('wp_ajax_test_weather_api', array($this, 'test_weather_api_callback'));
        add_action('wp_ajax_clear_weather_cache', array($this, 'clear_cache_callback'));
        add_action('wp_ajax_weather_cache_stats', array($this, 'cache_stats_callback'));
        
        // Cache cleanup scheduling
        $this->schedule_cache_cleanup();
    }
    
    public function init() {
        // Plugin initialisieren
        $this->init_cache_system();
    }
    
    /**
     * ===== INTELLIGENTE CACHING STRATEGIE =====
     */
    
    // Cache System initialisieren
    private function init_cache_system() {
        // Prüfe ob Object Cache verfügbar ist
        if (function_exists('wp_cache_supports') && wp_cache_supports('switch_to_blog')) {
            // Redis/Memcached verfügbar
            $this->cache_method = 'object_cache';
        } else {
            // Fallback auf Transients
            $this->cache_method = 'transients';
        }
        
        // Cache-Tabelle für erweiterte Funktionen erstellen
        $this->maybe_create_cache_table();
    }
    
    // Cache-Tabelle erstellen falls nicht vorhanden
    private function maybe_create_cache_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wetter_cache';
        
        // Prüfe ob Tabelle bereits existiert
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            return;
        }
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            cache_key varchar(191) NOT NULL,
            cache_value longtext NOT NULL,
            expiration_time datetime NOT NULL,
            hit_count int(11) DEFAULT 0,
            last_accessed datetime NOT NULL,
            data_size int(11) DEFAULT 0,
            api_endpoint varchar(255) DEFAULT '',
            location_hash varchar(32) DEFAULT '',
            created_time datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY cache_key (cache_key),
            KEY expiration_time (expiration_time),
            KEY location_hash (location_hash),
            KEY last_accessed (last_accessed)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    // Intelligente Cache-Schlüssel Generierung
    private function generate_cache_key($type, $params = array()) {
        $options = get_option($this->option_name);
        
        $key_parts = array(
            'wetter',
            $type,
            md5($options['latitude'] . '|' . $options['longitude']),
            date('Y-m-d-H') // Stündliche Aktualisierung
        );
        
        if (!empty($params)) {
            $key_parts[] = md5(serialize($params));
        }
        
        return implode('_', $key_parts);
    }
    
    // Multi-Level Cache Abruf
    private function get_cached_data($cache_key, $fallback_hours = 24) {
        // Level 1: Object Cache (Redis/Memcached)
        if ($this->cache_method === 'object_cache') {
            $data = wp_cache_get($cache_key, $this->cache_group);
            if ($data !== false) {
                $this->update_cache_stats($cache_key, 'hit', 'object_cache');
                return $data;
            }
        }
        
        // Level 2: WordPress Transients
        $data = get_transient($this->cache_prefix . $cache_key);
        if ($data !== false) {
            // Cache hit - in Object Cache speichern für schnelleren Zugriff
            if ($this->cache_method === 'object_cache') {
                wp_cache_set($cache_key, $data, $this->cache_group, 3600);
            }
            $this->update_cache_stats($cache_key, 'hit', 'transient');
            return $data;
        }
        
        // Level 3: Datenbank Cache (für Fallback-Daten)
        $fallback_data = $this->get_database_cache($cache_key, $fallback_hours);
        if ($fallback_data !== false) {
            $this->update_cache_stats($cache_key, 'hit', 'database_fallback');
            return $fallback_data;
        }
        
        $this->update_cache_stats($cache_key, 'miss');
        return false;
    }
    
    // Cache-Daten speichern
    private function set_cached_data($cache_key, $data, $expiration = 3600) {
        global $wpdb;
        
        $options = get_option($this->option_name);
        $cache_ttl = $this->calculate_intelligent_ttl($data);
        
        // Level 1: Object Cache
        if ($this->cache_method === 'object_cache') {
            wp_cache_set($cache_key, $data, $this->cache_group, $cache_ttl);
        }
        
        // Level 2: WordPress Transients
        set_transient($this->cache_prefix . $cache_key, $data, $cache_ttl);
        
        // Level 3: Datenbank für langfristige Speicherung und Statistiken
        $table_name = $wpdb->prefix . 'wetter_cache';
        $location_hash = md5($options['latitude'] . '|' . $options['longitude']);
        
        $wpdb->replace(
            $table_name,
            array(
                'cache_key' => $cache_key,
                'cache_value' => maybe_serialize($data),
                'expiration_time' => date('Y-m-d H:i:s', time() + $cache_ttl),
                'last_accessed' => current_time('mysql'),
                'data_size' => strlen(serialize($data)),
                'api_endpoint' => 'weather_api',
                'location_hash' => $location_hash,
                'created_time' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );
    }
    
    // Intelligente TTL Berechnung
    private function calculate_intelligent_ttl($weather_data) {
        $options = get_option($this->option_name);
        $base_interval = isset($options['update_interval']) ? intval($options['update_interval']) : 3;
        $base_ttl = $base_interval * 3600; // Basis TTL in Sekunden
        
        // Dynamische Anpassung basierend auf Wetterdaten
        if (isset($weather_data['data_day'])) {
            $today_data = $weather_data['data_day'];
            
            // Kürzere Cache-Zeit bei:
            // - Hoher Niederschlagswahrscheinlichkeit (häufige Änderungen)
            // - Geringer Vorhersagequalität
            // - Extremwetter
            
            $rain_prob = isset($today_data['precipitation_probability'][0]) ? $today_data['precipitation_probability'][0] : 0;
            $predictability = isset($today_data['predictability'][0]) ? $today_data['predictability'][0] : 50;
            $pictocode = isset($today_data['pictocode'][0]) ? $today_data['pictocode'][0] : 2;
            
            $ttl_modifier = 1.0;
            
            // Regen/Gewitter = kürzere Cache-Zeit
            if ($rain_prob > 70 || in_array($pictocode, [8, 9, 13, 14])) {
                $ttl_modifier *= 0.5; // Halbiere Cache-Zeit
            }
            
            // Geringe Vorhersagequalität = kürzere Cache-Zeit
            if ($predictability < 30) {
                $ttl_modifier *= 0.7;
            }
            
            // Stabiles Wetter = längere Cache-Zeit
            if ($rain_prob < 20 && $predictability > 70 && in_array($pictocode, [1, 2, 3])) {
                $ttl_modifier *= 1.5;
            }
            
            $calculated_ttl = $base_ttl * $ttl_modifier;
            
            // Grenzen setzen: min 30 Minuten, max 6 Stunden
            return max(1800, min(21600, $calculated_ttl));
        }
        
        return $base_ttl;
    }
    
    // Datenbank Cache abrufen (Fallback)
    private function get_database_cache($cache_key, $fallback_hours = 24) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wetter_cache';
        $fallback_time = date('Y-m-d H:i:s', time() - ($fallback_hours * 3600));
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT cache_value FROM $table_name 
             WHERE cache_key = %s AND created_time > %s 
             ORDER BY created_time DESC LIMIT 1",
            $cache_key,
            $fallback_time
        ));
        
        if ($result) {
            return maybe_unserialize($result);
        }
        
        return false;
    }
    
    // Cache-Statistiken aktualisieren
    private function update_cache_stats($cache_key, $type, $source = '') {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wetter_cache';
        
        if ($type === 'hit') {
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_name 
                 SET hit_count = hit_count + 1, last_accessed = %s 
                 WHERE cache_key = %s",
                current_time('mysql'),
                $cache_key
            ));
        }
        
        // Cache-Metriken für Admin-Dashboard
        $stats = get_option('wetter_cache_stats', array(
            'total_hits' => 0,
            'total_misses' => 0,
            'object_cache_hits' => 0,
            'transient_hits' => 0,
            'database_hits' => 0
        ));
        
        if ($type === 'hit') {
            $stats['total_hits']++;
            if ($source) {
                $stats[$source . '_hits'] = isset($stats[$source . '_hits']) ? $stats[$source . '_hits'] + 1 : 1;
            }
        } else {
            $stats['total_misses']++;
        }
        
        update_option('wetter_cache_stats', $stats);
    }
    
    // Cache-Cleanup Scheduler
    private function schedule_cache_cleanup() {
        if (!wp_next_scheduled('wetter_cache_cleanup_hook')) {
            wp_schedule_event(time(), 'daily', 'wetter_cache_cleanup_hook');
        }
    }
    
    // Abgelaufene Cache-Einträge bereinigen
    public function cleanup_expired_cache() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wetter_cache';
        
        // Lösche abgelaufene Einträge
        $deleted = $wpdb->query(
            "DELETE FROM $table_name WHERE expiration_time < NOW()"
        );
        
        // Lösche alte, selten genutzte Einträge (älter als 7 Tage, weniger als 5 Hits)
        $wpdb->query(
            "DELETE FROM $table_name 
             WHERE created_time < DATE_SUB(NOW(), INTERVAL 7 DAY) 
             AND hit_count < 5"
        );
        
        // Cache-Größe begrenzen: Behalte nur die 1000 neuesten Einträge
        $wpdb->query(
            "DELETE FROM $table_name 
             WHERE id NOT IN (
                 SELECT id FROM (
                     SELECT id FROM $table_name 
                     ORDER BY last_accessed DESC 
                     LIMIT 1000
                 ) as keep_these
             )"
        );
        
        // Cleanup-Statistiken
        $cleanup_stats = get_option('wetter_cleanup_stats', array());
        $cleanup_stats[date('Y-m-d')] = array(
            'deleted_entries' => $deleted,
            'cleanup_time' => current_time('mysql')
        );
        update_option('wetter_cleanup_stats', $cleanup_stats);
    }
    
    /**
     * ===== ADMIN INTERFACE =====
     */
    
    // Admin Menu hinzufügen
    public function add_admin_menu() {
        add_options_page(
            'Wetter Display Einstellungen',
            'Wetter Display',
            'manage_options',
            'wetter_display',
            array($this, 'options_page')
        );
    }
    
    // Einstellungen registrieren
    public function settings_init() {
        register_setting('wetter_display', $this->option_name);
        
        add_settings_section(
            'wetter_display_section',
            'Wetter API Einstellungen',
            array($this, 'settings_section_callback'),
            'wetter_display'
        );
        
        add_settings_field('api_key', 'API Key', array($this, 'api_key_render'), 'wetter_display', 'wetter_display_section');
        add_settings_field('city_name', 'Stadt Name', array($this, 'city_name_render'), 'wetter_display', 'wetter_display_section');
        add_settings_field('latitude', 'Breitengrad', array($this, 'latitude_render'), 'wetter_display', 'wetter_display_section');
        add_settings_field('longitude', 'Längengrad', array($this, 'longitude_render'), 'wetter_display', 'wetter_display_section');
        add_settings_field('update_interval', 'Update Intervall (Stunden)', array($this, 'update_interval_render'), 'wetter_display', 'wetter_display_section');
        add_settings_field('icon_style', 'Icon Style', array($this, 'icon_style_render'), 'wetter_display', 'wetter_display_section');
        
        // Cache Einstellungen
        add_settings_section(
            'wetter_cache_section',
            'Cache Einstellungen',
            array($this, 'cache_section_callback'),
            'wetter_display'
        );
        
        add_settings_field('cache_strategy', 'Cache Strategie', array($this, 'cache_strategy_render'), 'wetter_display', 'wetter_cache_section');
        add_settings_field('fallback_hours', 'Fallback Stunden', array($this, 'fallback_hours_render'), 'wetter_display', 'wetter_cache_section');
    }
    
    // Callback-Funktionen für Einstellungsfelder
    public function api_key_render() {
        $options = get_option($this->option_name);
        ?>
        <input type='text' name='<?php echo $this->option_name; ?>[api_key]' 
               value='<?php echo isset($options['api_key']) ? esc_attr($options['api_key']) : ''; ?>' 
               size='50' placeholder='Ihr API Key'>
        <p class="description">API Key von Ihrem Wetter-Service Provider</p>
        <?php
    }
    
    public function city_name_render() {
        $options = get_option($this->option_name);
        ?>
        <input type='text' name='<?php echo $this->option_name; ?>[city_name]' 
               value='<?php echo isset($options['city_name']) ? esc_attr($options['city_name']) : 'Berlin'; ?>' 
               placeholder='Berlin'>
        <p class="description">Name der Stadt für die Anzeige</p>
        <?php
    }
    
    public function latitude_render() {
        $options = get_option($this->option_name);
        ?>
        <input type='number' step='0.000001' name='<?php echo $this->option_name; ?>[latitude]' 
               value='<?php echo isset($options['latitude']) ? esc_attr($options['latitude']) : '52.5068'; ?>' 
               placeholder='52.5068'>
        <p class="description">Breitengrad der Stadt</p>
        <?php
    }
    
    public function longitude_render() {
        $options = get_option($this->option_name);
        ?>
        <input type='number' step='0.000001' name='<?php echo $this->option_name; ?>[longitude]' 
               value='<?php echo isset($options['longitude']) ? esc_attr($options['longitude']) : '13.09509'; ?>' 
               placeholder='13.09509'>
        <p class="description">Längengrad der Stadt</p>
        <?php
    }
    
    public function update_interval_render() {
        $options = get_option($this->option_name);
        $interval = isset($options['update_interval']) ? $options['update_interval'] : 3;
        ?>
        <select name='<?php echo $this->option_name; ?>[update_interval]'>
            <?php for($i = 1; $i <= 24; $i++): ?>
                <option value='<?php echo $i; ?>' <?php selected($interval, $i); ?>>
                    <?php echo $i; ?> Stunde<?php echo $i > 1 ? 'n' : ''; ?>
                </option>
            <?php endfor; ?>
        </select>
        <p class="description">Wie oft sollen die Wetterdaten aktualisiert werden?</p>
        <?php
    }
    
    public function icon_style_render() {
        $options = get_option($this->option_name);
        $style = isset($options['icon_style']) ? $options['icon_style'] : 'default';
        ?>
        <select name='<?php echo $this->option_name; ?>[icon_style]'>
            <option value='default' <?php selected($style, 'default'); ?>>Standard (Unicode Emojis)</option>
            <option value='colorful' <?php selected($style, 'colorful'); ?>>Farbig (CSS Icons)</option>
            <option value='minimal' <?php selected($style, 'minimal'); ?>>Minimal (Symbole)</option>
        </select>
        <p class="description">Wählen Sie den Stil für die Wetter-Icons</p>
        <?php
    }
    
    public function settings_section_callback() {
        echo '<p>Konfigurieren Sie hier Ihre Wetter-API Einstellungen.</p>';
    }
    
    public function cache_section_callback() {
        echo '<p>Konfigurieren Sie hier das Caching-Verhalten des Plugins.</p>';
    }
    
    public function cache_strategy_render() {
        $options = get_option($this->option_name);
        $strategy = isset($options['cache_strategy']) ? $options['cache_strategy'] : 'intelligent';
        ?>
        <select name='<?php echo $this->option_name; ?>[cache_strategy]'>
            <option value='intelligent' <?php selected($strategy, 'intelligent'); ?>>Intelligent (Empfohlen)</option>
            <option value='aggressive' <?php selected($strategy, 'aggressive'); ?>>Aggressiv (Mehr Caching)</option>
            <option value='conservative' <?php selected($strategy, 'conservative'); ?>>Konservativ (Weniger Caching)</option>
            <option value='disabled' <?php selected($strategy, 'disabled'); ?>>Deaktiviert</option>
        </select>
        <p class="description">Intelligent: Passt Cache-Zeit an Wetterbedingungen an</p>
        <?php
    }
    
    public function fallback_hours_render() {
        $options = get_option($this->option_name);
        $hours = isset($options['fallback_hours']) ? $options['fallback_hours'] : 24;
        ?>
        <input type='number' name='<?php echo $this->option_name; ?>[fallback_hours]' 
               value='<?php echo $hours; ?>' min='1' max='168'>
        <p class="description">Wie lange sollen alte Daten als Fallback verwendet werden? (1-168 Stunden)</p>
        <?php
    }
    
    // Admin Seite anzeigen
    public function options_page() {
        ?>
        <div class="wrap">
            <h1>Wetter Display Einstellungen</h1>
            
            <!-- Cache-Statistiken Box -->
            <div class="notice notice-info">
                <h3>📊 Cache Performance</h3>
                <div id="cache-stats-container">
                    <button type="button" class="button" onclick="loadCacheStats()">Cache-Statistiken laden</button>
                </div>
            </div>
            
            <form action='options.php' method='post'>
                <?php
                settings_fields('wetter_display');
                do_settings_sections('wetter_display');
                submit_button();
                ?>
            </form>
            
            <!-- Cache-Management -->
            <hr>
            <h2>🗄️ Cache Management</h2>
            <table class="form-table">
                <tr>
                    <th>Cache leeren</th>
                    <td>
                        <button type="button" class="button button-secondary" onclick="clearWeatherCache()">
                            Alle Cache-Daten löschen
                        </button>
                        <p class="description">Löscht alle gespeicherten Wetterdaten. Nächste Abfrage wird neue Daten laden.</p>
                    </td>
                </tr>
                <tr>
                    <th>Cache Status</th>
                    <td>
                        <span id="cache-method-info">
                            <strong>Aktive Methode:</strong> <?php echo $this->cache_method === 'object_cache' ? 'Object Cache (Redis/Memcached)' : 'WordPress Transients'; ?>
                        </span>
                        <br>
                        <span id="cache-table-info">
                            <?php
                            global $wpdb;
                            $table_name = $wpdb->prefix . 'wetter_cache';
                            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE 1");
                            echo "<strong>Gespeicherte Einträge:</strong> " . intval($count);
                            ?>
                        </span>
                    </td>
                </tr>
            </table>
            
            <hr>
            <h2>Shortcode Verwendung</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Shortcode</th>
                        <th>Beschreibung</th>
                        <th>Beispiel</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>[wetter]</code></td>
                        <td>Nur Text</td>
                        <td>Das Wetter in Berlin heute ist regnerisch mit 5,6mm Niederschlag...</td>
                    </tr>
                    <tr>
                        <td><code>[wetter_icon]</code></td>
                        <td>Icon + Text</td>
                        <td>🌧️ Das Wetter in Berlin heute ist regnerisch mit 5,6mm Niederschlag...</td>
                    </tr>
                    <tr>
                        <td><code>[wetter_icon size="large"]</code></td>
                        <td>Große Icons</td>
                        <td>Größere Darstellung der Icons</td>
                    </tr>
                    <tr>
                        <td><code>[wetter_icon layout="compact"]</code></td>
                        <td>Kompakte Ansicht</td>
                        <td>🌧️ Berlin: 16-24°C, 5,6mm Regen</td>
                    </tr>
                </tbody>
            </table>
            
            <hr>
            <h2>Wetter Test</h2>
            <button type="button" class="button" onclick="testWeatherAPI()">API Verbindung testen</button>
            <div id="weather-test-result"></div>
            
            <script>
            function loadCacheStats() {
                var container = document.getElementById('cache-stats-container');
                container.innerHTML = '<p>Lade Statistiken...</p>';
                
                jQuery.post(ajaxurl, {
                    action: 'weather_cache_stats',
                    nonce: '<?php echo wp_create_nonce('weather_cache_nonce'); ?>'
                }, function(response) {
                    if(response.success) {
                        container.innerHTML = response.data;
                    } else {
                        container.innerHTML = '<p style="color: red;">Fehler beim Laden der Statistiken</p>';
                    }
                });
            }
            
            function clearWeatherCache() {
                if(confirm('Sind Sie sicher, dass Sie alle Cache-Daten löschen möchten?')) {
                    jQuery.post(ajaxurl, {
                        action: 'clear_weather_cache',
                        nonce: '<?php echo wp_create_nonce('weather_cache_nonce'); ?>'
                    }, function(response) {
                        if(response.success) {
                            alert('Cache erfolgreich geleert!');
                            location.reload();
                        } else {
                            alert('Fehler beim Leeren des Cache: ' + response.data);
                        }
                    });
                }
            }
            
            function testWeatherAPI() {
                var result = document.getElementById('weather-test-result');
                result.innerHTML = '<p>Teste API Verbindung...</p>';
                
                jQuery.post(ajaxurl, {
                    action: 'test_weather_api',
                    nonce: '<?php echo wp_create_nonce('weather_test_nonce'); ?>'
                }, function(response) {
                    if(response.success) {
                        result.innerHTML = '<p style="color: green;">✓ API Test erfolgreich: ' + response.data + '</p>';
                    } else {
                        result.innerHTML = '<p style="color: red;">✗ API Test fehlgeschlagen: ' + response.data + '</p>';
                    }
                });
            }
            </script>
        </div>
        <?php
    }
    
    /**
     * ===== CRON & UPDATE FUNKTIONEN =====
     */
    
    // Cron Job für Updates planen
    public function schedule_weather_update() {
        if (!wp_next_scheduled('wetter_update_hook')) {
            wp_schedule_event(time(), 'hourly', 'wetter_update_hook');
        }
    }
    
    // Wetterdaten aktualisieren
    public function update_weather_data() {
        $options = get_option($this->option_name);
        $last_update = get_option('wetter_last_update', 0);
        $interval = isset($options['update_interval']) ? intval($options['update_interval']) : 3;
        
        // Prüfen ob Update nötig ist
        if (time() - $last_update < ($interval * 3600)) {
            return;
        }
        
        $weather_data = $this->fetch_weather_data(true);
        if ($weather_data) {
            $cache_key = $this->generate_cache_key('weather_data');
            $this->set_cached_data($cache_key, $weather_data);
            update_option('wetter_last_update', time());
        }
    }
    
    /**
     * ===== AJAX CALLBACKS =====
     */
    
    public function cache_stats_callback() {
        check_ajax_referer('weather_cache_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wetter_cache';
        $stats = get_option('wetter_cache_stats', array());
        
        // Datenbank-Statistiken
        $db_stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total_entries,
                COALESCE(SUM(hit_count), 0) as total_hits,
                COALESCE(AVG(data_size), 0) as avg_size,
                COALESCE(SUM(data_size), 0) as total_size
             FROM $table_name"
        );
        
        $hit_rate = ($stats['total_hits'] + $stats['total_misses']) > 0 
            ? round(($stats['total_hits'] / ($stats['total_hits'] + $stats['total_misses'])) * 100, 2) 
            : 0;
        
        $html = '<table class="widefat striped">';
        $html .= '<tr><th>Cache Hit Rate</th><td>' . $hit_rate . '%</td></tr>';
        $html .= '<tr><th>Gesamte Hits</th><td>' . intval($stats['total_hits']) . '</td></tr>';
        $html .= '<tr><th>Gesamte Misses</th><td>' . intval($stats['total_misses']) . '</td></tr>';
        $html .= '<tr><th>Aktive Einträge</th><td>' . intval($db_stats->total_entries) . '</td></tr>';
        $html .= '<tr><th>Cache-Größe</th><td>' . size_format($db_stats->total_size) . '</td></tr>';
        $html .= '<tr><th>Ø Eintragsgröße</th><td>' . size_format($db_stats->avg_size) . '</td></tr>';
        $html .= '</table>';
        
        wp_send_json_success($html);
    }
    
    public function clear_cache_callback() {
        check_ajax_referer('weather_cache_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        global $wpdb;
        
        // Alle Cache-Ebenen leeren
        wp_cache_flush_group($this->cache_group);
        
        // Transients löschen
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_{$this->cache_prefix}%' 
             OR option_name LIKE '_transient_timeout_{$this->cache_prefix}%'"
        );
        
        // Datenbank-Cache leeren
        $table_name = $wpdb->prefix . 'wetter_cache';
        $wpdb->query("TRUNCATE TABLE $table_name");
        
        // Statistiken zurücksetzen
        update_option('wetter_cache_stats', array(
            'total_hits' => 0,
            'total_misses' => 0,
            'object_cache_hits' => 0,
            'transient_hits' => 0,
            'database_hits' => 0
        ));
        
        wp_send_json_success('Cache erfolgreich geleert');
    }
    
    public function test_weather_api_callback() {
        check_ajax_referer('weather_test_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        // API Test ohne Cache
        $result = $this->fetch_weather_data(true); // Force refresh
        
        if ($result) {
            wp_send_json_success('API Verbindung erfolgreich!');
        } else {
            wp_send_json_error('API Verbindung fehlgeschlagen. Prüfen Sie Ihre Einstellungen.');
        }
    }
    
    /**
     * ===== CORE WETTER FUNKTIONEN =====
     */
    
    // Wetterdaten mit Cache abrufen
    public function get_weather_data($force_refresh = false) {
        $cache_key = $this->generate_cache_key('weather_data');
        
        if (!$force_refresh) {
            $cached_data = $this->get_cached_data($cache_key);
            if ($cached_data !== false) {
                return $cached_data;
            }
        }
        
        // Neue Daten von API abrufen
        $fresh_data = $this->fetch_weather_data();
        if ($fresh_data) {
            $this->set_cached_data($cache_key, $fresh_data);
            return $fresh_data;
        }
        
        // Fallback: Versuche alte Daten
        $options = get_option($this->option_name);
        $fallback_hours = isset($options['fallback_hours']) ? intval($options['fallback_hours']) : 24;
        return $this->get_cached_data($cache_key, $fallback_hours);
    }
    
    // API-Aufruf mit Fehlerbehandlung
    private function fetch_weather_data($bypass_cache = false) {
        $options = get_option($this->option_name);
        
        if (empty($options['api_key']) || empty($options['latitude']) || empty($options['longitude'])) {
            return false;
        }
        
        $api_url = sprintf(
            'https://my.meteoblue.com/packages/basic-day?lat=%s&lon=%s&apikey=%s',
            $options['latitude'],
            $options['longitude'],
            $options['api_key']
        );
        
        $response = wp_remote_get($api_url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WordPress Wetter Plugin 3.0'
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('Wetter Plugin API Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('Wetter Plugin API HTTP Error: ' . $response_code);
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Wetter Plugin JSON Error: ' . json_last_error_msg());
            return false;
        }
        
        return $data;
    }
    
    /**
     * ===== SHORTCODE FUNKTIONEN =====
     */
    
    // Standard Shortcode Handler (nur Text)
    public function wetter_shortcode($atts) {
        $atts = shortcode_atts(array('city' => null), $atts);
        $weather_data = $this->get_weather_data();
        
        if (!$weather_data) {
            return '<span class="wetter-error">Wetterdaten konnten nicht geladen werden.</span>';
        }
        
        return $this->format_weather_text($weather_data, $atts['city'], false);
    }
    
    // Icon Shortcode Handler (Icon + Text)
    public function wetter_icon_shortcode($atts) {
        $atts = shortcode_atts(array(
            'city' => null,
            'size' => 'normal',
            'layout' => 'full',
            'icon_only' => false
        ), $atts);
        
        $weather_data = $this->get_weather_data();
        
        if (!$weather_data) {
            return '<span class="wetter-error">🚫 Wetterdaten konnten nicht geladen werden.</span>';
        }
        
        return $this->format_weather_with_icon($weather_data, $atts);
    }
    
    // Wettertext ohne Icon formatieren
    private function format_weather_text($data, $custom_city = null, $with_icon = false) {
        $options = get_option($this->option_name);
        $city = $custom_city ?: (isset($options['city_name']) ? $options['city_name'] : 'Ihre Stadt');
        
        if (isset($data['data_day']) && !empty($data['data_day']['time'])) {
            $today_data = array(
                'temp_min' => round($data['data_day']['temperature_min'][0]),
                'temp_max' => round($data['data_day']['temperature_max'][0]),
                'precipitation' => $data['data_day']['precipitation'][0],
                'precipitation_prob' => $data['data_day']['precipitation_probability'][0],
                'pictocode' => $data['data_day']['pictocode'][0]
            );
            
            $weather_condition = $this->get_weather_condition($today_data['pictocode']);
            
            $text = "Das Wetter in {$city} heute ist {$weather_condition}";
            
            if ($today_data['precipitation'] > 0) {
                $text .= " mit {$today_data['precipitation']}mm Niederschlag";
            }
            
            $text .= ". Temperaturen zwischen {$today_data['temp_min']}°C und {$today_data['temp_max']}°C";
            
            if ($today_data['precipitation_prob'] > 50) {
                $text .= " ({$today_data['precipitation_prob']}% Regenwahrscheinlichkeit)";
            }
            
            $text .= ".";
            
            return '<span class="wetter-display">' . $text . '</span>';
        }
        
        return '<span class="wetter-error">Wetterdaten konnten nicht verarbeitet werden.</span>';
    }
    
    // Wettertext mit Icon formatieren
    private function format_weather_with_icon($data, $atts) {
        $options = get_option($this->option_name);
        $city = $atts['city'] ?: (isset($options['city_name']) ? $options['city_name'] : 'Ihre Stadt');
        
        if (isset($data['data_day']) && !empty($data['data_day']['time'])) {
            $today_data = array(
                'temp_min' => round($data['data_day']['temperature_min'][0]),
                'temp_max' => round($data['data_day']['temperature_max'][0]),
                'precipitation' => $data['data_day']['precipitation'][0],
                'precipitation_prob' => $data['data_day']['precipitation_probability'][0],
                'pictocode' => $data['data_day']['pictocode'][0]
            );
            
            $weather_condition = $this->get_weather_condition($today_data['pictocode']);
            $weather_icon = $this->get_weather_icon($today_data['pictocode'], $atts['size']);
            
            // Kompakte Darstellung
            if ($atts['layout'] === 'compact') {
                $text = "{$weather_icon} {$city}: {$today_data['temp_min']}-{$today_data['temp_max']}°C";
                if ($today_data['precipitation'] > 0) {
                    $text .= ", {$today_data['precipitation']}mm Regen";
                }
                return '<span class="wetter-display-icon wetter-compact">' . $text . '</span>';
            }
            
            // Nur Icon
            if ($atts['icon_only']) {
                return '<span class="wetter-icon-only ' . $atts['size'] . '">' . $weather_icon . '</span>';
            }
            
            // Vollständige Darstellung mit Icon
            $text = "{$weather_icon} Das Wetter in {$city} heute ist {$weather_condition}";
            
            if ($today_data['precipitation'] > 0) {
                $text .= " mit {$today_data['precipitation']}mm Niederschlag";
            }
            
            $text .= ". Temperaturen zwischen {$today_data['temp_min']}°C und {$today_data['temp_max']}°C";
            
            if ($today_data['precipitation_prob'] > 50) {
                $text .= " ({$today_data['precipitation_prob']}% Regenwahrscheinlichkeit)";
            }
            
            $text .= ".";
            
            return '<span class="wetter-display-icon ' . $atts['size'] . '">' . $text . '</span>';
        }
        
        return '<span class="wetter-error">🚫 Wetterdaten konnten nicht verarbeitet werden.</span>';
    }
    
    // Wettericon basierend auf Pictocode und Stil
    private function get_weather_icon($pictocode, $size = 'normal') {
        $options = get_option($this->option_name);
        $icon_style = isset($options['icon_style']) ? $options['icon_style'] : 'default';
        
        // Unicode Emoji Icons (Standard)
        $emoji_icons = array(
            1 => '☀️',    // sonnig
            2 => '🌤️',    // heiter
            3 => '⛅',    // bewölkt
            4 => '☁️',    // bedeckt
            5 => '🌦️',    // Regenschauer
            6 => '🌨',    // Regen und Schnee
            7 => '🌧️',    // leicht regnerisch
            8 => '🌧️',    // regnerisch
            9 => '⛈️',    // Gewitter
            10 => '🌫️',   // neblig
            11 => '🌨️',   // Schneeschauer
            12 => '❄️',   // verschneit
            13 => '💨',   // stürmisch
            14 => '🌪️',   // sehr stürmisch
            15 => '🧊',   // Hagel
            16 => '☁️'    // wolkig
        );
        
        // CSS gestylte Icons
        $css_icons = array(
            1 => '<span class="weather-icon weather-sunny">☀</span>',
            2 => '<span class="weather-icon weather-partly-cloudy">🌤</span>',
            3 => '<span class="weather-icon weather-cloudy">☁</span>',
            4 => '<span class="weather-icon weather-overcast">☁</span>',
            5 => '<span class="weather-icon weather-showers">🌦</span>',
            6 => '<span class="weather-icon weather-sleet">🌨</span>',
            7 => '<span class="weather-icon weather-light-rain">🌧</span>',
            8 => '<span class="weather-icon weather-rainy">🌧</span>',
            9 => '<span class="weather-icon weather-stormy">⛈</span>',
            10 => '<span class="weather-icon weather-foggy">🌫</span>',
            11 => '<span class="weather-icon weather-snow-showers">🌨</span>',
            12 => '<span class="weather-icon weather-snowy">❄</span>',
            13 => '<span class="weather-icon weather-windy">💨</span>',
            14 => '<span class="weather-icon weather-very-windy">🌪</span>',
            15 => '<span class="weather-icon weather-hail">🧊</span>',
            16 => '<span class="weather-icon weather-cloudy">☁</span>'
        );
        
        // Minimale Symbole
        $minimal_icons = array(
            1 => '●',     // sonnig
            2 => '◐',     // heiter
            3 => '○',     // bewölkt
            4 => '●',     // bedeckt
            5 => '⋯',     // Regenschauer
            6 => '※',     // Regen und Schnee
            7 => '⋯',     // leicht regnerisch
            8 => '⋯',     // regnerisch
            9 => '⚡',     // Gewitter
            10 => '≡',    // neblig
            11 => '❅',    // Schneeschauer
            12 => '❅',    // verschneit
            13 => '~',    // stürmisch
            14 => '~~',   // sehr stürmisch
            15 => '◇',    // Hagel
            16 => '○'     // wolkig
        );
        
        $icon = '';
        switch ($icon_style) {
            case 'colorful':
                $icon = isset($css_icons[$pictocode]) ? $css_icons[$pictocode] : $css_icons[16];
                break;
            case 'minimal':
                $icon = isset($minimal_icons[$pictocode]) ? $minimal_icons[$pictocode] : $minimal_icons[16];
                break;
            default:
                $icon = isset($emoji_icons[$pictocode]) ? $emoji_icons[$pictocode] : $emoji_icons[16];
                break;
        }
        
        return $icon;
    }
    
    // Wetterbedingung basierend auf Pictocode
    private function get_weather_condition($pictocode) {
        $conditions = array(
            1 => 'sonnig',
            2 => 'heiter',
            3 => 'bewölkt',
            4 => 'bedeckt',
            5 => 'Regenschauer',
            6 => 'Regen und Schnee',
            7 => 'leicht regnerisch',
            8 => 'regnerisch',
            9 => 'Gewitter',
            10 => 'neblig',
            11 => 'Schneeschauer',
            12 => 'verschneit',
            13 => 'stürmisch',
            14 => 'sehr stürmisch',
            15 => 'Hagel',
            16 => 'wolkig'
        );
        
        return isset($conditions[$pictocode]) ? $conditions[$pictocode] : 'unbekannt';
    }
    
    /**
     * ===== CSS STYLES =====
     */
    
    // CSS Styles hinzufügen
    public function add_weather_styles() {
        ?>
        <style>
        /* Basis Styles */
        .wetter-display {
            font-family: Arial, sans-serif;
            background: #f0f8ff;
            padding: 10px 15px;
            border-left: 4px solid #4a90e2;
            border-radius: 4px;
            display: inline-block;
            margin: 10px 0;
            line-height: 1.4;
        }
        
        .wetter-display-icon {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #e3f2fd 0%, #f0f8ff 100%);
            padding: 15px 20px;
            border-left: 4px solid #4a90e2;
            border-radius: 8px;
            display: inline-block;
            margin: 10px 0;
            line-height: 1.5;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .wetter-compact {
            padding: 8px 12px;
            font-size: 0.9em;
            background: #f8f9fa;
            border-radius: 20px;
            border: 1px solid #e9ecef;
        }
        
        .wetter-error {
            color: #d32f2f;
            font-style: italic;
        }
        
        /* Icon Größen */
        .wetter-display-icon.small {
            font-size: 0.8em;
            padding: 8px 12px;
        }
        
        .wetter-display-icon.large {
            font-size: 1.2em;
            padding: 20px 25px;
        }
        
        .wetter-icon-only {
            font-size: 2em;
            display: inline-block;
            margin: 5px;
        }
        
        .wetter-icon-only.small { font-size: 1.5em; }
        .wetter-icon-only.large { font-size: 3em; }
        
        /* CSS Icon Styles */
        .weather-icon {
            display: inline-block;
            margin-right: 5px;
            font-size: 1.2em;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.1);
        }
        
        .weather-sunny { color: #ffb74d; text-shadow: 0 0 10px #ffa726; }
        .weather-partly-cloudy { color: #90a4ae; }
        .weather-cloudy { color: #78909c; }
        .weather-overcast { color: #607d8b; }
        .weather-showers { color: #42a5f5; }
        .weather-light-rain { color: #5c6bc0; }
        .weather-rainy { color: #3f51b5; }
        .weather-stormy { color: #673ab7; animation: flash 2s infinite; }
        .weather-foggy { color: #9e9e9e; opacity: 0.8; }
        .weather-snow-showers { color: #81d4fa; }
        .weather-snowy { color: #e1f5fe; text-shadow: 0 0 5px #b3e5fc; }
        .weather-windy { color: #455a64; animation: sway 1s ease-in-out infinite; }
        .weather-very-windy { color: #37474f; animation: shake 0.5s infinite; }
        .weather-hail { color: #b0bec5; }
        .weather-sleet { color: #78909c; }
        
        /* Animationen */
        @keyframes flash {
            0%, 50%, 100% { opacity: 1; }
            25%, 75% { opacity: 0.5; }
        }
        
        @keyframes sway {
            0%, 100% { transform: translateX(0); }
            50% { transform: translateX(3px); }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-2px); }
            75% { transform: translateX(2px); }
        }
        </style>
        <?php
    }
    
    /**
     * ===== PLUGIN DEAKTIVIERUNG =====
     */
    
    // Plugin deaktivieren
    public function deactivate_plugin() {
        wp_clear_scheduled_hook('wetter_update_hook');
        wp_clear_scheduled_hook('wetter_cache_cleanup_hook');
        
        // Optional: Cache-Daten beim Deaktivieren löschen
        global $wpdb;
        $table_name = $wpdb->prefix . 'wetter_cache';
        // $wpdb->query("DROP TABLE IF EXISTS $table_name"); // Nur wenn gewünscht
        
        delete_option('wetter_cached_data');
        delete_option('wetter_last_update');
        delete_option('wetter_cache_stats');
        delete_option('wetter_cleanup_stats');
    }
}

// Plugin initialisieren
new WetterDisplay();

// Update Checker (nur wenn die Datei existiert)
if (file_exists(WETTER_DISPLAY_PLUGIN_DIR . 'includes/plugin-update-checker/plugin-update-checker.php')) {
    require_once WETTER_DISPLAY_PLUGIN_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';
    
    if (class_exists('YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
        $myUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/matthesv/wetter-display/',
            __FILE__,
            'wetter-display'
        );
        $myUpdateChecker->setBranch('main');
    }
}