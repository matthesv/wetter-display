<?php
if (!defined('ABSPATH')) {
    exit; // Sicherheitsabbruch
}

class My_Feedback_Plugin_Ajax {

    public function __construct() {
        // AJAX Hooks: Für eingeloggte und ausgeloggte Benutzer
        add_action('wp_ajax_my_feedback_plugin_vote', array($this, 'handle_ajax_vote'));
        add_action('wp_ajax_nopriv_my_feedback_plugin_vote', array($this, 'handle_ajax_vote'));
    }

    /**
     * Nimmt per AJAX das Feedback entgegen und speichert bzw. aktualisiert es in der Datenbank.
     *
     * Ablauf:
     * - Wenn KEIN "vote_id" übergeben wird, legen wir einen neuen Datensatz an (Insert).
     * - Wenn "vote_id" übergeben wird, aktualisieren wir NUR das Feedback-Feld (Update).
     */
    public function handle_ajax_vote() {
        // Nonce-Check
        check_ajax_referer('feedback_nonce_action', 'security');

        global $wpdb;
        $table_name = $wpdb->prefix . 'feedback_votes';

        // Eingehende Werte
        $vote_id = isset($_POST['vote_id']) ? intval($_POST['vote_id']) : 0;
        $question = isset($_POST['question']) ? sanitize_text_field($_POST['question']) : '';
        $vote     = isset($_POST['vote']) ? sanitize_text_field($_POST['vote']) : '';
        $feedback = isset($_POST['feedback']) ? sanitize_textarea_field($_POST['feedback']) : '';
        $post_id  = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        // Beim allerersten Insert benötigen wir mindestens eine Frage und einen Vote.
        // Beim Update könnte es sein, dass wir (strikt genommen) vote/frage nicht mehr benötigen,
        // aber wir validieren dennoch den eingehenden Vote auf Korrektheit.
        if ($vote_id === 0) {
            // Neuer Eintrag (Insert)
            if (empty($question) || empty($vote)) {
                wp_send_json_error(array(
                    'message' => __('Ungültige Daten übermittelt.', 'feedback-voting')
                ));
            }

            $data = array(
                'question'      => $question,
                'vote'          => $vote,
                'feedback_text' => $feedback, // kann hier leer sein
                'post_id'       => $post_id,
                'created_at'    => current_time('mysql')
            );

            $format = array('%s','%s','%s','%d','%s');

            $result = $wpdb->insert($table_name, $data, $format);

            if ($result === false) {
                wp_send_json_error(array(
                    'message' => __('Fehler beim Speichern der Bewertung.', 'feedback-voting')
                ));
            }

            $insert_id = $wpdb->insert_id;
            wp_send_json_success(array(
                'message' => __('Bewertung erfolgreich gespeichert.', 'feedback-voting'),
                'vote_id' => $insert_id
            ));

        } else {
            // Update - hier wird nur das feedback_text-Feld nachträglich gefüllt
            // (der Vote 'no' ist bereits vorhanden)
            $update_result = $wpdb->update(
                $table_name,
                array('feedback_text' => $feedback),
                array('id' => $vote_id),
                array('%s'),
                array('%d')
            );

            if ($update_result === false) {
                wp_send_json_error(array(
                    'message' => __('Fehler beim Aktualisieren des Feedbacks.', 'feedback-voting')
                ));
            }

            wp_send_json_success(array(
                'message' => __('Feedback erfolgreich aktualisiert.', 'feedback-voting'),
                'vote_id' => $vote_id
            ));
        }
    }
}
