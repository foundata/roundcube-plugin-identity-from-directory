# Development

This file provides additional information for maintainers and contributors.


## Testing

Nothing special or automated yet. Therefore just some hints for manual testing:

* Run the plugin with invalid config values.
* Run the plugin with technically valid config values but a directory without the needed data.
* Create identities with dummy data for a user upfront and activate the plugin afterwards. Check the updates after login.
* Add new email addresses to the user's dataset in the directory and check if new identities are created properly.


## Composer, PHP dependencies

* Make sure you are using up-to-date dependencies during development (`php composer.phar update --no-dev`).
* Run `php composer.phar validate` after doing changes.
* Use [`composer normalize`](https://github.com/ergebnis/composer-normalize) if possible.


## Releases

Nothing automated yet, therefore at least manual instructions:

1. Do proper [Testing](#testing). Continue only if everything is fine.
2. Determine the next version number. This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
3. Update the `version` key in the [`composer.json`](./composer.json) file. The [specification discourages the usage of the `version` property](https://getcomposer.org/doc/04-schema.md#version), but it is useful for some scripts and used as a fallback source for `rcube_plugin_api::get_info()`.
4. Update the [`CHANGELOG.md`](./CHANGELOG.md). Insert a section for the new release. Do not forget the comparison link at the end of the file.
5. If everything is fine: commit the changes, tag the release and push:
   ```bash
   version="<FIXME version>"
   git tag "v${version}" <commit> -m "version ${version}"
   git show "v${version}"
   git push origin main --follow-tags
   ```
   If something minor went wrong (like missing `CHANGELOG.md` or `composer.json` update), delete the tag and start over:
   ```bash
   git tag -d "v${version}" # delete the old tag locally
   git push origin ":refs/tags/v${version}" # delete the old tag remotely
   ```
   This is *only* possible if there was no [GitHub release](https://github.com/foundata/roundcube-plugin-identity-from-directory/releases/). Use a new patch version number otherwise.
6. Create a release tarball including all dependencies:
   ```bash
   # define target version and stash unsaved work
   version="<FIXME version>"
   branch="$(git branch --show-current)"
   git stash

   # create a temporary branch to create a release tarball including
   # dependencies (if any)
   git checkout -b "v${version}-release" "tags/v${version}"

   # The only dependency currently is "roundcube/plugin-installer" which
   # is solely needed during a Composer based installation process. It
   # is unnecessary in stand-alone release tarballs. You can use the
   # following code if there are real runtime dependencies some day:
   #sed -i -E -e '/^\/vendor\/$/d' "./.gitignore"
   #composer update --no-dev && \
   #git add "./.gitignore" "./composer.json" "./composer.lock" "./vendor/." && \
   #git commit -m "Add PHP dependencies"

   # create the tarball (archive is respecting .gitignore and .gitattributes)
   git archive --verbose \
     --format="tar.gz" \
     --prefix="identity_from_directory/" \
     --output="../identity_from_directory-v${version}.tar.gz" \
     HEAD "./"

   # create a checksums file
   pushd "$(pwd)" && cd ..
   sha256sum "identity_from_directory-v${version}.tar.gz" > "./identity_from_directory-v${version}.tar.gz.sha256"
   popd

   # change back to original branch and clean-up
   git checkout "${branch}"
   git stash pop
   git branch --delete --force "v${version}-release"
   ```
7. Use [GitHub's release feature](https://github.com/foundata/roundcube-plugin-identity-from-directory/releases/new), select the tag you pushed and create a new release:
   * Use `v<version>` as title.
   * A description is optional. In doubt, use `See CHANGELOG.md for more information about this release.`.
8. Check if the GitHub API delivers the correct version as `latest`:
   ```bash
   curl -s -L https://api.github.com/repos/foundata/roundcube-plugin-identity-from-directory/releases/latest | jq -r '.tag_name' | sed -e 's/^v//g'
   ```
9. Add the created release tarball as [additional asset](https://docs.github.com/en/enterprise-cloud@latest/rest/releases/assets#upload-a-release-asset) / file attachment:
   ```bash
   github_api_token="FIXME"
   release_id="$(curl -s -L https://api.github.com/repos/foundata/roundcube-plugin-identity-from-directory/releases/latest | jq -r '.id')"

   curl -L \
    -X POST \
    -H "Accept: application/vnd.github+json" \
    -H "Authorization: Bearer ${github_api_token}" \
    -H "X-GitHub-Api-Version: 2022-11-28" \
    -H "Content-Type: application/octet-stream" \
    "https://uploads.github.com/repos/foundata/roundcube-plugin-identity-from-directory/releases/${release_id}/assets?name=identity_from_directory-v${version}.tar.gz" \
    --data-binary "@../identity_from_directory-v${version}.tar.gz"

   curl -L \
    -X POST \
    -H "Accept: application/vnd.github+json" \
    -H "Authorization: Bearer ${github_api_token}" \
    -H "X-GitHub-Api-Version: 2022-11-28" \
    -H "Content-Type: text/plain;charset=UTF-8" \
    "https://uploads.github.com/repos/foundata/roundcube-plugin-identity-from-directory/releases/${release_id}/assets?name=identity_from_directory-v${version}.tar.gz.sha256" \
    --data-binary "@../identity_from_directory-v${version}.tar.gz.sha256"

   unset github_api_token
   ```
10. Inform [Packist](https://packagist.org/) about the new release:
   ```bash
   packagist_api_token="FIXME"

   curl -L \
     -X POST \
     -H "Content-Type: application/json" \
     -d '{"repository":{"url":"https://github.com/foundata/roundcube-plugin-identity-from-directory"}}' \
     "https://packagist.org/api/update-package?username=foundata&apiToken=${packagist_api_token}"

   unset packagist_api_token
   ```


## Miscellaneous

* See <https://github.com/roundcube/roundcubemail/wiki/Dev-Guidelines> for Roundcube's code style and other development resources.
* See the following resources for information about Composer and Plugin releases:
  * <http://plugins.roundcube.net/#/about/>
  * <https://github.com/roundcube/plugin-installer>
* Use UTF-8 encoding with `LF` (Line Feed `\n`) line endings *without* [BOM](https://en.wikipedia.org/wiki/Byte_order_mark) for all files.
