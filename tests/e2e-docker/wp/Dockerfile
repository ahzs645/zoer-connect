FROM wordpress:php8.3-apache
# WP-CLI from the official image, so CLI and web requests share PHP, user and files.
COPY --from=wordpress:cli-php8.3 /usr/local/bin/wp /usr/local/bin/wp
# The document root is chosen per site at runtime (Apache expands ${APACHE_DOCUMENT_ROOT}).
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!<Directory /var/www/>!<Directory ${APACHE_DOCUMENT_ROOT}/>!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && mkdir -p /srv/source/public /srv/source/zoer-private /var/www/zoer-private /tmp/wp-cli-cache \
 && chown www-data:www-data /srv/source/public /srv/source/zoer-private /var/www/zoer-private /tmp/wp-cli-cache \
 && chmod 0700 /srv/source/zoer-private /var/www/zoer-private
