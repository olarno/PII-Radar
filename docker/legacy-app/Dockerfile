# "Clinique Bonsoir": the deliberately dirty legacy app that PII Radar audits.
FROM php:7.4-apache

RUN docker-php-ext-install mysqli pdo_mysql

COPY demo/legacy-app/public/ /var/www/html/
