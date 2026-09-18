# Changelog

## [1.3.1](https://github.com/Avunu/wordpress-sqlite-anywhere/compare/v1.3.0...v1.3.1) (2026-09-18)


### Bug Fixes

* **turso:** widen the embedded replica's busy-retry budget for real concurrency ([#20](https://github.com/Avunu/wordpress-sqlite-anywhere/issues/20)) ([12652d6](https://github.com/Avunu/wordpress-sqlite-anywhere/commit/12652d60cc1abb2b3ed17702c2a743eef067a73e))


### Miscellaneous Chores

* bump phpunit/phpunit from 13.3.3 to 13.3.4 in the composer group ([#18](https://github.com/Avunu/wordpress-sqlite-anywhere/issues/18)) ([e359290](https://github.com/Avunu/wordpress-sqlite-anywhere/commit/e359290da673698e570422688f4050f8e9a566ef))
* bump turso ([#17](https://github.com/Avunu/wordpress-sqlite-anywhere/issues/17)) ([2a06d9a](https://github.com/Avunu/wordpress-sqlite-anywhere/commit/2a06d9aab9bc9727082ab61275dd6f5b12c84081))
* bump wrangler in /packages/d1-proxy-worker in the npm group ([#15](https://github.com/Avunu/wordpress-sqlite-anywhere/issues/15)) ([a0c62a2](https://github.com/Avunu/wordpress-sqlite-anywhere/commit/a0c62a240e4dd55c701198c5d1711faf95a3723c))

## [1.3.0](https://github.com/Avunu/wordpress-sqlite-anywhere/compare/v1.2.0...v1.3.0) (2026-09-16)


### Features

* **driver:** translate the INTERVAL operator forms of date arithmetic ([#13](https://github.com/Avunu/wordpress-sqlite-anywhere/issues/13)) ([3f36325](https://github.com/Avunu/wordpress-sqlite-anywhere/commit/3f3632580d796ee8bef797d06f69d5b32936e9c1))

## [1.2.0](https://github.com/Avunu/wordpress-sqlite-anywhere/compare/v1.1.0...v1.2.0) (2026-09-15)


### Features

* **driver:** index-only ALTER TABLE without a table rebuild ([#12](https://github.com/Avunu/wordpress-sqlite-anywhere/issues/12)) ([a6f27d3](https://github.com/Avunu/wordpress-sqlite-anywhere/commit/a6f27d32cb87ccd003cb1165c3ccb254ad2ef181))
* **turso:** pull the embedded replica through a write instead of latching ([#10](https://github.com/Avunu/wordpress-sqlite-anywhere/issues/10)) ([654a36a](https://github.com/Avunu/wordpress-sqlite-anywhere/commit/654a36ad9366fc20f50572fda1de6b1a80058eed))


### Miscellaneous Chores

* bump phpunit/phpunit from 13.3.2 to 13.3.3 in the composer group ([#7](https://github.com/Avunu/wordpress-sqlite-anywhere/issues/7)) ([64ed8b7](https://github.com/Avunu/wordpress-sqlite-anywhere/commit/64ed8b78ad6affb974f44b039d633b9cd9eaf5c2))
* bump the github-actions group with 2 updates ([#9](https://github.com/Avunu/wordpress-sqlite-anywhere/issues/9)) ([0c50650](https://github.com/Avunu/wordpress-sqlite-anywhere/commit/0c50650292bbd54416945d9a8f5921afd5624d8c))
* bump wrangler in /packages/d1-proxy-worker in the npm group ([#8](https://github.com/Avunu/wordpress-sqlite-anywhere/issues/8)) ([f56dedb](https://github.com/Avunu/wordpress-sqlite-anywhere/commit/f56dedb2c5627e005f36a1ad0b5721d0dd5202ff))

## [1.1.0](https://github.com/Avunu/wordpress-sqlite-anywhere/compare/v1.0.0...v1.1.0) (2026-09-14)


### Features

* **turso:** a native extension with a pooled client and an embedded replica ([1a74845](https://github.com/Avunu/wordpress-sqlite-anywhere/commit/1a748458dfa1bf3fb137432dc9b986b6da82bdfc))

## 1.0.0 (2026-09-11)


### Features

* pin upstream driver as a submodule and carry the core delta as patches ([791ab3b](https://github.com/Avunu/wordpress-sqlite-anywhere/commit/791ab3b6337849375147058cdf15dab977ad7803))
* the plugin, its drop-in, and the Nix-managed toolchain ([32ec074](https://github.com/Avunu/wordpress-sqlite-anywhere/commit/32ec0745f39b16e54fcbe2d31b9c80ff76b72d89))


### Miscellaneous Chores

* bump phpunit/phpunit ([#2](https://github.com/Avunu/wordpress-sqlite-anywhere/issues/2)) ([13b6ef8](https://github.com/Avunu/wordpress-sqlite-anywhere/commit/13b6ef82e811e7603ae7772acd3b1f846e9e2b97))
* bump the npm group in /packages/d1-proxy-worker with 3 updates ([#4](https://github.com/Avunu/wordpress-sqlite-anywhere/issues/4)) ([f7bddf0](https://github.com/Avunu/wordpress-sqlite-anywhere/commit/f7bddf0316fabd8c85f30d2f7a591e488e7c8b1d))
* revert the PHPUnit 13 and worker test-stack bumps; gate majors ([e42fa6c](https://github.com/Avunu/wordpress-sqlite-anywhere/commit/e42fa6cb0e2742640a2fc318b02d0240c8187ff2))

## Changelog
