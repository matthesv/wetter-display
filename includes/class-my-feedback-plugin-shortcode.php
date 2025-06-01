<?php
if (!defined('ABSPATH')) {
    exit; // Sicherheitsabbruch
}

class My_Feedback_Plugin_Shortcode {

    public function __construct() {
        add_shortcode('feedback_voting', array($this, 'render_shortcode'));
    }

    /**
     * Rendert den Shortcode [feedback_voting question="..."]
     * Ermittelt außerdem die aktuelle Post-ID, um sie mitzuspeichern.
     */
    public function render_shortcode($atts) {
        // Standardwerte festlegen
        $atts = shortcode_atts(array(
            // Neue Default-Frage:
            'question' => __('War diese Antwort hilfreich?', 'feedback-voting'),
        ), $atts, 'feedback_voting');

        $question = $atts['question'];

        // Post-ID ermitteln
        global $post;
        $post_id = (is_object($post) && isset($post->ID)) ? $post->ID : 0;

        ob_start();
        ?>
        <!-- Hauptcontainer mit Rahmen -->
        <div class="feedback-voting-container"
             data-question="<?php echo esc_attr($question); ?>"
             data-postid="<?php echo esc_attr($post_id); ?>">

            <div class="feedback-voting-top-row">
                <p class="feedback-question"><?php echo esc_html($question); ?></p>

                <!-- Daumen hoch -->
                <button class="feedback-button feedback-yes" data-vote="yes">
                    <span class="dashicons dashicons-thumbs-up"></span>
                    <span class="button-text"><?php _e('Ja, war sie', 'feedback-voting'); ?></span>
                </button>

                <!-- Daumen runter -->
                <button class="feedback-button feedback-no" data-vote="no">
                    <span class="dashicons dashicons-thumbs-down"></span>
                    <span class="button-text"><?php _e('Nein, leider nicht', 'feedback-voting'); ?></span>
                </button>
            </div>
        </div>

        <!-- Separate Box ohne Rahmen für das "Nein"-Feedback -->
        <div class="feedback-no-text-box">
            <label for="feedback-no-text">
                <?php _e('Helfen Sie uns, was können wir besser machen?', 'feedback-voting'); ?>
            </label>
            <textarea
            class="feedback-no-text"
            id="feedback-no-text"
            rows="3"
            placeholder="<?php esc_attr_e('Hier können Sie uns Ihre Anregungen mitteilen (optional)', 'feedback-voting'); ?>"
            ></textarea>

            <button class="feedback-button feedback-submit-no">
                <span class="button-text"><?php _e('Feedback senden', 'feedback-voting'); ?></span>
            </button>
        </div>
        <?php
        return ob_get_clean();
    }
}