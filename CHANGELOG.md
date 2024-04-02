# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


## [Unreleased]

- Nothing worth mentioning yet.

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


[unreleased]: https://github.com/foundata/roundcube-plugin-identity-from-directory/compare/v1.1.1...HEAD
[1.1.1]: https://github.com/foundata/roundcube-plugin-identity-from-directory/releases/tag/v1.1.1
[1.1.0]: https://github.com/foundata/roundcube-plugin-identity-from-directory/releases/tag/v1.1.0
[1.0.0]: https://github.com/foundata/roundcube-plugin-identity-from-directory/releases/tag/v1.0.0
