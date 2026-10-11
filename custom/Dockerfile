# Custom Firefly III image with the "Investments" page.
#
# It starts from the official image and only adds the files of the investments page.
# Build it from the root of this repository (see custom/README.md):
#
#   docker build -f custom/Dockerfile -t firefly-iii-custom:6.7.7 .
#
# To build on top of another Firefly III version, change FIREFLY_VERSION below or pass
# --build-arg FIREFLY_VERSION=6.7.8 (see "Moving to a newer Firefly III version" in custom/README.md).

ARG FIREFLY_VERSION=6.7.7
FROM fireflyiii/core:version-${FIREFLY_VERSION}
ARG FIREFLY_VERSION

# The official image runs as the "www-data" user, so the new files get that owner too.
COPY --chown=www-data:www-data app/Support/Investments/            /var/www/html/app/Support/Investments/
COPY --chown=www-data:www-data app/Http/Controllers/Investments/   /var/www/html/app/Http/Controllers/Investments/
COPY --chown=www-data:www-data routes/custom/investments.php       /var/www/html/routes/custom/investments.php
COPY --chown=www-data:www-data resources/views/investments/        /var/www/html/resources/views/investments/
COPY --chown=www-data:www-data resources/lang/en_US/investments.php /var/www/html/resources/lang/en_US/investments.php
COPY --chown=www-data:www-data public/v1/js/ff/investments/        /var/www/html/public/v1/js/ff/investments/
COPY --chown=www-data:www-data app/Console/Commands/Investments/   /var/www/html/app/Console/Commands/Investments/
# The two new tables (products and prices). Firefly III runs migrations automatically when the container starts.
COPY --chown=www-data:www-data database/migrations/2026_10_11_120000_create_investment_tables.php /var/www/html/database/migrations/

# Make the two one-line edits (route + menu entry) to the files that are in the official image.
COPY --chown=www-data:www-data custom/apply-changes.sh /tmp/apply-changes.sh
RUN bash /tmp/apply-changes.sh && rm /tmp/apply-changes.sh

LABEL org.opencontainers.image.description="Firefly III ${FIREFLY_VERSION} with a custom Investments page"
