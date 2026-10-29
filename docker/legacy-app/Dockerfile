# "Clinique Bonsoir": the deliberately dirty legacy app that PII Radar audits.
FROM php:7.4-apache

RUN docker-php-ext-install mysqli

# Serve demo/legacy-app/public; cron/ and logs/ sit next to it, outside the webroot.
ENV APACHE_DOCUMENT_ROOT=/var/www/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY demo/legacy-app/public/ /var/www/public/
COPY demo/legacy-app/cron/ /var/www/cron/
RUN mkdir -p /var/www/logs && chown www-data:www-data /var/www/logs
