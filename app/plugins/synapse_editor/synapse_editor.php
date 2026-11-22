<?php

/*!
 * SynapseMail
 * Copyright (c) 2026 SynapseCyber.io. All rights reserved.
 * Developed by Milad Ahmadipour (https://milad.io).
 *
 * Licensed under the Creative Commons Attribution-NonCommercial-NoDerivatives 4.0
 * International License (CC BY-NC-ND 4.0). You may use and share it free of charge
 * with attribution. You may not modify it, distribute modified versions, or sell it.
 * https://creativecommons.org/licenses/by-nc-nd/4.0/
 */

/**
 * SynapseMail: replaces Roundcube's TinyMCE with the Lexical editor (compose + identity signatures).
 * synapse_editor.js implements the rcube_text_editor API on top of lexical.bundle.js.
 */
class synapse_editor extends rcube_plugin
{
    public $task = 'mail|settings';

    public function init()
    {
        $this->add_hook('html_editor', [$this, 'html_editor']);
        $this->add_texts('localization/', true);
    }

    public function html_editor($args)
    {
        $rcmail = rcmail::get_instance();

        $this->include_script('lexical.bundle.js');
        $this->include_script('synapse_editor.js');

        $rcmail->output->set_env('editor_config', [
            'mode' => $args['mode'],
            'lang' => $_SESSION['language'] ?? 'en_US',
            'default_font' => $rcmail->config->get('default_font', ''),
            'top_posting' => (int) $rcmail->config->get('reply_mode') > 0,
        ]);
        $rcmail->output->add_label('close', 'signature', 'quote', 'undo');

        // core only registers editor_init for these modes after the (aborted) TinyMCE setup
        $ids = ['identity' => 'rcmfd_signature', 'response' => 'fftext'];
        if (!empty($ids[$args['mode']])) {
            $rcmail->output->add_script("rcmail.enable_command('toggle-editor', true); rcmail.editor_init(null, '{$ids[$args['mode']]}');", 'docready');
        }

        $args['abort'] = true;

        return $args;
    }
}
