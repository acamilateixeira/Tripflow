FROM mcr.microsoft.com/devcontainers/php:1-8.3-bookworm

# Extensões da aplicação: MySQL, Redis (cache/locks/filas), sockets (RabbitMQ) e pcntl (sinais nos workers).
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql redis sockets pcntl \
    && apt-get update && apt-get install -y --no-install-recommends default-mysql-client \
    && rm -rf /var/lib/apt/lists/*
