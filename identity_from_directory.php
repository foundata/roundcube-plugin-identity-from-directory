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

        // Triggered after a user successfully logged in
        // https://github.com/roundcube/roundcubemail/wiki/Plugin-Hooks#login_after
        // This plugin is using it to update / edit existing identities
        $this->add_hook('login_after', [$this, 'login_after']);
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
        $debug_plugin = (bool) $this->rc->config->get('identity_from_directory_debug');

        // get user's data from directory and prepare it for further processing
        $user_data = [
            'name' => '',
            'email' => '', // default / main email address; IDN domains (no Punycode/ACE)
            'email_list' => [], // list of all email addresses (main, default, aliases); IDN domains (no Punycode/ACE)
        ];
        if ($this->init_ldap([
            'mail_host' => $this->rc->user->data['mail_host'],
        ])) {
            // '*' does NOT search all fields but triggers the usage of 'search_fields' instead, see
            // https://github.com/roundcube/roundcubemail/blob/master/program/lib/Roundcube/rcube_ldap.php#L900C49-L900C62
            //
            // This 'search_fields' array gets set to the plugin's $config['identity_from_directory_ldap']['search_fields']
            // as connection property by $this->init_ldap(). So searching in '*' limits the fields to the plugin's config.
            $results = $this->ldap->search('*', $this->rc->user->data['username'], true);

            if (count($results->records) === 1) {

                $ad_handle_proxyaddresses = (bool) $this->rc->config->get('identity_from_directory_handle_proxyaddresses');
                $exclude_alias_regex = (string) $this->rc->config->get('identity_from_directory_exclude_alias_regex');

                $ldap_entry = $results->records[0];
                if ($debug_plugin) {
                    rcube::write_log('identity_from_directory',
                        'Found a record for ' . $this->rc->user->data['username'] . ': '
                        . print_r($ldap_entry, true));
                }

                $user_data['name'] = is_array($ldap_entry['name']) ? $ldap_entry['name'][0] : $ldap_entry['name'];
                $user_data['email'] = rcube_utils::idn_to_utf8(trim(is_array($ldap_entry['email']) ? $ldap_entry['email'][0] : $ldap_entry['email']));
                if (!empty($user_data['email'])) {
                    $user_data['email_list'][] = $user_data['email'];
                }

                foreach (array_keys($ldap_entry) as $key) {
                    // add email addresses (main, aliases) to the list for the user
                    if (preg_match('/^email($|:)/i', $key)) {
                        foreach ((array) $ldap_entry[$key] as $alias) {
                            $alias = rcube_utils::idn_to_utf8(trim($alias));
                            if (empty($alias) || self::email_in_array($alias, $user_data['email_list'])) {
                                continue;
                            }
                            if (strpos($alias, '@') === false || (!empty($exclude_alias_regex) && (preg_match($exclude_alias_regex, $alias)))) {
                                if ($debug_plugin) {
                                    rcube::write_log('identity_from_directory',
                                        'Excluded ' . $alias . ' from handling as it is an invalid email address or matching "'
                                        . $exclude_alias_regex . '" (identity_from_directory_exclude_alias_regex).');
                                }
                                continue;
                            }
                            $user_data['email_list'][] = $alias;
                        }

                    // handle Active Directory attribute "proxyAddresses"
                    } elseif ($ad_handle_proxyaddresses && preg_match('/^proxyaddresses($|:)/i', $key)) {
                        // originally a CSV string like 'smtp:foo@exmaple.com,bar@example.net'.
                        // The used library returns as string if there is only one address and
                        // an array if there are multiple.
                        $proxyaddresses = $ldap_entry[$key];
                        if (!is_array($proxyaddresses)) {
                            $proxyaddresses = [ $proxyaddresses ];
                        }
                        foreach ((array) $proxyaddresses as $alias) {
                            $alias = rcube_utils::idn_to_utf8(trim(preg_replace('/^smtp:(.+)/i', '\1', trim($alias), 1)));
                            if (empty($alias) || self::email_in_array($alias, $user_data['email_list'])) {
                                continue;
                            }
                            if (strpos($alias, '@') === false || (!empty($exclude_alias_regex) && (preg_match($exclude_alias_regex, $alias)))) {
                                if ($debug_plugin) {
                                    rcube::write_log('identity_from_directory',
                                        'Excluded '. $alias . ' from handling as it is an invalid email address or matching "'
                                        . $exclude_alias_regex . '" (identity_from_directory_exclude_alias_regex).');
                                }
                                continue;
                            }
                            $user_data['email_list'][] = $alias;
                        }

                    // add LDAP data but exclude _ID, _raw_attrib etc. and do not overwrite already existing keys
                    } elseif (strpos($key, '_') !== 0 && !array_key_exists($key, $user_data)) {
                        $user_data[$key] = $ldap_entry[$key];
                    }
                }
                $user_data['email_list'] = array_unique($user_data['email_list']);

            } elseif ($debug_plugin && count($results->records) > 1) {
                rcube::write_log('identity_from_directory',
                    'Searching for '. $this->rc->user->data['username']
                    . ' returned more than one result; all were ignored as unambiguous assignment is not possible.');
            }
        }
        if (empty($user_data['email_list'])) {
            return $args;
        }

        // get config and other data needed for further processing
        $ldap_config = (array) $this->rc->config->get('identity_from_directory_ldap');
        $update_signatures = (bool) $this->rc->config->get('identity_from_directory_update_signatures');
        $use_html_signature = (bool) $this->rc->config->get('identity_from_directory_use_html_signature');
        $wash_html_signature = (bool) $this->rc->config->get('identity_from_directory_wash_html_signature');
        if ($use_html_signature) {
            $signature_template = (string) $this->rc->config->get('identity_from_directory_signature_template_html');
        } else {
            $signature_template = (string) $this->rc->config->get('identity_from_directory_signature_template_plaintext');
        }
        $signature_fallback_values = (array) $this->rc->config->get('identity_from_directory_fallback_values');
        $identities_existing = $this->rc->user->list_emails(); // list of all user emails (from identities), array with identity_id, name and email address

        // maintain an identity for each of the user's determined email addresses
        foreach ((array) $user_data['email_list'] as $email) {
            $hook_to_use = 'identity_create';
            $identity_id = 0; // often called 'iid' in other parts of RC sources
            $is_standard = 0; // 1: use the identity as default (there can only be one)
            $signature   = $signature_template; // copy signature template

            foreach ($identities_existing as $identity_existing) {
                // case-insensitive search to update an existing identity, even if
                // there are differences in capitalization.
                if (self::email_in_array($identity_existing['email'], [ $email ])) {
                    $hook_to_use = 'identity_update';
                    $identity_id = $identity_existing['identity_id'];
                    break;
                }
            }

            if ($user_data['email'] === $email) {
                $is_standard = 1;
            }

            // see https://github.com/roundcube/roundcubemail/blob/master/program/actions/settings/identity_save.php for available keys
            $identity_record = [
                'user_id' => $this->rc->user->ID,
                'standard' => $is_standard,
                'name' => (!empty($user_data['name']) ? $user_data['name'] : $this->rc->user->data['username']),
                'email' => $email,
                'organization' => (array_key_exists('organization', $user_data) ? $user_data['organization'] : ''),
            ];

            if ($update_signatures) {
                // add signature to identity record, replace placeholders in a signature template with the values
                // from LDAP or $config['identity_from_directory_fallback_values']:
                // - %foo%: raw value of field 'foo'
                // - %foo_html%: HTML entities encoded value of field 'foo'
                // - %foo_url%: URL encoded value of field 'foo'. Additional optimizations are
                //   applied for the fields 'email' (usage of Punycode for email domains),
                //   'phone' and 'fax' (stripping of chars not compatible with tel:// URLs)
                foreach (array_keys($ldap_config['fieldmap']) as $fieldmap_key) {
                    $replace_raw = '';
                    if ($fieldmap_key === 'email') {
                        // Use the correct email address (alias) of the corresponding identity for
                        // the %email%, %email_html% and %email_url% placeholders instead of the
                        // single mapped value returned by the directory (which should be stored in
                        // $user_data['email']). Otherwise, the same single email address value would
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
                    $replace_html = htmlspecialchars($replace_raw, \ENT_NOQUOTES, RCUBE_CHARSET);

                    $replace_url = '';
                    if ($fieldmap_key === 'phone' || $fieldmap_key === 'fax') {
                        // strip some chars for "tel://" URL usage
                        $replace_url = urlencode(preg_replace('/[^+0-9]+/', '', $replace_raw));
                    } elseif ($fieldmap_key === 'email') {
                        // use Punycode/ACE for "mailto://" URL usage
                        $replace_url = urlencode(rcube_utils::idn_to_ascii($replace_raw));
                    } else {
                        $replace_url = urlencode($replace_raw);
                    }

                    $signature = str_replace([ '%'. $fieldmap_key . '%',
                                               '%'. $fieldmap_key . '_html%',
                                               '%'. $fieldmap_key . '_url%' ],
                                             [ $replace_raw,
                                               $replace_html,
                                               $replace_url ], $signature);
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

        // delete identities which are not managed by this plugin
        $delete_unmanaged = (bool) $this->rc->config->get('identity_from_directory_delete_unmanaged');
        $exclude_delete_unmanaged_regex = (string) $this->rc->config->get('identity_from_directory_exclude_delete_unmanaged_regex');
        if ($delete_unmanaged) {
            $identity_existing_count = count($identities_existing);
            foreach ($identities_existing as $identity_existing) {

                if ($identity_existing_count > 1 &&
                    // do NOT normalize email address here (e.g. via mb_strtolower(), trim(), and/or
                    // rcube_utils::idn_to_utf8()). Otherwise, some identities to be cleaned up
                    // would not be detected (e.g. leftovers with differences in only upper or lower
                    // case characters or Punycode/ACE in domain names)
                    !(in_array($identity_existing['email'], $user_data['email_list']))) {
                    if (!empty($exclude_delete_unmanaged_regex) && preg_match($exclude_delete_unmanaged_regex, $identity_existing['email'])) {
                        if ($debug_plugin) {
                            rcube::write_log('identity_from_directory',
                                'Excluded identity ' . $identity_existing['identity_id'] . ' of user '
                                . $this->rc->user->data['username'] . ' from automatic deletion. It\'s email '
                                . $identity_existing['email'] . ' is not listed in the directory but matching "'
                                . $exclude_delete_unmanaged_regex
                                . '" (identity_from_directory_exclude_delete_unmanaged_regex).');
                        }
                        continue;
                    }

                    if ($debug_plugin) {
                        rcube::write_log('identity_from_directory',
                            'Deleting identity '. $identity_existing['identity_id'] .' of user '
                            . $this->rc->user->data['username'] .' because it\'s email '
                            . $identity_existing['email'] . ' is the not listed in the directory.');
                    }

                    if (!($this->rc->user->delete_identity($identity_existing['identity_id'])) && $debug_plugin) {
                        rcube::write_log('identity_from_directory',
                            'Could note delete identity '. $identity_existing['identity_id']
                            . ' for email '.$identity_existing['email']);
                    }
                    $identity_existing_count--;
                }

            }
        }

        return $args;
    }


    /**
     * Search for an email address in an array of email addresses. The search
     * will ignores differences in capitalization or Punycode/ACE.
     *
     * RFC 5321 (Simple Mail Transfer Protocol) section 2.3.11 leaves it up
     * to the host if the "local-part" in "local-part@domain" is case-
     * insensitively. De-facto, it gets handled case-insensitive by most
     * systems out there and users are expecting that Foo@example.com ==
     * foo@example.com quite often. This function also acts like this.
     *
     * @param string $needle Value to seek.
     * @param array $haystack Array to seek in.
     * @return bool
     */
    public static function email_in_array($needle, $haystack)
    {
        $haystack_new = [];
        foreach($haystack as $key => $value) {
            $haystack_new[$key] = mb_strtolower(rcube_utils::idn_to_utf8(trim($value)), 'UTF-8');
        }
        return in_array(mb_strtolower(rcube_utils::idn_to_utf8(trim($needle)), 'UTF-8'), $haystack_new);
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

        $debug_plugin = (bool) $this->rc->config->get('identity_from_directory_debug');
        $debug_ldap = (bool) $this->rc->config->get('ldap_debug');
        $mail_domain = (string) $this->rc->config->mail_domain($args['mail_host']);

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
                rcube::write_log('identity_from_directory',
                    'The plugin config seems to be invalid, please check $config[\'identity_from_directory_ldap\'].');
            }
            return false;
        }
        if (!array_key_exists('name', $ldap_config['fieldmap']) ||
            !array_key_exists('email', $ldap_config['fieldmap']) ||
            !array_key_exists('organization', $ldap_config['fieldmap'])) {
            if ($debug_plugin) {
                rcube::write_log('identity_from_directory',
                    'The plugin config seems to be invalid, please check $config[\'identity_from_directory_ldap\'][\'fieldmap\'].');
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
