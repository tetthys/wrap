# dockerimages/phptestenv/Dockerfile
FROM php:8.3-cli-alpine

# Allow running Composer as root inside the container
ENV COMPOSER_ALLOW_SUPERUSER=1

# Working directory for the project
WORKDIR /workspace

# Install minimal tools needed for Composer and general workflows
# - git, unzip: often needed by composer
# - bash, curl: convenience and installer fetch
RUN apk add --no-cache git unzip bash curl

# Install PHP extension(s) you need for tests/builds

# Install Composer inside the image with signature verification
# (Uses the hash you provided from getcomposer.org/download/)
RUN set -eux; \
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
    php -r "if (hash_file('sha384', 'composer-setup.php') === 'ed0feb545ba87161262f2d45a633e34f591ebb3381f2e0063c345ebea4d228dd0043083717770234ec00c5a9f9593792') { echo 'Installer verified'.PHP_EOL; } else { echo 'Installer corrupt'.PHP_EOL; unlink('composer-setup.php'); exit(1); }" && \
    php composer-setup.php && \
    php -r "unlink('composer-setup.php');" && \
    mv composer.phar /usr/local/bin/composer

# Default command (docker-compose.yaml will override this with a test command)
CMD ["php", "-v"]
