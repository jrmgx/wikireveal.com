# built with command `docker build . -t jrmgx/wikinize`
FROM php:8.1-cli

RUN apt -y update \
    && apt install -y --no-install-recommends \
        unzip curl unzip zip git

RUN apt -y update \
    && apt install -y --no-install-recommends \
        libicu-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install intl

RUN curl -sS https://getcomposer.org/installer | php -- \
    && mv composer.phar /usr/local/bin/composer

RUN curl -1sLf 'https://dl.cloudsmith.io/public/symfony/stable/setup.deb.sh' | bash - \
    && apt install -y --no-install-recommends \
        symfony-cli

RUN curl -fsSL https://deb.nodesource.com/setup_16.x | bash - \
    && apt install -y --no-install-recommends \
        nodejs

WORKDIR /app
