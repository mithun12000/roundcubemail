<?php
/*
 +-------------------------------------------------------------------------+
 | Enigma Plugin for Roundcube                                             |
 |                                                                         |
 | Copyright (C) 2010-2015 The Roundcube Dev Team                          |
 |                                                                         |
 | Licensed under the GNU General Public License version 3 or              |
 | any later version with exceptions for skins & plugins.                  |
 | See the README file for a full license statement.                       |
 |                                                                         |
 +-------------------------------------------------------------------------+
 | Author: Aleksander Machniak <alec@alec.pl>                              |
 +-------------------------------------------------------------------------+
*/

/*
    This class contains only hooks and action handlers.
    Most plugin logic is placed in enigma_engine and enigma_ui classes.
*/

class enigma extends rcube_plugin
{
    public $task = 'mail|settings';
    public $rc;
    public $engine;

    private $env_loaded  = false;


    /**
     * Plugin initialization.
     */
    function init()
    {
        $this->rc = rcube::get_instance();

        if ($this->rc->task == 'mail') {
            // message parse/display hooks
            $this->add_hook('message_part_structure', array($this, 'part_structure'));
            $this->add_hook('message_part_body', array($this, 'part_body'));
            $this->add_hook('message_body_prefix', array($this, 'status_message'));

            $this->register_action('plugin.enigmaimport', array($this, 'import_file'));

            // message displaying
            if ($this->rc->action == 'show' || $this->rc->action == 'preview') {
                $this->add_hook('message_load', array($this, 'message_load'));
                $this->add_hook('template_object_messagebody', array($this, 'message_output'));
            }
            // message composing
            else if ($this->rc->action == 'compose') {
                $this->load_ui();
                $this->ui->init();
            }
            // message sending (and draft storing)
            else if ($this->rc->action == 'send') {
                $this->add_hook('message_ready', array($this, 'message_ready'));
            }

            $this->password_handler();
        }
        else if ($this->rc->task == 'settings') {
            // add hooks for Enigma settings
            $this->add_hook('settings_actions', array($this, 'settings_actions'));
            $this->add_hook('preferences_sections_list', array($this, 'preferences_sections_list'));
            $this->add_hook('preferences_list', array($this, 'preferences_list'));
            $this->add_hook('preferences_save', array($this, 'preferences_save'));

            // register handler for keys/certs management
//            $this->register_action('plugin.enigma', array($this, 'preferences_ui'));
            $this->register_action('plugin.enigmakeys', array($this, 'preferences_ui'));
//            $this->register_action('plugin.enigmacerts', array($this, 'preferences_ui'));

            $this->load_ui();
            $this->ui->add_css();
        }

        $this->add_hook('refresh', array($this, 'refresh'));
    }

    /**
     * Plugin environment initialization.
     */
    function load_env()
    {
        if ($this->env_loaded) {
            return;
        }

        $this->env_loaded = true;

        // Add include path for Enigma classes and drivers
        $include_path = $this->home . '/lib' . PATH_SEPARATOR;
        $include_path .= ini_get('include_path');
        set_include_path($include_path);

        // load the Enigma plugin configuration
        $this->load_config();

        // include localization (if wasn't included before)
        $this->add_texts('localization/');
    }

    /**
     * Plugin UI initialization.
     */
    function load_ui($all = false)
    {
        if (!$this->ui) {
            // load config/localization
            $this->load_env();

            // Load UI
            $this->ui = new enigma_ui($this, $this->home);
        }

        if ($all) {
            $this->ui->add_css();
            $this->ui->add_js();
        }
    }

    /**
     * Plugin engine initialization.
     */
    function load_engine()
    {
        if ($this->engine) {
            return $this->engine;
        }

        // load config/localization
        $this->load_env();

        return $this->engine = new enigma_engine($this);
    }

    /**
     * Handler for message_part_structure hook.
     * Called for every part of the message.
     *
     * @param array Original parameters
     *
     * @return array Modified parameters
     */
    function part_structure($p)
    {
        $this->load_engine();

        return $this->engine->part_structure($p);
    }

    /**
     * Handler for message_part_body hook.
     * Called to get body of a message part.
     *
     * @param array Original parameters
     *
     * @return array Modified parameters
     */
    function part_body($p)
    {
        $this->load_engine();

        return $this->engine->part_body($p);
    }

    /**
     * Handler for settings_actions hook.
     * Adds Enigma settings section into preferences.
     *
     * @param array Original parameters
     *
     * @return array Modified parameters
     */
    function settings_actions($args)
    {
        // add labels
        $this->add_texts('localization/');

        // register as settings action
        $args['actions'][] = array(
            'action' => 'plugin.enigmakeys',
            'class'  => 'enigma keys',
            'label'  => 'enigmakeys',
            'title'  => 'enigmakeys',
            'domain' => 'enigma',
        );
/*
        $args['actions'][] = array(
            'action' => 'plugin.enigmacerts',
            'class'  => 'enigma certs',
            'label'  => 'enigmacerts',
            'title'  => 'enigmacerts',
            'domain' => 'enigma',
        );
*/
        return $args;
    }

    /**
     * Handler for preferences_sections_list hook.
     * Adds Encryption settings section into preferences sections list.
     *
     * @param array Original parameters
     *
     * @return array Modified parameters
     */
    function preferences_sections_list($p)
    {
        $p['list']['enigma'] = array(
            'id' => 'enigma', 'section' => $this->gettext('encryption'),
        );

        return $p;
    }

    /**
     * Handler for preferences_list hook.
     * Adds options blocks into Enigma settings sections in Preferences.
     *
     * @param array Original parameters
     *
     * @return array Modified parameters
     */
    function preferences_list($p)
    {
        if ($p['section'] != 'enigma') {
            return $p;
        }

        $no_override = array_flip((array)$this->rc->config->get('dont_override'));

        $p['blocks']['main']['name'] = $this->gettext('mainoptions');

        if (!isset($no_override['enigma_sign_all'])) {
            if (!$p['current']) {
                $p['blocks']['main']['content'] = true;
                return $p;
            }

            $field_id = 'rcmfd_enigma_sign_all';
            $input    = new html_checkbox(array(
                    'name'  => '_enigma_sign_all',
                    'id'    => $field_id,
                    'value' => 1,
            ));

            $p['blocks']['main']['options']['enigma_sign_all'] = array(
                'title'   => html::label($field_id, $this->gettext('signdefault')),
                'content' => $input->show($this->rc->config->get('enigma_sign_all') ? 1 : 0),
            );
        }

        if (!isset($no_override['enigma_encrypt_all'])) {
            if (!$p['current']) {
                $p['blocks']['main']['content'] = true;
                return $p;
            }

            $field_id = 'rcmfd_enigma_encrypt_all';
            $input    = new html_checkbox(array(
                    'name'  => '_enigma_encrypt_all',
                    'id'    => $field_id,
                    'value' => 1,
            ));

            $p['blocks']['main']['options']['enigma_encrypt_all'] = array(
                'title'   => html::label($field_id, $this->gettext('encryptdefault')),
                'content' => $input->show($this->rc->config->get('enigma_encrypt_all') ? 1 : 0),
            );
        }

        return $p;
    }

    /**
     * Handler for preferences_save hook.
     * Executed on Enigma settings form submit.
     *
     * @param array Original parameters
     *
     * @return array Modified parameters
     */
    function preferences_save($p)
    {
        if ($p['section'] == 'enigma') {
            $p['prefs'] = array(
                'enigma_sign_all'    => intval(rcube_utils::get_input_value('_enigma_sign_all', rcube_utils::INPUT_POST)),
                'enigma_encrypt_all' => intval(rcube_utils::get_input_value('_enigma_encrypt_all', rcube_utils::INPUT_POST)),
            );
        }

        return $p;
    }

    /**
     * Handler for keys/certs management UI template.
     */
    function preferences_ui()
    {
        $this->load_ui();

        $this->ui->init();
    }

    /**
     * Handler for message_body_prefix hook.
     * Called for every displayed (content) part of the message.
     * Adds infobox about signature verification and/or decryption
     * status above the body.
     *
     * @param array Original parameters
     *
     * @return array Modified parameters
     */
    function status_message($p)
    {
        $this->load_ui();

        return $this->ui->status_message($p);
    }

    /**
     * Handler for message_load hook.
     * Check message bodies and attachments for keys/certs.
     */
    function message_load($p)
    {
        $this->load_ui();

        return $this->ui->message_load($p);
    }

    /**
     * Handler for template_object_messagebody hook.
     * This callback function adds a box below the message content
     * if there is a key/cert attachment available
     */
    function message_output($p)
    {
        $this->load_ui();

        return $this->ui->message_output($p);
    }

    /**
     * Handler for attached keys/certs import
     */
    function import_file()
    {
        $this->load_engine();

        $this->engine->import_file();
    }

    /**
     * Handle password submissions
     */
    function password_handler()
    {
        $this->load_engine();

        $this->engine->password_handler();
    }

    /**
     * Handle message_ready hook (encryption/signing)
     */
    function message_ready($p)
    {
        $this->load_ui();

        return $this->ui->message_ready($p);
    }

    /**
     * Handler for refresh hook.
     */
    function refresh($p)
    {
        // calling enigma_engine constructor to remove passwords
        // stored in session after expiration time
        $this->load_engine();

        return $p;
    }
}
