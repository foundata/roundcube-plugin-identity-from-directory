<?php

// FIXME desc
$config['identity_from_directory_match'] = 'sAMAccountName';

// Active Directory only: Search 'proxyAddresses' field for email alias addresses
// as CSV string like 'smtp:foo@exmaple.com,bar@example.net'
$config['identity_from_directory_handle_proxyaddresses'] = true;

// FIXME description (plus: mention ldap_debug for LDAP class debugging)
$config['identity_from_directory_debug'] = true;

// FIXME description
$config['identity_from_directory_ldap'] = array(
    'hosts' => array('ldaps://dc.contoso.com:636'),
    'base_dn' => 'DC=contoso,DC=com',
    'bind_dn' => 'CN=roundcube-svc,OU=service-accounts,DC=contoso,DC=com',
    'bind_pass' => 'password-of-service-user-in-bind_dn',
    'scope' => 'sub', // search mode: sub|base|list
    'filter' => '(&(sAMAccountType=805306368)(!(servicePrincipalName=*))(!(isCriticalSystemObject=TRUE))(!(userAccountControl:1.2.840.113556.1.4.803:=2)))',
    'search_fields' => ['mail', 'sAMAccountName'],
    'fieldmap' => array(
        'name' => 'displayName',
        'email' => 'mail',
        'surname' => 'sn',
        'firstname' => 'givenName',
        'jobtitle' => 'title',
        'department' => 'department',
        'phone' => 'telephoneNumber',
        'fax' => 'facsimileTelephoneNumber',
        'website' => 'wWWHomePage',
        'company' => 'organization',
        'username' => 'sAMAccountName',
      ),
);
