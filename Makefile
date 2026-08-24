PHP ?= php
PHPUNIT ?= vendor/bin/phpunit
PHPCS ?= vendor/bin/phpcs
TYPECHO_SOURCE ?=
FE_DB_DRIVER ?= sqlite

.PHONY: test lint phpcs integration integration-comments package verify

test:
	$(PHPUNIT) --configuration phpunit.xml.dist

lint:
	find Plugin.php Runtime Feed Http tests -type f -name '*.php' \
		-exec $(PHP) -l {} \;

phpcs:
	$(PHPCS) --standard=phpcs.xml.dist

integration:
	@test -n "$(TYPECHO_SOURCE)" \
		|| { echo 'TYPECHO_SOURCE must point to a Typecho source tree.' >&2; exit 2; }
	bash tests/Integration/run-http.sh "$(TYPECHO_SOURCE)" full

integration-comments:
	@test -n "$(TYPECHO_SOURCE)" \
		|| { echo 'TYPECHO_SOURCE must point to a Typecho source tree.' >&2; exit 2; }
	TYPECHO_SOURCE_ROOT="$(TYPECHO_SOURCE)" FE_DB_DRIVER="$(FE_DB_DRIVER)" \
		$(PHP) tests/Integration/secure-recent-comments-db.php

package:
	sh scripts/package.sh

verify: lint phpcs test package
