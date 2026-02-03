<?php

namespace ImageFocus;

/**
 * The class responsible for loading WordPress functionality and other classes
 *
 * Class ImageFocus
 * @package ImageFocus
 */
class ImageFocus
{
    public function __construct()
    {
        $this->addHooks();
    }

    /**
     * Make sure all hooks are being executed.
     */
    private function addHooks()
    {
        add_action('plugins_loaded', [$this, 'loadTextDomain']);
        add_action('admin_init', [$this, 'loadClasses']);
        add_action('wp_enqueue_media', [$this, 'mediaModalHotfix']);
    }

    /**
     * Hotfix for newer WordPress versions.
     *
     * Ensures the attachment details view outputs a data-id attribute.
     */
    public function mediaModalHotfix()
    {
        if (!is_admin()) {
            return;
        }

        wp_add_inline_script(
            'media-views',
            'wp.media.view.Attachment.Details=wp.media.view.Attachment.Details.extend({attributes:function(){return{"data-id":this.model.get("id")}}});'
        );
    }

    /**
     * Load the gettext plugin textdomain located in our language directory.
     */
    public function loadTextDomain()
    {
        load_plugin_textdomain(IMAGEFOCUS_TEXTDOMAIN, false, IMAGEFOCUS_LANGUAGES);
    }

    /**
     * Load all necessary classes
     */
    public function loadClasses()
    {
        /*
         * Load the resice service even if the current user is not allowed to upload files.
         * This is to prevent WordPress from falsely resizing images back to the default focus point.
         */
        new ResizeService();

        if (current_user_can('upload_files') === false) {
            return false;
        }

        new FocusPoint();
    }
}