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
     * 'user_create' hook handler.
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

            if (count($results->records) == 1) {
                $user = $results->records[0];
                if ($debug_plugin) {
                    rcube::write_log('identity_from_directory_ldap', 'Found a record for' . $args['user'] . ': '.print_r($user, true));
                }

                $user_name = is_array($user['name']) ? $user['name'][0] : $user['name'];
                $user_email = is_array($user['email']) ? $user['email'][0] : $user['email'];

                $args['user_name'] = $user_name;
                $args['email_list'] = [];

                if (empty($args['user_email']) && strpos($user_email, '@')) {
                    $args['user_email'] = rcube_utils::idn_to_ascii($user_email);
                }

                if (!empty($args['user_email'])) {
                    $args['email_list'][] = $args['user_email'];
                }

                foreach (array_keys($user) as $key) {
                    if (preg_match('/^email($|:)/', $key)) {
                        foreach ((array) $user[$key] as $alias) {
                            if (strpos($alias, '@')) {
                                $args['email_list'][] = rcube_utils::idn_to_ascii($alias);
                            }
                        }
                    // parse Active Directory attribute proxyAddresses, CSV string like 'smtp:foo@exmaple.com,bar@example.net'
                    } elseif ($ad_handle_proxyaddresses &&
                              preg_match('/^proxyaddresses($|:)/', $key)) {
                        $proxyaddresses = explode(',', $user[$key]);
                        foreach ((array) $proxyaddresses as $alias) {
                            $alias = preg_replace('/^smtp:(.+)/', '\1', $alias, 1);
                            if (strpos($alias, '@')) {
                                $args['email_list'][] = rcube_utils::idn_to_ascii($alias);
                            }
                        }
                    }
                }

                $args['email_list'] = array_unique($args['email_list']);

            } elseif ($debug_plugin && count($results->records) > 1) {
               rcube::write_log('identity_from_directory_ldap', 'Searching for ' . $args['user'] . ' returned more then one result, all where ignored as unambiguous assignment is not possible.');
            }
        }

        return $args;
    }

    /**
     * 'login_after' hook handler.
     */
    public function login_after($args)
    {
        $this->load_config();

        if ($this->ldap) {
            return $args;
        }

        $identities = $this->rc->user->list_emails();
        $ldap_entry = $this->lookup_user_name([
            'user' => $this->rc->user->data['username'],
            'mail_host' => $this->rc->user->data['mail_host'],
        ]);

        if (empty($ldap_entry['email_list'])) {
            return $args;
        }

        foreach ((array) $ldap_entry['email_list'] as $email) {
           // FIXME implement identity creation and/or update
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
        // See this plugin's config.php.dist for a detailled description of the important settings
        $this->load_config();

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

        // add mapping for the "proxyAddresses" attribute (which stores email aliases when using Active Directory)
        $ad_handle_proxyaddresses = $this->rc->config->get('identity_from_directory_handle_proxyaddresses');
        if ($ad_handle_proxyaddresses) {
            $ldap_config['fieldmap']['proxyaddresses'] = 'ProxyAddresses';
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
