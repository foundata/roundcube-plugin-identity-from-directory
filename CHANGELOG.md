# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


## [Unreleased]

- Nothing worth mentioning yet.


## [2.2.1] - 2024-05-14

### Changed

- Better inline documentation. (35e9f57)
- Code improvements (no functional changes).


## [2.2.0] - 2024-05-11

### Changed

- Renamed the debug log from `identity_from_directory_ldap` to `identity_from_directory`. It was for LDAP results when development started but contains additional log data nowdays.
- General code cleanup (e7142af)


### Fixed

- Correct handling of addresses with IDN domains and comparisons if capitalization of the same email addresses is not consistent. (#8)


## [2.1.0] - 2024-05-02

### Added

- New option `$config['identity_from_directory_exclude_delete_unmanaged_regex']` to exclude identities from automatic cleanup if `$config['identity_from_directory_delete_unmanaged']` is `true`. (#7)


## [2.0.1] - 2024-04-28

### Added

- Documentation about images in HTML signatures. Sadly, examples for CID (or Content-ID) images are still missing. (#3)


## [2.0.0] - 2024-04-28

### Added

- New option `$config['identity_from_directory_exclude_alias_regex']` to exclude propably unwanted identities for email alias addresses. (#6)


### Changed

- ⚠ Admins have to take action / adapt config file: Renamed several config values for better readability:
  * `$config['identity_from_directory_deleteunmanaged']` -> `$config['identity_from_directory_delete_unmanaged']`.
  * `$config['identity_from_directory_fallbackvalues']` -> `$config['identity_from_directory_fallback_values']`.
  * `$config['identity_from_directory_htmlsignature']` -> `$config['identity_from_directory_use_html_signature']`.
  * `$config['identity_from_directory_updatesignatures']` -> `$config['identity_from_directory_update_signatures']`.
  * `$config['identity_from_directory_washhtmlsignature']` -> `$config['identity_from_directory_wash_html_signature']`.
- `config.inc.php.dist` (the plugin's distribution config file) will be loaded and then merged with local configuration file, which can overwrite any settings. This will make it easier to introduce new config values in the future.


### Fixed

- The main email address from field mapping was used for `%email%`, `%email_url%` and `%email_html%` for every signature template, even for email alias addresses and their corresponding signatures. (#5)


## [1.1.1] - 2024-04-03

### Fixed

- Fixed an error if `$config['identity_from_directory_handle_proxyaddresses']` is set to `true` and there is more then one alias address stored in the user's `proxyAddresses` field (Active Directory only).


## [1.1.0] - 2024-04-03

### Added

- New option `$config['identity_from_directory_deleteunmanaged']` to delete propably unwanted identities automatically.

### Changed

- Renamed `$config['identity_from_directory_handlesignatures']` to `$config['identity_from_directory_updatesignatures']`.


## [1.0.0] - 2024-03-28

### Added

- All functionality and files.


[unreleased]: https://github.com/foundata/roundcube-plugin-identity-from-directory/compare/v2.2.1...HEAD
[2.2.1]: https://github.com/foundata/roundcube-plugin-identity-from-directory/releases/tag/v2.2.1
[2.2.0]: https://github.com/foundata/roundcube-plugin-identity-from-directory/releases/tag/v2.2.0
[2.1.0]: https://github.com/foundata/roundcube-plugin-identity-from-directory/releases/tag/v2.1.0
[2.0.1]: https://github.com/foundata/roundcube-plugin-identity-from-directory/releases/tag/v2.0.1
[2.0.0]: https://github.com/foundata/roundcube-plugin-identity-from-directory/releases/tag/v2.0.0
[1.1.1]: https://github.com/foundata/roundcube-plugin-identity-from-directory/releases/tag/v1.1.1
[1.1.0]: https://github.com/foundata/roundcube-plugin-identity-from-directory/releases/tag/v1.1.0
[1.0.0]: https://github.com/foundata/roundcube-plugin-identity-from-directory/releases/tag/v1.0.0
