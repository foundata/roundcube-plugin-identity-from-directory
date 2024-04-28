<?php

/**
 * Maintains a user's identities from a central directory on each login.
 *
 * @license SPDX-License-Identifier: GPL-3.0-or-later
 * @copyright SPDX-FileCopyrightText: foundata GmbH <https://foundata.com>
 */
class identity_from_directory extends rcube_plugin
{
    public $task = 'login';

    private $rc;
    private $ldap;


    /**
     * Plugin initialization. API hooks binding.
     */
    public function init()
    {
        $this->rc = rcmail::get_instance();

        // Triggered when a somebody logs in the first time and a local user is created.
        // https://github.com/roundcube/roundcubemail/wiki/Plugin-Hooks#user_create
        // This plugin is using it to create the first, main identity
        $this->add_hook('user_create', [$this, 'lookup_user_name']);

        // Triggered after a user successfully logged in
        // https://github.com/roundcube/roundcubemail/wiki/Plugin-Hooks#login_after
        // This plugin is using it to update / edit existing identities
        $this->add_hook('login_after', [$this, 'login_after']);
    }


    /**
     * 'user_create' hook handler, used to grab and prepare the user data from LDAP
     */
    public function lookup_user_name($args)
    {
        if ($this->init_ldap([
            'mail_host' => $this->rc->user->data['mail_host'],
        ])) {
            $debug_plugin = $this->rc->config->get('identity_from_directory_debug');
            $ad_handle_proxyaddresses = $this->rc->config->get('identity_from_directory_handle_proxyaddresses');

            // '*' does NOT search all fields but triggers the usage of 'search_fields' instead, see
            // https://github.com/roundcube/roundcubemail/blob/master/program/lib/Roundcube/rcube_ldap.php#L900C49-L900C62
            //
            // This 'search_fields' array gets set to the plugin's $config['identity_from_directory_ldap']['search_fields']
            // as connection property by $this->init_ldap(). So searching in '*' limits the field to the plugin's config.
            $results = $this->ldap->search('*', $args['user'], true);

            if (count($results->records) === 1) {
                $ldap_entry = $results->records[0];
                if ($debug_plugin) {
                    rcube::write_log('identity_from_directory_ldap', 'Found a record for' . $args['user'] . ': '.print_r($ldap_entry, true));
                }

                $user_name = is_array($ldap_entry['name']) ? $ldap_entry['name'][0] : $ldap_entry['name'];
                $user_email = is_array($ldap_entry['email']) ? $ldap_entry['email'][0] : $ldap_entry['email'];

                $args['user_name'] = $user_name;
                $args['email_list'] = [];
                $args['email_default'] = '';

                if (empty($args['user_email']) && strpos($user_email, '@')) {
                    $args['user_email'] = rcube_utils::idn_to_ascii($user_email);
                }

                if (!empty($args['user_email'])) {
                    $args['email_list'][] = $args['user_email'];
                }

                foreach (array_keys($ldap_entry) as $key) {
                    // add email addresses (main, aliases) to to the list for the user
                    if (preg_match('/^email($|:)/', $key)) {
                        foreach ((array) $ldap_entry[$key] as $alias) {
                            if (strpos($alias, '@')) {
                                $args['email_list'][] = rcube_utils::idn_to_ascii($alias);
                            }
                        }
                    } elseif ($ad_handle_proxyaddresses &&
                              preg_match('/^proxyaddresses($|:)/', $key)) {
                        // Handle Active Directory attribute proxyAddresses, originally a CSV string
                        // like 'smtp:foo@exmaple.com,bar@example.net'. Returned as string if there
                        // is only one, returned as array if there are multiple.
                        $proxyaddresses = $ldap_entry[$key];
                        if (!is_array($proxyaddresses)) {
                            $proxyaddresses = [ $proxyaddresses ];
                        }
                        foreach ((array) $proxyaddresses as $alias) {
                            $alias = trim($alias);
                            if (empty($alias)) {
                                continue;
                            }
                            $alias = trim(preg_replace('/^smtp:(.+)/', '\1', $alias, 1));
                            if (strpos($alias, '@') !== false) {
                                $args['email_list'][] = rcube_utils::idn_to_ascii($alias);
                            }
                        }
                    // add LDAP data as long as it does not overwrite already existing keys and exclude _ID, _raw_attrib etc.)
                    } elseif (strpos($key, '_') !== 0 && !array_key_exists($key, $args)) {
                        $args[$key] = $ldap_entry[$key];
                    }
                }

                $args['email_list'] = array_unique($args['email_list']);
                $args['email_default'] = $args['user_email'];

            } elseif ($debug_plugin && count($results->records) > 1) {
               rcube::write_log('identity_from_directory_ldap', 'Searching for ' . $args['user'] . ' returned more then one result, all where ignored as unambiguous assignment is not possible.');
            }
        }

        return $args;
    }


    /**
     * 'login_after' hook handler, used to create or update the user identities
     */
    public function login_after($args)
    {
        $this->load_config('config.inc.php.dist'); // load the plugin's distribution config file as default
        $this->load_config(); // merge with local configuration file (which can overwrite any settings)

        if ($this->ldap) {
            return $args;
        }

        $identities = $this->rc->user->list_emails();
        $user_data = $this->lookup_user_name([
            'user' => $this->rc->user->data['username'],
            'mail_host' => $this->rc->user->data['mail_host'],
        ]);

        if (empty($user_data['email_list'])) {
            return $args;
        }

        $debug_plugin = $this->rc->config->get('identity_from_directory_debug');
        $ldap_config = (array) $this->rc->config->get('identity_from_directory_ldap');
        $delete_unmanaged = (bool) $this->rc->config->get('identity_from_directory_delete_unmanaged');
        $update_signatures = (bool) $this->rc->config->get('identity_from_directory_update_signatures');
        $use_html_signature = (bool) $this->rc->config->get('identity_from_directory_use_html_signature');
        $wash_html_signature = (bool) $this->rc->config->get('identity_from_directory_wash_html_signature');
        if ($use_html_signature) {
            $signature_template = (string) $this->rc->config->get('identity_from_directory_signature_template_html');
        } else {
            $signature_template = (string) $this->rc->config->get('identity_from_directory_signature_template_plaintext');
        }
        $signature_fallback_values = (array) $this->rc->config->get('identity_from_directory_fallback_values');

        foreach ((array) $user_data['email_list'] as $email) {
            $hook_to_use = 'identity_create';
            $identity_id = 0; // often called 'iid' in other parts of RC sources
            $is_standard = 0; // 1: use the identity as default (there can only be one)
            $signature   = $signature_template; // copy signature template

            foreach ($identities as $identity) {
                if ($identity['email'] === $email) {
                    $hook_to_use = 'identity_update';
                    $identity_id = $identity['identity_id'];
                    break;
                }
            }

            if (strtolower($user_data['email_default']) === strtolower($email)) {
                $is_standard = 1;
            }

            // see https://github.com/roundcube/roundcubemail/blob/master/program/actions/settings/identity_save.php for available keys
            $identity_record = [
                'user_id' => $this->rc->user->ID,
                'standard' => $is_standard,
                'name' => (!empty($user_data['name']) ? $user_data['name'] : $user_data['user_name']),
                'email' => $email,
                'organization' => (array_key_exists('organization', $user_data) ? $user_data['organization'] : ''),
            ];

            if ($update_signatures) {
                // add signature to identity record, replace placeholders in a signature template with the values
                // from LDAP or $config['identity_from_directory_fallback_values']:
                // - %foo%: raw value of field 'foo'
                // - %foo_html%: HTML entities encoded value of field 'foo'
                // - %foo_url%: URL encoded value of field 'foo'
                foreach (array_keys($ldap_config['fieldmap']) as $fieldmap_key) {
                    $replace_raw = '';
                    if ($fieldmap_key === 'email') {
                        // Use the correct email address (alias) of the corresponding identity for
                        // the %email%, %email_html% and %email_url% placeholders instead of the single
                        // mapped value returned by the directory (which should be stored in
                        // $user_data['user_email']). Otherwise, the same single email address value would
                        // be used for all of the user's identities (even the one of alias addresses).
                        $replace_raw = (string) $email;
                    } elseif (array_key_exists($fieldmap_key, $user_data) && ((string) $user_data[$fieldmap_key] !== '')) {
                        $replace_raw = (string) $user_data[$fieldmap_key];
                    } elseif (array_key_exists($fieldmap_key, $signature_fallback_values) && ((string) $signature_fallback_values[$fieldmap_key] !== '')) {
                        $replace_raw = (string) $signature_fallback_values[$fieldmap_key];
                    } elseif (array_key_exists($fieldmap_key, $identity_record) && ((string) $identity_record[$fieldmap_key] !== '')) {
                        $replace_raw = (string) $identity_record[$fieldmap_key];
                    } else {
                        continue;
                    }

                    $replace_html = '';
                    $replace_html = htmlspecialchars($replace_raw, ENT_NOQUOTES, RCUBE_CHARSET);

                    $replace_url = '';
                    if ($fieldmap_key === 'phone' || $fieldmap_key === 'fax') {
                        $replace_url = urlencode(preg_replace('/[^+0-9]+/', '', $replace_raw)); // strip some chars for "tel://" URL usage
                    } else {
                        $replace_url = urlencode($replace_raw);
                    }

                    $signature = str_replace([ '%'. $fieldmap_key . '%',
                                               '%'. $fieldmap_key . '_html%',
                                               '%'. $fieldmap_key . '_url%', ],
                                             [  $replace_raw,
                                                $replace_html,
                                                $replace_url, ], $signature);
                }

                $identity_record['html_signature'] = ($use_html_signature) ? 1 : 0;
                $identity_record['signature'] = ($use_html_signature && $wash_html_signature) ? rcmail_action_settings_index::wash_html($signature) : $signature; // XSS protection
            }

            $plugin = $this->rc->plugins->exec_hook($hook_to_use, [
                'id' => $identity_id,
                'record' => $identity_record,
            ]);

            if (!$plugin['abort'] && !empty($plugin['record']['email'])) {
                if ($identity_id === 0) {
                    $this->rc->user->insert_identity($plugin['record']);
                } else {
                    $this->rc->user->update_identity($identity_id, $plugin['record']);
                }
            }
        }

        if ($delete_unmanaged) {
            $identities_count = count($identities);
            foreach ($identities as $identity) {
                if ($identities_count > 1 && !(in_array($identity['email'], $user_data['email_list']))) {
                    if ($debug_plugin) {
                        rcube::write_log('identity_from_directory_ldap', 'Deleting identity '. $identity['identity_id']. ' of user '.  $this->rc->user->data['username'] .' because it\'s email '. $identity['email'] .' is the not listed in the directory.');
                    }

                    if (!($this->rc->user->delete_identity($identity['identity_id'])) && $debug_plugin) {
                        rcube::write_log('identity_from_directory_ldap', 'Could note delete identity '. $identity['identity_id']. ' for email '. $identity['email']);
                    }
                    $identities_count--;
                }
            }
        }

        return $args;
    }


    /**
     * Initialize LDAP backend connection
     */
    private function init_ldap($args)
    {
        // check if connection is already initialized / nothing to do
        if ($this->ldap) {
            return $this->ldap->ready;
        }

        // Get config and set some fallback / default values
        // See this plugin's config.php.dist for a detailled description of the settings
        $this->load_config('config.inc.php.dist'); // load the plugin's distribution config file as default
        $this->load_config(); // merge with local configuration file (which can overwrite any settings)

        $debug_plugin = $this->rc->config->get('identity_from_directory_debug');
        $debug_ldap = $this->rc->config->get('ldap_debug');
        $mail_domain = $this->rc->config->mail_domain($args['mail_host']);

        $ldap_config = (array) $this->rc->config->get('identity_from_directory_ldap');
        if (!array_key_exists('searchonly', $ldap_config)) {
            $ldap_config['searchonly'] = true;
        }
        if (!array_key_exists('search_fields', $ldap_config) || !is_array($ldap_config['search_fields'])) {
            $ldap_config['search_fields'] = ['mail', 'sAMAccountName', 'username'];
        }
        if (empty($ldap_config) ||
            empty($ldap_config['search_fields']) ||
            empty($ldap_config['fieldmap'])) {
            if ($debug_plugin) {
                rcube::write_log('identity_from_directory_ldap', 'The plugin config seems to be invalid, please check $config[\'identity_from_directory_ldap\'].');
            }
            return false;
        }
        if (!array_key_exists('name', $ldap_config['fieldmap']) ||
            !array_key_exists('email', $ldap_config['fieldmap']) ||
            !array_key_exists('organization', $ldap_config['fieldmap'])) {
            if ($debug_plugin) {
                rcube::write_log('identity_from_directory_ldap', 'The plugin config seems to be invalid, please check $config[\'identity_from_directory_ldap\'][\'fieldmap\'].');
            }
            return false;
        }

        // add mapping for the "proxyAddresses" attribute (which stores email aliases when using Active Directory)
        $ad_handle_proxyaddresses = $this->rc->config->get('identity_from_directory_handle_proxyaddresses');
        if ($ad_handle_proxyaddresses) {
            $ldap_config['fieldmap']['proxyaddresses'] = 'proxyAddresses';
        }

        // connect to the directory
        $this->ldap = new identity_from_directory_ldap_backend($ldap_config, $debug_ldap, $mail_domain, $ldap_config['search_fields']);
        return $this->ldap->ready;
    }
}


/**
 * Utilize Roundcube's dedicated model class to access an LDAP-like address directory
 *
 * @link https://github.com/roundcube/roundcubemail/blob/master/program/lib/Roundcube/rcube_ldap.php
 */
class identity_from_directory_ldap_backend extends rcube_ldap
{
    public function __construct($props, $debug, $mail_domain, $search)
    {
        parent::__construct($props, $debug, $mail_domain);
        $this->prop['search_fields'] = (array) $search;
    }
}
