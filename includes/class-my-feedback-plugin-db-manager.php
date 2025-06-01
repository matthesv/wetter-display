<?php
if (!defined('ABSPATH')) {
    exit; // Sicherheitsabbruch
}

/**
 * Kümmert sich um die Erstellung und Aktualisierung der benötigten DB-Tabelle.
 */
class My_Feedback_Plugin_DB_Manager {

    /**
     * Wird beim Aktivieren des Plugins ausgeführt.
     * Legt (bzw. aktualisiert) eine eigene Datenbank-Tabelle an und setzt Standard-Einstellungen.
     */
    public static function activate() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'feedback_votes';
        $charset_collate = $wpdb->get_charset_collate();

        // Angepasst: Keine "DEFAULT CURRENT_TIMESTAMP"
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            question TEXT NOT NULL,
            vote VARCHAR(10) NOT NULL,
            feedback_text TEXT NULL,
            post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Standard-Option für das Freitextfeld bei "Nein" (1 = aktiviert)
        add_option('feedback_voting_enable_feedback_field', '1');

        // DB-Versionsinfo abspeichern
        update_option('feedback_voting_db_version', FEEDBACK_VOTING_DB_VERSION);
    }

    /**
     * Wird beim Deaktivieren des Plugins ausgeführt.
     * (Derzeit leer, könnte man aber für Aufräumarbeiten verwenden.)
     */
    public static function deactivate() {
        // Ggf. weitere Aufräumarbeiten (optionale Implementierung).
    }

    /**
     * Prüft bei jedem Laden (plugins_loaded), ob wir ein DB-Update durchführen müssen.
     */
    public static function maybe_update_db() {
        $installed_version = get_option('feedback_voting_db_version', '0.0.0');
        if (version_compare($installed_version, FEEDBACK_VOTING_DB_VERSION, '<')) {
            // Falls ein Update nötig ist, unsere Aktivierungsroutine aufrufen:
            self::activate();
        }
    }
}
