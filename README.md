# Roundcube Plugin: `identity_from_directory` (use LDAP or AD to maintain email identities)

A [Roundcube](https://roundcube.net/) [plugin](https://plugins.roundcube.net/) to populate and maintain a user's email identities automatically on each login, based on corresponding LDAP or Active Directory data.


## Table of Contents

- [Installation](#installation)
  - [Installation using Composer](#installation-using-composer)
  - [Installation from source or release tarball](#installation-from-source-or-release-tarball)
- [Updating](#updating)
  - [Update using Composer](#update-using-composer)
  - [Update from source or release tarball](#update-from-source-or-release-tarball)
- [Configuration](#configuration)
- [Compatibility](#compatibility)
- [Licensing, copyright](#licensing-copyright)
- [Author information](#author-information)


## Installation

### Installation using Composer

A package and release is in preparation but not done yet.


### Installation from source or release tarball

Simply place the plugin source code in `plugins/identity_from_directory/`. Add `identity_from_directory` to Roundcube's `$config['plugins']` array afterwards.


## Updating

### Update using Composer

A package and release is in preparation but not done yet.


### Update from source or release tarball

Updating is as simple as overwriting the file. Just follow the [installation instructions](#installation) again to get the newest release. This should be a low-risk operation as there were no backwards-compatibility-breaking releases yet and there are no database schema changes.


## Configuration

- Copy the template `config.inc.php.dist` to `config.inc.php` (Composer may already have done this for you)
- Edit `plugins/identity_from_directory/config.inc.php` as you need. The inline comments in the file will help you with that.


## Compatibility

- Roundcube 1.6 or newer. The plugin may work with older versions, but this is not tested nor supported.
- PHP 8 or higher. The plugin may work with older versions, but this is not tested nor supported.
- There is no special requirement regarding the used database. This plugin does not adapt the schema nor directly interacts with the database. It is only using Roundcube's already existing actions and hooks to handle the identity data.


## Licensing, copyright

<!--REUSE-IgnoreStart-->
Copyright (c) 2024, foundata GmbH (https://foundata.com)

This project is licensed under the GNU General Public License v3.0 or later (SPDX-License-Identifier: `GPL-3.0-or-later`), see [`LICENSES/GPL-3.0-or-later.txt`](LICENSES/GPL-3.0-or-later.txt) for the full text.

The [`.reuse/dep5`](.reuse/dep5) file provides detailed licensing and copyright information in a human- and machine-readable format. This includes parts that may be subject to different licensing or usage terms, such as third party components. The repository conforms to the [REUSE specification](https://reuse.software/spec/), you can use [`reuse spdx`](https://reuse.readthedocs.io/en/latest/readme.html#cli) to create a [SPDX software bill of materials (SBOM)](https://en.wikipedia.org/wiki/Software_Package_Data_Exchange).
<!--REUSE-IgnoreEnd-->


## Author information

This project was created and is maintained by [foundata](https://foundata.com/). If you like it, you might [buy them a coffee](https://buy-me-a.coffee/roundcube-plugin-identity-from-directory/).